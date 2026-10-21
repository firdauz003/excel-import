<?php
// public/process_upload.php

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/vendor/autoload.php';

use App\ExcelImporter;

// --- Config ---
$allowedExt  = ['xlsx', 'xls'];
$maxSize     = 10 * 1024 * 1024; // 10 MB
$tmpDir      = dirname(__DIR__) . '/storage/tmp';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: upload.php');
    exit;
}

if (empty($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = 'Upload failed. Please try again.';
    header('Location: upload.php');
    exit;
}

$file = $_FILES['excel'];

// Extension check
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    $_SESSION['flash_error'] = 'Only .xlsx and .xls files are allowed.';
    header('Location: upload.php');
    exit;
}

// Size check
if ($file['size'] > $maxSize) {
    $_SESSION['flash_error'] = 'File is too large (max 10 MB).';
    header('Location: upload.php');
    exit;
}

// Generate safe unique filename
$uuid = bin2hex(random_bytes(16));
$targetPath = $tmpDir . '/import_' . $uuid . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    $_SESSION['flash_error'] = 'Could not save uploaded file.';
    header('Location: upload.php');
    exit;
}

// Quick validation: can we read headers?
try {
    $importer = new ExcelImporter($targetPath);
    $importer->loadHeadersAndSamples(3);
    $headers = $importer->getHeaders();
    $samples = $importer->getSampleRows();
} catch (Throwable $e) {
    @unlink($targetPath);
    $_SESSION['flash_error'] = 'Invalid Excel file: ' . $e->getMessage();
    header('Location: upload.php');
    exit;
}

// Store staging info in session
$_SESSION['import'] = [
    'file_path'     => $targetPath,
    'original_name' => $file['name'],
    'headers'       => $headers,
    'samples'       => $samples,
    'created_at'    => time(),
];

header('Location: map.php');
exit;