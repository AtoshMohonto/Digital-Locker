<?php
/**
 * Create a new password entry. Kept deliberately minimal: Access Level and
 * who's assigned are both managed from the dedicated Credential Assignments
 * page now, not here, and there's no separate Category/Type to fill in --
 * picking a Project inherits that project's own Category/Type automatically
 * (shown as read-only badges), the same classification already used
 * everywhere else for that project.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$pageTitle = 'New Credential';
$activePage = 'passwords';

// A Manager can only file a new credential under a project they're a member
// of (or a brand-new one, via "+ Create new project" below) -- not any
// project in the system.
$projects = myAccessibleProjects();

$errors = [];
$old = [
    'username'    => '',
    'email'       => '',
    'password'    => '',
    'url'         => '',
    'notes'       => '',
    'extra_info'  => '',
    // Arriving from a project's own "+ New Credential" button pre-selects
    // that project, so you're not hunting for it again in the dropdown.
    'project_id'  => (int) ($_GET['project_id'] ?? 0),
];
$newProjectName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $newProjectName = trim($_POST['new_project_name'] ?? '');
        $old = [
            'username'    => trim($_POST['username'] ?? ''),
            'email'       => trim($_POST['email'] ?? ''),
            'password'    => (string) ($_POST['password'] ?? ''),
            'url'         => trim($_POST['url'] ?? ''),
            'notes'       => trim($_POST['notes'] ?? ''),
            'extra_info'  => trim($_POST['extra_info'] ?? ''),
            'project_id'  => ($_POST['project_id'] ?? '') === '__new__' ? -1 : (int) ($_POST['project_id'] ?? 0),
        ];

        if ($old['password'] === '') {
            $errors[] = 'A password value is required.';
        }
        if ($old['project_id'] === -1 && $newProjectName === '') {
            $errors[] = 'Enter a name for the new project.';
        }
        if ($old['project_id'] > 0 && !canAccessProject($old['project_id'])) {
            $errors[] = 'You are not a member of that project.';
        }
        $policyErrors = validatePasswordPolicy($old['password'], effectivePolicy());
        if ($policyErrors) {
            $errors = array_merge($errors, $policyErrors);
        }

        $isNewProject = $old['project_id'] === -1;

        if (!$errors) {
            $db->beginTransaction();

            // "+ Create new project" was chosen instead of an existing one --
            // create it first so the credential can link straight to it.
            if ($old['project_id'] === -1) {
                try {
                    $db->prepare('INSERT INTO projects (name) VALUES (:name)')->execute(['name' => $newProjectName]);
                    $old['project_id'] = (int) $db->lastInsertId();
                    if (!isAdministrator()) {
                        $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, project_role) VALUES (:pid, :uid, :role)')
                            ->execute(['pid' => $old['project_id'], 'uid' => (int) currentUser()['id'], 'role' => 'manager']);
                    }
                } catch (PDOException $e) {
                    $db->rollBack();
                    $errors[] = 'A project named "' . $newProjectName . '" already exists. Pick it from the dropdown instead.';
                }
            }
        }

        if (!$errors) {
            // There's no free-text "System Name" field on this form -- the
            // identifying part of the title is auto-derived: username, or
            // email, or the project name, in that order.
            $projectNameForTitle = '';
            $inheritedCategory = '';
            if ($isNewProject) {
                $projectNameForTitle = $newProjectName;
            } elseif ($old['project_id'] > 0) {
                foreach ($projects as $p) {
                    if ((int) $p['id'] === $old['project_id']) {
                        $projectNameForTitle = $p['name'];
                        $inheritedCategory = (string) $p['category'];
                        break;
                    }
                }
            }
            $finalTitle = $old['username'] !== '' ? $old['username']
                : ($old['email'] !== '' ? $old['email']
                : ($projectNameForTitle !== '' ? $projectNameForTitle : 'Untitled credential'));

            $stmt = $db->prepare(
                'INSERT INTO passwords (title, category, username, email, encrypted, url, notes, extra_info, project_id, created_by)
                 VALUES (:title, :category, :username, :email, :encrypted, :url, :notes, :extra_info, :project_id, :created_by)'
            );
            $stmt->execute([
                'title'      => $finalTitle,
                'category'   => $inheritedCategory,
                'username'   => $old['username'],
                'email'      => $old['email'],
                'encrypted'  => encrypt_password($old['password'], $appConfig),
                'url'        => $old['url'],
                'notes'      => $old['notes'] !== '' ? $old['notes'] : null,
                'extra_info' => $old['extra_info'] !== '' ? encrypt_password($old['extra_info'], $appConfig) : null,
                'project_id'  => $old['project_id'] > 0 ? $old['project_id'] : null,
                'created_by' => currentUser()['id'],
            ]);
            $passwordId = (int) $db->lastInsertId();

            $db->commit();

            logAudit('create', $passwordId, $finalTitle);
            flash('success', 'Credential created. Set its Access Level and Assign Manager/Tester from Credential Assignments.');
            redirect(BASE_URL . '/passwords/index.php');
        }
    }
}

// Project id -> inherited badge info, for the JS to redraw on change without
// a round trip -- same data the PHP-rendered initial state below uses.
$projectMeta = [];
foreach ($projects as $p) {
    $projectMeta[(int) $p['id']] = [
        'category'     => (string) $p['category'],
        'type'         => (string) $p['type'],
        'categoryIcon' => $p['category'] !== '' ? projectCategoryIcon((string) $p['category']) : '',
        'typeIcon'     => $p['type'] !== '' ? projectTypeIcon((string) $p['type']) : '',
    ];
}
$selectedProjectMeta = $projectMeta[$old['project_id']] ?? null;

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">New Credential</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="create.php" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id">
                <option value="0">— None —</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $old['project_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
                <option value="__new__" <?= $old['project_id'] === -1 ? 'selected' : '' ?>>+ Create new project…</option>
            </select>
            <input type="text" id="new_project_name" name="new_project_name" value="<?= e($newProjectName) ?>"
                   placeholder="New project name" style="margin-top:8px" <?= $old['project_id'] === -1 ? '' : 'hidden' ?>>
            <div class="project-inherited-badges" id="project-inherited-badges" <?= $selectedProjectMeta && ($selectedProjectMeta['category'] || $selectedProjectMeta['type']) ? '' : 'hidden' ?>>
                <?php if ($selectedProjectMeta && $selectedProjectMeta['category']): ?>
                    <span class="badge badge--role"><?= e($selectedProjectMeta['categoryIcon']) ?> <?= e($selectedProjectMeta['category']) ?></span>
                <?php endif; ?>
                <?php if ($selectedProjectMeta && $selectedProjectMeta['type']): ?>
                    <span class="badge badge--role"><?= e($selectedProjectMeta['typeIcon']) ?> <?= e($selectedProjectMeta['type']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="url">URL / IP Address</label>
            <input type="text" id="url" name="url" value="<?= e($old['url']) ?>" placeholder="https://... or 10.0.0.15">
        </div>

        <div class="grid grid--two">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= e($old['username']) ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($old['email']) ?>">
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
            <label for="extra_info">Recovery Info <span class="muted">(security questions, PINs, recovery codes, etc.)</span></label>
            <textarea id="extra_info" name="extra_info" rows="3"><?= e($old['extra_info']) ?></textarea>
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3"><?= e($old['notes']) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save Credential</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
            <a class="btn btn--ghost" href="bulk_create.php">Add several at once…</a>
        </div>
    </form>
</div>

<script>
    (function () {
        var projectMeta = <?= json_encode($projectMeta, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

        var select = document.getElementById('project_id');
        var newName = document.getElementById('new_project_name');
        var badges = document.getElementById('project-inherited-badges');
        if (!select) { return; }

        function makeBadge(icon, text) {
            var span = document.createElement('span');
            span.className = 'badge badge--role';
            span.textContent = (icon ? icon + ' ' : '') + text;
            return span;
        }

        select.addEventListener('change', function () {
            if (newName) {
                newName.hidden = select.value !== '__new__';
                if (!newName.hidden) { newName.focus(); }
            }
            if (!badges) { return; }
            var meta = projectMeta[select.value];
            badges.textContent = '';
            var any = false;
            if (meta) {
                if (meta.category) { badges.appendChild(makeBadge(meta.categoryIcon, meta.category)); any = true; }
                if (meta.type) { badges.appendChild(makeBadge(meta.typeIcon, meta.type)); any = true; }
            }
            badges.hidden = !any;
        });
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
