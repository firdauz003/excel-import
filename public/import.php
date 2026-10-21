<?php
// public/import.php

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/vendor/autoload.php';

use App\ExcelImporter;

// --- Security / validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['import']['file_path'])) {
    $_SESSION['flash_error'] = 'Invalid request or session expired.';
    header('Location: upload.php');
    exit;
}

$filePath = $_SESSION['import']['file_path'];
if (!is_file($filePath)) {
    unset($_SESSION['import']);
    $_SESSION['flash_error'] = 'Staged file no longer exists.';
    header('Location: upload.php');
    exit;
}

// Mapping from form: [excel_index => db_column|""]
$rawMapping = $_POST['mapping'] ?? [];
$mapping = [];
foreach ($rawMapping as $idx => $dbCol) {
    $idx = (int) $idx;
    $dbCol = trim((string) $dbCol);
    $mapping[$idx] = $dbCol !== '' ? $dbCol : null;
}

// At least one column must be mapped
if (!array_filter($mapping)) {
    $_SESSION['flash_error'] = 'Please map at least one column.';
    header('Location: map.php');
    exit;
}

// --- DB connection ---
$dbConfig = require dirname(__DIR__) . '/config/database.php';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname'],
        $dbConfig['charset']
    );
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $_SESSION['flash_error'] = 'Database connection failed: ' . $e->getMessage();
    header('Location: map.php');
    exit;
}

// --- Run import ---
try {
    $importer = new ExcelImporter($filePath);
    $result = $importer->importWithMapping($pdo, 'employees', $mapping, [
        'skip_header' => true,
        'batch_size'  => 50,
        'unique_key'  => 'email',   // enables ON DUPLICATE KEY UPDATE
    ]);
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Import failed: ' . $e->getMessage();
    header('Location: map.php');
    exit;
}

// Clean up staged file
@unlink($filePath);
unset($_SESSION['import']);

// Store result for display
$_SESSION['import_result'] = $result;

header('Location: result.php');
exit;