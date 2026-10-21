<?php
// public/upload.php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel Import – Upload</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 24px; }
        h1 { margin-top: 0; font-size: 1.5rem; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input[type=file] { margin-bottom: 16px; }
        button { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #1d4ed8; }
        .msg { padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .error { background: #fee2e2; color: #991b1b; }
        .info  { background: #e0f2fe; color: #075985; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Upload Excel File</h1>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="msg error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['flash_info'])): ?>
            <div class="msg info"><?= htmlspecialchars($_SESSION['flash_info']) ?></div>
            <?php unset($_SESSION['flash_info']); ?>
        <?php endif; ?>

        <form action="process_upload.php" method="post" enctype="multipart/form-data">
            <label for="excel">Choose .xlsx or .xls file</label>
            <input type="file" name="excel" id="excel" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" required>

            <button type="submit">Upload &amp; Continue to Mapping</button>
        </form>
    </div>
</body>
</html>