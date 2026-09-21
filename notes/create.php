<?php
/**
 * Create a personal to-do item or note. Strictly private to the creator --
 * same isolation model as the Personal Vault. Optionally tagged to a project
 * (own reminder while working on it -- never shared with the project team).
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$userId = currentUser()['id'];
$projects = $db->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();

/** Server-decided redirect target -- never trusts a client-supplied URL. */
function notesRedirect(?int $projectId): void
{
    if ($projectId) {
        redirect(BASE_URL . '/projects/view.php?id=' . $projectId . '#notes');
    }
    redirect(BASE_URL . '/notes/index.php');
}

$errors = [];
$old = ['type' => 'todo', 'title' => '', 'body' => '', 'project_id' => (int) ($_GET['project_id'] ?? 0)];

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
                'INSERT INTO personal_notes (user_id, project_id, type, title, body)
                 VALUES (:uid, :pid, :type, :title, :body)'
            )->execute([
                'uid'   => $userId,
                'pid'   => $projectId,
                'type'  => $old['type'],
                'title' => $old['title'],
                'body'  => $old['body'] !== '' ? $old['body'] : null,
            ]);
            notesRedirect($projectId);
        }
    }
}

$pageTitle = 'New ' . ($old['type'] === 'note' ? 'Note' : 'To-Do');
$activePage = 'notes';

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">New To-Do / Note</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="create.php">
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
            <button type="submit" class="btn btn--primary">Save</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
