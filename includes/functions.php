<?php
/**
 * Generic helper functions.
 */

declare(strict_types=1);

/**
 * Absolute application base URL for links and redirects.
 * Falls back to auto-detection when config/app/base_url is empty.
 */
function baseUrl(array $config): string
{
    $configured = trim((string) ($config['app']['base_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appDir  = realpath(__DIR__ . '/..');
    $path    = '';

    if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
        $path = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    }

    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . $path;
}

/**
 * URL for a static asset (under /assets), cache-busted with the file's
 * mtime so an edited style.css/app.js is picked up immediately instead of
 * being served stale from the browser's cache on returning visits.
 */
function assetUrl(string $relativePath): string
{
    $relativePath = '/' . ltrim($relativePath, '/');
    $diskPath = __DIR__ . '/..' . $relativePath;
    $version = is_file($diskPath) ? filemtime($diskPath) : time();

    return BASE_URL . $relativePath . '?v=' . $version;
}

/**
 * HTML-escape a value for safe output.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect and stop execution.
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * CSRF protection.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Flash messages. usage: flash('success', 'Saved'); then <?= renderFlash() ?>
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function renderFlash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $msg) {
        $html .= '<div class="alert alert-' . e($msg['type']) . '">' . e($msg['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/**
 * Encryption helpers (AES-256-GCM).
 */
function encrypt_password(string $plain, array $config): string
{
    $key = hex2bin($config['crypto']['master_key']);
    if ($key === false) {
        throw new RuntimeException('Invalid master key in config. It must be a 64 char hex string.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, $config['crypto']['cipher'], $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_password(string $payload, array $config): ?string
{
    $key = hex2bin($config['crypto']['master_key']);
    if ($key === false) {
        return null;
    }
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 12 + 16) {
        return null;
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $data = substr($raw, 12 + 16);
    $plain = openssl_decrypt($data, $config['crypto']['cipher'], $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/**
 * Password policy. Returns list of violated rules (empty = ok).
 */
function validatePasswordPolicy(string $password, array $policy): array
{
    $errors = [];
    if (strlen($password) < $policy['min_length']) {
        $errors[] = 'Password must be at least ' . $policy['min_length'] . ' characters long.';
    }
    if (!empty($policy['require_upper']) && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain an uppercase letter.';
    }
    if (!empty($policy['require_lower']) && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain a lowercase letter.';
    }
    if (!empty($policy['require_digit']) && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain a digit.';
    }
    if (!empty($policy['require_special']) && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain a special character.';
    }
    return $errors;
}

/**
 * Generate a strong random password.
 */
function generatePassword(int $length = 18): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $special = '!@#$%^&*()-_=+[]{}';

    $all = $upper . $lower . $digits . $special;
    $password = '';
    $charsets = [$upper, $lower, $digits, $special];

    foreach ($charsets as $set) {
        $password .= $set[random_int(0, strlen($set) - 1)];
    }
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($password);
}

/**
 * Get a setting value from the settings table.
 */
function setting(string $key, ?string $default = null): ?string
{
    global $db;
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $rows = $db->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Active password policy: DB settings override config defaults.
 */
function effectivePolicy(): array
{
    global $appConfig;

    $policy = $appConfig['policy'];
    $policy['min_length'] = (int) setting('policy_min_length', (string) $policy['min_length']);
    $policy['require_upper']   = (int) setting('policy_require_upper',   $policy['require_upper']   ? '1' : '0') === 1;
    $policy['require_lower']   = (int) setting('policy_require_lower',   $policy['require_lower']   ? '1' : '0') === 1;
    $policy['require_digit']   = (int) setting('policy_require_digit',   $policy['require_digit']   ? '1' : '0') === 1;
    $policy['require_special'] = (int) setting('policy_require_special', $policy['require_special'] ? '1' : '0') === 1;

    return $policy;
}

function appName(): string
{
    return setting('app_name') ?: 'Digital Locker';
}

/**
 * Consistent page title suffix.
 */
function pageTitle(string $title): string
{
    return $title . ' | ' . appName();
}

/**
 * Credential Vault: fixed category list offered in the create/edit forms.
 */
function categoryOptions(): array
{
    return ['Server', 'Router/Network', 'Application', 'Database', 'Email Account', 'Domain/DNS', 'Software License', 'Other'];
}

/**
 * Credential Vault: icon shown next to the system name, keyed by category.
 */
function categoryIcon(string $category): string
{
    $icons = [
        'Server'            => '🖥️',
        'Router/Network'    => '📡',
        'Application'       => '🧩',
        'Database'          => '🗄️',
        'Email Account'     => '📧',
        'Domain/DNS'        => '🌐',
        'Software License'  => '📄',
        'Other'             => '🔐',
    ];
    return $icons[$category] ?? '🔐';
}

/**
 * Credential Vault: friendlier label for a role's access level.
 * Falls back to the role's own name for custom roles.
 */
function accessLabel(?string $roleName): string
{
    $labels = [
        'Administrator' => 'Admin Only',
        'Manager'       => 'Management+',
        'Viewer'        => 'All Staff',
    ];
    return $labels[$roleName] ?? ($roleName ?: '—');
}

/**
 * Personal Vault: fixed category list offered in the create/edit forms.
 */
function personalCategoryOptions(): array
{
    return ['Social', 'Bank', 'Email', 'Shopping', 'Work', 'Other'];
}

/**
 * Personal Vault: icon shown next to the entry title, keyed by category.
 */
function personalCategoryIcon(string $category): string
{
    $icons = [
        'Social'   => '💬',
        'Bank'     => '🏦',
        'Email'    => '📧',
        'Shopping' => '🛒',
        'Work'     => '💼',
        'Other'    => '🔒',
    ];
    return $icons[$category] ?? '🔒';
}

/**
 * Whether the current user may view/reveal/edit/delete a specific credential,
 * based on its Access Level (assigned roles). Administrators always pass.
 * A credential with no roles assigned is unrestricted (open to anyone with
 * the base passwords.view/passwords.manage permission).
 */
function canAccessCredential(int $passwordId): bool
{
    global $db;

    if (isAdministrator()) {
        return true;
    }

    static $cache = [];
    if (array_key_exists($passwordId, $cache)) {
        return $cache[$passwordId];
    }

    $stmt = $db->prepare('SELECT role_id FROM password_roles WHERE password_id = :id');
    $stmt->execute(['id' => $passwordId]);
    $allowedRoleIds = array_map('intval', array_column($stmt->fetchAll(), 'role_id'));

    $result = !$allowedRoleIds || array_intersect($allowedRoleIds, currentUserRoleIds());
    $cache[$passwordId] = (bool) $result;
    return $cache[$passwordId];
}

/**
 * Record a vault action (reveal/create/update/delete) to the audit log.
 * Never throws: auditing must not block the primary action if it fails.
 */
function logAudit(string $action, ?int $passwordId, string $passwordTitle): void
{
    global $db;
    try {
        $stmt = $db->prepare(
            'INSERT INTO audit_log (user_id, action, password_id, password_title, ip_address)
             VALUES (:user_id, :action, :password_id, :password_title, :ip)'
        );
        $stmt->execute([
            'user_id'        => isLoggedIn() ? currentUser()['id'] : null,
            'action'         => $action,
            'password_id'    => $passwordId,
            'password_title' => $passwordTitle,
            'ip'             => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $e) {
        // Audit table may not exist yet if migrate.php hasn't run; don't break the request.
    }
}
