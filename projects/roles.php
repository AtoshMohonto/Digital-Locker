<?php
/**
 * Project Roles: bulk-edit the credential roles per project, Category/Type/
 * Sub Type-wise -- the same three fields the New/Edit Project form's
 * Credential Roles checkboxes are scoped by, so a role added here for a given
 * Category+Type+Sub Type becomes visible for marking the moment a project
 * with that same combination is created or edited. A project can carry
 * several Types at once (e.g. Web App + Mobile App), so it appears in one
 * group per Type it has. Projects are grouped into collapsible (Category,
 * Type, Sub Type) groups; every group's projects list only the roles they
 * are actually assigned (never the whole catalog), and the assigning person
 * marks roles row-by-row next to each project. The row's total role set
 * updates as marks change, and a per-group "add role" field creates a new
 * role and applies it to every project in that group at once.
 *
 * Add/Delete exist at three grains: page-wide (Add/Delete a role across every
 * filtered project), per-group (the "Add for this group" field plus the mark
 * checkboxes, saved together), and per-project (the "x" on each role badge,
 * a single-role delete with no group save needed).
 *
 * Open to Administrators and Managers (passwords.manage). A Manager only ever
 * sees -- and can therefore only touch -- the projects they're a member of,
 * same scoping as everywhere else.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$pageTitle = 'Project Roles';
$activePage = 'project-roles';

$categoryOptions = projectCategoryOptions();
$typeOptions     = projectTypeOptions();
$subTypeOptions  = projectPurposeOptions();
$catalogRoles    = credentialTypeOptions();

$projectsAll = myAccessibleProjects();

// Load every accessible project's current credential roles in one query.
$rolesByProject = [];
$projectIdsAll = array_column($projectsAll, 'id');
if ($projectIdsAll) {
    $placeholders = implode(',', array_fill(0, count($projectIdsAll), '?'));
    $stmt = $db->prepare("SELECT project_id, name FROM project_roles WHERE project_id IN ($placeholders) ORDER BY name");
    $stmt->execute(array_values(array_map('intval', $projectIdsAll)));
    foreach ($stmt->fetchAll() as $roleRow) {
        $rolesByProject[(int) $roleRow['project_id']][] = (string) $roleRow['name'];
    }
}

/**
 * Applies the Category/Type/Sub Type filters to the accessible-project set.
 * A project can carry several Types at once, so the Type filter matches if
 * the filtered Type is any one of them.
 */
$filterProjects = static function (array $projects, string $fCategory, string $fType, string $fSubType): array {
    if ($fCategory === '__uncategorized__') {
        $projects = array_values(array_filter($projects, static fn ($p) => (string) $p['category'] === ''));
    } elseif ($fCategory !== '') {
        $projects = array_values(array_filter($projects, static fn ($p) => (string) $p['category'] === $fCategory));
    }
    if ($fType !== '') {
        $projects = array_values(array_filter($projects, static fn ($p) => in_array($fType, $p['types'], true)));
    }
    if ($fSubType === '__no_subtype__') {
        $projects = array_values(array_filter($projects, static fn ($p) => (string) $p['role'] === ''));
    } elseif ($fSubType !== '') {
        $projects = array_values(array_filter($projects, static fn ($p) => (string) $p['role'] === $fSubType));
    }
    return $projects;
};

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } elseif (isset($_POST['add_role_all'])) {
        // After picking Category/Type/Sub Type, add one or more comma-separated
        // roles to every project the current filters match. Pure additive
        // (existing roles are left untouched).
        $newRoles   = splitRoleNames($_POST['new_role'] ?? '');
        $catAll     = trim($_POST['category'] ?? '');
        $typeAll    = trim($_POST['type'] ?? '');
        $subTypeAll = trim($_POST['subtype'] ?? '');
        if (!$newRoles) {
            $errors[] = 'Enter at least one role name to add (comma-separated works: Admin, Editor, …).';
        } else {
            $targets = $filterProjects($projectsAll, $catAll, $typeAll, $subTypeAll);
            foreach ($newRoles as $roleName) {
                rememberCredentialType($roleName);
            }
            $db->beginTransaction();
            $stmtRole = $db->prepare('INSERT IGNORE INTO project_roles (project_id, name) VALUES (:pid, :name)');
            $added = 0;
            foreach ($targets as $t) {
                foreach ($newRoles as $roleName) {
                    $stmtRole->execute(['pid' => (int) $t['id'], 'name' => $roleName]);
                }
                $added++;
            }
            $db->commit();

            logAudit('project_roles_add_all', 0, 'roles [' . implode(', ', $newRoles) . '] added to ' . $added . ' project(s)');
            flash('success', 'Role(s) "' . implode(', ', $newRoles) . '" added to ' . $added . ' matching project(s).');
            $keep = array_intersect_key($_POST, array_flip(['category', 'type', 'subtype']));
            redirect(BASE_URL . '/projects/roles.php' . (($q = http_build_query($keep)) !== '' ? '?' . $q : ''));
        }
    } elseif (isset($_POST['remove_role_all'])) {
        // Delete counterpart to "Add to matching projects": removes one or more
        // existing roles from every project matching this Category/Type/Sub Type.
        $rolesToRemove = array_values(array_unique(array_filter(
            array_map('trim', (array) ($_POST['roles_to_remove'] ?? [])),
            static fn ($r) => $r !== ''
        )));
        $catAll     = trim($_POST['category'] ?? '');
        $typeAll    = trim($_POST['type'] ?? '');
        $subTypeAll = trim($_POST['subtype'] ?? '');
        if (!$rolesToRemove) {
            $errors[] = 'Pick at least one role to remove.';
        } else {
            $targets = $filterProjects($projectsAll, $catAll, $typeAll, $subTypeAll);
            $delStmt = $db->prepare('DELETE FROM project_roles WHERE project_id = :pid AND name = :name');
            $removed = 0;
            foreach ($targets as $t) {
                foreach ($rolesToRemove as $roleName) {
                    $delStmt->execute(['pid' => (int) $t['id'], 'name' => $roleName]);
                    if ($delStmt->rowCount() > 0) {
                        $removed++;
                    }
                }
            }
            logAudit('project_roles_remove_all', 0, 'role(s) [' . implode(', ', $rolesToRemove) . '] removed, ' . $removed . ' assignment(s)');
            flash('success', $removed ? 'Role(s) removed from ' . $removed . ' assignment(s).' : 'No matching project had those role(s).');
            $keep = array_intersect_key($_POST, array_flip(['category', 'type', 'subtype']));
            redirect(BASE_URL . '/projects/roles.php' . (($q = http_build_query($keep)) !== '' ? '?' . $q : ''));
        }
    } elseif (isset($_POST['remove_role_one'])) {
        // Quick single-click delete of one role from one project's badge --
        // no need to open the group and hit Save marks for a single removal.
        $pid      = (int) ($_POST['pid'] ?? 0);
        $roleName = trim($_POST['role_name'] ?? '');
        $accessibleIds = array_map('intval', array_column($projectsAll, 'id'));
        if ($roleName === '' || !in_array($pid, $accessibleIds, true)) {
            $errors[] = 'Invalid request.';
        } else {
            $db->prepare('DELETE FROM project_roles WHERE project_id = :pid AND name = :name')
                ->execute(['pid' => $pid, 'name' => $roleName]);
            logAudit('project_roles_remove_one', $pid, 'role "' . $roleName . '" removed');
            flash('success', 'Role "' . $roleName . '" removed.');
            $keep = array_intersect_key($_GET, array_flip(['category', 'type', 'subtype']));
            redirect(BASE_URL . '/projects/roles.php' . (($q = http_build_query($keep)) !== '' ? '?' . $q : ''));
        }
    } elseif (isset($_POST['save_group'])) {
        // Per-group save: the marks laid out row-by-row inside the group.
        $category = trim($_POST['category'] ?? '');
        $type     = trim($_POST['type'] ?? '');
        $subType  = trim($_POST['subtype'] ?? '');
        $newRoles = splitRoleNames($_POST['new_role'] ?? '');
        foreach ($newRoles as $roleName) {
            rememberCredentialType($roleName);
        }

        $targets = array_values(array_filter(
            $projectsAll,
            static fn ($p) => (string) $p['category'] === $category
                && ($type === '' ? !$p['types'] : in_array($type, $p['types'], true))
                && (string) $p['role'] === $subType
        ));

        if (!$targets) {
            $errors[] = 'No projects match that Category/Type/Sub Type group.';
        }

        // Submissions must be roles the group already uses, catalog roles, or
        // the newly-typed ones -- keeps the options list clean.
        $allowed = $catalogRoles;
        foreach ($targets as $t) {
            foreach ($rolesByProject[(int) $t['id']] ?? [] as $existing) {
                $allowed[] = $existing;
            }
        }
        foreach ($newRoles as $roleName) {
            $allowed[] = $roleName;
        }
        $allowed = array_values(array_unique(array_filter($allowed, static fn ($r) => $r !== '')));

        if (!$errors) {
            $posted = $_POST['roles'] ?? [];
            $rowRolesByProject = [];
            foreach ($targets as $t) {
                $pid = (int) $t['id'];
                $rowRoles = [];
                foreach ((array) ($posted[$pid] ?? []) as $roleName) {
                    $roleName = trim((string) $roleName);
                    if ($roleName !== '' && in_array($roleName, $allowed, true)) {
                        $rowRoles[$roleName] = true;
                    }
                }
                foreach ($newRoles as $roleName) {
                    $rowRoles[$roleName] = true;
                }
                $rowRolesByProject[$pid] = array_values(array_keys($rowRoles));
            }

            $db->beginTransaction();
            $delRole = $db->prepare('DELETE FROM project_roles WHERE project_id = :pid');
            $insRole = $db->prepare('INSERT IGNORE INTO project_roles (project_id, name) VALUES (:pid, :name)');
            $updated = 0;
            foreach ($targets as $t) {
                $pid = (int) $t['id'];
                $rowRoles = $rowRolesByProject[$pid];
                if (($rolesByProject[$pid] ?? []) !== $rowRoles) {
                    $updated++;
                }
                $delRole->execute(['pid' => $pid]);
                foreach ($rowRoles as $roleName) {
                    $insRole->execute(['pid' => $pid, 'name' => $roleName]);
                }
            }
            $db->commit();

            logAudit('project_roles_update', 0, $updated . ' project(s) updated in "' . ($category ?: 'Uncategorized') . '" / "' . ($type ?: 'No type') . '" / "' . ($subType ?: 'No sub type') . '"');
            flash('success', 'Role marks saved — ' . $updated . ' project(s) updated in this group.');
            $keep = array_intersect_key($_GET, array_flip(['category', 'type', 'subtype']));
            redirect(BASE_URL . '/projects/roles.php' . (($q = http_build_query($keep)) !== '' ? '?' . $q : ''));
        }
    }
}

$filterCategory = trim($_GET['category'] ?? '');
$filterType     = trim($_GET['type'] ?? '');
$filterSubType  = trim($_GET['subtype'] ?? '');

$projects = $filterProjects($projectsAll, $filterCategory, $filterType, $filterSubType);

// Every role already in use across the filtered set -- the pick list for the
// "Delete roles" checkboxes below (deletion targets existing roles, so this
// is a fixed list, not free text like the Add field).
$filteredRoleOptions = [];
foreach ($projects as $p) {
    foreach ($rolesByProject[(int) $p['id']] ?? [] as $rn) {
        $filteredRoleOptions[$rn] = true;
    }
}
$filteredRoleOptions = array_keys($filteredRoleOptions);
usort($filteredRoleOptions, 'strcasecmp');

// Group the visible projects Category/Type/Sub Type-wise -- the same three
// dimensions the New/Edit Project form scopes its Credential Roles checkboxes
// by, so a role added to a group here becomes visible for marking there. A
// project can carry several Types at once, so it lands in one group per Type
// (a project with no Type at all still gets one "No type" group). A group's
// mark options are only the roles its own projects are assigned -- never the
// whole catalog.
$groups = [];
foreach ($projects as $p) {
    $category = (string) $p['category'];
    $subType  = (string) $p['role'];
    $projectTypes = $p['types'] ?: [''];
    foreach ($projectTypes as $type) {
        $key = $category . '||' . $type . '||' . $subType;
        if (!isset($groups[$key])) {
            $groups[$key] = ['category' => $category, 'type' => $type, 'subtype' => $subType, 'projects' => [], 'roleOptions' => []];
        }
        $groups[$key]['projects'][] = [
            'id'    => (int) $p['id'],
            'name'  => $p['name'],
            'roles' => $rolesByProject[(int) $p['id']] ?? [],
        ];
        foreach ($rolesByProject[(int) $p['id']] ?? [] as $rn) {
            $groups[$key]['roleOptions'][$rn] = true;
        }
    }
}
uasort($groups, static function ($a, $b) {
    $cmp = strcasecmp($a['category'], $b['category']);
    if ($cmp !== 0) { return $cmp; }
    $cmp = strcasecmp($a['type'], $b['type']);
    return $cmp !== 0 ? $cmp : strcasecmp($a['subtype'], $b['subtype']);
});
foreach ($groups as &$group) {
    $group['roleOptions'] = array_values(array_unique(array_keys($group['roleOptions'])));
    usort($group['roleOptions'], 'strcasecmp');
}
unset($group);

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">Project Roles</h2>
            <p class="muted" style="margin:4px 0 0">Filter by Category/Type/Sub Type, then collapsible groups list each matching project with the roles it's assigned — mark them row by row, the row's role set updates as you go.</p>
        </div>
        <div class="quick-actions quick-actions--row">
            <a class="btn btn--ghost" href="<?= BASE_URL ?>/projects/index.php">Projects</a>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="get" action="roles.php" class="filters">
        <div class="form-group">
            <label for="category">Category</label>
            <select id="category" name="category" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($categoryOptions as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e(projectCategoryIcon($cat)) ?> <?= e($cat) ?></option>
                <?php endforeach; ?>
                <option value="__uncategorized__" <?= $filterCategory === '__uncategorized__' ? 'selected' : '' ?>>Uncategorized</option>
            </select>
        </div>
        <div class="form-group">
            <label for="type">Type</label>
            <select id="type" name="type" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($typeOptions as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e(projectTypeIcon($t)) ?> <?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="subtype">Sub Type</label>
            <select id="subtype" name="subtype" onchange="this.form.submit()">
                <option value="">All sub types</option>
                <?php foreach ($subTypeOptions as $st): ?>
                    <option value="<?= e($st) ?>" <?= $filterSubType === $st ? 'selected' : '' ?>>🎯 <?= e($st) ?></option>
                <?php endforeach; ?>
                <option value="__no_subtype__" <?= $filterSubType === '__no_subtype__' ? 'selected' : '' ?>>No sub type</option>
            </select>
        </div>
        <div class="filters__actions">
            <a class="btn btn--ghost" href="roles.php">Reset</a>
        </div>
    </form>

    <form method="post" action="roles.php" class="filters">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Add a role to every project matching this Category / Type / Sub Type</label>
            <div class="form-inline-row">
                <input type="text" name="new_role" placeholder="Admin, Editor, Reposter… (comma-separated)" maxlength="160" style="flex:2">
                <input type="hidden" name="category" value="<?= e($filterCategory) ?>">
                <input type="hidden" name="type" value="<?= e($filterType) ?>">
                <input type="hidden" name="subtype" value="<?= e($filterSubType) ?>">
                <button type="submit" name="add_role_all" value="1" class="btn btn--primary">Add to matching projects</button>
            </div>
        </div>
    </form>

    <?php if ($filteredRoleOptions): ?>
        <form method="post" action="roles.php" onsubmit="return confirm('Delete the selected role(s) from every project matching this Category/Type/Sub Type? This can\'t be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="category" value="<?= e($filterCategory) ?>">
            <input type="hidden" name="type" value="<?= e($filterType) ?>">
            <input type="hidden" name="subtype" value="<?= e($filterSubType) ?>">
            <div class="form-group">
                <label>Delete roles from every project matching this Category / Type / Sub Type</label>
                <div class="role-picker-row">
                    <?php foreach ($filteredRoleOptions as $rn): ?>
                        <label class="assignee-picker__option">
                            <input type="checkbox" name="roles_to_remove[]" value="<?= e($rn) ?>">
                            <?= e($rn) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions" style="margin-top:8px">
                    <button type="submit" name="remove_role_all" value="1" class="btn btn--danger btn--small">Delete selected</button>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!$projects): ?>
        <p class="muted">No projects match your filters.</p>
    <?php else: ?>
        <div class="group-controls">
            <button type="button" class="btn btn--small btn--ghost" id="expand-all-btn">⊞ Expand All</button>
            <button type="button" class="btn btn--small btn--ghost" id="collapse-all-btn">⊟ Collapse All</button>
        </div>

        <?php foreach ($groups as $group): ?>
            <details class="vault-group" open>
                <summary class="vault-group__summary">
                    <span>🏷 <?= e($group['category'] ?: 'Uncategorized') ?> — 🎭 <?= e($group['type'] ?: 'No type') ?> — 🎯 <?= e($group['subtype'] ?: 'No sub type') ?></span>
                    <span class="badge badge--role"><?= count($group['projects']) ?> project<?= count($group['projects']) === 1 ? '' : 's' ?></span>
                </summary>

                <form method="post" action="roles.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="save_group" value="1">
                    <input type="hidden" name="category" value="<?= e($group['category']) ?>">
                    <input type="hidden" name="type" value="<?= e($group['type']) ?>">
                    <input type="hidden" name="subtype" value="<?= e($group['subtype']) ?>">

                    <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Roles <span class="muted" style="font-weight:400">(check to mark, × to remove now)</span></th>
                                <th>Role set</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($group['projects'] as $gp): ?>
                            <tr>
                                <td class="matrix-td-project">
                                    <strong><a href="view.php?id=<?= (int) $gp['id'] ?>"><?= e($gp['name']) ?></a></strong>
                                    <div class="muted" style="font-size:0.78rem"><?= count($gp['roles']) ?> assigned</div>
                                </td>
                                <td>
                                    <?php if ($group['roleOptions']): ?>
                                        <div class="role-mark-row" style="display:flex;gap:2px 16px;flex-wrap:wrap;min-width:260px;max-width:660px" data-count-target="role-count-<?= (int) $gp['id'] ?>">
                                            <?php foreach ($group['roleOptions'] as $roleName): ?>
                                                <?php $isAssigned = in_array($roleName, $gp['roles'], true); ?>
                                                <label class="assignee-picker__option">
                                                    <input type="checkbox" name="roles[<?= (int) $gp['id'] ?>][]" value="<?= e($roleName) ?>" <?= $isAssigned ? 'checked' : '' ?>>
                                                    <?= e($roleName) ?>
                                                    <?php if ($isAssigned): ?>
                                                        <button type="button" class="badge-remove-btn role-remove-btn" data-pid="<?= (int) $gp['id'] ?>" data-role="<?= e($roleName) ?>" title="Remove now, without Save marks">×</button>
                                                    <?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">No roles yet — add one below</span>
                                    <?php endif; ?>
                                </td>
                                <td class="matrix-cell">
                                    <span id="role-count-<?= (int) $gp['id'] ?>"><?= count($gp['roles']) ?></span>
                                    <span class="muted"> role<?= count($gp['roles']) === 1 ? '' : 's' ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="form-inline-row" style="margin:10px 0 0">
                        <input type="text" name="new_role" placeholder="Add for this group: Admin, Editor, …" maxlength="160" style="flex:2">
                        <button type="submit" class="btn btn--primary">Save marks</button>
                    </div>
                    <?php if (!$group['roleOptions']): ?>
                        <p class="muted" style="margin-top:8px">No roles assigned in this group yet — add one above.</p>
                    <?php endif; ?>
                </form>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<form method="post" action="roles.php" id="remove-role-one-form" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="remove_role_one" value="1">
    <input type="hidden" name="pid" id="remove-role-one-pid">
    <input type="hidden" name="role_name" id="remove-role-one-name">
</form>

<script>
document.querySelectorAll('.role-mark-row').forEach(function (row) {
    var countTarget = document.getElementById(row.dataset.countTarget);
    row.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var n = row.querySelectorAll('input[type=checkbox]:checked').length;
            countTarget.textContent = n;
        });
    });
});

// Single-role delete "x" next to a checked role -- posts to the one shared
// hidden form instead of nesting a <form> inside the group's Save marks form
// (which HTML doesn't allow). It sits inside the role's <label>, so stop the
// click from also toggling that label's checkbox.
(function () {
    var removeForm = document.getElementById('remove-role-one-form');
    var removePid = document.getElementById('remove-role-one-pid');
    var removeName = document.getElementById('remove-role-one-name');
    if (!removeForm) { return; }
    document.querySelectorAll('.role-remove-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('Remove role "' + btn.dataset.role + '" from this project?')) { return; }
            removePid.value = btn.dataset.pid;
            removeName.value = btn.dataset.role;
            removeForm.submit();
        });
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>