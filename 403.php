<?php
/**
 * Styled 403 page.
 */
require_once __DIR__ . '/includes/init.php';

$pageTitle = '403 Forbidden';

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(pageTitle($pageTitle)) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='5' y='11' width='14' height='10' rx='2' fill='%232563eb'/%3E%3Cpath d='M8 11V8a4 4 0 0 1 8 0v3' fill='none' stroke='%232563eb' stroke-width='2'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= e(assetUrl('/assets/css/style.css')) ?>">
</head>
<body class="auth-body">
    <div class="auth-card" style="text-align:center">
        <div class="auth-card__brand">
            <div class="empty-state__icon" aria-hidden="true">&#128274;</div>
            <h1>403</h1>
            <p>Forbidden</p>
        </div>
        <p class="muted">You don't have permission to access this page. Contact an administrator if you believe this is an error.</p>
        <p style="margin-top:18px"><a class="btn btn--primary" href="<?= BASE_URL ?>/dashboard.php">Back to dashboard</a></p>
    </div>
</body>
</html>
