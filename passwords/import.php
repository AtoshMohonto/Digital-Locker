<?php
/**
 * Import password entries from a CSV file.
 * Two-step flow (PRG): upload+parse -> preview -> confirm insert.
 * Requires passwords.manage.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$pageTitle = 'Import CSV';
$activePage = 'passwords';

const CSV_COLUMNS = ['title', 'category', 'username', 'password', 'url', 'notes', 'recovery_info', 'assigned_to', 'access_roles'];
const MAX_ROWS = 2000;

function sendTemplate(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="digital-locker-passwords-template.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, CSV_COLUMNS);
    fputcsv($out, [
        'App Server (Production)', 'Server', 'root', 'Str0ng!Pa55', '10.0.0.15',
        'Created via CSV import', 'Recovery code: ABC-123', '', 'Administrator',
    ]);
    fclose($out);
    exit;
}

if (isset($_GET['template'])) {
    requireLogin();
    sendTemplate();
}

// Step 3: actually insert the rows the user selected in the preview.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Security token mismatch. Please try again.');
        redirect(BASE_URL . '/passwords/import.php');
    }

    $rows = $_SESSION['csv_import_rows'] ?? [];
    $selected = array_map('intval', $_POST['rows'] ?? []);

    if (!$rows || !$selected) {
        flash('error', 'Nothing was selected to import.');
        redirect(BASE_URL . '/passwords/import.php');
    }

    $userCache = [];
    $roleCache = [];
    $imported  = 0;
    $skipped   = 0;

    $findUser = function (string $name) use ($db, &$userCache): ?int {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (isset($userCache[$name])) {
            return $userCache[$name];
        }
        $stmt = $db->prepare('SELECT id FROM users WHERE username = :name OR full_name = :name');
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();
        $userCache[$name] = $id === false ? null : (int) $id;
        return $userCache[$name];
    };

    $findRoleIds = function (string $names) use ($db, &$roleCache): array {
        $ids = [];
        foreach (preg_split('/[;,]/', $names) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (!isset($roleCache[$name])) {
                $stmt = $db->prepare('SELECT id FROM roles WHERE name = :name');
                $stmt->execute(['name' => $name]);
                $id = $stmt->fetchColumn();
                $roleCache[$name] = $id === false ? null : (int) $id;
            }
            if ($roleCache[$name] !== null) {
                $ids[] = $roleCache[$name];
            }
        }
        return array_unique($ids);
    };

    $insert = $db->prepare(
        'INSERT INTO passwords (title, category, username, encrypted, url, notes, extra_info, assigned_to, created_by)
         VALUES (:title, :category, :username, :encrypted, :url, :notes, :extra_info, :assigned_to, :created_by)'
    );
    $insertRole = $db->prepare('INSERT IGNORE INTO password_roles (password_id, role_id) VALUES (:pid, :rid)');

    $db->beginTransaction();
    try {
        foreach ($rows as $index => $row) {
            if (!in_array($index, $selected, true)) {
                continue;
            }
            if (trim($row['title']) === '' || trim($row['password']) === '') {
                $skipped++;
                continue;
            }
            $insert->execute([
                'title'       => trim($row['title']),
                'category'    => trim($row['category']),
                'username'    => trim($row['username']),
                'encrypted'   => encrypt_password($row['password'], $appConfig),
                'url'         => trim($row['url']),
                'notes'       => trim($row['notes']) !== '' ? trim($row['notes']) : null,
                'extra_info'  => trim($row['recovery_info']) !== '' ? encrypt_password(trim($row['recovery_info']), $appConfig) : null,
                'assigned_to' => $findUser($row['assigned_to']),
                'created_by'  => currentUser()['id'],
            ]);
            $passwordId = (int) $db->lastInsertId();
            foreach ($findRoleIds($row['access_roles']) as $roleId) {
                $insertRole->execute(['pid' => $passwordId, 'rid' => $roleId]);
            }
            $imported++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash('error', 'Import failed. No rows were written.');
        redirect(BASE_URL . '/passwords/import.php');
    }

    unset($_SESSION['csv_import_rows']);
    flash('success', "Import finished: $imported added, $skipped skipped (missing title or password).");
    redirect(BASE_URL . '/passwords/index.php');
}

// Step 2: show the preview of parsed rows stored in the session.
if (isset($_GET['preview'])) {
    $rows = $_SESSION['csv_import_rows'] ?? [];
    if (!$rows) {
        flash('error', 'No parsed data found. Please upload the CSV again.');
        redirect(BASE_URL . '/passwords/import.php');
    }
    $hasErrors = false;
    foreach ($rows as $row) {
        if (trim($row['title']) === '' || trim($row['password']) === '') {
            $hasErrors = true;
            break;
        }
    }

    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="card">
        <div class="card__header card__header--stack">
            <h2 class="card__title">Preview — <?= count($rows) ?> row(s) parsed</h2>
            <div>
                <a class="btn btn--small" href="index.php">&larr; Back to vault</a>
                <a class="btn btn--small" href="import.php">Upload another file</a>
            </div>
        </div>

        <?php if ($hasErrors): ?>
            <div class="alert alert-error">
                Some rows are missing a <strong>title</strong> or <strong>password</strong> and will be skipped during import.
            </div>
        <?php endif; ?>

        <form method="post" action="import.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">

            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="check-all" checked></th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Username</th>
                        <th>Assigned To</th>
                        <th>Access Roles</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $index => $row): ?>
                    <?php
                    $invalid = trim($row['title']) === '' || trim($row['password']) === '';
                    ?>
                    <tr<?= $invalid ? ' class="row-invalid"' : '' ?>>
                        <td><input type="checkbox" name="rows[]" value="<?= (int) $index ?>" <?= $invalid ? '' : 'checked' ?>></td>
                        <td><?= e($row['title']) ?><?= $invalid ? ' <span class="badge badge--danger">skip</span>' : '' ?></td>
                        <td><?= e($row['category']) ?: '—' ?></td>
                        <td><?= e($row['username']) ?></td>
                        <td><?= e($row['assigned_to']) ?: '—' ?></td>
                        <td><?= e($row['access_roles']) ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Import selected rows</button>
                <a class="btn btn--ghost" href="import.php">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        (function () {
            var all = document.getElementById('check-all');
            if (!all) { return; }
            all.addEventListener('change', function () {
                document.querySelectorAll('input[name="rows[]"]').forEach(function (cb) { cb.checked = all.checked; });
            });
        })();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// Step 1: accept the uploaded file and parse it.
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file to upload.';
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            $errors[] = 'Unable to read the uploaded file.';
        } else {
            $raw = [];
            while (($line = fgetcsv($handle)) !== false) {
                $raw[] = array_map('trim', $line);
            }
            fclose($handle);

            if (!$raw) {
                $errors[] = 'The uploaded file is empty.';
            } else {
                // Detect a header row: it must contain the "title" column.
                $header = null;
                $first = array_map('strtolower', $raw[0]);
                if (in_array('title', $first, true)) {
                    $header = $raw[0];
                    array_shift($raw);
                }

                $rows = [];
                foreach ($raw as $line) {
                    if (count(array_filter($line, fn ($v) => $v !== '')) === 0) {
                        continue; // skip blank lines
                    }
                    $item = array_fill_keys(CSV_COLUMNS, '');
                    if ($header !== null) {
                        foreach ($header as $i => $col) {
                            $col = strtolower(trim($col));
                            if (in_array($col, CSV_COLUMNS, true) && isset($line[$i])) {
                                $item[$col] = $line[$i];
                            }
                        }
                    } else {
                        foreach (CSV_COLUMNS as $i => $col) {
                            if (isset($line[$i])) {
                                $item[$col] = $line[$i];
                            }
                        }
                    }
                    $rows[] = $item;
                }

                if (!$rows) {
                    $errors[] = 'No usable rows found in the file. Use the template below for the expected columns.';
                } elseif (count($rows) > MAX_ROWS) {
                    $errors[] = 'Too many rows (max ' . MAX_ROWS . '). Please split the file.';
                } else {
                    $_SESSION['csv_import_rows'] = $rows;
                    redirect(BASE_URL . '/passwords/import.php?preview=1');
                }
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Import passwords from CSV</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <p class="muted">
        Expected columns (header optional):
        <code>title, category, username, password, url, notes, recovery_info, assigned_to, access_roles</code>.
        <code>assigned_to</code> is matched by username or full name; <code>access_roles</code> accepts one or more
        role names separated by <code>;</code> (e.g. <code>Administrator; Manager</code>) — this matches the
        format produced by Export CSV, so exported files can be re-imported as-is.
        Download the <a href="import.php?template=1">CSV template</a> to see the format.
    </p>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="import.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">

        <div class="form-group">
            <label for="csv_file">CSV file *</label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Upload &amp; preview</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
