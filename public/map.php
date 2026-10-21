<?php
// public/map.php

declare(strict_types=1);

session_start();

if (empty($_SESSION['import']['file_path']) || !is_file($_SESSION['import']['file_path'])) {
    $_SESSION['flash_error'] = 'No staged file found. Please upload again.';
    header('Location: upload.php');
    exit;
}

if (empty($_SESSION['import']['headers'])) {
    // Sheet not chosen yet
    header('Location: select_sheet.php');
    exit;
}

$import    = $_SESSION['import'];
$headers   = $import['headers'];
$samples   = $import['samples'];
$sheetName = $import['sheet_name'] ?? '(default)';

$dbColumns = [
    'name'       => 'Name (required)',
    'email'      => 'Email (required, unique)',
    'phone'      => 'Phone',
    'department' => 'Department',
    'joined_at'  => 'Joined Date',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map Columns</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 960px; margin: 40px auto; padding: 0 16px; }
        h1 { font-size: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; vertical-align: top; }
        th { background: #f8fafc; }
        select { width: 100%; padding: 6px; }
        .sample { color: #64748b; font-size: 0.85rem; margin-top: 4px; }
        button { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #1d4ed8; }
        .actions { margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap; }
        .secondary { background: #64748b; }
        .info { background: #e0f2fe; color: #075985; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .msg { padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <h1>Map Excel Columns → Database</h1>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="msg error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <div class="info">
        File: <strong><?= htmlspecialchars($import['original_name']) ?></strong><br>
        Sheet: <strong><?= htmlspecialchars((string)$sheetName) ?></strong><br>
        Detected <?= count($headers) ?> columns. Map each Excel column to a database field (or leave as “Ignore”).
    </div>

    <form action="import.php" method="post">
        <table>
            <thead>
                <tr>
                    <th style="width:40%">Excel Column</th>
                    <th style="width:35%">Map to DB Column</th>
                    <th style="width:25%">Sample Values</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($headers as $idx => $header): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($header) ?></strong>
                            <div class="sample">Index: <?= $idx ?></div>
                        </td>
                        <td>
                            <select name="mapping[<?= $idx ?>]">
                                <option value="">— Ignore —</option>
                                <?php foreach ($dbColumns as $col => $label): ?>
                                    <option value="<?= htmlspecialchars($col) ?>"
                                        <?= (strtolower($header) === strtolower($col) || strtolower($header) === strtolower(str_replace('_', ' ', $col))) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <?php
                            $sampleVals = [];
                            foreach ($samples as $row) {
                                $val = $row[$idx] ?? '';
                                if ($val !== '' && $val !== null) {
                                    $sampleVals[] = htmlspecialchars((string)$val);
                                }
                            }
                            echo $sampleVals ? implode('<br>', array_slice($sampleVals, 0, 3)) : '<em>—</em>';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="actions">
            <button type="submit">Import Data</button>
            <?php if (count($import['sheet_names'] ?? []) > 1): ?>
                <a href="select_sheet.php"><button type="button" class="secondary">Change sheet</button></a>
            <?php endif; ?>
            <a href="upload.php"><button type="button" class="secondary">Cancel / Upload another</button></a>
        </div>
    </form>
</body>
</html>