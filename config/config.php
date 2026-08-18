<?php
/**
 * Application configuration.
 * Adjust the encryption key BEFORE first use. Once passwords are stored,
 * changing the key will make them unrecoverable.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'digital_locker',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name'        => 'Digital Locker',
        // Leave empty to auto-detect from the current request (recommended).
        'base_url'    => '',
        'timezone'    => 'UTC',
        'session_name' => 'digital_locker_session',
        // Folder scanned by the "Import htdocs projects" tool.
        // Leave empty to auto-detect from the web root (recommended).
        'htdocs_path' => '',
    ],

    'crypto' => [
        // 32-byte key for AES-256-GCM. Generate with:
        // php -r "echo bin2hex(random_bytes(32));"
        'master_key' => '632298fd011d4eeac1e5add399ed8a4c1c66e70933326ccec3e32067b481729a',
        'cipher'     => 'aes-256-gcm',
    ],

    'policy' => [
        'min_length'    => 12,
        'require_upper' => true,
        'require_lower' => true,
        'require_digit' => true,
        'require_special' => true,
        'auto_lock_minutes' => 5,
    ],
];
