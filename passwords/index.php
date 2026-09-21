<?php
/**
 * Passwords list, filterable and groupable by project, category, and access role.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.view');

$pageTitle = 'Credential Vault';
$activePage = 'passwords';

$roles    = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
$projects = $db->query('SELECT id, name FROM projects ORDER BY name')->fetchAll();

$filterRole     = isset($_GET['role']) ? (int) $_GET['role'] : 0;
$filterProject  = isset($_GET['project']) ? (int) $_GET['project'] : 0;
$filterCategory = trim($_GET['category'] ?? '');
$filterType     = trim($_GET['type'] ?? '');
$filterSearch   = trim($_GET['q'] ?? '');
$groupBy      = in_array($_GET['group'] ?? '', ['project', 'category', 'role'], true) ? $_GET['group'] : '';
$viewMode     = ($_GET['view'] ?? '') === 'grid' ? 'grid' : 'table';
$gridUrl      = 'index.php?' . http_build_query(array_merge($_GET, ['view' => 'grid']));
$tableUrl     = 'index.php?' . http_build_query(array_merge($_GET, ['view' => 'table']));
// One-click toggle, same idea as the Projects page's role accordion: click to
// group by category, click again to go back to a flat list.
$categoryToggleUrl = 'index.php?' . http_build_query(array_merge($_GET, ['group' => $groupBy === 'category' ? '' : 'category']));

$sql = 'SELECT p.id, p.title, p.category, p.username, p.email, p.url, p.updated_at,
               p.project_id, p.assigned_to, pr_j.name AS project_name,
               u.username AS assigned_username, u.full_name AS assigned_full_name,
               GROUP_CONCAT(DISTINCT r.id, ":", r.name ORDER BY r.name SEPARATOR "||") AS role_pairs
          FROM passwords p
          LEFT JOIN projects pr_j ON pr_j.id = p.project_id
          LEFT JOIN users u ON u.id = p.assigned_to
          LEFT JOIN password_roles pr ON pr.password_id = p.id
          LEFT JOIN roles r ON r.id = pr.role_id
         WHERE 1=1';
$params = [];

if ($filterRole > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM password_roles pr2 WHERE pr2.password_id = p.id AND pr2.role_id = :role)';
    $params['role'] = $filterRole;
}
if ($filterProject > 0) {
    $sql .= ' AND p.project_id = :project';
    $params['project'] = $filterProject;
}
if ($filterCategory !== '') {
    $sql .= ' AND p.category = :category';
    $params['category'] = $filterCategory;
}
if ($filterType !== '') {
    // Titles are composed as "Role — System Name" (see extractLoginRole()); a
    // type match is a title prefix match on that same em-dash convention.
    $sql .= ' AND p.title LIKE :type';
    $params['type'] = $filterType . ' — %';
}
if ($filterSearch !== '') {
    $sql .= ' AND (p.title LIKE :q1 OR p.username LIKE :q2 OR p.url LIKE :q3)';
    $like = '%' . $filterSearch . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$sql .= ' GROUP BY p.id ORDER BY p.title ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$passwords = $stmt->fetchAll();

// Parse the "id:name||id:name" role_pairs string into a simple list of ['id'=>, 'name'=>] per row.
foreach ($passwords as &$row) {
    $row['roles'] = [];
    if ($row['role_pairs']) {
        foreach (explode('||', $row['role_pairs']) as $pair) {
            [$rid, $rname] = explode(':', $pair, 2);
            $row['roles'][] = ['id' => (int) $rid, 'name' => $rname];
        }
    }
}
unset($row);

/**
 * Render one credential row. Shared between the flat table and every
 * collapsible group so the markup only lives in one place.
 */
function renderPasswordRow(array $row): void
{
    $canAccess = canAccessCredential((int) $row['id']);
    // A multi-role credential is repeated across several groups in grouped
    // views, so the same id would otherwise produce duplicate DOM ids --
    // suffix every occurrence after the first to keep reveal targeting correct.
    static $seen = [];
    $seen[$row['id']] = ($seen[$row['id']] ?? -1) + 1;
    $domId = 'secret-row-' . (int) $row['id'] . ($seen[$row['id']] > 0 ? '-' . $seen[$row['id']] : '');
    ?>
    <?php
    $loginRole = extractLoginRole($row['title']);
    $hasLoginRole = $loginRole !== $row['title'];
    ?>
    <tr>
        <td><strong><a href="view.php?id=<?= (int) $row['id'] ?>"><?= categoryIcon((string) $row['category']) ?> <?= e($row['title']) ?></a></strong></td>
        <td><?= $hasLoginRole ? '<span class="badge badge--role">' . e($loginRole) . '</span>' : '—' ?></td>
        <td><?= $row['category'] ? '<a href="index.php?category=' . urlencode($row['category']) . '">' . e($row['category']) . '</a>' : '—' ?></td>
        <td><?= $row['project_name'] ? '<a href="' . BASE_URL . '/projects/view.php?id=' . (int) $row['project_id'] . '">' . e($row['project_name']) . '</a>' : '—' ?></td>
        <td>
            <?php if ($row['username']): ?>
                <?= e($row['username']) ?>
                <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['username']) ?>" title="Copy username">📋</button>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
        <td>
            <?php if ($row['email']): ?>
                <?= e($row['email']) ?>
                <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['email']) ?>" title="Copy email">📋</button>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
        <td>
            <?php if ($canAccess): ?>
                <span class="secret secret--row" id="<?= e($domId) ?>">••••••••</span>
                <?php if (hasPermission('passwords.view')): ?>
                    <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="<?= e($domId) ?>" title="Reveal">👁</button>
                    <button type="button" class="btn btn--small btn--ghost copy-row-btn" data-url="ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="<?= e($domId) ?>" title="Copy password">📋</button>
                <?php endif; ?>
            <?php else: ?>
                <span class="muted">🔒 Restricted</span>
            <?php endif; ?>
        </td>
        <td class="table__actions">
            <?php if ($canAccess && hasPermission('passwords.manage')): ?>
                <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $row['id'] ?>" title="Edit">✏️</a>
                <form class="inline-form" method="post" action="delete.php"
                      onsubmit="return confirm('Delete this credential?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn--small btn--ghost" title="Delete">🗑</button>
                </form>
            <?php elseif (!$canAccess): ?>
                <span class="muted">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/**
 * Card-grid rendering of one credential, same visual component used on the
 * Project detail page -- kept as one shared function so grid mode looks
 * identical everywhere it appears (flat grid, grouped sections, project view).
 */
function renderCredentialCard(array $row): void
{
    $canAccess = canAccessCredential((int) $row['id']);
    // See renderPasswordRow() -- same duplicate-id fix, needed because a
    // multi-role credential is repeated across the "Access level" groups.
    static $seen = [];
    $seen[$row['id']] = ($seen[$row['id']] ?? -1) + 1;
    $domId = 'secret-row-' . (int) $row['id'] . ($seen[$row['id']] > 0 ? '-' . $seen[$row['id']] : '');
    ?>
    <div class="credential-card<?= $canAccess ? '' : ' credential-card--restricted' ?>">
        <div class="credential-card__header">
            <span class="credential-card__icon"><?= categoryIcon((string) $row['category']) ?></span>
            <a class="credential-card__title" href="view.php?id=<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a>
        </div>

        <?php $loginRole = extractLoginRole($row['title']); ?>
        <div class="credential-card__meta">
            <?php if ($loginRole !== $row['title']): ?><span class="badge badge--role">🎭 <?= e($loginRole) ?></span><?php endif; ?>
            <?php if ($row['category']): ?><a class="badge badge--role" href="index.php?category=<?= urlencode($row['category']) ?>" title="View all in <?= e($row['category']) ?>"><?= e($row['category']) ?></a><?php endif; ?>
            <?php if ($row['project_name']): ?><a class="badge badge--role" href="<?= BASE_URL ?>/projects/view.php?id=<?= (int) $row['project_id'] ?>" title="Open project">📁 <?= e($row['project_name']) ?></a><?php endif; ?>
        </div>

        <dl class="credential-card__fields">
            <dt>Username</dt>
            <dd>
                <?php if ($row['username']): ?>
                    <?= e($row['username']) ?>
                    <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['username']) ?>" title="Copy username">📋</button>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>

            <dt>Email</dt>
            <dd>
                <?php if ($row['email']): ?>
                    <?= e($row['email']) ?>
                    <button type="button" class="btn btn--small btn--ghost copy-text-btn" data-copy="<?= e($row['email']) ?>" title="Copy email">📋</button>
                <?php else: ?>
                    —
                <?php endif; ?>
            </dd>

            <dt>Password</dt>
            <dd>
                <?php if ($canAccess): ?>
                    <span class="secret secret--row" id="<?= e($domId) ?>">••••••••</span>
                    <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="<?= e($domId) ?>" title="Reveal">👁</button>
                    <button type="button" class="btn btn--small btn--ghost copy-row-btn" data-url="ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="<?= e($domId) ?>" title="Copy password">📋</button>
                <?php else: ?>
                    <span class="muted">🔒 Restricted</span>
                <?php endif; ?>
            </dd>
        </dl>

        <?php if ($canAccess && hasPermission('passwords.manage')): ?>
            <div class="credential-card__actions">
                <a class="btn btn--small btn--ghost" href="edit.php?id=<?= (int) $row['id'] ?>">Edit</a>
                <form class="inline-form" method="post" action="delete.php"
                      onsubmit="return confirm('Delete this credential?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn--small btn--ghost">Delete</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * A password's group keys can be multi-valued (a credential can carry several
 * access roles), so grouping returns a list of [key, label] pairs per row
 * rather than a single key -- the row is repeated under each matching group.
 */
function groupKeysFor(array $row, string $groupBy): array
{
    switch ($groupBy) {
        case 'project':
            return [[$row['project_id'] ?: 0, $row['project_name'] ?: 'No project']];
        case 'category':
            $cat = $row['category'] ?: '';
            return [[$cat !== '' ? $cat : '(uncategorized)', $cat !== '' ? $cat : 'Uncategorized']];
        case 'role':
            if (!$row['roles']) {
                return [[0, 'Unrestricted (no access level set)']];
            }
            return array_map(static fn ($r) => [$r['id'], accessLabel($r['name'])], $row['roles']);
        default:
            return [];
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">🔐 Credential Vault</h2>
            <p class="muted" style="margin:4px 0 0">System URLs, IPs, router, server &amp; email account logins — access restricted by role.</p>
        </div>
        <div class="quick-actions quick-actions--row">
            <div class="view-toggle">
                <a class="btn btn--small<?= $viewMode === 'grid' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($gridUrl) ?>">▦ Grid</a>
                <a class="btn btn--small<?= $viewMode === 'table' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($tableUrl) ?>">☰ Table</a>
                <a class="btn btn--small<?= $groupBy === 'category' ? ' btn--primary' : ' btn--ghost' ?>" href="<?= e($categoryToggleUrl) ?>">🏷 By Category</a>
            </div>
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn" href="import.php">Import CSV</a>
            <?php endif; ?>
            <a class="btn" href="export.php">Export CSV</a>
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn btn--primary" href="create.php">+ New Credential</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="alert alert-vault">🛡 All credential reveals are logged to the audit trail. Never share vault access outside the IT team.</div>

    <form method="get" action="index.php" class="filters">
        <input type="hidden" name="view" value="<?= e($viewMode) ?>">
        <div class="form-group">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="<?= e($filterSearch) ?>" placeholder="System, username, URL/IP">
        </div>
        <div class="form-group">
            <label for="role">Access level</label>
            <select id="role" name="role">
                <option value="0">All levels</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $filterRole === (int) $r['id'] ? 'selected' : '' ?>><?= e(accessLabel($r['name'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="project">Project</label>
            <select id="project" name="project">
                <option value="0">All projects</option>
                <?php foreach ($projects as $pr): ?>
                    <option value="<?= (int) $pr['id'] ?>" <?= $filterProject === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                <?php foreach (categoryOptions() as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">All types</option>
                <?php foreach (existingLoginRoles() as $roleType): ?>
                    <option value="<?= e($roleType) ?>" <?= $filterType === $roleType ? 'selected' : '' ?>><?= e($roleType) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="group">Group by</label>
            <select id="group" name="group">
                <option value="">Flat list</option>
                <option value="project" <?= $groupBy === 'project' ? 'selected' : '' ?>>Project</option>
                <option value="category" <?= $groupBy === 'category' ? 'selected' : '' ?>>Category</option>
                <option value="role" <?= $groupBy === 'role' ? 'selected' : '' ?>>Access level</option>
            </select>
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn">Filter</button>
            <a class="btn btn--ghost" href="index.php">Reset</a>
        </div>
    </form>

    <?php
    /** Renders one flat set of credentials as a table or a card grid, per $viewMode. */
    function renderPasswordSet(array $rows, string $viewMode): void
    {
        if ($viewMode === 'table') {
            ?>
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>System</th>
                        <th>Role</th>
                        <th>Category</th>
                        <th>Project</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Password</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php renderPasswordRow($row); ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php
        } else {
            ?>
            <div class="credential-grid">
                <?php foreach ($rows as $row): ?>
                    <?php renderCredentialCard($row); ?>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }
    ?>

    <?php if (!$passwords): ?>
        <p class="muted">No credentials match your filters.</p>
    <?php elseif ($groupBy === ''): ?>
        <?php if (hasPermission('passwords.view')): ?>
            <div class="group-controls">
                <button type="button" class="btn btn--small btn--ghost" id="reveal-all-btn">👁 Reveal All</button>
                <button type="button" class="btn btn--small btn--ghost" id="hide-all-btn">🙈 Hide All</button>
            </div>
        <?php endif; ?>
        <?php renderPasswordSet($passwords, $viewMode); ?>
    <?php else: ?>
        <?php
        $groups = [];
        foreach ($passwords as $row) {
            foreach (groupKeysFor($row, $groupBy) as [$key, $label]) {
                if (!isset($groups[$key])) {
                    $groups[$key] = ['label' => $label, 'rows' => []];
                }
                $groups[$key]['rows'][] = $row;
            }
        }
        uasort($groups, static fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        ?>
        <div class="group-controls">
            <?php if (count($groups) > 1): ?>
                <button type="button" class="btn btn--small btn--ghost" id="expand-all-btn">⊞ Expand All</button>
                <button type="button" class="btn btn--small btn--ghost" id="collapse-all-btn">⊟ Collapse All</button>
            <?php endif; ?>
            <?php if (hasPermission('passwords.view')): ?>
                <button type="button" class="btn btn--small btn--ghost" id="reveal-all-btn">👁 Reveal All</button>
                <button type="button" class="btn btn--small btn--ghost" id="hide-all-btn">🙈 Hide All</button>
            <?php endif; ?>
        </div>
        <?php foreach ($groups as $group): ?>
            <details class="vault-group" open>
                <summary class="vault-group__summary">
                    <span><?= e($group['label']) ?></span>
                    <span class="badge badge--role"><?= count($group['rows']) ?></span>
                </summary>
                <?php renderPasswordSet($group['rows'], $viewMode); ?>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
