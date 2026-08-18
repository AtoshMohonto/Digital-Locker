<?php
/**
 * Entry point. Routes to dashboard or login.
 */
require_once __DIR__ . '/includes/init.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}
redirect(BASE_URL . '/login.php');
