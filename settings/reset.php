<?php
/**
 * Factory Reset: three independent, irreversible wipes -- Administrator only,
 * regardless of what role_permissions happens to grant anyone else (this is
 * far more sensitive than the settings.manage permission this section
 * otherwise runs on). Each action requires typing an exact confirmation
 * phrase, checked server-side, before anything is deleted.
 *
 * - Reset All Data: every credential, project, task, discussion post/image,
 *   and personal note/password -- back to a blank workspace. User accounts,
 *   roles, and the category/type catalogs are untouched.
 * - Reset Settings: password policy + app name, and the Project Categories,
 *   Project Types, and Credential Types catalogs -- back to their original
 *   defaults. Anything already using a since-removed category/type keeps
 *   that text value (same as deleting one manually on the Categories page).
 * - Reset Manager and Tester: deletes every user whose only roles are
 *   Manager and/or Tester (anyone who also holds Administrator is never
 *   touched). Their project memberships, credential assignments, and
 *   personal vault/notes go with them via cascading foreign keys.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

if (!isAdministrator()) {
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Factory Reset';
$activePage = 'reset';

/** Deletes every file under $dir (recursively), keeping the directory itself and any .htaccess. */
function clearDirectoryContents(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->getFilename() === '.htaccess') {
            continue;
        }
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Security token mismatch. Please try again.');
        redirect(BASE_URL . '/settings/reset.php');
    }

    $do = $_POST['do'] ?? '';
    $confirm = trim($_POST['confirm'] ?? '');

    if ($do === 'reset_data') {
        if ($confirm !== 'RESET DATA') {
            flash('error', 'Type RESET DATA exactly to confirm. Nothing was deleted.');
            redirect(BASE_URL . '/settings/reset.php');
        }

        $counts = [
            'credentials' => (int) $db->query('SELECT COUNT(*) FROM passwords')->fetchColumn(),
            'projects'    => (int) $db->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
            'comments'    => (int) $db->query('SELECT COUNT(*) FROM project_comments')->fetchColumn(),
            'notes'       => (int) $db->query('SELECT COUNT(*) FROM personal_notes')->fetchColumn(),
        ];

        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'password_assignees', 'password_roles', 'passwords',
            'project_comments', 'project_tasks', 'project_members', 'projects',
            'personal_notes', 'personal_passwords', 'audit_log',
        ] as $table) {
            $db->exec("TRUNCATE TABLE $table");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        clearDirectoryContents(__DIR__ . '/../assets/uploads/discussion');

        logAudit('factory_reset_data', null, 'All data reset by administrator');
        flash('success', sprintf(
            'All data reset: %d credentials, %d projects, %d discussion posts, and %d notes removed.',
            $counts['credentials'], $counts['projects'], $counts['comments'], $counts['notes']
        ));
        redirect(BASE_URL . '/settings/reset.php');
    }

    if ($do === 'reset_settings') {
        if ($confirm !== 'RESET SETTINGS') {
            flash('error', 'Type RESET SETTINGS exactly to confirm. Nothing was changed.');
            redirect(BASE_URL . '/settings/reset.php');
        }

        $db->exec('TRUNCATE TABLE settings');
        $db->exec('TRUNCATE TABLE credential_types');

        $db->exec('TRUNCATE TABLE project_categories');
        $seedCat = $db->prepare('INSERT INTO project_categories (name, icon) VALUES (:name, :icon)');
        foreach ([
            ['Work', '💼'], ['Freelancing', '🧑‍💻'], ['Client', '🤝'], ['Demo', '🧪'],
            ['Personal', '🏠'], ['Learning', '📚'], ['Other', '📁'],
        ] as [$name, $icon]) {
            $seedCat->execute(['name' => $name, 'icon' => $icon]);
        }

        $db->exec('TRUNCATE TABLE project_types');
        $seedType = $db->prepare('INSERT INTO project_types (name, icon) VALUES (:name, :icon)');
        foreach ([
            ['Web App', '🌐'], ['Mobile App', '📱'], ['Desktop App', '🖥️'],
            ['API/Service', '🔌'], ['Other', '🏷️'],
        ] as [$name, $icon]) {
            $seedType->execute(['name' => $name, 'icon' => $icon]);
        }

        clearDirectoryContents(__DIR__ . '/../assets/uploads/project_categories');
        clearDirectoryContents(__DIR__ . '/../assets/uploads/project_types');

        logAudit('factory_reset_settings', null, 'Settings and catalogs reset by administrator');
        flash('success', 'Settings, and the Project Categories/Types/Credential Types catalogs, reset to defaults.');
        redirect(BASE_URL . '/settings/reset.php');
    }

    if ($do === 'reset_users') {
        if ($confirm !== 'RESET USERS') {
            flash('error', 'Type RESET USERS exactly to confirm. No accounts were deleted.');
            redirect(BASE_URL . '/settings/reset.php');
        }

        $candidates = $db->query(
            "SELECT DISTINCT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.name IN ('Manager', 'Tester')
                AND u.id NOT IN (
                    SELECT ur2.user_id FROM user_roles ur2
                     JOIN roles r2 ON r2.id = ur2.role_id
                     WHERE r2.name = 'Administrator'
                )"
        )->fetchAll(PDO::FETCH_COLUMN);

        $deleteStmt = $db->prepare('DELETE FROM users WHERE id = :id');
        foreach ($candidates as $uid) {
            $deleteStmt->execute(['id' => (int) $uid]);
        }

        logAudit('factory_reset_users', null, 'Manager/Tester accounts reset by administrator');
        flash('success', sprintf('%d Manager/Tester account(s) removed. Administrator accounts were left untouched.', count($candidates)));
        redirect(BASE_URL . '/settings/reset.php');
    }

    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/settings/reset.php');
}

$counts = [
    'credentials' => (int) $db->query('SELECT COUNT(*) FROM passwords')->fetchColumn(),
    'projects'    => (int) $db->query('SELECT COUNT(*) FROM projects')->fetchColumn(),
    'comments'    => (int) $db->query('SELECT COUNT(*) FROM project_comments')->fetchColumn(),
    'notes'       => (int) $db->query('SELECT COUNT(*) FROM personal_notes')->fetchColumn(),
    'managers_testers' => (int) $db->query(
        "SELECT COUNT(DISTINCT u.id) FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id
          WHERE r.name IN ('Manager', 'Tester')
            AND u.id NOT IN (
                SELECT ur2.user_id FROM user_roles ur2
                 JOIN roles r2 ON r2.id = ur2.role_id
                 WHERE r2.name = 'Administrator'
            )"
    )->fetchColumn(),
];

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">⚠️ Factory Reset</h2>
    </div>
    <p class="muted">Each action below is <strong>permanent and cannot be undone</strong>. Type the exact confirmation phrase to enable its button.</p>

    <div class="reset-block">
        <h3 class="perm-group-title">Reset all data</h3>
        <p class="muted">Deletes every credential, project, task, discussion post/image, and personal note/password. Currently: <?= $counts['credentials'] ?> credentials, <?= $counts['projects'] ?> projects, <?= $counts['comments'] ?> discussion posts, <?= $counts['notes'] ?> notes. User accounts, roles, and catalogs are kept.</p>
        <form method="post" action="reset.php" class="reset-form" data-confirm="RESET DATA">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="reset_data">
            <input type="text" name="confirm" placeholder="Type RESET DATA to confirm" autocomplete="off">
            <button type="submit" class="btn btn--danger" disabled>Reset All Data</button>
        </form>
    </div>

    <div class="reset-block">
        <h3 class="perm-group-title">Reset settings</h3>
        <p class="muted">Restores the app name, password policy, auto-lock, and reveal-confirmation settings to their defaults, and resets the Project Categories, Project Types, and Credential Types catalogs back to their originals.</p>
        <form method="post" action="reset.php" class="reset-form" data-confirm="RESET SETTINGS">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="reset_settings">
            <input type="text" name="confirm" placeholder="Type RESET SETTINGS to confirm" autocomplete="off">
            <button type="submit" class="btn btn--danger" disabled>Reset Settings</button>
        </form>
    </div>

    <div class="reset-block">
        <h3 class="perm-group-title">Reset Manager and Tester accounts</h3>
        <p class="muted">Deletes every user account whose only roles are Manager and/or Tester (currently <?= $counts['managers_testers'] ?> account(s)). Administrator accounts are never touched.</p>
        <form method="post" action="reset.php" class="reset-form" data-confirm="RESET USERS">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="reset_users">
            <input type="text" name="confirm" placeholder="Type RESET USERS to confirm" autocomplete="off">
            <button type="submit" class="btn btn--danger" disabled>Reset Manager &amp; Tester Accounts</button>
        </form>
    </div>
</div>

<script>
    (function () {
        document.querySelectorAll('.reset-form').forEach(function (form) {
            var phrase = form.getAttribute('data-confirm');
            var input = form.querySelector('input[name="confirm"]');
            var button = form.querySelector('button[type="submit"]');
            input.addEventListener('input', function () {
                button.disabled = input.value !== phrase;
            });
            form.addEventListener('submit', function (e) {
                if (input.value !== phrase || !confirm('This cannot be undone. Continue?')) {
                    e.preventDefault();
                }
            });
        });
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
