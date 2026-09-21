<?php
/**
 * Credential Assignments: who's using each credential, and its Access Level --
 * moved out of the main Credential Vault / Project views to keep those focused
 * on day-to-day use. Administrator only.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

if (!isAdministrator()) {
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Credential Assignments';
$activePage = 'assignments';

$assignUsers = $db->query('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY username')->fetchAll();
$returnTo = BASE_URL . '/passwords/assignments.php';

$rows = $db->query(
    'SELECT p.id, p.title, p.assigned_to, p.project_id, pr.name AS project_name,
            u.username AS assigned_username, u.full_name AS assigned_full_name,
            GROUP_CONCAT(DISTINCT r.id, ":", r.name ORDER BY r.name SEPARATOR "||") AS role_pairs
       FROM passwords p
       LEFT JOIN projects pr ON pr.id = p.project_id
       LEFT JOIN users u ON u.id = p.assigned_to
       LEFT JOIN password_roles pr2 ON pr2.password_id = p.id
       LEFT JOIN roles r ON r.id = pr2.role_id
      GROUP BY p.id
      ORDER BY p.title ASC'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">Credential Assignments</h2>
            <p class="muted" style="margin:4px 0 0">Who's using each credential, and its Access Level -- kept separate from the main Vault view.</p>
        </div>
        <a class="btn btn--small" href="index.php">&larr; Credential Vault</a>
    </div>

    <?php if (!$rows): ?>
        <p class="muted">No credentials yet.</p>
    <?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>System</th>
                    <th>Project</th>
                    <th>Assigned To</th>
                    <th>Access</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><a href="view.php?id=<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a></strong></td>
                    <td><?= $row['project_name'] ? '<a href="' . BASE_URL . '/projects/view.php?id=' . (int) $row['project_id'] . '">' . e($row['project_name']) . '</a>' : '—' ?></td>
                    <td>
                        <form method="post" action="update_assigned.php" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                            <select name="assigned_to" onchange="this.form.submit()">
                                <option value="0">— Unassigned —</option>
                                <?php foreach ($assignUsers as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>" <?= (int) $row['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name'] ?: $u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <?php if ($row['role_pairs']): ?>
                            <?php foreach (explode('||', $row['role_pairs']) as $pair): ?>
                                <?php [, $roleName] = explode(':', $pair, 2); ?>
                                <span class="badge <?= $roleName === 'Administrator' ? 'badge--danger' : 'badge--role' ?>"><?= e(accessLabel($roleName)) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="badge badge--success">All Staff</span>
                        <?php endif; ?>
                        <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $row['id'] ?>" title="Change Access Level">✏️</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
