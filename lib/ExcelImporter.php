<?php
// lib/ExcelImporter.php

declare(strict_types=1);

namespace App;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PDO;
use RuntimeException;

class ExcelImporter
{
    private string $filePath;
    private ?array $headers = null;
    private ?array $sampleRows = null;
    private ?string $selectedSheet = null;

    public function __construct(string $filePath)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('File not found or not readable: ' . $filePath);
        }
        $this->filePath = $filePath;
    }

    /**
     * List all worksheet names in the workbook (does not load full data).
     *
     * @return string[]
     */
    public function listSheetNames(): array
    {
        $reader = $this->createReader();
        if (method_exists($reader, 'listWorksheetNames')) {
            /** @var string[] $names */
            $names = $reader->listWorksheetNames($this->filePath);
            return array_values($names);
        }

        // Fallback: load workbook metadata
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($this->filePath);
        $names = $spreadsheet->getSheetNames();
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return array_values($names);
    }

    /**
     * Load headers + sample rows from a specific sheet.
     *
     * @param string|null $sheetName  null = active/first sheet
     * @param bool $calculateFormulas Resolve formulas (including cross-sheet refs)
     */
    public function loadHeadersAndSamples(
        ?string $sheetName = null,
        int $sampleCount = 5,
        bool $calculateFormulas = true
    ): void {
        $this->selectedSheet = $sheetName;

        // For formula calculation we need the full workbook loaded (other sheets available).
        $reader = $this->createReader();
        $reader->setReadDataOnly(!$calculateFormulas);

        $spreadsheet = $reader->load($this->filePath);
        $sheet = $this->resolveSheet($spreadsheet, $sheetName);

        // toArray: nullValue, calculateFormulas, formatData, returnCellRef
        $rows = $sheet->toArray(null, $calculateFormulas, true, false);

        if (empty($rows)) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('Selected sheet is empty.');
        }

        $rawHeaders = $rows[0] ?? [];
        $this->headers = [];
        foreach ($rawHeaders as $idx => $header) {
            $clean = trim((string) $header);
            $this->headers[$idx] = $clean !== '' ? $clean : 'Column_' . ($idx + 1);
        }

        $this->sampleRows = array_slice($rows, 1, $sampleCount);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    public function getHeaders(): array
    {
        if ($this->headers === null) {
            $this->loadHeadersAndSamples($this->selectedSheet);
        }
        return $this->headers;
    }

    public function getSampleRows(): array
    {
        if ($this->sampleRows === null) {
            $this->loadHeadersAndSamples($this->selectedSheet);
        }
        return $this->sampleRows;
    }

    /**
     * Import using column mapping.
     *
     * $options:
     *   - skip_header (bool)
     *   - batch_size (int)
     *   - unique_key (string|null)
     *   - sheet_name (string|null)
     *   - calculate_formulas (bool) — resolve formulas / cross-sheet references
     *
     * @return array{success:int, skipped:int, errors:array}
     */
    public function importWithMapping(PDO $pdo, string $table, array $mapping, array $options = []): array
    {
        $skipHeader         = $options['skip_header'] ?? true;
        $batchSize          = $options['batch_size'] ?? 100;
        $uniqueKey          = $options['unique_key'] ?? null;
        $sheetName          = $options['sheet_name'] ?? $this->selectedSheet;
        $calculateFormulas  = $options['calculate_formulas'] ?? true;

        $reader = $this->createReader();
        $reader->setReadDataOnly(!$calculateFormulas);
        $spreadsheet = $reader->load($this->filePath);
        $sheet = $this->resolveSheet($spreadsheet, $sheetName);

        $allRows = $sheet->toArray(null, $calculateFormulas, true, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($skipHeader && !empty($allRows)) {
            array_shift($allRows);
        }

        $dbColumns = array_values(array_filter($mapping, fn($col) => $col !== null && $col !== ''));
        if (empty($dbColumns)) {
            throw new RuntimeException('No columns mapped.');
        }

        $placeholders = implode(', ', array_fill(0, count($dbColumns), '?'));
        $columnList   = '`' . implode('`, `', $dbColumns) . '`';

        if ($uniqueKey && in_array($uniqueKey, $dbColumns, true)) {
            $updates = [];
            foreach ($dbColumns as $col) {
                if ($col !== $uniqueKey) {
                    $updates[] = "`$col` = VALUES(`$col`)";
                }
            }
            $sql = "INSERT INTO `$table` ($columnList) VALUES ($placeholders)
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        } else {
            $sql = "INSERT INTO `$table` ($columnList) VALUES ($placeholders)";
        }

        $stmt = $pdo->prepare($sql);

        $success = 0;
        $skipped = 0;
        $errors  = [];
        $batch   = [];

        foreach ($allRows as $rowIndex => $row) {
            $data = [];
            $hasData = false;

            foreach ($mapping as $excelIdx => $dbCol) {
                if ($dbCol === null || $dbCol === '') {
                    continue;
                }
                $value = $row[$excelIdx] ?? null;
                if ($value !== null && $value !== '') {
                    $hasData = true;
                }
                if (is_string($value)) {
                    $value = trim($value);
                }
                // Excel date serial → Y-m-d when column looks like a date field
                if (is_numeric($value) && $dbCol === 'joined_at') {
                    try {
                        $value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value)
                            ->format('Y-m-d');
                    } catch (\Throwable $e) {
                        // keep original
                    }
                }
                $data[] = $value === '' ? null : $value;
            }

            if (!$hasData) {
                $skipped++;
                continue;
            }

            $batch[] = $data;

            if (count($batch) >= $batchSize) {
                $this->flushBatch($stmt, $batch, $success, $errors, $rowIndex);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->flushBatch($stmt, $batch, $success, $errors, count($allRows));
        }

        return [
            'success' => $success,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    private function resolveSheet(Spreadsheet $spreadsheet, ?string $sheetName)
    {
        if ($sheetName === null || $sheetName === '') {
            return $spreadsheet->getActiveSheet();
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);
        if ($sheet === null) {
            throw new RuntimeException('Sheet not found: ' . $sheetName);
        }
        return $sheet;
    }

    private function flushBatch(\PDOStatement $stmt, array $batch, int &$success, array &$errors, int $approxRow): void
    {
        try {
            foreach ($batch as $data) {
                $stmt->execute($data);
                $success++;
            }
        } catch (\PDOException $e) {
            foreach ($batch as $i => $data) {
                try {
                    $stmt->execute($data);
                    $success++;
                } catch (\PDOException $ex) {
                    $errors[] = [
                        'row'   => $approxRow - count($batch) + $i + 2,
                        'error' => $ex->getMessage(),
                        'data'  => $data,
                    ];
                }
            }
        }
    }

    private function createReader(): IReader
    {
        return IOFactory::createReaderForFile($this->filePath);
    }
}