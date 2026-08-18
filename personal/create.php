<?php
/**
 * Create a Personal Vault entry. Always owned by the current user.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$pageTitle = 'New Personal Entry';
$activePage = 'personal';

$categories = personalCategoryOptions();

$errors = [];
$old = [
    'title'    => '',
    'category' => '',
    'username' => '',
    'password' => '',
    'url'      => '',
    'notes'    => '',
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
        if ($old['password'] === '') {
            $errors[] = 'A password value is required.';
        }

        if (!$errors) {
            $stmt = $db->prepare(
                'INSERT INTO personal_passwords (user_id, title, category, username, encrypted, url, notes)
                 VALUES (:user_id, :title, :category, :username, :encrypted, :url, :notes)'
            );
            $stmt->execute([
                'user_id'   => currentUser()['id'],
                'title'     => $old['title'],
                'category'  => $old['category'],
                'username'  => $old['username'],
                'encrypted' => encrypt_password($old['password'], $appConfig),
                'url'       => $old['url'],
                'notes'     => $old['notes'] !== '' ? $old['notes'] : null,
            ]);

            logAudit('personal_create', null, $old['title']);
            flash('success', 'Entry saved.');
            redirect(BASE_URL . '/personal/index.php');
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">New Personal Entry</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="create.php" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="title">Title *</label>
            <input type="text" id="title" name="title" value="<?= e($old['title']) ?>" required placeholder="e.g. Facebook, Chase Bank">
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
            <label for="password">Password *</label>
            <div class="input-row">
                <input type="text" id="password" name="password" value="<?= e($old['password']) ?>" required>
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
            <button type="submit" class="btn btn--primary">Save Entry</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
