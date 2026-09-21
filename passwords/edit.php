<?php
/**
 * Edit an existing password entry.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM passwords WHERE id = :id');
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Credential not found.');
    redirect(BASE_URL . '/passwords/index.php');
}

if (!canAccessCredential($id)) {
    logAudit('edit_denied', $id, $item['title']);
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Edit: ' . $item['title'];
$activePage = 'passwords';

$roles      = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$users      = $db->query('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY username')->fetchAll();
$projects   = $db->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();
$categories = categoryOptions();
$loginRoles = existingLoginRoles();

$roleStmt = $db->prepare('SELECT role_id FROM password_roles WHERE password_id = :id');
$roleStmt->execute(['id' => $id]);
$selectedRoles = array_map('intval', array_column($roleStmt->fetchAll(), 'role_id'));

$titleParts = splitLoginRoleTitle($item['title']);

$errors = [];
$old = [
    'title'       => $titleParts['base'],
    'login_role'  => $titleParts['role'],
    'category'    => (string) $item['category'],
    'username'    => $item['username'],
    'email'       => (string) $item['email'],
    'password'    => '',
    'url'         => $item['url'],
    'notes'       => (string) $item['notes'],
    'extra_info'  => '',
    'assigned_to' => (int) $item['assigned_to'],
    'project_id'  => (int) $item['project_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
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
            'project_id'  => (int) ($_POST['project_id'] ?? 0),
        ];
        $validRoleIds = array_column($roles, 'id');
        $selectedRoles = array_intersect(array_map('intval', $_POST['roles'] ?? []), $validRoleIds);

        if ($old['title'] === '') {
            $errors[] = 'System name is required.';
        }

        if ($old['password'] !== '' && validatePasswordPolicy($old['password'], effectivePolicy())) {
            $errors = array_merge($errors, validatePasswordPolicy($old['password'], effectivePolicy()));
        }

        if (!$errors) {
            $encrypted = $old['password'] !== ''
                ? encrypt_password($old['password'], $appConfig)
                : $item['encrypted'];

            $extraInfo = $old['extra_info'] !== ''
                ? encrypt_password($old['extra_info'], $appConfig)
                : $item['extra_info'];

            $db->beginTransaction();

            $finalTitle = $old['login_role'] !== '' ? $old['login_role'] . ' — ' . $old['title'] : $old['title'];

            $stmt = $db->prepare(
                'UPDATE passwords
                    SET title = :title, category = :category, username = :username, email = :email,
                        encrypted = :encrypted, url = :url, notes = :notes, extra_info = :extra_info,
                        assigned_to = :assigned_to, project_id = :project_id
                  WHERE id = :id'
            );
            $stmt->execute([
                'title'       => $finalTitle,
                'category'    => $old['category'],
                'username'    => $old['username'],
                'email'       => $old['email'],
                'encrypted'   => $encrypted,
                'url'         => $old['url'],
                'notes'       => $old['notes'] !== '' ? $old['notes'] : null,
                'extra_info'  => $extraInfo,
                'assigned_to' => $old['assigned_to'] > 0 ? $old['assigned_to'] : null,
                'project_id'  => $old['project_id'] > 0 ? $old['project_id'] : null,
                'id'          => $id,
            ]);

            $db->prepare('DELETE FROM password_roles WHERE password_id = :id')->execute(['id' => $id]);
            $insertRole = $db->prepare('INSERT INTO password_roles (password_id, role_id) VALUES (:pid, :rid)');
            foreach (array_unique($selectedRoles) as $roleId) {
                $insertRole->execute(['pid' => $id, 'rid' => $roleId]);
            }

            $db->commit();

            logAudit('update', $id, $finalTitle);
            flash('success', 'Credential updated.');
            redirect(BASE_URL . '/passwords/index.php');
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit Credential</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>" novalidate>
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
                </select>
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
            <label for="password">New Password <span class="muted">(leave blank to keep current)</span></label>
            <div class="input-row">
                <input type="text" id="password" name="password" value="<?= e($old['password']) ?>">
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
            <label for="extra_info">New Recovery Info <span class="muted">(leave blank to keep current)</span></label>
            <textarea id="extra_info" name="extra_info" rows="3"><?= e($old['extra_info']) ?></textarea>
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
