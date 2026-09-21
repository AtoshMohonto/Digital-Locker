<?php
/**
 * My Tasks: cross-project task list. A Tester sees only what's assigned to
 * them; a Manager/Administrator (tasks.manage) sees every task, for oversight.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('tasks.view');

$pageTitle = 'My Tasks';
$activePage = 'tasks';

$canManageTasks = hasPermission('tasks.manage');
$myUserId = (int) currentUser()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Security token mismatch. Please try again.');
        redirect(BASE_URL . '/tasks/index.php');
    }

    $taskId = (int) ($_POST['task_id'] ?? 0);
    $status = in_array($_POST['status'] ?? '', array_keys(taskStatusOptions()), true) ? $_POST['status'] : 'open';

    $ownStmt = $db->prepare('SELECT assigned_to FROM project_tasks WHERE id = :id');
    $ownStmt->execute(['id' => $taskId]);
    $assignedTo = $ownStmt->fetchColumn();

    if ($canManageTasks || ((int) $assignedTo === $myUserId)) {
        $db->prepare('UPDATE project_tasks SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $taskId]);
        flash('success', 'Task status updated.');
    }
    redirect(BASE_URL . '/tasks/index.php');
}

$sql = 'SELECT t.*, p.name AS project_name, p.id AS project_id,
               u.username AS assignee_username, u.full_name AS assignee_full_name
          FROM project_tasks t
          JOIN projects p ON p.id = t.project_id
          LEFT JOIN users u ON u.id = t.assigned_to';
$params = [];
if (!$canManageTasks) {
    $sql .= ' WHERE t.assigned_to = :uid';
    $params['uid'] = $myUserId;
}
$sql .= ' ORDER BY FIELD(t.status, "open", "in_progress", "done"), t.due_date IS NULL, t.due_date, t.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title"><?= $canManageTasks ? 'All Tasks' : 'My Tasks' ?></h2>
    </div>
    <p class="muted" style="margin-top:-8px">
        <?= $canManageTasks
            ? 'Every task across all projects. Open a project to assign new work or manage its team.'
            : 'Work assigned to you. Update the status as you make progress -- your Manager can see it change here too.' ?>
    </p>

    <?php if (!$tasks): ?>
        <p class="muted">No tasks <?= $canManageTasks ? 'exist yet' : 'assigned to you yet' ?>.</p>
    <?php else: ?>
        <div class="task-list">
            <?php foreach ($tasks as $t): ?>
                <div class="task-list__row">
                    <div class="task-list__main">
                        <a href="<?= BASE_URL ?>/projects/view.php?id=<?= (int) $t['project_id'] ?>" class="badge badge--role">📁 <?= e($t['project_name']) ?></a>
                        <strong><?= e($t['title']) ?></strong>
                        <span class="badge <?= e(taskStatusBadgeClass($t['status'])) ?>"><?= e(taskStatusOptions()[$t['status']]) ?></span>
                        <?php if ($t['description']): ?><p class="muted" style="margin:4px 0 0"><?= nl2br(e($t['description'])) ?></p><?php endif; ?>
                        <p class="muted" style="margin:4px 0 0">
                            <?php if ($canManageTasks): ?>Assigned to <?= e($t['assignee_full_name'] ?: $t['assignee_username'] ?: 'Unassigned') ?><?php endif; ?>
                            <?php if ($t['due_date']): ?> · Due <?= e(date('M j, Y', strtotime($t['due_date']))) ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="task-list__actions">
                        <form method="post" action="index.php" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                            <select name="status" onchange="this.form.submit()">
                                <?php foreach (taskStatusOptions() as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $t['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
