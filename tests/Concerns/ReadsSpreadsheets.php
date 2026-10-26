<?php

namespace Tests\Concerns;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

trait ReadsSpreadsheets
{
    private function readSpreadsheet(string $contents): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'spreadsheet_export_test_');

        if ($path === false) {
            $this->fail('无法创建测试工作簿');
        }

        try {
            file_put_contents($path, $contents);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    /** @return array<int, array<int, mixed>> */
    private function spreadsheetRows(string $contents): array
    {
        $workbook = $this->readSpreadsheet($contents);

        try {
            return $workbook->getActiveSheet()->toArray(null, false, false);
        } finally {
            $workbook->disconnectWorksheets();
        }
    }
}
