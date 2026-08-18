<?php
/**
 * View a single password entry and reveal the secret on demand.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare(
    'SELECT p.*, u.username AS assigned_username, u.full_name AS assigned_full_name
       FROM passwords p
       LEFT JOIN users u ON u.id = p.assigned_to
      WHERE p.id = :id'
);
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Credential not found.');
    redirect(BASE_URL . '/passwords/index.php');
}

$roleStmt = $db->prepare(
    'SELECT r.name FROM password_roles pr JOIN roles r ON r.id = pr.role_id WHERE pr.password_id = :id ORDER BY r.name'
);
$roleStmt->execute(['id' => $id]);
$itemRoleNames = array_column($roleStmt->fetchAll(), 'name');

$canAccess = canAccessCredential($id);

$pageTitle = $item['title'];
$activePage = 'passwords';

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title"><?= categoryIcon((string) $item['category']) ?> <?= e($item['title']) ?></h2>
        <div>
            <a class="btn btn--small" href="index.php">&larr; Back</a>
            <?php if ($canAccess && hasPermission('passwords.manage')): ?>
                <a class="btn btn--small" href="edit.php?id=<?= (int) $item['id'] ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$canAccess): ?>
        <div class="alert alert-error">🔒 Your access level does not include this credential. Contact an administrator if you need access.</div>
    <?php endif; ?>

    <dl class="detail-list">
        <dt>Category</dt>
        <dd><?= e($item['category'] ?: '—') ?></dd>

        <dt>Username / Email</dt>
        <dd><?= e($item['username'] ?: '—') ?></dd>

        <dt>Password</dt>
        <dd>
            <?php if ($canAccess): ?>
                <span class="secret" id="secret-value">••••••••</span>
                <button type="button" class="btn btn--small btn--ghost" id="reveal-btn"
                        data-url="ajax.php?reveal=<?= (int) $item['id'] ?>">Reveal</button>
                <button type="button" class="btn btn--small btn--ghost" id="copy-btn" data-clip-target="secret-value">Copy</button>
            <?php else: ?>
                <span class="muted">🔒 Restricted</span>
            <?php endif; ?>
        </dd>

        <dt>URL / IP</dt>
        <dd>
            <?php if ($item['url']): ?>
                <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener"><?= e($item['url']) ?></a>
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>

        <dt>Assigned To</dt>
        <dd><?= e($item['assigned_full_name'] ?: $item['assigned_username'] ?: 'Unassigned') ?></dd>

        <dt>Access</dt>
        <dd>
            <?php if ($itemRoleNames): ?>
                <?php foreach ($itemRoleNames as $roleName): ?>
                    <span class="badge <?= $roleName === 'Administrator' ? 'badge--danger' : 'badge--role' ?>"><?= e(accessLabel($roleName)) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>

        <dt>Notes</dt>
        <dd class="detail-list__notes"><?= nl2br(e($item['notes'] ?: '—')) ?></dd>

        <dt>Recovery Info</dt>
        <dd>
            <?php if (!$canAccess): ?>
                <span class="muted">🔒 Restricted</span>
            <?php elseif ($item['extra_info']): ?>
                <span class="secret detail-list__notes" id="recovery-value">••••••••</span>
                <button type="button" class="btn btn--small btn--ghost" id="reveal-recovery-btn"
                        data-url="ajax.php?reveal=<?= (int) $item['id'] ?>">Reveal</button>
            <?php else: ?>
                —
            <?php endif; ?>
        </dd>

        <dt>Created</dt>
        <dd><?= e($item['created_at']) ?></dd>

        <dt>Last updated</dt>
        <dd><?= e($item['updated_at']) ?></dd>
    </dl>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
