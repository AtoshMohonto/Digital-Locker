<?php
/**
 * Dashboard: overview stats and quick access.
 */
require_once __DIR__ . '/includes/init.php';
requireLogin();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

// An Administrator sees every project; a Manager/Tester is scoped to only
// the ones they've been added to, matching what Projects/My Projects shows.
if (isAdministrator()) {
    $totalProjects = (int) $db->query('SELECT COUNT(*) FROM projects')->fetchColumn();
} else {
    $stmt = $db->prepare('SELECT COUNT(*) FROM project_members WHERE user_id = :uid');
    $stmt->execute(['uid' => (int) currentUser()['id']]);
    $totalProjects = (int) $stmt->fetchColumn();
}
$totalUsers     = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalRoles     = (int) $db->query('SELECT COUNT(*) FROM roles')->fetchColumn();

$canViewVault = hasPermission('passwords.view');
$totalPasswords = $canViewVault ? (int) $db->query('SELECT COUNT(*) FROM passwords')->fetchColumn() : 0;

$recent = [];
if ($canViewVault) {
    // Over-fetch then filter by per-credential access, since a restricted
    // credential's title/username must not leak into this widget either.
    $candidates = $db->query(
        'SELECT p.id, p.title, p.category, p.username, p.email, p.url, p.updated_at, p.project_id, pr.name AS project_name
           FROM passwords p
           LEFT JOIN projects pr ON pr.id = p.project_id
          ORDER BY p.updated_at DESC
          LIMIT 20'
    )->fetchAll();
    foreach ($candidates as $row) {
        if (canAccessCredential((int) $row['id'])) {
            $recent[] = $row;
            if (count($recent) >= 6) {
                break;
            }
        }
    }
}

$projectsByCategory = [];
foreach ($db->query(
    "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS category, COUNT(*) AS cnt
       FROM projects GROUP BY category ORDER BY cnt DESC"
)->fetchAll() as $row) {
    $projectsByCategory[] = $row;
}

require __DIR__ . '/includes/header.php';
?>

<div class="grid grid--stats">
    <div class="card stat-card">
        <div class="stat-card__value"><?= $totalPasswords ?></div>
        <div class="stat-card__label">Credentials</div>
    </div>
    <div class="card stat-card">
        <div class="stat-card__value"><?= $totalProjects ?></div>
        <div class="stat-card__label"><?= isAdministrator() ? 'Projects' : 'My Projects' ?></div>
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

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Recent credentials</h2>
        <?php if ($canViewVault): ?>
            <a class="btn btn--small" href="passwords/index.php">View all</a>
        <?php endif; ?>
    </div>
    <?php if (!$canViewVault): ?>
        <p class="muted">You don't have permission to view the Credential Vault.</p>
    <?php elseif ($recent): ?>
        <div class="credential-grid">
        <?php foreach ($recent as $row): ?>
            <?php $canAccess = canAccessCredential((int) $row['id']); ?>
            <div class="credential-card<?= $canAccess ? '' : ' credential-card--restricted' ?>">
                <div class="credential-card__header">
                    <span class="credential-card__icon"><?= categoryIcon((string) $row['category']) ?></span>
                    <a class="credential-card__title" href="passwords/view.php?id=<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a>
                </div>
                <div class="credential-card__meta">
                    <?php if ($row['project_name']): ?><a class="badge badge--role" href="projects/view.php?id=<?= (int) $row['project_id'] ?>">📁 <?= e($row['project_name']) ?></a><?php endif; ?>
                    <?php if ($row['category']): ?><a class="badge badge--role" href="passwords/index.php?category=<?= urlencode($row['category']) ?>"><?= e($row['category']) ?></a><?php endif; ?>
                    <span class="badge badge--role"><?= e(date('M j', strtotime($row['updated_at']))) ?></span>
                </div>
                <dl class="credential-card__fields">
                    <dt>Username</dt>
                    <dd>
                        <?php if ($row['username']): ?>
                            <?= e($row['username']) ?>
                            <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['username']) ?>" title="Copy username">📋</button>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt>Email</dt>
                    <dd>
                        <?php if ($row['email']): ?>
                            <?= e($row['email']) ?>
                            <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['email']) ?>" title="Copy email">📋</button>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt>Password</dt>
                    <dd>
                        <?php if ($canAccess): ?>
                            <span class="secret secret--row" id="secret-row-<?= (int) $row['id'] ?>">••••••••</span>
                            <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Reveal">👁</button>
                            <button type="button" class="btn btn--small btn--ghost copy-row-btn" data-url="passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Copy password">📋</button>
                        <?php else: ?>
                            <span class="muted">🔒 Restricted</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">No credentials stored yet.</p>
    <?php endif; ?>
</div>

<div class="grid grid--two">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Projects by category</h2>
            <a class="btn btn--small" href="projects/index.php">View all</a>
        </div>
        <?php if ($projectsByCategory): ?>
            <div class="quick-actions quick-actions--row">
                <?php foreach ($projectsByCategory as $row): ?>
                    <a class="badge badge--role" style="font-size:0.85rem;padding:8px 14px"
                       href="projects/index.php?category=<?= e($row['category'] === 'Uncategorized' ? '__uncategorized__' : $row['category']) ?>">
                        <?= $row['category'] !== 'Uncategorized' ? e(projectCategoryIcon($row['category'])) . ' ' : '' ?><?= e($row['category']) ?> (<?= (int) $row['cnt'] ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No projects yet.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Quick actions</h2>
        </div>
        <div class="quick-actions">
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn btn--primary" href="passwords/create.php">+ New Credential</a>
            <?php endif; ?>
            <a class="btn" href="personal/index.php">Personal Vault</a>
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
