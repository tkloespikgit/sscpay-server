<?php

namespace App\Filament\Support;

use AchyutN\FilamentLogViewer\Contracts\LogParser;
use AchyutN\FilamentLogViewer\Contracts\LogProvider;
use AchyutN\FilamentLogViewer\Contracts\StackTraceParser;
use AchyutN\FilamentLogViewer\Providers\LocalLogProvider;
use Generator;
use RuntimeException;
use SplMinHeap;

/**
 * @phpstan-import-type LogRow from LogProvider
 */
class RecentLogProvider extends LocalLogProvider
{
    public const MAX_SOURCE_BYTES = 2 * 1024 * 1024;

    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly LogParser $parser,
        StackTraceParser $stackTraceParser,
    ) {
        parent::__construct($parser, $stackTraceParser);
    }

    /**
     * Avoid loading or serializing the plugin's unbounded persistent cache.
     * The parent still memoizes these rows within the current request.
     *
     * @return list<LogRow>
     */
    protected function getCachedRows(): array
    {
        return $this->parseAll();
    }

    /**
     * @return list<LogRow>
     */
    protected function parseAll(): array
    {
        $directory = $this->getLogFilePath();
        $files = [];

        foreach ($this->getAllLogFiles() as $file) {
            $path = $directory.DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                continue;
            }

            $files[] = ['file' => $file, 'modified' => filemtime($path) ?: 0];
        }

        usort($files, fn (array $a, array $b): int => ($b['modified'] <=> $a['modified']) ?: strcmp($a['file'], $b['file']));

        /** @var SplMinHeap<array{string, int, LogRow}> $recentRows */
        $recentRows = new SplMinHeap;
        $remainingBytes = self::MAX_SOURCE_BYTES;
        $sequence = 0;

        foreach ($files as $file) {
            $path = $directory.DIRECTORY_SEPARATOR.$file['file'];
            clearstatcache(true, $path);
            $size = is_file($path) ? filesize($path) : false;
            if ($size === false || $size > $remainingBytes) {
                continue;
            }

            $remainingBytes -= $size;

            foreach ($this->parseSnapshot($path, $file['file'], $size) as $row) {
                $recentRows->insert([$row['date'], $sequence++, $row]);
                if ($recentRows->count() > self::MAX_ROWS) {
                    $recentRows->extract();
                }
            }
        }

        $rows = [];
        foreach ($recentRows as $entry) {
            $rows[] = $entry[2];
        }

        return array_reverse($rows);
    }

    /**
     * Freeze the byte budget even when a live log grows during parsing.
     *
     * @return Generator<int, LogRow>
     */
    private function parseSnapshot(string $path, string $file, int $size): Generator
    {
        $source = fopen($path, 'rb');
        if ($source === false) {
            return;
        }

        $snapshot = tmpfile();
        if ($snapshot === false) {
            fclose($source);

            throw new RuntimeException('Unable to create a temporary log snapshot.');
        }

        try {
            if (stream_copy_to_stream($source, $snapshot, $size) === false) {
                throw new RuntimeException('Unable to read the log snapshot.');
            }

            fflush($snapshot);

            yield from $this->parser->parse(stream_get_meta_data($snapshot)['uri'], $file);
        } finally {
            fclose($source);
            fclose($snapshot);
        }
    }
}
