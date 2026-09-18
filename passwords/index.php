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

$filterRole   = isset($_GET['role']) ? (int) $_GET['role'] : 0;
$filterSearch = trim($_GET['q'] ?? '');
$groupBy      = in_array($_GET['group'] ?? '', ['project', 'category', 'role'], true) ? $_GET['group'] : '';

$sql = 'SELECT p.id, p.title, p.category, p.username, p.url, p.updated_at,
               p.project_id, pr_j.name AS project_name,
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
if ($filterSearch !== '') {
    $sql .= ' AND (p.title LIKE :q OR p.username LIKE :q OR p.url LIKE :q)';
    $params['q'] = '%' . $filterSearch . '%';
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
    ?>
    <tr>
        <td><strong><a href="view.php?id=<?= (int) $row['id'] ?>"><?= categoryIcon((string) $row['category']) ?> <?= e($row['title']) ?></a></strong></td>
        <td><?= e($row['category'] ?: '—') ?></td>
        <td><?= e($row['project_name'] ?: '—') ?></td>
        <td><?= e($row['url'] ?: '—') ?></td>
        <td><?= e($row['username'] ?: '—') ?></td>
        <td><?= e($row['assigned_full_name'] ?: $row['assigned_username'] ?: 'Unassigned') ?></td>
        <td>
            <?php if ($row['roles']): ?>
                <?php foreach ($row['roles'] as $role): ?>
                    <span class="badge <?= $role['name'] === 'Administrator' ? 'badge--danger' : 'badge--role' ?>"><?= e(accessLabel($role['name'])) ?></span>
                <?php endforeach; ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </td>
        <td>
            <?php if ($canAccess): ?>
                <span class="secret secret--row" id="secret-row-<?= (int) $row['id'] ?>">••••••••</span>
                <?php if (hasPermission('passwords.view')): ?>
                    <button type="button" class="btn btn--small btn--ghost reveal-row-btn" data-url="ajax.php?reveal=<?= (int) $row['id'] ?>" data-target="secret-row-<?= (int) $row['id'] ?>" title="Reveal">👁</button>
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

    <?php if (!$passwords): ?>
        <p class="muted">No credentials match your filters.</p>
    <?php elseif ($groupBy === ''): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>System</th>
                    <th>Category</th>
                    <th>Project</th>
                    <th>URL / IP</th>
                    <th>Username</th>
                    <th>Assigned To</th>
                    <th>Access</th>
                    <th>Password</th>
                    <th class="table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($passwords as $row): ?>
                <?php renderPasswordRow($row); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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
        <?php foreach ($groups as $group): ?>
            <details class="vault-group" open>
                <summary class="vault-group__summary">
                    <span><?= e($group['label']) ?></span>
                    <span class="badge badge--role"><?= count($group['rows']) ?></span>
                </summary>
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>System</th>
                            <th>Category</th>
                            <th>Project</th>
                            <th>URL / IP</th>
                            <th>Username</th>
                            <th>Assigned To</th>
                            <th>Access</th>
                            <th>Password</th>
                            <th class="table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['rows'] as $row): ?>
                        <?php renderPasswordRow($row); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
