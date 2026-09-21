<?php
/**
 * Edit a personal to-do item or note. Strictly scoped to the owning user.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$userId = currentUser()['id'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM personal_notes WHERE id = :id AND user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $userId]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Entry not found.');
    redirect(BASE_URL . '/notes/index.php');
}

$projects = $db->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();

$errors = [];
$old = [
    'type'       => $item['type'],
    'title'      => $item['title'],
    'body'       => (string) $item['body'],
    'project_id' => (int) $item['project_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'type'       => $_POST['type'] === 'note' ? 'note' : 'todo',
            'title'      => trim($_POST['title'] ?? ''),
            'body'       => trim($_POST['body'] ?? ''),
            'project_id' => (int) ($_POST['project_id'] ?? 0),
        ];
        $projectId = $old['project_id'] > 0 && in_array($old['project_id'], array_column($projects, 'id'), true)
            ? $old['project_id']
            : null;

        if ($old['title'] === '') {
            $errors[] = 'Title is required.';
        }

        if (!$errors) {
            $db->prepare(
                'UPDATE personal_notes SET type = :type, title = :title, body = :body, project_id = :pid
                  WHERE id = :id AND user_id = :uid'
            )->execute([
                'type'  => $old['type'],
                'title' => $old['title'],
                'body'  => $old['body'] !== '' ? $old['body'] : null,
                'pid'   => $projectId,
                'id'    => $id,
                'uid'   => $userId,
            ]);
            flash('success', 'Saved.');
            if ($projectId) {
                redirect(BASE_URL . '/projects/view.php?id=' . $projectId . '#notes');
            }
            redirect(BASE_URL . '/notes/index.php');
        }
    }
}

$pageTitle = 'Edit: ' . $item['title'];
$activePage = 'notes';

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit To-Do / Note</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label>Type</label>
            <div class="quick-actions quick-actions--row">
                <label class="checkbox"><input type="radio" name="type" value="todo" <?= $old['type'] === 'todo' ? 'checked' : '' ?>> <span>To-Do</span></label>
                <label class="checkbox"><input type="radio" name="type" value="note" <?= $old['type'] === 'note' ? 'checked' : '' ?>> <span>Note</span></label>
            </div>
        </div>

        <div class="form-group">
            <label for="title">Title *</label>
            <input type="text" id="title" name="title" value="<?= e($old['title']) ?>" required autofocus>
        </div>

        <div class="form-group">
            <label for="body">Details <span class="muted">(optional -- start a line with "- " or "1. " for a bulleted or numbered list)</span></label>
            <textarea id="body" name="body" rows="4"><?= e($old['body']) ?></textarea>
        </div>

        <div class="form-group">
            <label for="project_id">Project <span class="muted">(optional)</span></label>
            <select id="project_id" name="project_id">
                <option value="0">— None —</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $old['project_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save changes</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
