<?php
/**
 * Retired: this tool used to bulk-create a project + empty placeholder
 * credential for every htdocs folder, which left the vault cluttered with
 * blank entries. Removed for every role at the user's request.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

flash('error', 'The htdocs import tool has been removed.');
redirect(BASE_URL . '/projects/index.php');

$pageTitle = 'Import htdocs projects';
$activePage = 'projects';

$htdocs = rtrim((string) ($config['app']['htdocs_path'] ?? ''), '\\/');
if ($htdocs === '') {
    $htdocs = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
}
if ($htdocs === '' || $htdocs === false || !is_dir($htdocs)) {
    $htdocs = 'C:/xampp/htdocs';
}

function htdocsFolders(string $root): array
{
    $out = [];
    $entries = scandir($root);
    if ($entries === false) {
        return $out;
    }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..' || $name[0] === '.') {
            continue; // skip dot/hidden entries
        }
        $full = $root . DIRECTORY_SEPARATOR . $name;
        if (is_dir($full)) {
            $out[] = ['name' => $name, 'path' => $full];
        }
    }
    usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

// Existing project names + password titles, to mark what is already imported.
$existingProjects = [];
foreach ($db->query('SELECT name FROM projects')->fetchAll() as $p) {
    $existingProjects[$p['name']] = true;
}
$existingTitles = [];
foreach ($db->query('SELECT title FROM passwords')->fetchAll() as $p) {
    $existingTitles[$p['title']] = true;
}

$folders = htdocsFolders($htdocs);

$importedProjects = 0;
$importedEntries  = 0;
$skipped          = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Security token mismatch. Please try again.');
        redirect(BASE_URL . '/projects/index.php');
    }

    $selected = $_POST['folders'] ?? [];
    $insertProject = $db->prepare('INSERT INTO projects (name, description) VALUES (:name, :desc)');
    $insertPassword = $db->prepare(
        'INSERT INTO passwords (title, username, email, phone, encrypted, url, notes, extra_info, project_id, created_by)
         VALUES (:title, :username, :email, :phone, :encrypted, :url, :notes, :extra_info, :project_id, :created_by)'
    );

    $db->beginTransaction();
    try {
        foreach ($folders as $folder) {
            $name = $folder['name'];
            if (!in_array($name, $selected, true)) {
                continue;
            }

            $projectId = null;
            if (isset($existingProjects[$name])) {
                $stmt = $db->prepare('SELECT id FROM projects WHERE name = :name');
                $stmt->execute(['name' => $name]);
                $projectId = (int) $stmt->fetchColumn();
            } else {
                $insertProject->execute(['name' => $name, 'desc' => 'Imported from htdocs folder']);
                $projectId = (int) $db->lastInsertId();
                $existingProjects[$name] = true;
                $importedProjects++;
            }

            if (isset($existingTitles[$name])) {
                $skipped++;
                continue;
            }

            $insertPassword->execute([
                'title'      => $name,
                'username'   => '',
                'email'      => '',
                'phone'      => '',
                'encrypted'  => encrypt_password('', $appConfig),
                'url'        => '',
                'notes'      => 'Imported from htdocs folder: ' . $folder['path'],
                'extra_info' => null,
                'project_id' => $projectId,
                'created_by' => currentUser()['id'],
            ]);
            $existingTitles[$name] = true;
            $importedEntries++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash('error', 'Import failed. No changes were written.');
        redirect(BASE_URL . '/tools/import_htdocs.php');
    }

    flash('success', "htdocs import done: $importedProjects project(s) and $importedEntries password entry/entries created, $skipped already present.");
    redirect(BASE_URL . '/tools/import_htdocs.php');
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <h2 class="card__title">Import projects from htdocs</h2>
        <div>
            <a class="btn btn--small" href="<?= BASE_URL ?>/projects/index.php">&larr; Projects</a>
            <a class="btn btn--small" href="import_htdocs.php">Refresh list</a>
        </div>
    </div>

    <p class="muted">
        Scanning folder: <strong><?= e($htdocs) ?></strong> &mdash; <?= count($folders) ?> folder(s) found.
        Each imported folder becomes a project plus an empty password entry you can fill in later.
        Folders already in the locker are <span class="badge badge--success">imported</span>.
    </p>

    <?php if (!$folders): ?>
        <p class="muted">No folders were found in the htdocs directory.</p>
    <?php else: ?>
        <form method="post" action="import_htdocs.php">
            <?= csrf_field() ?>

            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="check-all" checked></th>
                        <th>Folder</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($folders as $folder): ?>
                    <?php
                    $already = isset($existingProjects[$folder['name']]) || isset($existingTitles[$folder['name']]);
                    ?>
                    <tr>
                        <td><input type="checkbox" name="folders[]" value="<?= e($folder['name']) ?>" <?= $already ? '' : 'checked' ?>></td>
                        <td><strong><?= e($folder['name']) ?></strong></td>
                        <td>
                            <?php if ($already): ?>
                                <span class="badge badge--success">Imported</span>
                            <?php else: ?>
                                <span class="badge badge--role">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Import selected folders</button>
                <a class="btn btn--ghost" href="<?= BASE_URL ?>/projects/index.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
    (function () {
        var all = document.getElementById('check-all');
        if (!all) { return; }
        all.addEventListener('change', function () {
            document.querySelectorAll('input[name="folders[]"]').forEach(function (cb) { cb.checked = all.checked; });
        });
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
