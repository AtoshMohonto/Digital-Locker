<?php
/**
 * Projects list. Administrator sees and manages every project. Everyone else
 * -- Manager or Tester -- sees a "My Projects" list scoped to specifically
 * the projects they've been added to as a team member: a Manager does not
 * automatically manage every project, only the ones assigned to them.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$isAdmin = isAdministrator();
$canCreateProjects = hasPermission('projects.manage');
// A Manager reaches this page even with zero projects so far -- they still
// need "+ New project" to create their first one. Only block someone who can
// neither manage nor has ever been added as a team member (nothing to do here).
if (!$isAdmin && !$canCreateProjects && !hasAnyProjectMembership()) {
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = $isAdmin ? 'Projects' : 'My Projects';
$activePage = 'projects';
$myUserId = (int) currentUser()['id'];

// '__uncategorized__' is a distinct sentinel from '' (no filter at all) so
// "Uncategorized" can be filtered to specifically, without also matching
// every other category once projects actually start getting tagged.
$filterCategory = trim($_GET['category'] ?? '');
$filterType     = trim($_GET['type'] ?? '');

$sql = 'SELECT p.id, p.name, p.client, p.category, p.role, p.description, p.created_at,
               (SELECT COUNT(*) FROM passwords pw WHERE pw.project_id = p.id) AS password_count'
     . (!$isAdmin ? ', pm.project_role AS my_role' : '')
     . '   FROM projects p';
$params = [];
if (!$isAdmin) {
    $sql .= ' JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = :uid';
    $params['uid'] = $myUserId;
}
$sql .= ' WHERE 1=1';
if ($filterCategory === '__uncategorized__') {
    $sql .= " AND p.category = ''";
} elseif ($filterCategory !== '') {
    $sql .= ' AND p.category = :category';
    $params['category'] = $filterCategory;
}
if ($filterType !== '') {
    // A project can carry several Types at once -- match if the filtered
    // Type is any one of them.
    $sql .= ' AND EXISTS (SELECT 1 FROM project_type_links ptl WHERE ptl.project_id = p.id AND ptl.type_name = :type)';
    $params['type'] = $filterType;
}
$sql .= ' ORDER BY p.name';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Load every listed project's Type(s) in one follow-up query (same
// avoid-N+1 pattern as loading Credential Roles elsewhere) and attach as
// $p['types'], plus a canonical $p['type_key'] the merged table view uses to
// decide whether two consecutive projects' Type sets are identical.
$projectIds = array_column($projects, 'id');
$typesById = [];
if ($projectIds) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $typeStmt = $db->prepare("SELECT project_id, type_name FROM project_type_links WHERE project_id IN ($placeholders) ORDER BY type_name");
    $typeStmt->execute(array_values(array_map('intval', $projectIds)));
    foreach ($typeStmt->fetchAll() as $row) {
        $typesById[(int) $row['project_id']][] = (string) $row['type_name'];
    }
}
foreach ($projects as &$p) {
    $p['types'] = $typesById[(int) $p['id']] ?? [];
    $p['type_key'] = implode('|', $p['types']);
}
unset($p);

$categories = projectCategoryOptions();
$types = projectTypeOptions();
$viewMode = ($_GET['view'] ?? '') === 'table' ? 'table' : 'grid';
$gridUrl  = 'index.php?' . http_build_query(array_merge($_GET, ['view' => 'grid']));
$tableUrl = 'index.php?' . http_build_query(array_merge($_GET, ['view' => 'table']));
$groupBy = ($_GET['group'] ?? '') === 'date' ? 'date' : '';
$dateToggleUrl = 'index.php?' . http_build_query(array_merge($_GET, ['group' => $groupBy === 'date' ? '' : 'date']));

/**
 * Rowspan run lengths for a merged column: consecutive rows sharing the same
 * value get span-merged into one cell, keyed by start index. Same pattern as
 * the Credential Vault's System/Category columns.
 */
function projectColumnRuns(array $projects, string $field): array
{
    $runs = [];
    $n = count($projects);
    for ($i = 0; $i < $n;) {
        $v = (string) ($projects[$i][$field] ?? '');
        $j = $i + 1;
        while ($j < $n && (string) ($projects[$j][$field] ?? '') === $v) { $j++; }
        $runs[$i] = $j - $i;
        $i = $j;
    }
    return $runs;
}

/**
 * Same table as the plain rows, but with the repeated Category / Type / Role
 * cells span-merged when several consecutive projects share a value -- the
 * Credential Vault's merged-layout pattern applied to the Projects page.
 */
function renderMergedProjectTable(array $projects, bool $isAdmin): void
{
    $clientRuns = projectColumnRuns($projects, 'client');
    $catRuns  = projectColumnRuns($projects, 'category');
    $typeRuns = projectColumnRuns($projects, 'type_key');
    $roleRuns = projectColumnRuns($projects, 'role');
    foreach ($projects as $i => $p) {
        ?>
        <tr>
            <?php if ($isAdmin): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" class="proj-check"></td><?php endif; ?>
            <?php if (isset($clientRuns[$i])): ?>
                <td<?= $clientRuns[$i] > 1 ? ' rowspan="' . $clientRuns[$i] . '"' : '' ?>><?= $p['client'] ? '👤 ' . e($p['client']) : '—' ?></td>
            <?php endif; ?>
            <?php if (isset($catRuns[$i])): ?>
                <td<?= $catRuns[$i] > 1 ? ' rowspan="' . $catRuns[$i] . '"' : '' ?>><?= $p['category'] ? e(projectCategoryIcon($p['category'])) . ' ' . e($p['category']) : '—' ?></td>
            <?php endif; ?>
            <?php if (isset($typeRuns[$i])): ?>
                <td<?= $typeRuns[$i] > 1 ? ' rowspan="' . $typeRuns[$i] . '"' : '' ?>>
                    <?php if ($p['types']): ?>
                        <?php foreach ($p['types'] as $t): ?>
                            <span class="badge badge--role"><?= e(projectTypeIcon($t)) ?> <?= e($t) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            <?php endif; ?>
            <?php if (isset($roleRuns[$i])): ?>
                <td<?= $roleRuns[$i] > 1 ? ' rowspan="' . $roleRuns[$i] . '"' : '' ?>><?= $p['role'] ? '🎯 ' . e($p['role']) : '—' ?></td>
            <?php endif; ?>
            <td><strong><a href="view.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a></strong></td>
            <td><?= e($p['description'] ?: '—') ?></td>
            <td><?= (int) $p['password_count'] ?></td>
            <td class="table__actions">
                <?php if ($isAdmin || ($p['my_role'] ?? null) === 'manager'): ?>
                    <a class="btn btn--small" href="edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
                <?php else: ?>
                    <a class="btn btn--small btn--ghost" href="view.php?id=<?= (int) $p['id'] ?>">View</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

/** Renders one project's card -- shared between the flat grid and every date group. */
function renderProjectCard(array $p, bool $isAdmin, string $viewMode): void
{
    ?>
    <div class="credential-card">
        <div class="credential-card__header">
            <?php if ($isAdmin): ?><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" class="proj-check credential-card__mark"><?php endif; ?>
            <span class="credential-card__icon"><?= $p['category'] ? e(projectCategoryIcon($p['category'])) : '📁' ?></span>
            <a class="credential-card__title" href="view.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a>
        </div>

        <div class="credential-card__meta">
            <?php if ($p['client']): ?><span class="badge badge--role">👤 <?= e($p['client']) ?></span><?php endif; ?>
            <?php if ($p['category']): ?><a class="badge badge--role" href="index.php?category=<?= urlencode($p['category']) ?>&amp;view=<?= e($viewMode) ?>"><?= e($p['category']) ?></a><?php endif; ?>
            <?php foreach ($p['types'] as $t): ?>
                <a class="badge badge--role" href="index.php?type=<?= urlencode($t) ?>&amp;view=<?= e($viewMode) ?>"><?= e(projectTypeIcon($t)) ?> <?= e($t) ?></a>
            <?php endforeach; ?>
            <?php if ($p['role']): ?><span class="badge badge--role">🎯 <?= e($p['role']) ?></span><?php endif; ?>
            <span class="badge badge--role"><?= (int) $p['password_count'] ?> credential<?= (int) $p['password_count'] === 1 ? '' : 's' ?></span>
        </div>

        <p class="muted" style="margin:10px 0 0"><?= e($p['description'] ?: 'No description.') ?></p>

        <div class="credential-card__actions">
            <a class="btn btn--small btn--ghost" href="view.php?id=<?= (int) $p['id'] ?>">View</a>
            <?php if ($isAdmin || ($p['my_role'] ?? null) === 'manager'): ?>
                <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/** Renders one flat set of projects as a table or a card grid, per $viewMode. */
function renderProjectSet(array $projects, string $viewMode, bool $isAdmin): void
{
    if ($viewMode === 'table') {
        ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <?php if ($isAdmin): ?><th style="width:36px"><input type="checkbox" class="proj-check-all-group"></th><?php endif; ?>
                    <th>Client</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Role</th>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Passwords</th>
                    <th class="table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php renderMergedProjectTable($projects, $isAdmin); ?>
            </tbody>
        </table>
        </div>
        <?php
    } else {
        ?>
        <div class="credential-grid">
            <?php foreach ($projects as $p): ?>
                <?php renderProjectCard($p, $isAdmin, $viewMode); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <h2 class="card__title"><?= e($pageTitle) ?></h2>
        <div class="quick-actions quick-actions--row">
            <div class="view-toggle">
                <a class="btn btn--small<?= $viewMode === 'grid' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($gridUrl) ?>">▦ Grid</a>
                <a class="btn btn--small<?= $viewMode === 'table' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($tableUrl) ?>">☰ Table</a>
                <a class="btn btn--small<?= $groupBy === 'date' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($dateToggleUrl) ?>">📅 By Date</a>
            </div>
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn" href="<?= BASE_URL ?>/projects/roles.php">Project Roles</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a class="btn" href="<?= BASE_URL ?>/categories/index.php">Categories</a>
            <?php endif; ?>
            <?php if ($canCreateProjects): ?>
                <a class="btn btn--primary" href="create.php">+ New project</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$isAdmin): ?>
        <p class="muted" style="margin-top:-8px">Projects you've been added to as a team member.</p>
    <?php endif; ?>

    <form method="get" action="index.php" class="filters">
        <div class="form-group">
            <label for="category">Category</label>
            <select id="category" name="category" onchange="this.form.submit()">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e(projectCategoryIcon($cat)) ?> <?= e($cat) ?></option>
                <?php endforeach; ?>
                <option value="__uncategorized__" <?= $filterCategory === '__uncategorized__' ? 'selected' : '' ?>>Uncategorized</option>
            </select>
        </div>
        <div class="form-group">
            <label for="type">Type</label>
            <select id="type" name="type" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e(projectTypeIcon($t)) ?> <?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="view" value="<?= e($viewMode) ?>">
        <div class="filters__actions">
            <button type="submit" class="btn">Filter</button>
            <a class="btn btn--ghost" href="index.php">Reset</a>
        </div>
    </form>

    <?php if (!$projects): ?>
        <p class="muted">No projects match your filters.</p>
    <?php else: ?>
        <?php if ($isAdmin): ?>
        <form method="post" action="delete.php" onsubmit="return confirm('Delete the selected project(s)? Their passwords will be unassigned.');">
            <?= csrf_field() ?>
        <?php endif; ?>

        <?php
            // Default view: collapsible groups by Category (the Projects
            // analogue of the Vault's System grouping), with repeated
            // Category/Type/Role cells merged inside each group. "By Date"
            // switches the same tables over to date groups instead.
            $groupKey = $groupBy === 'date' ? 'date' : 'category';
            $groupIcons = ['date' => '📅', 'category' => '🏷'];
            $groups = [];
            foreach ($projects as $p) {
                if ($groupBy === 'date') {
                    $day = date('Y-m-d', strtotime($p['created_at']));
                    $key = $day;
                    $label = relativeDayLabel($p['created_at']);
                } else {
                    $key = $p['category'] !== '' ? $p['category'] : '__uncategorized__';
                    $label = $p['category'] !== '' ? $p['category'] : 'Uncategorized';
                }
                if (!isset($groups[$key])) {
                    $groups[$key] = ['label' => $label, 'rows' => []];
                }
                $groups[$key]['rows'][] = $p;
            }
            if ($groupBy === 'date') {
                krsort($groups);
            } else {
                uasort($groups, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));
            }
            ?>
            <div class="group-controls">
                <?php if (count($groups) > 1): ?>
                    <button type="button" class="btn btn--small btn--ghost" id="expand-all-btn">⊞ Expand All</button>
                    <button type="button" class="btn btn--small btn--ghost" id="collapse-all-btn">⊟ Collapse All</button>
                <?php endif; ?>
            </div>
            <?php foreach ($groups as $group): ?>
                <details class="vault-group" open>
                    <summary class="vault-group__summary">
                        <span><?= e($groupIcons[$groupKey]) ?> <?= e($group['label']) ?></span>
                        <span class="badge badge--role"><?= count($group['rows']) ?></span>
                    </summary>
                    <?php renderProjectSet($group['rows'], $viewMode, $isAdmin); ?>
                </details>
            <?php endforeach; ?>

        <?php if ($isAdmin): ?>
        <div class="form-actions">
            <button type="submit" class="btn btn--danger btn--small">Delete selected</button>
        </div>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<script>
    (function () {
        // One or more "select all" checkboxes (one per table when grouped by
        // date) -- any of them toggles every project checkbox on the page.
        document.querySelectorAll('.proj-check-all-group').forEach(function (all) {
            all.addEventListener('change', function () {
                document.querySelectorAll('.proj-check').forEach(function (cb) { cb.checked = all.checked; });
            });
        });
    })();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
