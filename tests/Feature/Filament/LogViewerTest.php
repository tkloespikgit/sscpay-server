<?php

namespace Tests\Feature\Filament;

use AchyutN\FilamentLogViewer\Contracts\LogParser;
use AchyutN\FilamentLogViewer\Contracts\StackTraceParser;
use AchyutN\FilamentLogViewer\LogTable;
use App\Filament\Pages\LogViewer;
use App\Filament\Support\RecentLogProvider;
use App\Models\User;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Generator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LogViewerTest extends TestCase
{
    private ?string $logDirectory = null;

    protected function tearDown(): void
    {
        if ($this->logDirectory !== null) {
            (new Filesystem)->deleteDirectory($this->logDirectory);
        }

        parent::tearDown();
    }

    public function test_logs_are_read_without_loading_or_writing_the_shared_parsed_row_cache(): void
    {
        $provider = $this->provider();
        $this->writeLog('current.log', $this->logLine('2026-10-22 10:00:00', 'current entry'));
        config()->set('filament-log-viewer.disable_cache', false);
        Cache::shouldReceive('get')->never();
        Cache::shouldReceive('forever')->never();

        $rows = $provider->getRows();

        $this->assertSame(['current entry'], array_column($rows, 'message'));
    }

    public function test_an_empty_log_directory_has_no_rows_files_or_counts(): void
    {
        $provider = $this->provider();

        $this->assertSame([], $provider->getRows());
        $this->assertSame([], $provider->getFiles());
        $this->assertNull($provider->getCount());
    }

    public function test_source_budget_prefers_recent_files_and_still_includes_smaller_files_that_fit(): void
    {
        $provider = $this->provider();
        $this->writeLog('old-small.log', $this->paddedLog('small', 512 * 1024), 1000);
        $this->writeLog('middle-too-large.log', $this->paddedLog('middle', 1024 * 1024), 2000);
        $this->writeLog('newest.log', $this->paddedLog('newest', 1536 * 1024), 3000);

        $files = array_column($provider->getRows(), 'file');

        $this->assertEqualsCanonicalizing(['newest.log', 'old-small.log'], $files);
    }

    public function test_the_latest_thousand_entries_are_retained_across_files_with_unordered_dates(): void
    {
        $provider = $this->provider();
        $baseDate = new DateTimeImmutable('2026-10-22 00:00:00');
        $indices = array_merge(range(1100, 1199), range(0, 1099));
        $lines = array_map(
            fn (int $index): string => $this->logLine($baseDate->modify("+{$index} seconds")->format('Y-m-d H:i:s'), "entry-{$index}"),
            $indices,
        );
        $this->writeLog('newer-file.log', implode('', array_slice($lines, 0, 600)), 2000);
        $this->writeLog('older-file.log', implode('', array_slice($lines, 600)), 1000);

        $rows = $provider->getRows();

        $this->assertCount(1000, $rows);
        $this->assertSame(
            array_map(fn (int $index): string => "entry-{$index}", range(1199, 200)),
            array_column($rows, 'message'),
        );
    }

    public function test_refresh_reloads_new_log_entries(): void
    {
        $provider = $this->provider();
        $firstLine = $this->logLine('2026-10-22 10:00:00', 'first');
        $this->writeLog('current.log', $firstLine);
        $this->assertSame(['first'], array_column($provider->getRows(), 'message'));
        $this->writeLog('current.log', $firstLine.$this->logLine('2026-10-22 10:01:00', 'second'));

        $this->assertSame(['first'], array_column($provider->getRows(), 'message'));
        $this->assertSame(['second', 'first'], array_column($provider->getRows(true), 'message'));
    }

    public function test_growth_during_parsing_is_deferred_until_refresh_and_temporary_snapshots_are_removed(): void
    {
        $parser = new class(app(LogParser::class), $this->logLine('2026-10-22 10:01:00', 'second')) implements LogParser
        {
            public string $sourcePath;

            /** @var list<string> */
            public array $snapshotPaths = [];

            private bool $hasAppended = false;

            public function __construct(private readonly LogParser $parser, private readonly string $appendedLine) {}

            public function parse(string $filePath, string $fileName): Generator
            {
                $this->snapshotPaths[] = $filePath;

                if (! $this->hasAppended) {
                    file_put_contents($this->sourcePath, $this->appendedLine, FILE_APPEND);
                    clearstatcache(true, $this->sourcePath);
                    $this->hasAppended = true;
                }

                yield from $this->parser->parse($filePath, $fileName);
            }

            public function isMailEntry(string $message): bool
            {
                return $this->parser->isMailEntry($message);
            }
        };
        $provider = $this->provider($parser);
        $parser->sourcePath = $this->logDirectory.'/current.log';
        $this->writeLog('current.log', $this->logLine('2026-10-22 10:00:00', 'first'));

        $this->assertSame(['first'], array_column($provider->getRows(), 'message'));
        $this->assertNotSame($parser->sourcePath, $parser->snapshotPaths[0]);
        $this->assertFileDoesNotExist($parser->snapshotPaths[0]);
        $this->assertSame(['second', 'first'], array_column($provider->getRows(true), 'message'));
        $this->assertCount(2, $parser->snapshotPaths);
        $this->assertFileDoesNotExist($parser->snapshotPaths[1]);
        $this->assertFileExists($parser->sourcePath);
    }

    public function test_files_above_the_plugins_single_file_limit_are_still_skipped(): void
    {
        $provider = $this->provider();
        config()->set('filament-log-viewer.max_log_file_size', 1);
        $this->writeLog('too-large.log', $this->paddedLog('too large', 1025), 2000);
        $this->writeLog('allowed.log', $this->paddedLog('allowed', 1024), 1000);

        $this->assertSame(['allowed.log'], array_column($provider->getRows(), 'file'));
    }

    public function test_only_the_admin_panel_registers_the_bounded_log_viewer(): void
    {
        $admin = Filament::getPanel('admin');
        $merchant = Filament::getPanel('merchant');
        $plugin = $admin->getPlugin('filament-log-viewer');

        $this->assertSame(RecentLogProvider::class, $plugin->getProviderClass());
        $this->assertSame(LogViewer::class, $plugin->getPageClass());
        $this->assertContains(LogViewer::class, $admin->getPages());
        $this->assertNotContains(LogTable::class, $admin->getPages());
        $this->assertFalse($merchant->hasPlugin('filament-log-viewer'));
        $this->assertNotContains(LogViewer::class, $merchant->getPages());
    }

    public function test_the_log_viewer_remains_restricted_to_super_admins(): void
    {
        Filament::setCurrentPanel('admin');
        $this->assertFalse(LogViewer::canAccess());

        $this->actingAs(User::factory()->make(['is_super_admin' => false, 'status' => true]));
        $this->assertFalse(LogViewer::canAccess());

        $this->actingAs(User::factory()->make(['is_super_admin' => true, 'status' => true]));
        $this->assertTrue(LogViewer::canAccess());
    }

    private function provider(?LogParser $parser = null): RecentLogProvider
    {
        $this->logDirectory = sys_get_temp_dir().'/log-viewer-test-'.bin2hex(random_bytes(8));
        mkdir($this->logDirectory, 0700);
        config()->set('filament-log-viewer.max_log_file_size', 2048);

        return new class($parser ?? app(LogParser::class), app(StackTraceParser::class), $this->logDirectory) extends RecentLogProvider
        {
            public function __construct(LogParser $parser, StackTraceParser $stackTraceParser, private readonly string $directory)
            {
                parent::__construct($parser, $stackTraceParser);
            }

            protected function getLogFilePath(): string
            {
                return $this->directory;
            }
        };
    }

    private function writeLog(string $name, string $contents, ?int $modifiedAt = null): void
    {
        $path = $this->logDirectory.'/'.$name;
        file_put_contents($path, $contents);

        if ($modifiedAt !== null) {
            touch($path, $modifiedAt);
        }

        clearstatcache(true, $path);
    }

    private function logLine(string $date, string $message): string
    {
        return "[{$date}] testing.INFO: {$message}\n";
    }

    private function paddedLog(string $message, int $bytes): string
    {
        return str_pad(rtrim($this->logLine('2026-10-22 10:00:00', $message), "\n"), $bytes - 1)."\n";
    }
}
