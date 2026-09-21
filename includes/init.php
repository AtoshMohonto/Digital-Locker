<?php
/**
 * Central bootstrap. Loaded by every page.
 */
declare(strict_types=1);

/**
 * Never let a raw PHP warning/notice render into the page -- a credential
 * vault leaking stack traces or file paths to the browser is a real
 * information-disclosure risk, not just cosmetic. Errors still go to the
 * server's error log (where every fix in this app has been diagnosed from);
 * they're just never echoed into the response body.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['app']['timezone']);

/**
 * Named + hardened session cookie. Must happen before session_start():
 * a custom name keeps this app's session distinct from the many other
 * PHP apps sharing this htdocs tree (they'd otherwise all use PHPSESSID).
 */
session_name($config['app']['session_name']);

$isSecure = false;
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $isSecure = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $isSecure = true;
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
    $isSecure = true;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $isSecure,
]);
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$db = getDbConnection($config);
$appConfig = $config;

/**
 * Absolute application base URL used for every link and redirect.
 * Auto-detected unless a value is configured in config/config.php.
 */
define('BASE_URL', baseUrl($config));
