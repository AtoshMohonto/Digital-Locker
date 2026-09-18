<?php
/**
 * Login page. No sidebar layout - dedicated centered card.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $stmt = $db->prepare('SELECT * FROM users WHERE username = :u OR email = :e LIMIT 1');
            $stmt->execute(['u' => $username, 'e' => $username]);
            $user = $stmt->fetch();

            if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);

                $db->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')->execute(['id' => $user['id']]);

                $_SESSION['user'] = [
                    'id'        => (int) $user['id'],
                    'username'  => $user['username'],
                    'email'     => $user['email'],
                    'full_name' => $user['full_name'],
                ];

                redirect(BASE_URL . '/dashboard.php');
            }

            $error = 'Invalid credentials or the account is disabled.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(pageTitle('Sign in')) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='5' y='11' width='14' height='10' rx='2' fill='%232563eb'/%3E%3Cpath d='M8 11V8a4 4 0 0 1 8 0v3' fill='none' stroke='%232563eb' stroke-width='2'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= e(assetUrl('/assets/css/style.css')) ?>">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="auth-card__brand">
            <svg class="auth-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3M12 15v3"/></svg>
            <h1><?= e(appName()) ?></h1>
            <p>Sign in to your vault</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= BASE_URL ?>/login.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Username or email</label>
                <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" autofocus required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn--primary btn--block">Sign in</button>
        </form>
    </div>
</body>
</html>
