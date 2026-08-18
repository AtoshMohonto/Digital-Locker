<?php
/**
 * One-time / repeatable schema migration.
 * Adds extra contact & login columns to the passwords table.
 * Safe to run multiple times (idempotent).
 *
 * Run from CLI:  php migrate.php
 * Or open in browser:  <base>/migrate.php  (logged in as an administrator)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

if (PHP_SAPI !== 'cli') {
    requirePermission('settings.manage');
}

$pdo = $db;
$applied = [];
$skipped = [];

$columns = [
    ['name' => 'email',       'definition' => "ADD COLUMN email VARCHAR(120) NOT NULL DEFAULT '' AFTER username"],
    ['name' => 'phone',       'definition' => "ADD COLUMN phone VARCHAR(40)  NOT NULL DEFAULT '' AFTER email"],
    ['name' => 'extra_info',  'definition' => "ADD COLUMN extra_info TEXT NULL AFTER notes"],
    ['name' => 'category',    'definition' => "ADD COLUMN category VARCHAR(60) NOT NULL DEFAULT '' AFTER title"],
    ['name' => 'assigned_to', 'definition' => "ADD COLUMN assigned_to INT UNSIGNED NULL AFTER role_id"],
];

$existing = [];
foreach ($pdo->query('SHOW COLUMNS FROM passwords')->fetchAll() as $col) {
    $existing[] = $col['Field'];
}

foreach ($columns as $col) {
    if (in_array($col['name'], $existing, true)) {
        $skipped[] = $col['name'];
        continue;
    }
    $pdo->exec('ALTER TABLE passwords ' . $col['definition']);
    $applied[] = $col['name'];
}

// Foreign key for assigned_to (added separately: only valid once the column exists).
$existingConstraints = [];
foreach ($pdo->query(
    "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'passwords' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
)->fetchAll() as $row) {
    $existingConstraints[] = $row['CONSTRAINT_NAME'];
}
if (!in_array('fk_p_assigned', $existingConstraints, true)) {
    $pdo->exec(
        'ALTER TABLE passwords
           ADD CONSTRAINT fk_p_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL'
    );
    $applied[] = 'fk_p_assigned';
} else {
    $skipped[] = 'fk_p_assigned';
}

// Audit log: records credential reveals and CRUD actions on vault entries.
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('audit_log', $tables, true)) {
    $pdo->exec(
        'CREATE TABLE audit_log (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id        INT UNSIGNED NULL,
            action         VARCHAR(30) NOT NULL,
            password_id    INT UNSIGNED NULL,
            password_title VARCHAR(150) NOT NULL DEFAULT \'\',
            ip_address     VARCHAR(45) NOT NULL DEFAULT \'\',
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_al_password FOREIGN KEY (password_id) REFERENCES passwords(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );
    $applied[] = 'audit_log (table)';
} else {
    $skipped[] = 'audit_log (table)';
}

// Access level becomes many-to-many: a credential can be visible to several roles at once.
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('password_roles', $tables, true)) {
    $pdo->exec(
        'CREATE TABLE password_roles (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            password_id INT UNSIGNED NOT NULL,
            role_id     INT UNSIGNED NOT NULL,
            UNIQUE KEY uniq_password_role (password_id, role_id),
            CONSTRAINT fk_pr_password FOREIGN KEY (password_id) REFERENCES passwords(id) ON DELETE CASCADE,
            CONSTRAINT fk_pr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
    $applied[] = 'password_roles (table)';
} else {
    $skipped[] = 'password_roles (table)';
}

// One-time backfill: carry each password's old single role_id into password_roles.
$existing = [];
foreach ($pdo->query('SHOW COLUMNS FROM passwords')->fetchAll() as $col) {
    $existing[] = $col['Field'];
}
if (in_array('role_id', $existing, true)) {
    $pdo->exec(
        'INSERT IGNORE INTO password_roles (password_id, role_id)
         SELECT id, role_id FROM passwords WHERE role_id IS NOT NULL'
    );
    $applied[] = 'password_roles (backfilled from role_id)';

    // Drop the now-superseded single-role column and its FK.
    $existingConstraints = [];
    foreach ($pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'passwords' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchAll() as $row) {
        $existingConstraints[] = $row['CONSTRAINT_NAME'];
    }
    if (in_array('fk_p_role', $existingConstraints, true)) {
        $pdo->exec('ALTER TABLE passwords DROP FOREIGN KEY fk_p_role');
    }
    $pdo->exec('ALTER TABLE passwords DROP COLUMN role_id');
    $applied[] = 'passwords.role_id (dropped)';
} else {
    $skipped[] = 'passwords.role_id (already removed)';
}

// Personal Vault: strictly private per-user credentials (social, bank, etc.).
// Never joined against roles/assigned_to -- access is enforced purely by user_id.
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('personal_passwords', $tables, true)) {
    $pdo->exec(
        'CREATE TABLE personal_passwords (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            title       VARCHAR(150) NOT NULL,
            category    VARCHAR(60)  NOT NULL DEFAULT \'\',
            username    VARCHAR(120) NOT NULL DEFAULT \'\',
            encrypted   TEXT         NOT NULL,
            url         VARCHAR(255) NOT NULL DEFAULT \'\',
            notes       TEXT,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
    $applied[] = 'personal_passwords (table)';
} else {
    $skipped[] = 'personal_passwords (table)';
}

if (PHP_SAPI === 'cli') {
    echo 'Applied : ' . implode(', ', $applied ?: ['(none)']) . PHP_EOL;
    echo 'Skipped : ' . implode(', ', $skipped ?: ['(none)']) . PHP_EOL;
} else {
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Migration</title>'
          . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#0f172a}'
          . 'li{line-height:1.8}</style></head><body><h2>Migration result</h2>'
          . '<p>Applied columns: <strong>' . e(implode(', ', $applied ?: ['(none)'])) . '</strong></p>'
          . '<p>Skipped (already present): <strong>' . e(implode(', ', $skipped ?: ['(none)'])) . '</strong></p>'
          . '<p><a href="' . BASE_URL . '/dashboard.php">Back to dashboard</a></p></body></html>';
    echo $html;
}
