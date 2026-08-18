<?php
/**
 * Auto-lock: destroys the session and returns to the login page.
 */
require_once __DIR__ . '/includes/init.php';

$_SESSION = [];
session_destroy();
redirect(BASE_URL . '/login.php');
