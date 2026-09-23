<?php
/**
 * Project detail: credentials linked to this project, plus the project's
 * workflow -- Team (Manager/Tester membership), Tasks, and a Discussion feed.
 * Reachable by anyone with projects.manage/tasks.manage, or by a Tester who
 * has specifically been added to this project's team.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $id]);
$project = $stmt->fetch();

if (!$project) {
    flash('error', 'Project not found.');
    redirect(BASE_URL . '/projects/index.php');
}

if (!canAccessProject($id)) {
    require __DIR__ . '/../403.php';
    exit;
}

$canManageProject = hasPermission('projects.manage');
$canManageTasks   = hasPermission('tasks.manage');
$myUserId         = (int) currentUser()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Security token mismatch. Please try again.');
        redirect(BASE_URL . '/projects/view.php?id=' . $id);
    }

    $do = $_POST['do'] ?? '';

    if ($do === 'add_member' && $canManageTasks) {
        $memberUserId = (int) ($_POST['user_id'] ?? 0);
        $memberRole   = in_array($_POST['project_role'] ?? '', ['manager', 'tester'], true) ? $_POST['project_role'] : 'tester';
        if ($memberUserId > 0) {
            $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, project_role) VALUES (:pid, :uid, :role)')
                ->execute(['pid' => $id, 'uid' => $memberUserId, 'role' => $memberRole]);
            flash('success', 'Team member added.');
        }
        redirect(BASE_URL . '/projects/view.php?id=' . $id);
    }

    if ($do === 'remove_member' && $canManageTasks) {
        $db->prepare('DELETE FROM project_members WHERE id = :id AND project_id = :pid')
            ->execute(['id' => (int) ($_POST['member_id'] ?? 0), 'pid' => $id]);
        flash('success', 'Team member removed.');
        redirect(BASE_URL . '/projects/view.php?id=' . $id);
    }

    if ($do === 'create_task' && $canManageTasks) {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $db->prepare(
                'INSERT INTO project_tasks (project_id, title, description, assigned_to, due_date, created_by)
                 VALUES (:pid, :title, :description, :assigned_to, :due_date, :created_by)'
            )->execute([
                'pid'         => $id,
                'title'       => $title,
                'description' => trim($_POST['description'] ?? '') ?: null,
                'assigned_to' => (int) ($_POST['assigned_to'] ?? 0) ?: null,
                'due_date'    => trim($_POST['due_date'] ?? '') ?: null,
                'created_by'  => $myUserId,
            ]);
            flash('success', 'Task created.');
        }
        redirect(BASE_URL . '/projects/view.php?id=' . $id);
    }

    if ($do === 'delete_task' && $canManageTasks) {
        $db->prepare('DELETE FROM project_tasks WHERE id = :id AND project_id = :pid')
            ->execute(['id' => (int) ($_POST['task_id'] ?? 0), 'pid' => $id]);
        flash('success', 'Task deleted.');
        redirect(BASE_URL . '/projects/view.php?id=' . $id);
    }

    if ($do === 'update_task_status') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', array_keys(taskStatusOptions()), true) ? $_POST['status'] : 'open';

        $ownStmt = $db->prepare('SELECT assigned_to FROM project_tasks WHERE id = :id AND project_id = :pid');
        $ownStmt->execute(['id' => $taskId, 'pid' => $id]);
        $assignedTo = $ownStmt->fetchColumn();

        if ($canManageTasks || ((int) $assignedTo === $myUserId)) {
            $db->prepare('UPDATE project_tasks SET status = :status WHERE id = :id AND project_id = :pid')
                ->execute(['status' => $status, 'id' => $taskId, 'pid' => $id]);
            flash('success', 'Task status updated.');
        }
        redirect(BASE_URL . '/projects/view.php?id=' . $id);
    }

    if ($do === 'post_comment') {
        $body = trim($_POST['body'] ?? '');
        $attachmentPath = null;

        if (!empty($_FILES['attachment']['name'])) {
            $uploaded = $_FILES['attachment'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if ($uploaded['error'] !== UPLOAD_ERR_OK) {
                flash('error', 'Image upload failed. Please try again.');
            } elseif ($uploaded['size'] > 6 * 1024 * 1024) {
                flash('error', 'Image must be 6MB or smaller.');
            } else {
                $mime = mime_content_type($uploaded['tmp_name']);
                if (!isset($allowed[$mime]) || @getimagesize($uploaded['tmp_name']) === false) {
                    flash('error', 'Attachment must be a JPG, PNG, GIF, or WEBP image.');
                } else {
                    $uploadDir = __DIR__ . '/../assets/uploads/discussion/' . $id;
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                    if (move_uploaded_file($uploaded['tmp_name'], $uploadDir . '/' . $filename)) {
                        $attachmentPath = 'assets/uploads/discussion/' . $id . '/' . $filename;
                    } else {
                        flash('error', 'Could not save the attached image.');
                    }
                }
            }
        }

        if ($body !== '' || $attachmentPath !== null) {
            $db->prepare('INSERT INTO project_comments (project_id, user_id, author_name, body, attachment_path) VALUES (:pid, :uid, :name, :body, :attachment)')
                ->execute([
                    'pid'        => $id,
                    'uid'        => $myUserId,
                    'name'       => currentUser()['full_name'] ?: currentUser()['username'],
                    'body'       => $body,
                    'attachment' => $attachmentPath,
                ]);
        }
        redirect(BASE_URL . '/projects/view.php?id=' . $id . '#discussion');
    }
}

$pageTitle = $project['name'];
$activePage = 'projects';

$canViewVault = hasPermission('passwords.view');
$credentials = [];
$viewMode = ($_GET['view'] ?? '') === 'table' ? 'table' : 'grid';
$gridUrl  = 'view.php?' . http_build_query(array_merge($_GET, ['view' => 'grid']));
$tableUrl = 'view.php?' . http_build_query(array_merge($_GET, ['view' => 'table']));

if ($canViewVault) {
    $stmt = $db->prepare(
        'SELECT p.id, p.title, p.category, p.username, p.email, p.updated_at,
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

$members = $db->prepare(
    'SELECT pm.id, pm.project_role, u.id AS user_id, u.username, u.full_name
       FROM project_members pm JOIN users u ON u.id = pm.user_id
      WHERE pm.project_id = :pid
      ORDER BY pm.project_role, u.full_name'
);
$members->execute(['pid' => $id]);
$members = $members->fetchAll();
$memberUserIds = array_column($members, 'user_id');
$projectManagers = array_values(array_filter($members, static fn ($m) => $m['project_role'] === 'manager'));

// Candidates for "add member": active Manager/Tester users not already on the team.
$candidates = $db->prepare(
    "SELECT DISTINCT u.id, u.full_name, u.username, r.name AS role_name
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id
      WHERE u.is_active = 1 AND r.name IN ('Manager', 'Tester')
      ORDER BY r.name, u.full_name"
);
$candidates->execute();
$candidates = array_filter($candidates->fetchAll(), static fn ($u) => !in_array((int) $u['id'], $memberUserIds, true));

$testerMembers = array_filter($members, static fn ($m) => $m['project_role'] === 'tester');

$tasks = $db->prepare(
    'SELECT t.*, u.username AS assignee_username, u.full_name AS assignee_full_name
       FROM project_tasks t
       LEFT JOIN users u ON u.id = t.assigned_to
      WHERE t.project_id = :pid
      ORDER BY FIELD(t.status, "open", "in_progress", "done"), t.due_date IS NULL, t.due_date, t.created_at DESC'
);
$tasks->execute(['pid' => $id]);
$tasks = $tasks->fetchAll();

$comments = $db->prepare('SELECT * FROM project_comments WHERE project_id = :pid ORDER BY created_at ASC');
$comments->execute(['pid' => $id]);
$comments = $comments->fetchAll();

// Strictly this user's own reminders about this project -- never shown to
// anyone else, same isolation model as the Personal Vault.
$myNotes = $db->prepare(
    "SELECT * FROM personal_notes WHERE user_id = :uid AND project_id = :pid
      ORDER BY type = 'todo' DESC, is_done ASC, created_at DESC"
);
$myNotes->execute(['uid' => $myUserId, 'pid' => $id]);
$myNotes = $myNotes->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title"><?= $project['category'] ? e(projectCategoryIcon($project['category'])) : '📁' ?> <?= e($project['name']) ?><?php if ($project['category']): ?> <span class="badge badge--role"><?= e($project['category']) ?></span><?php endif; ?><?php if ($project['type']): ?> <span class="badge badge--role"><?= e(projectTypeIcon($project['type'])) ?> <?= e($project['type']) ?></span><?php endif; ?><?php if ($project['role']): ?> <span class="badge badge--role">🎯 <?= e($project['role']) ?></span><?php endif; ?></h2>
            <?php if ($project['description']): ?>
                <p class="muted" style="margin:4px 0 0"><?= e($project['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="quick-actions quick-actions--row">
            <a class="btn btn--small" href="index.php">&larr; Projects</a>
            <?php if ($canViewVault && $credentials): ?>
                <div class="view-toggle">
                    <a class="btn btn--small<?= $viewMode === 'grid' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($gridUrl) ?>">▦ Grid</a>
                    <a class="btn btn--small<?= $viewMode === 'table' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($tableUrl) ?>">☰ Table</a>
                </div>
            <?php endif; ?>
            <?php if ($canManageTasks): ?>
                <a class="btn btn--small" href="report.php?id=<?= (int) $project['id'] ?>">📄 Project Report</a>
            <?php endif; ?>
            <?php if ($canManageProject): ?>
                <a class="btn btn--small" href="edit.php?id=<?= (int) $project['id'] ?>">Edit Project</a>
            <?php endif; ?>
            <?php if ($canViewVault && hasPermission('passwords.manage')): ?>
                <a class="btn btn--primary" href="<?= BASE_URL ?>/passwords/create.php?project_id=<?= (int) $project['id'] ?>">+ New Credential</a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    function renderProjectCredentialCard(array $row): void
    {
        $canAccess = canAccessCredential((int) $row['id']);
        ?>
        <div class="credential-card<?= $canAccess ? '' : ' credential-card--restricted' ?>">
            <div class="credential-card__header">
                <span class="credential-card__icon"><?= categoryIcon((string) $row['category']) ?></span>
                <a class="credential-card__title" href="<?= BASE_URL ?>/passwords/view.php?id=<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a>
            </div>

            <div class="credential-card__meta">
                <?php if ($row['category']): ?><a class="badge badge--role" href="<?= BASE_URL ?>/passwords/index.php?category=<?= urlencode($row['category']) ?>"><?= e($row['category']) ?></a><?php endif; ?>
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
                        <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="<?= BASE_URL ?>/passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Reveal">👁</button>
                        <button type="button" class="btn btn--small btn--ghost copy-row-btn" data-url="<?= BASE_URL ?>/passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Copy password">📋</button>
                    <?php else: ?>
                        <span class="muted">🔒 Restricted</span>
                    <?php endif; ?>
                </dd>
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
        <?php
    }

    function renderProjectCredentialRow(array $row): void
    {
        $canAccess = canAccessCredential((int) $row['id']);
        static $seen = [];
        $seen[$row['id']] = ($seen[$row['id']] ?? -1) + 1;
        $domId = 'secret-row-' . (int) $row['id'] . ($seen[$row['id']] > 0 ? '-' . $seen[$row['id']] : '');
        ?>
        <tr>
            <td><strong><a href="<?= BASE_URL ?>/passwords/view.php?id=<?= (int) $row['id'] ?>"><?= categoryIcon((string) $row['category']) ?> <?= e($row['title']) ?></a></strong></td>
            <td><?= $row['category'] ? '<a href="' . BASE_URL . '/passwords/index.php?category=' . urlencode($row['category']) . '">' . e($row['category']) . '</a>' : '—' ?></td>
            <td>
                <?php if ($row['username']): ?>
                    <?= e($row['username']) ?>
                    <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['username']) ?>" title="Copy username">📋</button>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <?php if ($row['email']): ?>
                    <?= e($row['email']) ?>
                    <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['email']) ?>" title="Copy email">📋</button>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <?php if ($canAccess): ?>
                    <span class="secret secret--row" id="<?= e($domId) ?>">••••••••</span>
                    <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="<?= BASE_URL ?>/passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="<?= e($domId) ?>" title="Reveal">👁</button>
                    <button type="button" class="btn btn--small btn--ghost copy-row-btn" data-url="<?= BASE_URL ?>/passwords/ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="<?= e($domId) ?>" title="Copy password">📋</button>
                <?php else: ?>
                    <span class="muted">🔒 Restricted</span>
                <?php endif; ?>
            </td>
            <td class="table__actions">
                <?php if ($canAccess && hasPermission('passwords.manage')): ?>
                    <a class="btn btn--small btn--ghost" href="<?= BASE_URL ?>/passwords/edit.php?id=<?= (int) $row['id'] ?>" title="Edit">✏️</a>
                    <form class="inline-form" method="post" action="<?= BASE_URL ?>/passwords/delete.php" onsubmit="return confirm('Delete this credential?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                        <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                    </form>
                <?php elseif (!$canAccess): ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    function renderProjectCredentialSet(array $rows, string $viewMode): void
    {
        if ($viewMode === 'table') {
            ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>System</th><th>Category</th><th>Username</th><th>Email</th><th>Password</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php renderProjectCredentialRow($row); ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php
        } else {
            ?>
            <div class="credential-grid">
                <?php foreach ($rows as $row): ?>
                    <?php renderProjectCredentialCard($row); ?>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }

    // Group by the role named in each title (e.g. "Admin — admin1" -> "Admin"),
    // same convention the Step-By-Step-Learning-Center demo credentials use.
    // Only worth an accordion when that actually clusters something -- a
    // typical project where every title is unique just gets the plain grid.
    $roleGroups = [];
    foreach ($credentials as $row) {
        $roleGroups[extractLoginRole($row['title'])][] = $row;
    }
    $useRoleAccordion = count($roleGroups) < count($credentials);
    ?>

    <?php if (!$canViewVault): ?>
        <p class="muted">You don't have permission to view the Credential Vault.</p>
    <?php elseif (!$credentials): ?>
        <p class="muted">No credentials are linked to this project yet.</p>
    <?php elseif ($useRoleAccordion): ?>
        <?php ksort($roleGroups, SORT_NATURAL | SORT_FLAG_CASE); ?>
        <div class="group-controls">
            <?php if (count($roleGroups) > 1): ?>
                <button type="button" class="btn btn--small btn--ghost" id="expand-all-btn">⊞ Expand All</button>
                <button type="button" class="btn btn--small btn--ghost" id="collapse-all-btn">⊟ Collapse All</button>
            <?php endif; ?>
            <?php if (hasPermission('passwords.view')): ?>
                <button type="button" class="btn btn--small btn--ghost" id="reveal-all-btn">👁 Reveal All</button>
                <button type="button" class="btn btn--small btn--ghost" id="hide-all-btn">🙈 Hide All</button>
            <?php endif; ?>
        </div>
        <?php foreach ($roleGroups as $roleName => $rows): ?>
            <details class="vault-group" open>
                <summary class="vault-group__summary">
                    <span><?= e($roleName) ?></span>
                    <span class="badge badge--role"><?= count($rows) ?></span>
                </summary>
                <?php renderProjectCredentialSet($rows, $viewMode); ?>
            </details>
        <?php endforeach; ?>
    <?php else: ?>
        <?php if (hasPermission('passwords.view')): ?>
            <div class="group-controls">
                <button type="button" class="btn btn--small btn--ghost" id="reveal-all-btn">👁 Reveal All</button>
                <button type="button" class="btn btn--small btn--ghost" id="hide-all-btn">🙈 Hide All</button>
            </div>
        <?php endif; ?>
        <?php renderProjectCredentialSet($credentials, $viewMode); ?>
    <?php endif; ?>
</div>

<?php
$managers = $projectManagers;
$testerMembersOnly = array_values(array_filter($members, static fn ($m) => $m['project_role'] === 'tester'));

function renderMemberGrid(array $group, int $projectId, bool $canManageTasks): void
{
    ?>
    <div class="member-grid">
        <?php foreach ($group as $m): ?>
            <div class="member-card">
                <span class="member-card__avatar"><?= e(initials($m['full_name'] ?: $m['username'])) ?></span>
                <span class="member-card__name"><?= e($m['full_name'] ?: $m['username']) ?></span>
                <?php if ($canManageTasks): ?>
                    <form class="inline-form" method="post" action="view.php?id=<?= $projectId ?>" onsubmit="return confirm('Remove this member from the project?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="remove_member">
                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                        <button type="submit" class="member-card__remove" title="Remove">✕</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}
?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Project Team</h2>
    </div>

    <div class="stat-strip">
        <div class="stat-chip"><strong><?= count($members) ?></strong> Team Member<?= count($members) === 1 ? '' : 's' ?></div>
        <div class="stat-chip"><strong><?= count($managers) ?></strong> Manager<?= count($managers) === 1 ? '' : 's' ?></div>
        <div class="stat-chip"><strong><?= count($testerMembersOnly) ?></strong> Tester<?= count($testerMembersOnly) === 1 ? '' : 's' ?></div>
        <div class="stat-chip">Started <strong><?= e(date('M j, Y', strtotime($project['created_at']))) ?></strong></div>
        <?php if ($project['category']): ?><div class="stat-chip"><?= e(projectCategoryIcon($project['category'])) ?> <strong><?= e($project['category']) ?></strong></div><?php endif; ?>
        <?php if ($project['type']): ?><div class="stat-chip"><?= e(projectTypeIcon($project['type'])) ?> <strong><?= e($project['type']) ?></strong></div><?php endif; ?>
    </div>

    <?php if (!$members): ?>
        <p class="muted">No team members assigned yet.</p>
    <?php else: ?>
        <?php if ($managers): ?>
            <h3 class="perm-group-title">Managers</h3>
            <?php renderMemberGrid($managers, $id, $canManageTasks); ?>
        <?php endif; ?>
        <?php if ($testerMembersOnly): ?>
            <h3 class="perm-group-title">Testers</h3>
            <?php renderMemberGrid($testerMembersOnly, $id, $canManageTasks); ?>
        <?php endif; ?>
    <?php endif; ?>

        <?php if ($canManageTasks): ?>
            <form method="post" action="view.php?id=<?= $id ?>" class="form-inline-row" style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="add_member">
                <select name="user_id" required>
                    <option value="">Add a team member…</option>
                    <?php foreach ($candidates as $u): ?>
                        <option value="<?= (int) $u['id'] ?>"><?= e($u['full_name'] ?: $u['username']) ?> (<?= e($u['role_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <select name="project_role">
                    <option value="tester">As Tester</option>
                    <option value="manager">As Manager</option>
                </select>
                <button type="submit" class="btn btn--small">Add</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__header">
            <h2 class="card__title">Tasks</h2>
        </div>
        <?php if ($tasks): ?>
            <div class="task-list">
                <?php foreach ($tasks as $t): ?>
                    <div class="task-list__row">
                        <div class="task-list__main">
                            <strong><?= e($t['title']) ?></strong>
                            <span class="badge <?= e(taskStatusBadgeClass($t['status'])) ?>"><?= e(taskStatusOptions()[$t['status']]) ?></span>
                            <?php if ($t['description']): ?><p class="muted" style="margin:4px 0 0"><?= nl2br(e($t['description'])) ?></p><?php endif; ?>
                            <p class="muted" style="margin:4px 0 0">
                                Assigned to <?= e($t['assignee_full_name'] ?: $t['assignee_username'] ?: 'Unassigned') ?>
                                <?php if ($t['due_date']): ?> · Due <?= e(date('M j, Y', strtotime($t['due_date']))) ?><?php endif; ?>
                            </p>
                        </div>
                        <div class="task-list__actions">
                            <?php if ($canManageTasks || (int) $t['assigned_to'] === $myUserId): ?>
                                <form method="post" action="view.php?id=<?= $id ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="update_task_status">
                                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                                    <select name="status" onchange="this.form.submit()">
                                        <?php foreach (taskStatusOptions() as $key => $label): ?>
                                            <option value="<?= e($key) ?>" <?= $t['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>
                            <?php if ($canManageTasks): ?>
                                <form method="post" action="view.php?id=<?= $id ?>" class="inline-form" onsubmit="return confirm('Delete this task?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="delete_task">
                                    <input type="hidden" name="task_id" value="<?= (int) $t['id'] ?>">
                                    <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No tasks yet.</p>
        <?php endif; ?>

        <?php if ($canManageTasks): ?>
            <form method="post" action="view.php?id=<?= $id ?>" style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="create_task">
                <div class="form-group">
                    <input type="text" name="title" placeholder="Task title" required>
                </div>
                <div class="form-group">
                    <textarea name="description" rows="2" placeholder="Details (optional)"></textarea>
                </div>
                <div class="grid grid--two">
                    <div class="form-group">
                        <select name="assigned_to">
                            <option value="">Assign to…</option>
                            <?php foreach ($testerMembers as $m): ?>
                                <option value="<?= (int) $m['user_id'] ?>"><?= e($m['full_name'] ?: $m['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="date" name="due_date">
                    </div>
                </div>
                <button type="submit" class="btn btn--primary btn--small">+ New Task</button>
            </form>
        <?php endif; ?>
    </div>

<div class="card" id="notes">
    <div class="card__header">
        <h2 class="card__title">📝 My Notes &amp; To-Do</h2>
        <a class="btn btn--small" href="<?= BASE_URL ?>/notes/index.php">All my notes</a>
    </div>
    <p class="muted" style="margin-top:-8px">Private to you -- not visible to anyone else on this project's team.</p>

    <?php if ($myNotes): ?>
        <div class="todo-list">
            <?php foreach ($myNotes as $n): ?>
                <div class="todo-list__row<?= $n['type'] === 'todo' && $n['is_done'] ? ' todo-list__row--done' : '' ?>">
                    <?php if ($n['type'] === 'todo'): ?>
                        <form method="post" action="<?= BASE_URL ?>/notes/toggle.php" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="todo-check" title="<?= $n['is_done'] ? 'Mark not done' : 'Mark done' ?>"><?= $n['is_done'] ? '✅' : '⬜' ?></button>
                        </form>
                    <?php else: ?>
                        <span class="todo-check" aria-hidden="true">🗒️</span>
                    <?php endif; ?>
                    <div class="todo-list__body">
                        <span class="todo-list__title"><?= e($n['title']) ?></span>
                        <?php if ($n['body']): ?><div class="note-body muted"><?= renderNoteBody($n['body']) ?></div><?php endif; ?>
                    </div>
                    <div class="todo-list__actions">
                        <a class="btn btn--small btn--ghost" href="<?= BASE_URL ?>/notes/edit.php?id=<?= (int) $n['id'] ?>" title="Edit">✏️</a>
                        <form method="post" action="<?= BASE_URL ?>/notes/delete.php" class="inline-form" onsubmit="return confirm('Delete this?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">Nothing here yet -- add a private reminder for yourself about this project.</p>
    <?php endif; ?>

    <form method="post" action="<?= BASE_URL ?>/notes/create.php" style="margin-top:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= (int) $id ?>">
        <div class="form-inline-row">
            <label class="checkbox"><input type="radio" name="type" value="todo" checked> <span>To-Do</span></label>
            <label class="checkbox"><input type="radio" name="type" value="note"> <span>Note</span></label>
            <input type="text" name="title" placeholder="Quick add…" required style="flex:2">
            <button type="submit" class="btn btn--small btn--primary">Add</button>
        </div>
    </form>

    <details class="bulk-add">
        <summary>Add multiple to-dos at once</summary>
        <form method="post" action="<?= BASE_URL ?>/notes/bulk_create.php" class="bulk-add__form">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= (int) $id ?>">
            <div class="form-group">
                <label for="notes-bulk-titles">One to-do per line</label>
                <textarea id="notes-bulk-titles" name="titles" rows="4" placeholder="Test login flow&#10;Check password reset email&#10;Verify export button"></textarea>
            </div>
            <button type="submit" class="btn btn--small btn--primary">Add all</button>
        </form>
    </details>
</div>

<div class="card" id="discussion">
    <div class="card__header">
        <h2 class="card__title">Project Discussion</h2>
    </div>
    <p class="muted" style="margin-top:-8px">
        Post updates, findings, or reviews here -- visible to everyone on this project's team.
        <?php if ($projectManagers): ?>
            <br>Mentor<?= count($projectManagers) === 1 ? '' : 's' ?>: <strong><?= e(implode(', ', array_map(static fn ($m) => $m['full_name'] ?: $m['username'], $projectManagers))) ?></strong>
        <?php endif; ?>
    </p>

    <div class="discussion-feed" id="discussion-feed"><?= renderProjectComments($comments) ?></div>

    <form method="post" action="view.php?id=<?= $id ?>#discussion" class="chat-composer" id="discussion-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="post_comment">
        <button type="button" class="btn chat-composer__attach" id="discussion-attach-btn" title="Attach image">📎</button>
        <input type="file" name="attachment" id="discussion-attachment" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
        <textarea name="body" id="discussion-body" rows="1" placeholder="Write an update or review… (Enter to send, Shift+Enter for a new line, or paste an image)"></textarea>
        <button type="submit" class="btn btn--primary chat-composer__send" title="Send">➤</button>
        <div class="chat-composer__preview" id="discussion-preview">
            <img id="discussion-preview-img" alt="">
            <span class="chat-composer__preview-name" id="discussion-preview-name"></span>
            <button type="button" class="chat-composer__preview-remove" id="discussion-preview-remove" title="Remove">✕</button>
        </div>
    </form>
</div>

<script>
    (function () {
        var feed = document.getElementById('discussion-feed');
        if (!feed) { return; }
        feed.scrollTop = feed.scrollHeight;
        setInterval(function () {
            fetch('discussion_feed.php?id=<?= $id ?>', { credentials: 'same-origin' })
                .then(function (res) { return res.ok ? res.text() : null; })
                .then(function (html) {
                    if (html === null) { return; }
                    var wasAtBottom = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 40;
                    feed.innerHTML = html;
                    if (wasAtBottom) { feed.scrollTop = feed.scrollHeight; }
                });
        }, 8000);

        var textarea = document.getElementById('discussion-body');
        var form = document.getElementById('discussion-form');
        var fileInput = document.getElementById('discussion-attachment');
        var attachBtn = document.getElementById('discussion-attach-btn');
        var preview = document.getElementById('discussion-preview');
        var previewImg = document.getElementById('discussion-preview-img');
        var previewName = document.getElementById('discussion-preview-name');
        var previewRemove = document.getElementById('discussion-preview-remove');

        var showPreview = function (file) {
            previewImg.src = URL.createObjectURL(file);
            previewName.textContent = file.name || 'Pasted image';
            preview.classList.add('is-active');
        };
        var clearPreview = function () {
            fileInput.value = '';
            previewImg.src = '';
            preview.classList.remove('is-active');
        };
        var hasAttachment = function () {
            return fileInput.files && fileInput.files.length > 0;
        };

        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', function () {
                if (fileInput.files[0]) { showPreview(fileInput.files[0]); } else { clearPreview(); }
            });
        }
        if (previewRemove) {
            previewRemove.addEventListener('click', clearPreview);
        }

        if (textarea && form) {
            var grow = function () {
                textarea.style.height = 'auto';
                textarea.style.height = Math.min(textarea.scrollHeight, 140) + 'px';
            };
            textarea.addEventListener('input', grow);
            textarea.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    if (textarea.value.trim() !== '' || hasAttachment()) { form.submit(); }
                }
            });
            textarea.addEventListener('paste', function (e) {
                var items = (e.clipboardData || window.clipboardData).items || [];
                for (var i = 0; i < items.length; i++) {
                    if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
                        var file = items[i].getAsFile();
                        var dt = new DataTransfer();
                        dt.items.add(file);
                        fileInput.files = dt.files;
                        showPreview(file);
                        e.preventDefault();
                        break;
                    }
                }
            });
            grow();
        }
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
