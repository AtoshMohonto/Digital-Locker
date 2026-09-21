<?php
/**
 * Create a new password entry.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$pageTitle = 'New Credential';
$activePage = 'passwords';

$roles       = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$users       = $db->query('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY username')->fetchAll();
$projects    = $db->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
$categories  = categoryOptions();
$loginRoles  = existingLoginRoles();

$errors = [];
$old = [
    'title'       => '',
    'login_role'  => '',
    'category'    => '',
    'username'    => '',
    'email'       => '',
    'password'    => '',
    'url'         => '',
    'notes'       => '',
    'extra_info'  => '',
    'assigned_to' => 0,
    // Arriving from a project's own "+ New Credential" button pre-selects
    // that project, so you're not hunting for it again in the dropdown.
    'project_id'  => (int) ($_GET['project_id'] ?? 0),
];
$selectedRoles = [];
$newProjectName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $newProjectName = trim($_POST['new_project_name'] ?? '');
        $old = [
            'title'       => trim($_POST['title'] ?? ''),
            'login_role'  => trim($_POST['login_role'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'username'    => trim($_POST['username'] ?? ''),
            'email'       => trim($_POST['email'] ?? ''),
            'password'    => (string) ($_POST['password'] ?? ''),
            'url'         => trim($_POST['url'] ?? ''),
            'notes'       => trim($_POST['notes'] ?? ''),
            'extra_info'  => trim($_POST['extra_info'] ?? ''),
            'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
            'project_id'  => ($_POST['project_id'] ?? '') === '__new__' ? -1 : (int) ($_POST['project_id'] ?? 0),
        ];
        $validRoleIds = array_column($roles, 'id');
        $selectedRoles = array_intersect(array_map('intval', $_POST['roles'] ?? []), $validRoleIds);

        if ($old['title'] === '') {
            $errors[] = 'System name is required.';
        }
        if ($old['password'] === '') {
            $errors[] = 'A password value is required.';
        }
        if ($old['project_id'] === -1 && $newProjectName === '') {
            $errors[] = 'Enter a name for the new project.';
        }
        $policyErrors = validatePasswordPolicy($old['password'], effectivePolicy());
        if ($policyErrors) {
            $errors = array_merge($errors, $policyErrors);
        }

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
            // The Role field is a convenience over the "Role — System Name"
            // convention the Role column/accordion grouping already parse --
            // it just saves you typing the em dash yourself.
            $finalTitle = $old['login_role'] !== '' ? $old['login_role'] . ' — ' . $old['title'] : $old['title'];

            $stmt = $db->prepare(
                'INSERT INTO passwords (title, category, username, email, encrypted, url, notes, extra_info, assigned_to, project_id, created_by)
                 VALUES (:title, :category, :username, :email, :encrypted, :url, :notes, :extra_info, :assigned_to, :project_id, :created_by)'
            );
            $stmt->execute([
                'title'      => $finalTitle,
                'category'   => $old['category'],
                'username'   => $old['username'],
                'email'      => $old['email'],
                'encrypted'  => encrypt_password($old['password'], $appConfig),
                'url'        => $old['url'],
                'notes'      => $old['notes'] !== '' ? $old['notes'] : null,
                'extra_info' => $old['extra_info'] !== '' ? encrypt_password($old['extra_info'], $appConfig) : null,
                'assigned_to' => $old['assigned_to'] > 0 ? $old['assigned_to'] : null,
                'project_id'  => $old['project_id'] > 0 ? $old['project_id'] : null,
                'created_by' => currentUser()['id'],
            ]);
            $passwordId = (int) $db->lastInsertId();

            $roleStmt = $db->prepare('INSERT INTO password_roles (password_id, role_id) VALUES (:pid, :rid)');
            foreach (array_unique($selectedRoles) as $roleId) {
                $roleStmt->execute(['pid' => $passwordId, 'rid' => $roleId]);
            }

            $db->commit();

            logAudit('create', $passwordId, $finalTitle);
            flash('success', 'Credential created.');
            redirect(BASE_URL . '/passwords/index.php');
        }
    }
}

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
            <label for="title">System Name *</label>
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
            </div>
        </div>

        <div class="form-group">
            <label>Access Level <span class="muted">(who can see this)</span></label>
            <?php foreach ($roles as $r): ?>
                <label class="checkbox">
                    <input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>"
                           <?= in_array((int) $r['id'], $selectedRoles, true) ? 'checked' : '' ?>>
                    <span><?= e(accessLabel($r['name'])) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-group">
            <label for="url">URL / IP Address</label>
            <input type="text" id="url" name="url" value="<?= e($old['url']) ?>" placeholder="https://... or 10.0.0.15">
        </div>

        <div class="form-group">
            <label for="login_role">Role <span class="muted">(optional -- e.g. Admin, Manager, Teacher; groups this credential in the Role column and role accordion)</span></label>
            <input type="text" id="login_role" name="login_role" value="<?= e($old['login_role']) ?>" list="login-role-suggestions" placeholder="e.g. Admin">
            <datalist id="login-role-suggestions">
                <?php foreach ($loginRoles as $lr): ?>
                    <option value="<?= e($lr) ?>">
                <?php endforeach; ?>
            </datalist>
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
            <label for="assigned_to">Assigned To <span class="muted">(who's using it)</span></label>
            <select id="assigned_to" name="assigned_to">
                <option value="0">— Unassigned / Shared —</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $old['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name'] ?: $u['username']) ?></option>
                <?php endforeach; ?>
            </select>
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
        </div>
    </form>
</div>

<script>
    (function () {
        var select = document.getElementById('project_id');
        var newName = document.getElementById('new_project_name');
        if (!select || !newName) { return; }
        select.addEventListener('change', function () {
            newName.hidden = select.value !== '__new__';
            if (!newName.hidden) { newName.focus(); }
        });
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
