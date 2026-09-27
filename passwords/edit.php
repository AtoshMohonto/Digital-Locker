<?php
/**
 * Edit an existing password entry.
 *
 * Mirrors the create flow: there's no free-text "System Name" field and no
 * separate Category to fill in -- the title is derived from Username > Email
 * > Project name (with the Role prefix in front), and the Category is
 * inherited from the Project. Nothing here asks for data the create page
 * doesn't already capture.
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

if (!canAccessCredentialProject($id) || !canAccessCredential($id)) {
    logAudit('edit_denied', $id, $item['title']);
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Edit: ' . $item['title'];
$activePage = 'passwords';

$roles = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$userGroups = usersGroupedByRole();
// Same project scoping as the create form: a Manager can only re-file this
// credential under a project they're a member of, not any project.
$projects = myAccessibleProjects();
$loginRoles = credentialTypeOptions();

$roleStmt = $db->prepare('SELECT role_id FROM password_roles WHERE password_id = :id');
$roleStmt->execute(['id' => $id]);
$selectedRoles = array_map('intval', array_column($roleStmt->fetchAll(), 'role_id'));

$titleParts = splitLoginRoleTitle($item['title']);

// Derives the identifying part of a title (and the inherited Category) the
// same way the create form does: Username > Email > Project name. Falls back
// to whatever was already stored (title base / category) instead of the
// empty-state placeholder, so re-saving an existing credential for an
// unrelated reason (e.g. rotating the password) can't silently wipe a real
// title down to "Untitled credential" or blank out a category just because
// this save has no project/username/email to derive from.
$deriveTitleParts = function (string $username, string $email, int $projectId) use ($item, $titleParts): array {
    global $projects;
    $projectName = '';
    $category = '';
    foreach ($projects as $p) {
        if ((int) $p['id'] === $projectId) {
            $projectName = $p['name'];
            $category = (string) $p['category'];
            break;
        }
    }
    $base = $username !== '' ? $username
        : ($email !== '' ? $email
        : ($projectName !== '' ? $projectName
        : ($titleParts['base'] !== '' ? $titleParts['base'] : 'Untitled credential')));
    if ($category === '') {
        $category = (string) $item['category'];
    }
    return ['project' => $projectName, 'category' => $category, 'base' => $base];
};

$derivedTitleBase = $deriveTitleParts($item['username'], (string) $item['email'], (int) $item['project_id']);

$errors = [];
$old = [
    'login_role'  => $titleParts['role'],
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
            'login_role'  => trim($_POST['login_role'] ?? ''),
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

        if ($old['project_id'] > 0 && !canAccessProject($old['project_id'])) {
            $errors[] = 'You are not a member of that project.';
        }

        if ($old['password'] !== '' && validatePasswordPolicy($old['password'], effectivePolicy())) {
            $errors = array_merge($errors, validatePasswordPolicy($old['password'], effectivePolicy()));
        }

        if (!$errors) {
            $derived = $deriveTitleParts($old['username'], $old['email'], $old['project_id']);
            $finalTitle = $old['login_role'] !== '' ? $old['login_role'] . ' — ' . $derived['base'] : $derived['base'];
            rememberCredentialType($old['login_role']);

            $encrypted = $old['password'] !== ''
                ? encrypt_password($old['password'], $appConfig)
                : $item['encrypted'];

            $extraInfo = $old['extra_info'] !== ''
                ? encrypt_password($old['extra_info'], $appConfig)
                : $item['extra_info'];

            $db->beginTransaction();

            $stmt = $db->prepare(
                'UPDATE passwords
                    SET title = :title, category = :category, username = :username, email = :email,
                        encrypted = :encrypted, url = :url, notes = :notes, extra_info = :extra_info,
                        assigned_to = :assigned_to, project_id = :project_id
                  WHERE id = :id'
            );
            $stmt->execute([
                'title'       => $finalTitle,
                'category'    => $derived['category'],
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

// Project id -> inherited badge info for the JS (category/type) plus the
// projected title shown under the Role field -- same data the create form's
// badges use, reused for the live preview here.
$projectMeta = [];
foreach ($projects as $p) {
    $projectMeta[(int) $p['id']] = [
        'name'         => $p['name'],
        'category'     => (string) $p['category'],
        'categoryIcon' => $p['category'] !== '' ? projectCategoryIcon((string) $p['category']) : '',
        'types'        => array_map(static fn ($t) => ['name' => $t, 'icon' => projectTypeIcon($t)], $p['types']),
    ];
}
$selectedProjectMeta = $projectMeta[$old['project_id']] ?? null;
$derived = $deriveTitleParts($old['username'], $old['email'], $old['project_id']);
$titlePreview = $old['login_role'] !== '' ? $old['login_role'] . ' — ' . $derived['base'] : $derived['base'];

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
            <label for="project_id">Project</label>
            <select id="project_id" name="project_id">
                <option value="0">— None —</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $old['project_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="project-inherited-badges" id="project-inherited-badges" <?= $selectedProjectMeta && ($selectedProjectMeta['category'] || $selectedProjectMeta['types']) ? '' : 'hidden' ?>>
                <?php if ($selectedProjectMeta && $selectedProjectMeta['category']): ?>
                    <span class="badge badge--role"><?= e($selectedProjectMeta['categoryIcon']) ?> <?= e($selectedProjectMeta['category']) ?></span>
                <?php endif; ?>
                <?php foreach ($selectedProjectMeta['types'] ?? [] as $t): ?>
                    <span class="badge badge--role"><?= e($t['icon']) ?> <?= e($t['name']) ?></span>
                <?php endforeach; ?>
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
            <p class="muted" style="margin:6px 0 0">Title: <span id="title-preview"><?= e($titlePreview) ?></span></p>
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
                <?php foreach ($userGroups as $group): ?>
                    <optgroup label="<?= e($group['label']) ?>">
                        <?php foreach ($group['users'] as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $old['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['full_name'] ?: $u['username']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
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

<script>
    (function () {
        var projectMeta = <?= json_encode($projectMeta, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var existingBase = <?= json_encode($titleParts['base'], JSON_HEX_TAG | JSON_HEX_APOS) ?>;

        var select = document.getElementById('project_id');
        var badges = document.getElementById('project-inherited-badges');
        var roleInput = document.getElementById('login_role');
        var usernameInput = document.getElementById('username');
        var emailInput = document.getElementById('email');
        var preview = document.getElementById('title-preview');
        if (!select) { return; }

        function makeBadge(icon, text) {
            var span = document.createElement('span');
            span.className = 'badge badge--role';
            span.textContent = (icon ? icon + ' ' : '') + text;
            return span;
        }

        function refreshBadges() {
            if (!badges) { return; }
            var meta = projectMeta[select.value];
            badges.textContent = '';
            var any = false;
            if (meta) {
                if (meta.category) { badges.appendChild(makeBadge(meta.categoryIcon, meta.category)); any = true; }
                (meta.types || []).forEach(function (t) { badges.appendChild(makeBadge(t.icon, t.name)); any = true; });
            }
            badges.hidden = !any;
        }

        function refreshTitle() {
            if (!preview) { return; }
            var meta = projectMeta[select.value];
            var base = (usernameInput && usernameInput.value) || (emailInput && emailInput.value) || (meta && meta.name) || existingBase || 'Untitled credential';
            var role = roleInput ? roleInput.value : '';
            preview.textContent = role ? role + ' — ' + base : base;
        }

        select.addEventListener('change', function () { refreshBadges(); refreshTitle(); });
        if (roleInput) { roleInput.addEventListener('input', refreshTitle); }
        if (usernameInput) { usernameInput.addEventListener('input', refreshTitle); }
        if (emailInput) { emailInput.addEventListener('input', refreshTitle); }

        refreshBadges();
        refreshTitle();
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
