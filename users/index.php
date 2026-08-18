<?php
/**
 * User accounts list. Requires users.manage.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('users.manage');

$pageTitle = 'User Accounts';
$activePage = 'users';

$users = $db->query(
    'SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.last_login,
            GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS role_names
       FROM users u
       LEFT JOIN user_roles ur ON ur.user_id = u.id
       LEFT JOIN roles r ON r.id = ur.role_id
      GROUP BY u.id
      ORDER BY u.username'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <h2 class="card__title">User accounts</h2>
        <a class="btn btn--primary" href="create.php">+ New user</a>
    </div>

    <?php if ($users): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Full name</th>
                    <th>Roles</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th class="table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= e($u['username']) ?></strong><br><span class="muted"><?= e($u['email']) ?></span></td>
                    <td><?= e($u['full_name'] ?: '—') ?></td>
                    <td><?= e($u['role_names'] ?: '—') ?></td>
                    <td>
                        <?php if ((int) $u['is_active'] === 1): ?>
                            <span class="badge badge--success">Active</span>
                        <?php else: ?>
                            <span class="badge badge--danger">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['last_login'] ?: '—') ?></td>
                    <td class="table__actions">
                        <a class="btn btn--small" href="edit.php?id=<?= (int) $u['id'] ?>">Edit</a>
                        <?php if ((int) $u['id'] !== (int) currentUser()['id']): ?>
                            <form class="inline-form" method="post" action="delete.php"
                                  onsubmit="return confirm('Delete this user account?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn btn--small btn--danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">No users yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
