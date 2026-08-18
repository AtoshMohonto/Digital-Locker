<?php
/**
 * Central bootstrap. Loaded by every page.
 */
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['app']['timezone']);

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
