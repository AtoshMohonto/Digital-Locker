<?php
/**
 * Personal Vault: strictly private per-user credentials (social, bank, etc.).
 * Every query here is filtered by the current user's id -- there is no
 * role-based sharing and no admin override. Available to any logged-in user.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$pageTitle = 'Personal Vault';
$activePage = 'personal';

$userId = currentUser()['id'];

$filterSearch = trim($_GET['q'] ?? '');

$sql = 'SELECT id, title, category, username, url, updated_at FROM personal_passwords WHERE user_id = :uid';
$params = ['uid' => $userId];

if ($filterSearch !== '') {
    $sql .= ' AND (title LIKE :q1 OR username LIKE :q2 OR url LIKE :q3)';
    $like = '%' . $filterSearch . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$sql .= ' ORDER BY title ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">🔒 Personal Vault</h2>
            <p class="muted" style="margin:4px 0 0">Your own private logins — social, banking, shopping. Only you can see or edit these.</p>
        </div>
        <div class="quick-actions quick-actions--row">
            <a class="btn btn--primary" href="create.php">+ New Entry</a>
        </div>
    </div>

    <div class="alert alert-vault">🔒 This vault is private to your account. Not even administrators can view these entries.</div>

    <form method="get" action="index.php" class="filters">
        <div class="form-group">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($filterSearch) ?>" placeholder="Title, username, URL">
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn">Filter</button>
            <a class="btn btn--ghost" href="index.php">Reset</a>
        </div>
    </form>

    <?php if ($entries): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Category</th>
                    <th>URL</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th class="table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $row): ?>
                <tr>
                    <td><strong><?= personalCategoryIcon((string) $row['category']) ?> <?= e($row['title']) ?></strong></td>
                    <td><?= e($row['category'] ?: '—') ?></td>
                    <td><?= e($row['url'] ?: '—') ?></td>
                    <td><?= e($row['username'] ?: '—') ?></td>
                    <td>
                        <span class="secret secret--row" id="secret-row-<?= (int) $row['id'] ?>">••••••••</span>
                        <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Reveal">👁</button>
                    </td>
                    <td class="table__actions">
                        <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $row['id'] ?>" title="Edit">✏️</a>
                        <form class="inline-form" method="post" action="delete.php"
                              onsubmit="return confirm('Delete this entry?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">No personal entries yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
