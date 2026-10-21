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

// Ensure tmp directory exists and is writable (helps IIS / Apache)
if (!is_dir($tmpDir)) {
    if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        $_SESSION['flash_error'] = 'Cannot create upload directory: storage/tmp. Check folder permissions.';
        header('Location: upload.php');
        exit;
    }
}
if (!is_writable($tmpDir)) {
    $_SESSION['flash_error'] = 'Upload directory is not writable: storage/tmp. '
        . 'On Windows/IIS give the app pool identity write permission to that folder.';
    header('Location: upload.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: upload.php');
    exit;
}

// Detailed upload error messages
if (empty($_FILES['excel'])) {
    $_SESSION['flash_error'] = 'No file received. Check post_max_size / upload_max_filesize in php.ini '
        . '(IIS often uses a different php.ini than CLI).';
    header('Location: upload.php');
    exit;
}

$uploadError = $_FILES['excel']['error'];
if ($uploadError !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in the form.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on the server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    $_SESSION['flash_error'] = $messages[$uploadError] ?? ('Upload error code: ' . $uploadError);
    header('Location: upload.php');
    exit;
}

$file = $_FILES['excel'];

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    $_SESSION['flash_error'] = 'Only .xlsx and .xls files are allowed.';
    header('Location: upload.php');
    exit;
}

if ($file['size'] > $maxSize) {
    $_SESSION['flash_error'] = 'File is too large (max 10 MB).';
    header('Location: upload.php');
    exit;
}

$uuid = bin2hex(random_bytes(16));
$targetPath = $tmpDir . DIRECTORY_SEPARATOR . 'import_' . $uuid . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    $_SESSION['flash_error'] = 'Could not save uploaded file to storage/tmp. '
        . 'Check that the folder exists and IIS has write permission.';
    header('Location: upload.php');
    exit;
}

// List sheets + stage
try {
    $importer = new ExcelImporter($targetPath);
    $sheetNames = $importer->listSheetNames();
    if (empty($sheetNames)) {
        throw new RuntimeException('Workbook has no sheets.');
    }
} catch (Throwable $e) {
    @unlink($targetPath);
    $_SESSION['flash_error'] = 'Invalid Excel file: ' . $e->getMessage();
    header('Location: upload.php');
    exit;
}

$_SESSION['import'] = [
    'file_path'     => $targetPath,
    'original_name' => $file['name'],
    'sheet_names'   => $sheetNames,
    'created_at'    => time(),
];

// One sheet → load headers and go straight to mapping
// Multiple sheets → let user choose
if (count($sheetNames) === 1) {
    try {
        $importer->loadHeadersAndSamples($sheetNames[0], 3, true);
        $_SESSION['import']['sheet_name'] = $sheetNames[0];
        $_SESSION['import']['headers']    = $importer->getHeaders();
        $_SESSION['import']['samples']    = $importer->getSampleRows();
    } catch (Throwable $e) {
        @unlink($targetPath);
        unset($_SESSION['import']);
        $_SESSION['flash_error'] = 'Could not read sheet: ' . $e->getMessage();
        header('Location: upload.php');
        exit;
    }
    header('Location: map.php');
    exit;
}

header('Location: select_sheet.php');
exit;