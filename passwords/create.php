<?php
/**
 * Create a new password entry.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$pageTitle = 'New Credential';
$activePage = 'passwords';

$roles      = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$users      = $db->query('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY username')->fetchAll();
$categories = categoryOptions();

$errors = [];
$old = [
    'title'       => '',
    'category'    => '',
    'username'    => '',
    'password'    => '',
    'url'         => '',
    'notes'       => '',
    'extra_info'  => '',
    'assigned_to' => 0,
];
$selectedRoles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'title'       => trim($_POST['title'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'username'    => trim($_POST['username'] ?? ''),
            'password'    => (string) ($_POST['password'] ?? ''),
            'url'         => trim($_POST['url'] ?? ''),
            'notes'       => trim($_POST['notes'] ?? ''),
            'extra_info'  => trim($_POST['extra_info'] ?? ''),
            'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
        ];
        $validRoleIds = array_column($roles, 'id');
        $selectedRoles = array_intersect(array_map('intval', $_POST['roles'] ?? []), $validRoleIds);

        if ($old['title'] === '') {
            $errors[] = 'System name is required.';
        }
        if ($old['password'] === '') {
            $errors[] = 'A password value is required.';
        }
        $policyErrors = validatePasswordPolicy($old['password'], effectivePolicy());
        if ($policyErrors) {
            $errors = array_merge($errors, $policyErrors);
        }

        if (!$errors) {
            $db->beginTransaction();

            $stmt = $db->prepare(
                'INSERT INTO passwords (title, category, username, encrypted, url, notes, extra_info, assigned_to, created_by)
                 VALUES (:title, :category, :username, :encrypted, :url, :notes, :extra_info, :assigned_to, :created_by)'
            );
            $stmt->execute([
                'title'       => $old['title'],
                'category'   => $old['category'],
                'username'   => $old['username'],
                'encrypted'  => encrypt_password($old['password'], $appConfig),
                'url'        => $old['url'],
                'notes'      => $old['notes'] !== '' ? $old['notes'] : null,
                'extra_info' => $old['extra_info'] !== '' ? encrypt_password($old['extra_info'], $appConfig) : null,
                'assigned_to' => $old['assigned_to'] > 0 ? $old['assigned_to'] : null,
                'created_by' => currentUser()['id'],
            ]);
            $passwordId = (int) $db->lastInsertId();

            $roleStmt = $db->prepare('INSERT INTO password_roles (password_id, role_id) VALUES (:pid, :rid)');
            foreach (array_unique($selectedRoles) as $roleId) {
                $roleStmt->execute(['pid' => $passwordId, 'rid' => $roleId]);
            }

            $db->commit();

            logAudit('create', $passwordId, $old['title']);
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
                <label>Access Level <span class="muted">(who can see this)</span></label>
                <?php foreach ($roles as $r): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>"
                               <?= in_array((int) $r['id'], $selectedRoles, true) ? 'checked' : '' ?>>
                        <span><?= e(accessLabel($r['name'])) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="url">URL / IP Address</label>
            <input type="text" id="url" name="url" value="<?= e($old['url']) ?>" placeholder="https://... or 10.0.0.15">
        </div>

        <div class="form-group">
            <label for="username">Username / Email</label>
            <input type="text" id="username" name="username" value="<?= e($old['username']) ?>">
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
