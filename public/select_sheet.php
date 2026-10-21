<?php
// public/select_sheet.php

declare(strict_types=1);

session_start();

if (empty($_SESSION['import']['file_path']) || !is_file($_SESSION['import']['file_path'])) {
    $_SESSION['flash_error'] = 'No staged file found. Please upload again.';
    header('Location: upload.php');
    exit;
}

$import     = $_SESSION['import'];
$sheetNames = $import['sheet_names'] ?? [];

if (empty($sheetNames)) {
    $_SESSION['flash_error'] = 'No sheets found in the workbook.';
    header('Location: upload.php');
    exit;
}

// Handle sheet selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen = trim((string)($_POST['sheet_name'] ?? ''));
    if ($chosen === '' || !in_array($chosen, $sheetNames, true)) {
        $_SESSION['flash_error'] = 'Please select a valid sheet.';
        header('Location: select_sheet.php');
        exit;
    }

    require dirname(__DIR__) . '/vendor/autoload.php';

    try {
        $importer = new \App\ExcelImporter($import['file_path']);
        // calculate_formulas = true so cross-sheet formula results appear in samples
        $importer->loadHeadersAndSamples($chosen, 3, true);
        $_SESSION['import']['sheet_name'] = $chosen;
        $_SESSION['import']['headers']    = $importer->getHeaders();
        $_SESSION['import']['samples']    = $importer->getSampleRows();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Could not read sheet: ' . $e->getMessage();
        header('Location: select_sheet.php');
        exit;
    }

    header('Location: map.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Sheet</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 24px; }
        h1 { margin-top: 0; font-size: 1.5rem; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        select { width: 100%; padding: 10px; margin-bottom: 16px; font-size: 1rem; }
        button { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #1d4ed8; }
        .msg { padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .error { background: #fee2e2; color: #991b1b; }
        .info { background: #e0f2fe; color: #075985; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .hint { color: #64748b; font-size: 0.9rem; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Select Worksheet</h1>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="msg error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="info">
            File: <strong><?= htmlspecialchars($import['original_name']) ?></strong><br>
            This workbook has <strong><?= count($sheetNames) ?></strong> sheets.
            Choose which sheet contains the data to import.
        </div>

        <form method="post">
            <label for="sheet_name">Sheet</label>
            <select name="sheet_name" id="sheet_name" required>
                <?php foreach ($sheetNames as $name): ?>
                    <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>

            <p class="hint">
                Formulas that reference other sheets in this workbook will be calculated
                when possible (values are imported, not the formula text).
            </p>

            <button type="submit">Continue to Column Mapping</button>
        </form>
    </div>
</body>
</html>