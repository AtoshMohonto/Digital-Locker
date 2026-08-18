<?php
/**
 * Roles list. Requires roles.manage.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('roles.manage');

$pageTitle = 'Roles & Permissions';
$activePage = 'roles';

$roles = $db->query(
    'SELECT r.id, r.name, r.description,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
            (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
       FROM roles r
      ORDER BY r.name'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <h2 class="card__title">Roles</h2>
        <a class="btn btn--primary" href="create.php">+ New role</a>
    </div>

    <p class="muted">
        Roles define what a user is allowed to do. Permissions are checked on every page load,
        so changes apply immediately.
    </p>

    <?php if ($roles): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Description</th>
                    <th>Permissions</th>
                    <th>Users</th>
                    <th class="table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><?= e($r['description'] ?: '—') ?></td>
                    <td><?= (int) $r['permission_count'] ?></td>
                    <td><?= (int) $r['user_count'] ?></td>
                    <td class="table__actions">
                        <a class="btn btn--small" href="edit.php?id=<?= (int) $r['id'] ?>">Edit</a>
                        <form class="inline-form" method="post" action="delete.php"
                              onsubmit="return confirm('Delete this role? Users holding it will lose its permissions.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn--small btn--danger" <?= (int) $r['id'] === 1 ? 'disabled' : '' ?>>Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">No roles yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
