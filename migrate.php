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

// Projects: user-defined category (Work, Freelancing, Demo, Personal, etc.).
$projectColumns = [];
foreach ($pdo->query('SHOW COLUMNS FROM projects')->fetchAll() as $col) {
    $projectColumns[] = $col['Field'];
}
if (!in_array('category', $projectColumns, true)) {
    $pdo->exec("ALTER TABLE projects ADD COLUMN category VARCHAR(60) NOT NULL DEFAULT '' AFTER name");
    $applied[] = 'projects.category';
} else {
    $skipped[] = 'projects.category';
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

// "Viewer" becomes "Tester": this app now models a real workflow (Manager
// assigns tasks to Testers on a project), not just a read-only role name.
$viewerRole = $pdo->query("SELECT id FROM roles WHERE name = 'Viewer'")->fetchColumn();
if ($viewerRole !== false) {
    $pdo->prepare("UPDATE roles SET name = 'Tester', description = 'Assigned to specific projects to test and review; read-only on the vault.' WHERE id = :id")
        ->execute(['id' => $viewerRole]);
    $applied[] = 'roles: Viewer -> Tester';

    $viewerUser = $pdo->prepare("SELECT id FROM users WHERE username = 'viewer'");
    $viewerUser->execute();
    $viewerUserId = $viewerUser->fetchColumn();
    if ($viewerUserId !== false) {
        $pdo->prepare("UPDATE users SET username = 'tester', email = 'tester@example.com', full_name = 'QA Tester' WHERE id = :id")
            ->execute(['id' => $viewerUserId]);
        $applied[] = 'users: viewer -> tester (seed account)';
    }
} else {
    $skipped[] = 'roles: Viewer -> Tester (already renamed)';
}

// Task-workflow permissions: tasks.manage (Administrator, Manager) lets you
// assign project team members and create/edit tasks; tasks.view (everyone
// with a role) lets a Tester see and act on their own assigned work.
$roleIds = [];
foreach ($pdo->query('SELECT id, name FROM roles')->fetchAll() as $r) {
    $roleIds[$r['name']] = (int) $r['id'];
}
$grantPerm = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (:rid, :perm)');
$grants = [];
if (isset($roleIds['Administrator'])) {
    $grants[] = [$roleIds['Administrator'], 'tasks.manage'];
    $grants[] = [$roleIds['Administrator'], 'tasks.view'];
}
if (isset($roleIds['Manager'])) {
    $grants[] = [$roleIds['Manager'], 'tasks.manage'];
    $grants[] = [$roleIds['Manager'], 'tasks.view'];
}
if (isset($roleIds['Tester'])) {
    $grants[] = [$roleIds['Tester'], 'tasks.view'];
}
foreach ($grants as [$rid, $perm]) {
    $grantPerm->execute(['rid' => $rid, 'perm' => $perm]);
}
$applied[] = 'role_permissions: tasks.manage / tasks.view granted';

// Project team membership: who is assigned to a project, as manager or tester.
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('project_members', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE project_members (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id   INT UNSIGNED NOT NULL,
            user_id      INT UNSIGNED NOT NULL,
            project_role ENUM('manager','tester') NOT NULL DEFAULT 'tester',
            added_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_user (project_id, user_id),
            CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $applied[] = 'project_members (table)';
} else {
    $skipped[] = 'project_members (table)';
}

// Tasks: a Manager assigns work to a Tester on a specific project.
if (!in_array('project_tasks', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE project_tasks (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id   INT UNSIGNED NOT NULL,
            title        VARCHAR(150) NOT NULL,
            description  TEXT,
            assigned_to  INT UNSIGNED NULL,
            status       ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
            due_date     DATE NULL,
            created_by   INT UNSIGNED NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_pt_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_pt_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_pt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $applied[] = 'project_tasks (table)';
} else {
    $skipped[] = 'project_tasks (table)';
}

// Project Discussion: a lightweight, auto-refreshing review/notes feed per
// project -- author_name is snapshotted so a later-deleted user's posts
// still read sensibly instead of showing as "Unknown".
if (!in_array('project_comments', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE project_comments (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id  INT UNSIGNED NOT NULL,
            user_id     INT UNSIGNED NULL,
            author_name VARCHAR(120) NOT NULL DEFAULT '',
            body        TEXT NOT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            CONSTRAINT fk_pc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $applied[] = 'project_comments (table)';
} else {
    $skipped[] = 'project_comments (table)';
}

// Personal to-do list & notes: every user gets their own, strictly private
// (same isolation model as Personal Vault -- no admin bypass), optionally
// tagged to a project so they can jot reminders while working on one.
if (!in_array('personal_notes', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE personal_notes (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            project_id  INT UNSIGNED NULL,
            type        ENUM('todo','note') NOT NULL DEFAULT 'note',
            title       VARCHAR(200) NOT NULL,
            body        TEXT NULL,
            is_done     TINYINT(1) NOT NULL DEFAULT 0,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_pn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pn_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );
    $applied[] = 'personal_notes (table)';
} else {
    $skipped[] = 'personal_notes (table)';
}

// Project categories: used to be a fixed PHP list; now a real, admin-editable
// table so new categories can be created (and unwanted ones deleted) instead
// of being stuck with a hardcoded set.
if (!in_array('project_categories', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE project_categories (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(60) NOT NULL UNIQUE,
            icon       VARCHAR(8) NOT NULL DEFAULT '📁',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $seed = $pdo->prepare('INSERT IGNORE INTO project_categories (name, icon) VALUES (:name, :icon)');
    foreach ([
        ['Work', '💼'], ['Freelancing', '🧑‍💻'], ['Client', '🤝'], ['Demo', '🧪'],
        ['Personal', '🏠'], ['Learning', '📚'], ['Other', '📁'],
    ] as [$name, $icon]) {
        $seed->execute(['name' => $name, 'icon' => $icon]);
    }
    $applied[] = 'project_categories (table, seeded)';
} else {
    $skipped[] = 'project_categories (table)';
}

// Widen icon: was sized for a single emoji only; now also holds an uploaded
// image's relative path (e.g. "assets/uploads/categories/<name>.jpg").
$catColumns = [];
foreach ($pdo->query('SHOW COLUMNS FROM project_categories')->fetchAll() as $col) {
    $catColumns[$col['Field']] = $col['Type'];
}
if (isset($catColumns['icon']) && stripos($catColumns['icon'], 'varchar(8)') !== false) {
    $pdo->exec("ALTER TABLE project_categories MODIFY icon VARCHAR(255) NOT NULL DEFAULT '📁'");
    $applied[] = 'project_categories.icon (widened)';
} else {
    $skipped[] = 'project_categories.icon (already widened)';
}

// Project types: a second, independent classification alongside category
// (e.g. Web App, Mobile App, API/Service) -- same catalogue-table pattern as
// project_categories, picked on the same New/Edit project forms.
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('project_types', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE project_types (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(60) NOT NULL UNIQUE,
            icon       VARCHAR(255) NOT NULL DEFAULT '🏷️',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $seed = $pdo->prepare('INSERT IGNORE INTO project_types (name, icon) VALUES (:name, :icon)');
    foreach ([
        ['Web App', '🌐'], ['Mobile App', '📱'], ['Desktop App', '🖥️'],
        ['API/Service', '🔌'], ['Other', '🏷️'],
    ] as [$name, $icon]) {
        $seed->execute(['name' => $name, 'icon' => $icon]);
    }
    $applied[] = 'project_types (table, seeded)';
} else {
    $skipped[] = 'project_types (table)';
}

$projectColumns = [];
foreach ($pdo->query('SHOW COLUMNS FROM projects')->fetchAll() as $col) {
    $projectColumns[] = $col['Field'];
}
if (!in_array('type', $projectColumns, true)) {
    $pdo->exec("ALTER TABLE projects ADD COLUMN type VARCHAR(60) NOT NULL DEFAULT '' AFTER category");
    $applied[] = 'projects.type';
} else {
    $skipped[] = 'projects.type';
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
