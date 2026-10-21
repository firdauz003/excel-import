<?php
// lib/ExcelImporter.php

declare(strict_types=1);

namespace App;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PDO;
use RuntimeException;

class ExcelImporter
{
    private string $filePath;
    private ?array $headers = null;
    private ?array $sampleRows = null;

    public function __construct(string $filePath)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('File not found or not readable: ' . $filePath);
        }
        $this->filePath = $filePath;
    }

    /**
     * Load only the first row as headers + a few sample data rows.
     */
    public function loadHeadersAndSamples(int $sampleCount = 5): void
    {
        $reader = $this->createReader();
        $reader->setReadDataOnly(true);

        // Read only first N+1 rows for speed
        $reader->setReadFilter(new class($sampleCount + 1) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            private int $maxRow;
            public function __construct(int $maxRow) { $this->maxRow = $maxRow; }
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRow;
            }
        });

        $spreadsheet = $reader->load($this->filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            throw new RuntimeException('Excel file is empty.');
        }

        // Normalize headers
        $rawHeaders = $rows[0] ?? [];
        $this->headers = [];
        foreach ($rawHeaders as $idx => $header) {
            $clean = trim((string) $header);
            $this->headers[$idx] = $clean !== '' ? $clean : 'Column_' . ($idx + 1);
        }

        // Sample data rows
        $this->sampleRows = array_slice($rows, 1, $sampleCount);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    public function getHeaders(): array
    {
        if ($this->headers === null) {
            $this->loadHeadersAndSamples();
        }
        return $this->headers;
    }

    public function getSampleRows(): array
    {
        if ($this->sampleRows === null) {
            $this->loadHeadersAndSamples();
        }
        return $this->sampleRows;
    }

    /**
     * Process the whole file using a mapping.
     *
     * $mapping example:
     * [
     *   0 => 'name',      // Excel column index 0 → DB column "name"
     *   2 => 'email',
     *   1 => null,        // ignore
     * ]
     *
     * Returns ['success' => int, 'errors' => array, 'skipped' => int]
     */
    public function importWithMapping(PDO $pdo, string $table, array $mapping, array $options = []): array
    {
        $skipHeader   = $options['skip_header'] ?? true;
        $batchSize    = $options['batch_size'] ?? 100;
        $uniqueKey    = $options['unique_key'] ?? null; // e.g. 'email' for simple upsert

        $reader = $this->createReader();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($this->filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($skipHeader && !empty($allRows)) {
            array_shift($allRows);
        }

        // Build the list of DB columns we will insert
        $dbColumns = array_values(array_filter($mapping, fn($col) => $col !== null && $col !== ''));
        if (empty($dbColumns)) {
            throw new RuntimeException('No columns mapped.');
        }

        $placeholders = implode(', ', array_fill(0, count($dbColumns), '?'));
        $columnList   = '`' . implode('`, `', $dbColumns) . '`';

        // Simple INSERT (or REPLACE / ON DUPLICATE if uniqueKey given)
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
                // Basic cleanup
                if (is_string($value)) {
                    $value = trim($value);
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

        // remaining
        if (!empty($batch)) {
            $this->flushBatch($stmt, $batch, $success, $errors, count($allRows));
        }

        return [
            'success' => $success,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    private function flushBatch(\PDOStatement $stmt, array $batch, int &$success, array &$errors, int $approxRow): void
    {
        // Try the whole batch first; on any failure fall back to row-by-row
        // so we can report which rows failed.
        try {
            foreach ($batch as $data) {
                $stmt->execute($data);
                $success++;
            }
        } catch (\PDOException $e) {
            // Isolate bad rows
            foreach ($batch as $i => $data) {
                try {
                    $stmt->execute($data);
                    $success++;
                } catch (\PDOException $ex) {
                    $errors[] = [
                        'row'   => $approxRow - count($batch) + $i + 2, // approximate Excel row
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