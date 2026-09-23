<?php
/**
 * My Notes & To-Do: strictly private per-user (same isolation model as the
 * Personal Vault -- no admin bypass), optionally filtered by project.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$pageTitle = 'My Notes & To-Do';
$activePage = 'notes';

$userId = currentUser()['id'];
$projects = myAccessibleProjects();
$filterProject = isset($_GET['project']) ? (int) $_GET['project'] : 0;

$sql = 'SELECT n.*, p.name AS project_name FROM personal_notes n LEFT JOIN projects p ON p.id = n.project_id WHERE n.user_id = :uid';
$params = ['uid' => $userId];
if ($filterProject > 0) {
    $sql .= ' AND n.project_id = :pid';
    $params['pid'] = $filterProject;
}

$todoStmt = $db->prepare($sql . " AND n.type = 'todo' ORDER BY n.is_done ASC, n.created_at DESC");
$todoStmt->execute($params);
$todos = $todoStmt->fetchAll();

$noteStmt = $db->prepare($sql . " AND n.type = 'note' ORDER BY n.updated_at DESC");
$noteStmt->execute($params);
$notes = $noteStmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">📝 My Notes &amp; To-Do</h2>
            <p class="muted" style="margin:4px 0 0">Your own private checklist and notes — nobody else can see these, including administrators.</p>
        </div>
        <div class="quick-actions quick-actions--row">
            <a class="btn btn--primary" href="create.php">+ New</a>
        </div>
    </div>

    <div class="alert alert-vault">🔒 Private to your account, same as the Personal Vault.</div>

    <form method="get" action="index.php" class="filters">
        <div class="form-group">
            <label for="project">Project</label>
            <select id="project" name="project" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $filterProject === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filters__actions">
            <a class="btn btn--ghost" href="index.php">Reset</a>
        </div>
    </form>
</div>

<div class="grid grid--two">
    <div class="card">
        <div class="card__header">
            <h2 class="card__title">To-Do</h2>
        </div>
        <?php if ($todos): ?>
            <div class="todo-list">
                <?php foreach ($todos as $t): ?>
                    <div class="todo-list__row<?= $t['is_done'] ? ' todo-list__row--done' : '' ?>">
                        <form method="post" action="toggle.php" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="todo-check" title="<?= $t['is_done'] ? 'Mark not done' : 'Mark done' ?>"><?= $t['is_done'] ? '✅' : '⬜' ?></button>
                        </form>
                        <div class="todo-list__body">
                            <span class="todo-list__title"><?= e($t['title']) ?></span>
                            <?php if ($t['project_name']): ?><span class="badge badge--role">📁 <?= e($t['project_name']) ?></span><?php endif; ?>
                            <?php if ($t['body']): ?><div class="note-body muted"><?= renderNoteBody($t['body']) ?></div><?php endif; ?>
                        </div>
                        <div class="todo-list__actions">
                            <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $t['id'] ?>" title="Edit">✏️</a>
                            <form method="post" action="delete.php" class="inline-form" onsubmit="return confirm('Delete this to-do?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Nothing on your to-do list yet.</p>
        <?php endif; ?>

        <details class="bulk-add">
            <summary>Add multiple to-dos at once</summary>
            <form method="post" action="bulk_create.php" class="bulk-add__form">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="titles">One to-do per line</label>
                    <textarea id="titles" name="titles" rows="4" placeholder="Test login flow&#10;Check password reset email&#10;Verify export button"></textarea>
                </div>
                <div class="form-group">
                    <label for="bulk_project_id">Project <span class="muted">(optional, applies to all)</span></label>
                    <select id="bulk_project_id" name="project_id">
                        <option value="0">— None —</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn--small btn--primary">Add all</button>
            </form>
        </details>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Notes</h2>
        </div>
        <?php if ($notes): ?>
            <div class="todo-list">
                <?php foreach ($notes as $n): ?>
                    <div class="todo-list__row">
                        <div class="todo-list__body">
                            <span class="todo-list__title"><?= e($n['title']) ?></span>
                            <?php if ($n['project_name']): ?><span class="badge badge--role">📁 <?= e($n['project_name']) ?></span><?php endif; ?>
                            <?php if ($n['body']): ?><div class="note-body muted"><?= renderNoteBody($n['body']) ?></div><?php endif; ?>
                        </div>
                        <div class="todo-list__actions">
                            <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $n['id'] ?>" title="Edit">✏️</a>
                            <form method="post" action="delete.php" class="inline-form" onsubmit="return confirm('Delete this note?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No notes yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
