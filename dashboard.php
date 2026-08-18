<?php
/**
 * Dashboard: overview stats and quick access.
 */
require_once __DIR__ . '/includes/init.php';
requireLogin();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$totalPasswords = (int) $db->query('SELECT COUNT(*) FROM passwords')->fetchColumn();
$totalProjects  = (int) $db->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$totalUsers     = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalRoles     = (int) $db->query('SELECT COUNT(*) FROM roles')->fetchColumn();

$recent = $db->query(
    'SELECT p.id, p.title, p.username, p.url, p.updated_at, pr.name AS project_name
       FROM passwords p
       LEFT JOIN projects pr ON pr.id = p.project_id
      ORDER BY p.updated_at DESC
      LIMIT 5'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="grid grid--stats">
    <div class="card stat-card">
        <div class="stat-card__value"><?= $totalPasswords ?></div>
        <div class="stat-card__label">Passwords</div>
    </div>
    <div class="card stat-card">
        <div class="stat-card__value"><?= $totalProjects ?></div>
        <div class="stat-card__label">Projects</div>
    </div>
    <div class="card stat-card">
        <div class="stat-card__value"><?= $totalUsers ?></div>
        <div class="stat-card__label">Users</div>
    </div>
    <div class="card stat-card">
        <div class="stat-card__value"><?= $totalRoles ?></div>
        <div class="stat-card__label">Roles</div>
    </div>
</div>

<div class="grid grid--two">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Recent passwords</h2>
            <?php if (hasPermission('passwords.view')): ?>
                <a class="btn btn--small" href="passwords/index.php">View all</a>
            <?php endif; ?>
        </div>
        <?php if ($recent): ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Username</th>
                        <th>Project</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= e($row['title']) ?></td>
                        <td><?= e($row['username']) ?></td>
                        <td><?= e($row['project_name'] ?: '—') ?></td>
                        <td><?= e(date('Y-m-d H:i', strtotime($row['updated_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p class="muted">No passwords stored yet.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Quick actions</h2>
        </div>
        <div class="quick-actions">
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn btn--primary" href="passwords/create.php">+ New password</a>
            <?php endif; ?>
            <?php if (hasPermission('roles.manage')): ?>
                <a class="btn" href="roles/index.php">Manage roles</a>
            <?php endif; ?>
            <?php if (hasPermission('users.manage')): ?>
                <a class="btn" href="users/index.php">Manage users</a>
            <?php endif; ?>
            <?php if (hasPermission('settings.manage')): ?>
                <a class="btn" href="settings/index.php">Password settings</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
