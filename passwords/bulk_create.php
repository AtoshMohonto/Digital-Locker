<?php
/**
 * Add several credentials at once, all under the same project. Each row
 * picks its own Role (e.g. Admin, Teacher, Student) since a batch commonly
 * mixes several -- everything else stays as lean as the single New
 * Credential form (no Access Level/Assigned To; Category is inherited from
 * the Project). Empty rows are skipped.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$pageTitle = 'Bulk Add Credentials';
$activePage = 'passwords';

$projects = myAccessibleProjects();
$loginRoles = credentialTypeOptions();

$errors = [];
$old = ['project_id' => (int) ($_GET['project_id'] ?? 0)];
$newProjectName = '';
$rows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $newProjectName = trim($_POST['new_project_name'] ?? '');
        $old['project_id'] = ($_POST['project_id'] ?? '') === '__new__' ? -1 : (int) ($_POST['project_id'] ?? 0);

        $roles     = $_POST['role'] ?? [];
        $usernames = $_POST['username'] ?? [];
        $emails    = $_POST['email'] ?? [];
        $passwords = $_POST['password'] ?? [];
        $urls      = $_POST['url'] ?? [];
        $count = max(count($roles), count($usernames), count($emails), count($passwords), count($urls));
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'role'     => trim((string) ($roles[$i] ?? '')),
                'username' => trim((string) ($usernames[$i] ?? '')),
                'email'    => trim((string) ($emails[$i] ?? '')),
                'password' => (string) ($passwords[$i] ?? ''),
                'url'      => trim((string) ($urls[$i] ?? '')),
            ];
        }
        // Drop fully-blank rows (e.g. extra rows the user added but never filled in).
        $rows = array_values(array_filter(
            $rows,
            static fn ($r) => $r['username'] !== '' || $r['email'] !== '' || $r['password'] !== '' || $r['url'] !== ''
        ));

        if (!$rows) {
            $errors[] = 'Add at least one credential.';
        }
        if ($old['project_id'] === -1 && $newProjectName === '') {
            $errors[] = 'Enter a name for the new project.';
        }
        if ($old['project_id'] > 0 && !canAccessProject($old['project_id'])) {
            $errors[] = 'You are not a member of that project.';
        }

        $policy = effectivePolicy();
        foreach ($rows as $i => $r) {
            if ($r['password'] === '') {
                $errors[] = 'Row ' . ($i + 1) . ': a password is required.';
                continue;
            }
            foreach (validatePasswordPolicy($r['password'], $policy) as $policyError) {
                $errors[] = 'Row ' . ($i + 1) . ': ' . $policyError;
            }
        }

        $isNewProject = $old['project_id'] === -1;

        if (!$errors) {
            $db->beginTransaction();

            if ($isNewProject) {
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

            $stmt = $db->prepare(
                'INSERT INTO passwords (title, category, username, email, encrypted, url, project_id, created_by)
                 VALUES (:title, :category, :username, :email, :encrypted, :url, :project_id, :created_by)'
            );
            $created = 0;
            foreach ($rows as $r) {
                $baseName = $r['username'] !== '' ? $r['username']
                    : ($r['email'] !== '' ? $r['email']
                    : ($projectNameForTitle !== '' ? $projectNameForTitle : 'Untitled credential'));
                $title = $r['role'] !== '' ? $r['role'] . ' — ' . $baseName : $baseName;
                rememberCredentialType($r['role']);
                $stmt->execute([
                    'title'      => $title,
                    'category'   => $inheritedCategory,
                    'username'   => $r['username'],
                    'email'      => $r['email'],
                    'encrypted'  => encrypt_password($r['password'], $appConfig),
                    'url'        => $r['url'],
                    'project_id' => $old['project_id'] > 0 ? $old['project_id'] : null,
                    'created_by' => currentUser()['id'],
                ]);
                $created++;
                logAudit('create', (int) $db->lastInsertId(), $title);
            }
            $db->commit();

            flash('success', $created . ' credential(s) created. Set Access Level and Assign Manager/Tester from Credential Assignments.');
            redirect(BASE_URL . '/passwords/index.php');
        }
    }
}

if (!$rows) {
    $rows = array_fill(0, 3, ['role' => '', 'username' => '', 'email' => '', 'password' => '', 'url' => '']);
}

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

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Bulk Add Credentials</h2>
        <a class="btn btn--small" href="create.php">&larr; Single credential</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="bulk_create.php" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="project_id">Project <span class="muted">(applies to every row below)</span></label>
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

        <div class="table-wrap">
        <table class="table" id="bulk-rows-table">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Password</th>
                    <th>URL / IP</th>
                    <th style="width:40px"></th>
                </tr>
            </thead>
            <tbody id="bulk-rows-body">
                <?php foreach ($rows as $r): ?>
                    <tr class="bulk-row">
                        <td>
                            <select name="role[]">
                                <option value="">— None —</option>
                                <?php foreach ($loginRoles as $lr): ?>
                                    <option value="<?= e($lr) ?>" <?= $r['role'] === $lr ? 'selected' : '' ?>><?= e($lr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="username[]" value="<?= e($r['username']) ?>"></td>
                        <td><input type="email" name="email[]" value="<?= e($r['email']) ?>"></td>
                        <td>
                            <div class="input-row">
                                <input type="text" name="password[]" value="<?= e($r['password']) ?>" class="bulk-password">
                                <button type="button" class="btn btn--small bulk-generate-btn">Generate</button>
                            </div>
                        </td>
                        <td><input type="text" name="url[]" value="<?= e($r['url']) ?>" placeholder="https://... or 10.0.0.15"></td>
                        <td><button type="button" class="btn btn--small btn--ghost remove-row-btn" title="Remove row">🗑</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <template id="bulk-row-template">
            <tr class="bulk-row">
                <td>
                    <select name="role[]">
                        <option value="">— None —</option>
                        <?php foreach ($loginRoles as $lr): ?>
                            <option value="<?= e($lr) ?>"><?= e($lr) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" name="username[]" value=""></td>
                <td><input type="email" name="email[]" value=""></td>
                <td>
                    <div class="input-row">
                        <input type="text" name="password[]" value="" class="bulk-password">
                        <button type="button" class="btn btn--small bulk-generate-btn">Generate</button>
                    </div>
                </td>
                <td><input type="text" name="url[]" value="" placeholder="https://... or 10.0.0.15"></td>
                <td><button type="button" class="btn btn--small btn--ghost remove-row-btn" title="Remove row">🗑</button></td>
            </tr>
        </template>

        <div class="form-actions">
            <button type="button" class="btn btn--small" id="add-row-btn">+ Add row</button>
            <button type="submit" class="btn btn--primary">Save Credentials</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<script>
    (function () {
        var projectMeta = <?= json_encode($projectMeta, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

        var select = document.getElementById('project_id');
        var newName = document.getElementById('new_project_name');
        var badges = document.getElementById('project-inherited-badges');

        function makeBadge(icon, text) {
            var span = document.createElement('span');
            span.className = 'badge badge--role';
            span.textContent = (icon ? icon + ' ' : '') + text;
            return span;
        }

        if (select) {
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
        }

        var body = document.getElementById('bulk-rows-body');
        var template = document.getElementById('bulk-row-template');
        var addBtn = document.getElementById('add-row-btn');
        if (addBtn && body && template) {
            addBtn.addEventListener('click', function () {
                body.appendChild(template.content.cloneNode(true));
            });
        }
        if (body) {
            body.addEventListener('click', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('remove-row-btn')) {
                    var row = e.target.closest('tr');
                    if (row && body.querySelectorAll('.bulk-row').length > 1) {
                        row.remove();
                    }
                }
            });
        }
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
