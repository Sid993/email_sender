<?php /** @var string $title */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22%3E%3Crect width=%2216%22 height=%2216%22 rx=%223%22 fill=%22%230a7bff%22/%3E%3Cpath d=%22M3 5l5 3.5L13 5v6H3z%22 fill=%22%23fff%22/%3E%3C/svg%3E">
    <title><?= htmlspecialchars($title ?? 'Bulk Mailer') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/app.css?v=1" rel="stylesheet">
<?php if (isset($csrf)): ?>    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<?php endif; ?>
</head>
