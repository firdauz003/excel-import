<?php
// public/result.php

declare(strict_types=1);

session_start();

$result = $_SESSION['import_result'] ?? null;
unset($_SESSION['import_result']);

if (!$result) {
    header('Location: upload.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Result</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 24px; }
        h1 { margin-top: 0; }
        .success { color: #15803d; font-size: 1.25rem; }
        .error-list { background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin-top: 16px; max-height: 300px; overflow: auto; }
        .error-list pre { margin: 0; font-size: 0.85rem; white-space: pre-wrap; }
        a.button { display: inline-block; margin-top: 20px; background: #2563eb; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Import Completed</h1>

        <p class="success">
            Successfully imported: <strong><?= (int)$result['success'] ?></strong> rows
        </p>
        <p>
            Skipped (empty): <?= (int)$result['skipped'] ?> rows
        </p>

        <?php if (!empty($result['errors'])): ?>
            <h3>Errors (<?= count($result['errors']) ?>)</h3>
            <div class="error-list">
                <?php foreach ($result['errors'] as $err): ?>
                    <div style="margin-bottom:10px;">
                        <strong>Row ≈ <?= (int)$err['row'] ?>:</strong>
                        <?= htmlspecialchars($err['error']) ?>
                        <pre><?= htmlspecialchars(json_encode($err['data'], JSON_UNESCAPED_UNICODE)) ?></pre>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <a href="upload.php" class="button">Import another file</a>
    </div>
</body>
</html>