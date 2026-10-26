<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use OverflowException;
use RuntimeException;

class XlsxExport
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const MAX_ROWS_PER_SHEET = 1048576;

    /**
     * 非金额字段显式写为文本，保留长编号、前导零及公式样式的原始字符。
     *
     * @param  list<int|float|string|null>  $values
     * @param  list<int>  $amountColumns  从 0 开始的金额列索引
     */
    public static function row(array $values, array $amountColumns = []): Row
    {
        $textStyle = (new Style)->setFormat('@');
        $amountStyle = (new Style)->setFormat('0.00');
        $cells = [];

        foreach ($values as $index => $value) {
            $cells[] = in_array($index, $amountColumns, true) && is_numeric($value)
                ? new NumericCell((float) $value, $amountStyle)
                : new StringCell((string) $value, $textStyle);
        }

        return new Row($cells);
    }

    /** @param  iterable<Row>  $rows */
    public static function write(iterable $rows, string $path = 'php://output', ?int $rowLimit = null): void
    {
        $options = new Options;
        $options->SHOULD_USE_INLINE_STRINGS = false;
        $options->DEFAULT_COLUMN_WIDTH = 28;
        $writer = new Writer($options);
        $writer->openToFile($path);

        try {
            foreach ($rows as $row) {
                if ($rowLimit !== null && $writer->getWrittenRowCount() >= $rowLimit) {
                    throw new OverflowException('Spreadsheet row limit exceeded.');
                }

                $writer->addRow($row);
            }
        } finally {
            $writer->close();
        }
    }

    /** @param  iterable<Row>  $rows */
    public static function contents(iterable $rows, ?int $rowLimit = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_export_');

        if ($path === false) {
            throw new RuntimeException('无法创建导出临时文件');
        }

        try {
            self::write($rows, $path, $rowLimit);
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException('无法读取导出文件');
            }

            return $contents;
        } finally {
            unlink($path);
        }
    }
}
