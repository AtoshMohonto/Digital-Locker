<?php
/**
 * Create a project.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$pageTitle = 'New project';
$activePage = 'projects';

$categories = projectCategoryOptions();
$types = projectTypeOptions();
$purposes = projectPurposeOptions();
$purposesByContext = projectPurposesByContext();
$clients = projectClientOptions();
$rolesByContext = projectRolesByContext();
$errors = [];
$old = ['name' => '', 'client' => '', 'category' => '', 'types' => [], 'new_type' => '', 'role' => '', 'description' => '', 'credential_roles' => [], 'new_credential_role' => ''];

// Credential Roles: the admin/manager picks existing roles for this project's
// credentials or types a brand-new one. This stays separate from the internal
// Team (Manager/Tester membership) picker below it -- project_members is
// about who works on the project, project_roles is about which role labels a
// credential under this project may carry.
$canManageCredentialRoles = hasPermission('passwords.manage');

// Team: pick which Managers/Testers this project needs right when creating
// it, instead of a separate trip to the project page afterward. Each user
// appears under only one of these two lists (their highest-priority role),
// same grouping as the Credential Assignments pickers.
$canManageTeam = hasPermission('tasks.manage');
$managerCandidates = [];
$testerCandidates = [];
foreach (usersGroupedByRole() as $ug) {
    if ($ug['label'] === 'Managers') {
        $managerCandidates = $ug['users'];
    } elseif ($ug['label'] === 'Testers') {
        $testerCandidates = $ug['users'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'name'        => trim($_POST['name'] ?? ''),
            'client'      => trim($_POST['client'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'role'        => trim($_POST['role'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        // Type: checked catalog entries plus any typed new type(s) -- same
        // checkbox-or-type-new pattern as Credential Roles below. A project
        // can carry several Types at once (e.g. Web App + Mobile App).
        $old['types'] = array_values(array_unique(array_filter(
            array_intersect(array_map('trim', (array) ($_POST['types'] ?? [])), $types),
            static fn ($v) => $v !== ''
        )));
        $old['new_type'] = trim($_POST['new_type'] ?? '');
        foreach (splitRoleNames($old['new_type']) as $typeName) {
            $old['types'][] = $typeName;
        }
        $old['types'] = array_values(array_unique($old['types']));

        // Credential Roles: checked catalog entries plus any typed new role.
        // The checked values are intersected with the known catalog so the
        // form can't be used to smuggle in an arbitrary role name; the typed
        // field is where a genuinely new role is allowed to come from. Only
        // processed when the user has the role-manager capability (Admin/
        // Manager) -- the section isn't even rendered without it.
        $old['credential_roles'] = [];
        $old['new_credential_role'] = '';
        if ($canManageCredentialRoles) {
            $scopedRoles = projectContextCredentialRoles($old['category'], $old['types'], $old['role']);
            $old['credential_roles'] = array_values(array_unique(array_filter(
                array_values(array_intersect(
                    array_map('trim', (array) ($_POST['credential_roles'] ?? [])),
                    $scopedRoles
                )),
                static fn ($v) => $v !== ''
            )));
            $old['new_credential_role'] = trim($_POST['new_credential_role'] ?? '');
            foreach (splitRoleNames($old['new_credential_role']) as $roleName) {
                $old['credential_roles'][] = $roleName;
            }
            $old['credential_roles'] = array_values(array_unique($old['credential_roles']));
        }

        if ($old['name'] === '') {
            $errors[] = 'Project name is required.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare('INSERT INTO projects (name, client, category, role, description) VALUES (:name, :client, :category, :role, :description)');
                $stmt->execute(['name' => $old['name'], 'client' => $old['client'], 'category' => $old['category'], 'role' => $old['role'], 'description' => $old['description']]);
                // Must be read before any other query runs -- lastInsertId() only
                // reflects the most recently executed statement. The
                // rememberProject*() calls below INSERT into their own catalogs
                // (auto-increment), so for a brand-new Category/Type they would
                // overwrite this id and the project's roles would be written to
                // the wrong row.
                $newId = (int) $db->lastInsertId();
                rememberProjectClient($old['client']);
                rememberProjectCategory($old['category']);
                foreach ($old['types'] as $typeName) {
                    rememberProjectType($typeName);
                }
                rememberProjectPurpose($old['role']);

                if ($old['types']) {
                    $typeStmt = $db->prepare('INSERT IGNORE INTO project_type_links (project_id, type_name) VALUES (:pid, :name)');
                    foreach ($old['types'] as $typeName) {
                        $typeStmt->execute(['pid' => $newId, 'name' => $typeName]);
                    }
                }

                // A Manager (not an Administrator) is scoped to only the projects
                // they're a member of, so join them to what they just created --
                // otherwise they'd immediately lose access to their own project.
                if (!isAdministrator()) {
                    $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, project_role) VALUES (:pid, :uid, :role)')
                        ->execute(['pid' => $newId, 'uid' => (int) currentUser()['id'], 'role' => 'manager']);
                }

                if ($canManageTeam) {
                    $managerIds = array_intersect(
                        array_map('intval', $_POST['manager_ids'] ?? []),
                        array_column($managerCandidates, 'id')
                    );
                    $testerIds = array_intersect(
                        array_map('intval', $_POST['tester_ids'] ?? []),
                        array_column($testerCandidates, 'id')
                    );
                    $memberStmt = $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, project_role) VALUES (:pid, :uid, :role)');
                    foreach ($managerIds as $uid) {
                        $memberStmt->execute(['pid' => $newId, 'uid' => $uid, 'role' => 'manager']);
                    }
                    foreach ($testerIds as $uid) {
                        $memberStmt->execute(['pid' => $newId, 'uid' => $uid, 'role' => 'tester']);
                    }
                }

                // Credential Roles for this project (see the admin/manager section on the
                // form). A typed new role is remembered into the shared catalog
                // so it's a suggestion elsewhere too.
                if ($canManageCredentialRoles) {
                    foreach (splitRoleNames($old['new_credential_role']) as $roleName) {
                        rememberCredentialType($roleName);
                    }
                    $roleStmt = $db->prepare('INSERT IGNORE INTO project_roles (project_id, name) VALUES (:pid, :name)');
                    foreach ($old['credential_roles'] as $roleName) {
                        $roleStmt->execute(['pid' => $newId, 'name' => $roleName]);
                    }
                }

                flash('success', 'Project created.');
                redirect(BASE_URL . '/projects/index.php');
            } catch (PDOException $e) {
                $errors[] = 'A project with that name already exists.';
            }
        }
    }
}

// Scoped Credential Roles list for the checkboxes below -- computed already
// (against the submitted values) if a POST just ran validation; otherwise
// (a plain GET) compute it fresh against whatever Category/Type(s)/Sub Type
// is currently in $old.
if (!isset($scopedRoles)) {
    $scopedRoles = $canManageCredentialRoles ? projectContextCredentialRoles($old['category'], $old['types'], $old['role']) : [];
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">New project</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="create.php" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="client">Client <span class="muted">(who this project is for -- pick one or type a new one)</span></label>
            <input type="text" id="client" name="client" value="<?= e($old['client']) ?>" list="client-suggestions" placeholder="e.g. Acme Corp">
            <datalist id="client-suggestions">
                <?php foreach ($clients as $c): ?>
                    <option value="<?= e($c) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="form-group">
            <label for="category">Category <span class="muted">(pick one or type a new one)</span></label>
            <input type="text" id="category" name="category" value="<?= e($old['category']) ?>" list="category-suggestions" placeholder="e.g. Freelancing">
            <datalist id="category-suggestions">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="form-group">
            <label>Type <span class="muted">(pick one or more, or type new ones -- a project can be more than one at once, e.g. Web App + Mobile App)</span></label>
            <div class="role-picker-row" id="type-options">
                <?php foreach ($types as $t): ?>
                    <label class="assignee-picker__option">
                        <input type="checkbox" name="types[]" value="<?= e($t) ?>" <?= in_array($t, $old['types'], true) ? 'checked' : '' ?>>
                        <?= e($t) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="form-inline-row" style="margin-top:8px">
                <input type="text" name="new_type" value="<?= e($old['new_type']) ?>" placeholder="Create new type(s)… comma-separated: Web App, Mobile App" maxlength="160">
            </div>
        </div>

        <div class="form-group">
            <label for="role">Sub Type <span class="muted">(a more specific name within the Category/Type above -- e.g. Data Collection, E-commerce, Login System; suggestions narrow to your Category/Type)</span></label>
            <input type="text" id="role" name="role" value="<?= e($old['role']) ?>" list="role-suggestions" placeholder="e.g. Data Collection">
            <datalist id="role-suggestions">
                <?php foreach ($purposes as $r): ?>
                    <option value="<?= e($r) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <?php if ($canManageCredentialRoles): ?>
            <div class="form-group" style="padding-top:14px;border-top:1px solid var(--border)">
                <label>Credential Roles <span class="muted">(the roles this project's credentials can use -- e.g. Admin, Teacher)</span></label>
                <p class="muted" style="margin:-4px 0 10px">Tick existing roles, or type brand-new ones (comma-separated works: "Admin, Editor"). The list below narrows to whatever's already set up for this Category/Type/Sub Type via the <a href="<?= BASE_URL ?>/projects/roles.php">Project Roles</a> page. Anyone adding credentials to this project can only pick from these. This is separate from the internal Team below.</p>
                <div class="role-picker-row" id="credential-role-options">
                    <?php foreach ($scopedRoles as $cr): ?>
                        <label class="assignee-picker__option">
                            <input type="checkbox" name="credential_roles[]" value="<?= e($cr) ?>" <?= in_array($cr, $old['credential_roles'], true) ? 'checked' : '' ?>>
                            <?= e($cr) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted" id="credential-role-empty-hint" style="margin:6px 0 0" <?= $scopedRoles ? 'hidden' : '' ?>>No roles yet for this Category/Type/Sub Type — type one below to create it.</p>
                <div class="form-inline-row" style="margin-top:8px">
                    <input type="text" name="new_credential_role" value="<?= e($old['new_credential_role']) ?>" placeholder="Create new role(s)… comma-separated: Admin, Editor, Support" style="flex:2" maxlength="160">
                </div>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?= e($old['description']) ?></textarea>
        </div>

        <?php if ($canManageTeam && ($managerCandidates || $testerCandidates)): ?>
            <div class="form-group">
                <label>Team (optional)</label>
                <p class="muted" style="margin:-4px 0 10px">Pick which Managers and Testers this project needs. You can change this later too.</p>
                <div class="team-picker">
                    <div class="team-picker__col">
                        <h4 class="perm-group-title">Managers</h4>
                        <?php foreach ($managerCandidates as $u): ?>
                            <label class="assignee-picker__option">
                                <input type="checkbox" name="manager_ids[]" value="<?= (int) $u['id'] ?>">
                                <?= e($u['full_name'] ?: $u['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="team-picker__col">
                        <h4 class="perm-group-title">Testers</h4>
                        <?php foreach ($testerCandidates as $u): ?>
                            <label class="assignee-picker__option">
                                <input type="checkbox" name="tester_ids[]" value="<?= (int) $u['id'] ?>">
                                <?= e($u['full_name'] ?: $u['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Create project</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<script>
    (function () {
        var purposesByContext = <?= json_encode($purposesByContext, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var allPurposes = <?= json_encode($purposes, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var rolesByContext = <?= json_encode($rolesByContext, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var currentCredentialRoles = <?= json_encode($old['credential_roles'], JSON_HEX_TAG | JSON_HEX_APOS) ?>;

        var categoryInput = document.getElementById('category');
        var subTypeInput = document.getElementById('role');
        var roleList = document.getElementById('role-suggestions');
        var typeOptionsRow = document.getElementById('type-options');
        var rolePickerRow = document.getElementById('credential-role-options');
        var roleEmptyHint = document.getElementById('credential-role-empty-hint');
        if (!categoryInput || !roleList) { return; }

        function selectedTypes() {
            var vals = [];
            if (typeOptionsRow) {
                typeOptionsRow.querySelectorAll('input[type=checkbox]:checked').forEach(function (cb) { vals.push(cb.value); });
            }
            return vals;
        }

        function refreshRoleSuggestions() {
            var category = categoryInput.value.trim();
            var contextual = [];
            selectedTypes().forEach(function (t) {
                (purposesByContext[category + '|' + t] || []).forEach(function (p) {
                    if (contextual.indexOf(p) === -1) { contextual.push(p); }
                });
            });
            var combined = contextual.concat(allPurposes.filter(function (p) { return contextual.indexOf(p) === -1; }));
            roleList.textContent = '';
            combined.forEach(function (name) {
                var opt = document.createElement('option');
                opt.value = name;
                roleList.appendChild(opt);
            });
        }

        // Re-scopes the Credential Roles checkboxes to this exact
        // Category/Type(s)/Sub Type combo -- but keeps anything already
        // checked (or already saved on this project) visible even if it falls
        // outside the new combo, so switching fields never silently drops a
        // role. A project can have several Types, so this unions the roles
        // scoped to each checked Type.
        function refreshCredentialRoleOptions() {
            if (!rolePickerRow) { return; }
            var category = categoryInput.value.trim();
            var subType = subTypeInput ? subTypeInput.value.trim() : '';
            var names = [];
            selectedTypes().forEach(function (t) {
                (rolesByContext[category + '|' + t + '|' + subType] || []).forEach(function (n) {
                    if (names.indexOf(n) === -1) { names.push(n); }
                });
            });
            var checked = [];
            rolePickerRow.querySelectorAll('input[type=checkbox]:checked').forEach(function (cb) { checked.push(cb.value); });
            (currentCredentialRoles || []).forEach(function (n) { if (checked.indexOf(n) === -1) { checked.push(n); } });
            checked.forEach(function (n) { if (names.indexOf(n) === -1) { names.push(n); } });

            rolePickerRow.innerHTML = '';
            names.forEach(function (name) {
                var label = document.createElement('label');
                label.className = 'assignee-picker__option';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'credential_roles[]';
                cb.value = name;
                cb.checked = checked.indexOf(name) !== -1;
                label.appendChild(cb);
                label.appendChild(document.createTextNode(' ' + name));
                rolePickerRow.appendChild(label);
            });
            if (roleEmptyHint) { roleEmptyHint.hidden = names.length > 0; }
        }

        categoryInput.addEventListener('input', function () { refreshRoleSuggestions(); refreshCredentialRoleOptions(); });
        if (typeOptionsRow) {
            typeOptionsRow.addEventListener('change', function (e) {
                if (e.target && e.target.type === 'checkbox') { refreshRoleSuggestions(); refreshCredentialRoleOptions(); }
            });
        }
        if (subTypeInput) { subTypeInput.addEventListener('input', refreshCredentialRoleOptions); }
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
