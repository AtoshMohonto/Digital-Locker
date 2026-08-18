<?php
/**
 * Edit a Personal Vault entry. Strictly scoped to the owning user.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$userId = currentUser()['id'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM personal_passwords WHERE id = :id AND user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $userId]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Entry not found.');
    redirect(BASE_URL . '/personal/index.php');
}

$pageTitle = 'Edit: ' . $item['title'];
$activePage = 'personal';

$categories = personalCategoryOptions();

$errors = [];
$old = [
    'title'    => $item['title'],
    'category' => (string) $item['category'],
    'username' => $item['username'],
    'password' => '',
    'url'      => $item['url'],
    'notes'    => (string) $item['notes'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'title'    => trim($_POST['title'] ?? ''),
            'category' => trim($_POST['category'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => (string) ($_POST['password'] ?? ''),
            'url'      => trim($_POST['url'] ?? ''),
            'notes'    => trim($_POST['notes'] ?? ''),
        ];

        if ($old['title'] === '') {
            $errors[] = 'Title is required.';
        }

        if (!$errors) {
            $encrypted = $old['password'] !== ''
                ? encrypt_password($old['password'], $appConfig)
                : $item['encrypted'];

            $stmt = $db->prepare(
                'UPDATE personal_passwords
                    SET title = :title, category = :category, username = :username,
                        encrypted = :encrypted, url = :url, notes = :notes
                  WHERE id = :id AND user_id = :uid'
            );
            $stmt->execute([
                'title'     => $old['title'],
                'category'  => $old['category'],
                'username'  => $old['username'],
                'encrypted' => $encrypted,
                'url'       => $old['url'],
                'notes'     => $old['notes'] !== '' ? $old['notes'] : null,
                'id'        => $id,
                'uid'       => $userId,
            ]);

            logAudit('personal_update', null, $old['title']);
            flash('success', 'Entry updated.');
            redirect(BASE_URL . '/personal/index.php');
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit Personal Entry</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="title">Title *</label>
            <input type="text" id="title" name="title" value="<?= e($old['title']) ?>" required>
        </div>

        <div class="grid grid--two">
            <div class="form-group">
                <label for="category">Category</label>
                <select id="category" name="category">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="username">Username / Email</label>
                <input type="text" id="username" name="username" value="<?= e($old['username']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="password">New Password <span class="muted">(leave blank to keep current)</span></label>
            <div class="input-row">
                <input type="text" id="password" name="password" value="<?= e($old['password']) ?>">
                <button type="button" class="btn" id="generate-btn">Generate</button>
            </div>
        </div>

        <div class="form-group">
            <label for="url">URL</label>
            <input type="url" id="url" name="url" value="<?= e($old['url']) ?>">
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"><?= e($old['notes']) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save Changes</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
