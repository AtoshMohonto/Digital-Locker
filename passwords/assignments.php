<?php
/**
 * Credential Assignments: who's using each credential, and its Access Level --
 * moved out of the main Credential Vault / Project views to keep those focused
 * on day-to-day use. Collapsible, grouped by Project, Category, Type, Role
 * (Access Level), or Date added -- same five groupings as the Credential
 * Vault's own toggle -- so the page scales past a handful of projects instead
 * of one long flat table.
 *
 * A credential can have several Managers and several Testers marked at once
 * (password_assignees), picked independently via the "Assign Manager" /
 * "Assign Tester" pickers below. Only an Administrator may change who's
 * assigned as Manager; a Manager may only (re)assign Testers, never the
 * Manager slot, even on the projects they manage themselves.
 *
 * An Administrator sees every project. A Manager sees and manages only the
 * projects they're a member of, same scoping as everywhere else in the
 * vault. A Tester never reaches this page at all (it requires
 * passwords.manage) -- their vault access is read-only with no export
 * capability (see passwords/export.php and the Export CSV button in
 * passwords/index.php).
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$isAdmin = isAdministrator();

$pageTitle = 'Credential Assignments';
$activePage = 'assignments';

$managerUsers = [];
$testerUsers = [];
foreach (usersGroupedByRole() as $ug) {
    if ($ug['label'] === 'Managers') {
        $managerUsers = $ug['users'];
    } elseif ($ug['label'] === 'Testers') {
        $testerUsers = $ug['users'];
    }
}
$returnTo = BASE_URL . '/passwords/assignments.php';

// Grouped by project by default; can switch to Category, Type, Role (Access
// Level), or Date added instead -- same five groupings as the Credential
// Vault's grouping toggle, all collapsible the same way.
$groupBy = in_array($_GET['group'] ?? '', ['category', 'type', 'role', 'date'], true) ? $_GET['group'] : 'project';
$projectGroupUrl  = 'assignments.php?' . http_build_query(array_merge($_GET, ['group' => 'project']));
$categoryGroupUrl = 'assignments.php?' . http_build_query(array_merge($_GET, ['group' => 'category']));
$typeGroupUrl     = 'assignments.php?' . http_build_query(array_merge($_GET, ['group' => 'type']));
$roleGroupUrl     = 'assignments.php?' . http_build_query(array_merge($_GET, ['group' => 'role']));
$dateGroupUrl     = 'assignments.php?' . http_build_query(array_merge($_GET, ['group' => 'date']));
$groupByLabel = ['project' => 'project', 'category' => 'category', 'type' => 'type', 'role' => 'access level', 'date' => 'date added'][$groupBy];

// Filters by the owning PROJECT's Category/Type (not the credential's own),
// same as the Projects page -- this page is organized by project, so "only
// Freelancing-category projects" is what a filter here should mean.
$filterCategory = trim($_GET['category'] ?? '');
$filterType     = trim($_GET['type'] ?? '');
$categoryOptionsList = projectCategoryOptions();
$typeOptionsList     = projectTypeOptions();

$sql = 'SELECT p.id, p.title, p.category, p.project_id, p.created_at, pr.name AS project_name,
               GROUP_CONCAT(DISTINCT r.id, ":", r.name ORDER BY r.name SEPARATOR "||") AS role_pairs
          FROM passwords p
          LEFT JOIN projects pr ON pr.id = p.project_id
          LEFT JOIN password_roles pr2 ON pr2.password_id = p.id
          LEFT JOIN roles r ON r.id = pr2.role_id
         WHERE 1=1';
$params = [];
if (!$isAdmin) {
    $sql .= ' AND p.project_id IS NOT NULL AND EXISTS (
                  SELECT 1 FROM project_members pm WHERE pm.project_id = p.project_id AND pm.user_id = :uid
              )';
    $params['uid'] = (int) currentUser()['id'];
}
if ($filterCategory !== '') {
    $sql .= ' AND pr.category = :pcategory';
    $params['pcategory'] = $filterCategory;
}
if ($filterType !== '') {
    $sql .= ' AND pr.type = :ptype';
    $params['ptype'] = $filterType;
}
$sql .= ' GROUP BY p.id ORDER BY p.title ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Load every Manager/Tester marked against these credentials in one query,
// keyed by password id then kind, so each row can render its own pickers
// without an N+1 query per credential.
$assigneesByPassword = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $aStmt = $db->prepare(
        "SELECT pa.password_id, pa.kind, pa.user_id, u.username, u.full_name
           FROM password_assignees pa
           JOIN users u ON u.id = pa.user_id
          WHERE pa.password_id IN ($placeholders)
          ORDER BY u.full_name, u.username"
    );
    $aStmt->execute($ids);
    foreach ($aStmt->fetchAll() as $a) {
        $assigneesByPassword[(int) $a['password_id']][$a['kind']][] = [
            'id'   => (int) $a['user_id'],
            'name' => $a['full_name'] ?: $a['username'],
        ];
    }
}

/**
 * A row's group key(s) for the current grouping mode. Role is multi-valued
 * (a credential can carry several access roles), so this returns a list of
 * [key, label, icon] triples -- the row is repeated under each matching
 * group, same as the Credential Vault's own "By Access level" grouping.
 */
function assignmentGroupKeysFor(array $row, string $groupBy): array
{
    switch ($groupBy) {
        case 'category':
            $cat = $row['category'] ?: '';
            return [[$cat !== '' ? $cat : '(uncategorized)', $cat !== '' ? $cat : 'Uncategorized', categoryIcon($cat)]];
        case 'type':
            $loginRole = extractLoginRole($row['title']);
            $hasType = $loginRole !== $row['title'];
            return [[$hasType ? $loginRole : '(no type)', $hasType ? $loginRole : 'No type set', '🎭']];
        case 'role':
            if (!$row['role_pairs']) {
                return [[0, 'Unrestricted (All Staff)', '🛡️']];
            }
            $pairs = [];
            foreach (explode('||', $row['role_pairs']) as $pair) {
                [$rid, $rname] = explode(':', $pair, 2);
                $pairs[] = [(int) $rid, accessLabel($rname), '🛡️'];
            }
            return $pairs;
        case 'date':
            return [[date('Y-m-d', strtotime($row['created_at'])), relativeDayLabel($row['created_at']), '📅']];
        default: // project
            return [[$row['project_id'] ?: 0, $row['project_name'] ?: 'No project', '📁']];
    }
}

// Hierarchical grouping: by project (a Manager only ever has project-linked
// rows here anyway, per the scoping above; an Administrator can additionally
// have a "No project" bucket for stragglers), or by Category/Type/Role when
// the toggle is switched.
$groups = [];
foreach ($rows as $row) {
    foreach (assignmentGroupKeysFor($row, $groupBy) as [$key, $label, $icon]) {
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'label'      => $label,
                'icon'       => $icon,
                'project_id' => $groupBy === 'project' ? $row['project_id'] : null,
                'rows'       => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
    }
}
if ($groupBy === 'date') {
    krsort($groups); // key = 'YYYY-MM-DD' -- newest day first, not alphabetical.
} else {
    uasort($groups, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));
}

/**
 * Renders one "Assign Manager" / "Assign Tester" cell: a checklist popover
 * (marks several people at once) when editable, or a plain name list when
 * the current user isn't allowed to touch that slot (Manager role viewing
 * the Manager column).
 */
function renderAssigneeCell(int $passwordId, string $kind, array $candidates, array $current, bool $editable, string $returnTo): string
{
    $names = $current
        ? implode(', ', array_map(static fn ($c) => e($c['name']), $current))
        : '<span class="muted">— Unassigned —</span>';

    if (!$editable) {
        return '<div class="assignee-picker__readonly">' . $names . '</div>';
    }

    $currentIds = array_column($current, 'id');
    $label = $kind === 'manager' ? 'Managers' : 'Testers';

    $html = '<details class="assignee-picker">'
          . '<summary class="assignee-picker__summary">' . $names . ' <span class="assignee-picker__caret">▾</span></summary>'
          . '<form method="post" action="update_assigned.php" class="assignee-picker__panel">'
          . csrf_field()
          . '<input type="hidden" name="id" value="' . $passwordId . '">'
          . '<input type="hidden" name="kind" value="' . e($kind) . '">'
          . '<input type="hidden" name="return_to" value="' . e($returnTo) . '">';

    if (!$candidates) {
        $html .= '<p class="assignee-picker__empty">No ' . $label . ' available.</p>';
    }
    foreach ($candidates as $u) {
        $uid = (int) $u['id'];
        $checked = in_array($uid, $currentIds, true) ? ' checked' : '';
        $html .= '<label class="assignee-picker__option"><input type="checkbox" name="user_ids[]" value="' . $uid . '"' . $checked . '> '
               . e($u['full_name'] ?: $u['username']) . '</label>';
    }

    $html .= '<button type="submit" class="btn btn--small">Save</button></form></details>';

    return $html;
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">Credential Assignments</h2>
            <p class="muted" style="margin:4px 0 0">Who's using each credential, and its Access Level -- grouped by <?= e($groupByLabel) ?>.</p>
        </div>
        <div class="quick-actions quick-actions--row">
            <div class="view-toggle">
                <a class="btn btn--small<?= $groupBy === 'project' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($projectGroupUrl) ?>">📁 By Project</a>
                <a class="btn btn--small<?= $groupBy === 'category' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($categoryGroupUrl) ?>">🏷 By Category</a>
                <a class="btn btn--small<?= $groupBy === 'type' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($typeGroupUrl) ?>">🎭 By Type</a>
                <a class="btn btn--small<?= $groupBy === 'role' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($roleGroupUrl) ?>">🛡 By Role</a>
                <a class="btn btn--small<?= $groupBy === 'date' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($dateGroupUrl) ?>">📅 By Date</a>
            </div>
        </div>
    </div>

    <form method="get" action="assignments.php" class="filters">
        <div class="form-group">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categoryOptionsList as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e(projectCategoryIcon($cat)) ?> <?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All types</option>
                <?php foreach ($typeOptionsList as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e(projectTypeIcon($t)) ?> <?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn">Filter</button>
            <a class="btn btn--ghost" href="assignments.php">Reset</a>
        </div>
    </form>

    <?php if (!$rows): ?>
        <p class="muted"><?= ($filterCategory !== '' || $filterType !== '') ? 'No credentials match your filters.' : 'No credentials yet.' ?></p>
    <?php else: ?>
        <?php if (count($groups) > 1): ?>
            <div class="group-controls">
                <button type="button" class="btn btn--small btn--ghost" id="expand-all-btn">⊞ Expand All</button>
                <button type="button" class="btn btn--small btn--ghost" id="collapse-all-btn">⊟ Collapse All</button>
            </div>
        <?php endif; ?>

        <?php foreach ($groups as $group): ?>
            <details class="vault-group" open>
                <summary class="vault-group__summary">
                    <span><?= e($group['icon']) ?> <?= $group['project_id'] ? '<a href="' . BASE_URL . '/projects/view.php?id=' . (int) $group['project_id'] . '">' . e($group['label']) . '</a>' : e($group['label']) ?></span>
                    <span class="badge badge--role"><?= count($group['rows']) ?></span>
                </summary>
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>System Name</th>
                            <th><?= $groupBy === 'category' ? 'Project' : 'Category' ?></th>
                            <th>Type</th>
                            <th>Assign Manager</th>
                            <th>Assign Tester</th>
                            <th>Access</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['rows'] as $row): ?>
                        <?php
                        $pid = (int) $row['id'];
                        $loginType = extractLoginRole($row['title']);
                        $hasLoginType = $loginType !== $row['title'];
                        $currentManagers = $assigneesByPassword[$pid]['manager'] ?? [];
                        $currentTesters  = $assigneesByPassword[$pid]['tester'] ?? [];
                        ?>
                        <tr>
                            <td><strong><a href="view.php?id=<?= $pid ?>"><?= e($row['title']) ?></a></strong></td>
                            <?php if ($groupBy === 'category'): ?>
                                <td><?= $row['project_id'] ? '<a href="' . BASE_URL . '/projects/view.php?id=' . (int) $row['project_id'] . '">' . e($row['project_name']) . '</a>' : '—' ?></td>
                            <?php else: ?>
                                <td><?= $row['category'] ? '<a href="index.php?category=' . urlencode($row['category']) . '">' . e($row['category']) . '</a>' : '—' ?></td>
                            <?php endif; ?>
                            <td><?= $hasLoginType ? '<span class="badge badge--role">' . e($loginType) . '</span>' : '—' ?></td>
                            <td><?= renderAssigneeCell($pid, 'manager', $managerUsers, $currentManagers, $isAdmin, $returnTo) ?></td>
                            <td><?= renderAssigneeCell($pid, 'tester', $testerUsers, $currentTesters, true, $returnTo) ?></td>
                            <td>
                                <?php if ($row['role_pairs']): ?>
                                    <?php foreach (explode('||', $row['role_pairs']) as $pair): ?>
                                        <?php [, $roleName] = explode(':', $pair, 2); ?>
                                        <span class="badge <?= $roleName === 'Administrator' ? 'badge--danger' : 'badge--role' ?>"><?= e(accessLabel($roleName)) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="badge badge--success">All Staff</span>
                                <?php endif; ?>
                                <a class="btn btn--small btn--ghost" href="edit.php?id=<?= $pid ?>" title="Change Access Level">✏️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
