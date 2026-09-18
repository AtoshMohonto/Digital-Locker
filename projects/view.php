<?php
/**
 * Project detail: shows every credential linked to this project as a card grid,
 * each respecting the same per-role Access Level restriction as the Vault list.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $id]);
$project = $stmt->fetch();

if (!$project) {
    flash('error', 'Project not found.');
    redirect(BASE_URL . '/projects/index.php');
}

$pageTitle = $project['name'];
$activePage = 'projects';

$canViewVault = hasPermission('passwords.view');
$credentials = [];

if ($canViewVault) {
    $stmt = $db->prepare(
        'SELECT p.id, p.title, p.category, p.username, p.url, p.updated_at,
                u.username AS assigned_username, u.full_name AS assigned_full_name,
                GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR "||") AS role_names
           FROM passwords p
           LEFT JOIN users u ON u.id = p.assigned_to
           LEFT JOIN password_roles pr ON pr.password_id = p.id
           LEFT JOIN roles r ON r.id = pr.role_id
          WHERE p.project_id = :pid
          GROUP BY p.id
          ORDER BY p.title ASC'
    );
    $stmt->execute(['pid' => $id]);
    $credentials = $stmt->fetchAll();
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">📁 <?= e($project['name']) ?></h2>
            <?php if ($project['description']): ?>
                <p class="muted" style="margin:4px 0 0"><?= e($project['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="quick-actions quick-actions--row">
            <a class="btn btn--small" href="index.php">&larr; Projects</a>
            <a class="btn btn--small" href="edit.php?id=<?= (int) $project['id'] ?>">Edit Project</a>
            <?php if ($canViewVault && hasPermission('passwords.manage')): ?>
                <a class="btn btn--primary" href="<?= BASE_URL ?>/passwords/create.php">+ New Credential</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$canViewVault): ?>
        <p class="muted">You don't have permission to view the Credential Vault.</p>
    <?php elseif (!$credentials): ?>
        <p class="muted">No credentials are linked to this project yet.</p>
    <?php else: ?>
        <div class="credential-grid">
            <?php foreach ($credentials as $row): ?>
                <?php $canAccess = canAccessCredential((int) $row['id']); ?>
                <div class="credential-card<?= $canAccess ? '' : ' credential-card--restricted' ?>">
                    <div class="credential-card__header">
                        <span class="credential-card__icon"><?= categoryIcon((string) $row['category']) ?></span>
                        <a class="credential-card__title" href="<?= BASE_URL ?>/passwords/view.php?id=<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a>
                    </div>

                    <div class="credential-card__meta">
                        <?php if ($row['category']): ?><span class="badge badge--role"><?= e($row['category']) ?></span><?php endif; ?>
                        <?php if ($row['role_names']): ?>
                            <?php foreach (explode('||', $row['role_names']) as $roleName): ?>
                                <span class="badge <?= $roleName === 'Administrator' ? 'badge--danger' : 'badge--role' ?>"><?= e(accessLabel($roleName)) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <dl class="credential-card__fields">
                        <dt>Username</dt>
                        <dd><?= e($row['username'] ?: '—') ?></dd>

                        <dt>Password</dt>
                        <dd>
                            <?php if ($canAccess): ?>
                                <span class="secret secret--row" id="secret-row-<?= (int) $row['id'] ?>">••••••••</span>
                                <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="<?= BASE_URL ?>/passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Reveal">👁</button>
                            <?php else: ?>
                                <span class="muted">🔒 Restricted</span>
                            <?php endif; ?>
                        </dd>

                        <dt>Assigned To</dt>
                        <dd><?= e($row['assigned_full_name'] ?: $row['assigned_username'] ?: 'Unassigned') ?></dd>
                    </dl>

                    <?php if ($canAccess && hasPermission('passwords.manage')): ?>
                        <div class="credential-card__actions">
                            <a class="btn btn--small btn--ghost" href="<?= BASE_URL ?>/passwords/edit.php?id=<?= (int) $row['id'] ?>">Edit</a>
                            <form class="inline-form" method="post" action="<?= BASE_URL ?>/passwords/delete.php"
                                  onsubmit="return confirm('Delete this credential?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn--small btn--ghost">Delete</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
