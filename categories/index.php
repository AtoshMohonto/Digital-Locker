<?php
/**
 * Project categories & types: create new ones, and mark + delete ones you no
 * longer need. Administrator only -- these are catalogue-level lists, like
 * Roles. Deleting an entry only removes it from its picker; projects that
 * already used it keep that text value (it just won't be offered going
 * forward). Categories and Types are two independent classifications a
 * project can carry at once (e.g. Category "Client", Type "Web App").
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

if (!isAdministrator()) {
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Project Categories & Types';
$activePage = 'categories';

/** Shared upload handler for a category/type icon: emoji field + optional image upload. */
function handleIconUpload(string $uploadSubdir, string $typedIcon, string $defaultIcon): array
{
    $icon = $typedIcon ?: $defaultIcon;
    if (empty($_FILES['icon_file']['name'])) {
        return [$icon, null];
    }
    $uploaded = $_FILES['icon_file'];
    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
    ];
    if ($uploaded['error'] !== UPLOAD_ERR_OK) {
        return [$icon, 'Icon upload failed. Please try again.'];
    }
    if ($uploaded['size'] > 512 * 1024) {
        return [$icon, 'Icon image must be 512KB or smaller.'];
    }
    $mime = mime_content_type($uploaded['tmp_name']);
    if (!isset($allowed[$mime])) {
        return [$icon, 'Icon must be a JPG, PNG, GIF, WEBP, or ICO image.'];
    }
    $uploadDir = __DIR__ . '/../assets/uploads/' . $uploadSubdir;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($uploaded['tmp_name'], $uploadDir . '/' . $filename)) {
        return [$icon, 'Could not save the uploaded icon.'];
    }
    return ['assets/uploads/' . $uploadSubdir . '/' . $filename, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Security token mismatch. Please try again.');
        redirect(BASE_URL . '/categories/index.php');
    }

    $do = $_POST['do'] ?? '';

    if ($do === 'create_category') {
        $name = trim($_POST['name'] ?? '');
        [$icon, $uploadError] = handleIconUpload('categories', trim($_POST['icon'] ?? ''), '📁');

        if ($uploadError) {
            flash('error', $uploadError);
        } elseif ($name === '') {
            flash('error', 'Category name is required.');
        } else {
            try {
                $db->prepare('INSERT INTO project_categories (name, icon) VALUES (:name, :icon)')
                    ->execute(['name' => $name, 'icon' => $icon]);
                flash('success', 'Category created.');
            } catch (PDOException $e) {
                flash('error', 'A category with that name already exists.');
            }
        }
        redirect(BASE_URL . '/categories/index.php');
    }

    if ($do === 'delete_selected_categories') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM project_categories WHERE id IN ($placeholders)")->execute($ids);
            flash('success', count($ids) . ' categor' . (count($ids) === 1 ? 'y' : 'ies') . ' deleted.');
        } else {
            flash('error', 'Nothing selected.');
        }
        redirect(BASE_URL . '/categories/index.php');
    }

    if ($do === 'create_type') {
        $name = trim($_POST['name'] ?? '');
        [$icon, $uploadError] = handleIconUpload('types', trim($_POST['icon'] ?? ''), '🏷️');

        if ($uploadError) {
            flash('error', $uploadError);
        } elseif ($name === '') {
            flash('error', 'Type name is required.');
        } else {
            try {
                $db->prepare('INSERT INTO project_types (name, icon) VALUES (:name, :icon)')
                    ->execute(['name' => $name, 'icon' => $icon]);
                flash('success', 'Type created.');
            } catch (PDOException $e) {
                flash('error', 'A type with that name already exists.');
            }
        }
        redirect(BASE_URL . '/categories/index.php');
    }

    if ($do === 'delete_selected_types') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM project_types WHERE id IN ($placeholders)")->execute($ids);
            flash('success', count($ids) . ' type' . (count($ids) === 1 ? '' : 's') . ' deleted.');
        } else {
            flash('error', 'Nothing selected.');
        }
        redirect(BASE_URL . '/categories/index.php');
    }
}

$categories = $db->query(
    "SELECT pc.id, pc.name, pc.icon,
            (SELECT COUNT(*) FROM projects p WHERE p.category = pc.name) AS project_count,
            (SELECT COUNT(DISTINCT pm.user_id) FROM project_members pm
               JOIN projects p ON p.id = pm.project_id
              WHERE p.category = pc.name AND pm.project_role = 'manager') AS manager_count,
            (SELECT COUNT(DISTINCT pm.user_id) FROM project_members pm
               JOIN projects p ON p.id = pm.project_id
              WHERE p.category = pc.name AND pm.project_role = 'tester') AS tester_count
       FROM project_categories pc
      ORDER BY pc.name"
)->fetchAll();

$types = $db->query(
    "SELECT pt.id, pt.name, pt.icon,
            (SELECT COUNT(*) FROM projects p WHERE p.type = pt.name) AS project_count,
            (SELECT COUNT(DISTINCT pm.user_id) FROM project_members pm
               JOIN projects p ON p.id = pm.project_id
              WHERE p.type = pt.name AND pm.project_role = 'manager') AS manager_count,
            (SELECT COUNT(DISTINCT pm.user_id) FROM project_members pm
               JOIN projects p ON p.id = pm.project_id
              WHERE p.type = pt.name AND pm.project_role = 'tester') AS tester_count
       FROM project_types pt
      ORDER BY pt.name"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">Project Categories</h2>
            <p class="muted" style="margin:4px 0 0">Mark and delete categories you don't need, or add new ones below.</p>
        </div>
        <a class="btn btn--small" href="<?= BASE_URL ?>/projects/index.php">&larr; Projects</a>
    </div>

    <?php if ($categories): ?>
        <form method="post" action="index.php" onsubmit="return confirm('Delete the selected categories? Projects already using them keep their label, but it won\'t be offered going forward.');">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_selected_categories">
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:36px"><input type="checkbox" id="cat-check-all"></th>
                        <th>Category</th>
                        <th>Projects</th>
                        <th>Managers</th>
                        <th>Testers</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" class="cat-check"></td>
                        <td><?= projectCategoryIconHtml($c['name']) ?> <?= e($c['name']) ?></td>
                        <td><a href="<?= BASE_URL ?>/projects/index.php?category=<?= urlencode($c['name']) ?>"><?= (int) $c['project_count'] ?> project<?= (int) $c['project_count'] === 1 ? '' : 's' ?></a></td>
                        <td><?= (int) $c['manager_count'] ?> running</td>
                        <td><?= (int) $c['tester_count'] ?> running</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--danger btn--small">Delete selected</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">No categories yet.</p>
    <?php endif; ?>

    <form method="post" action="index.php" enctype="multipart/form-data" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="create_category">
        <div class="form-inline-row">
            <input type="text" name="icon" placeholder="📁" maxlength="8" style="width:70px;flex:none;text-align:center">
            <input type="text" name="name" placeholder="New category name…" required style="flex:2">
            <button type="submit" class="btn btn--small btn--primary">+ Add category</button>
        </div>
        <div class="form-group" style="margin-top:10px;margin-bottom:0">
            <label for="cat_icon_file" class="muted" style="font-weight:400">Or upload an icon image <span class="muted">(favicon, JPG, PNG, GIF, or WEBP -- max 512KB; overrides the emoji above)</span></label>
            <input type="file" id="cat_icon_file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
        </div>
    </form>
</div>

<div class="card">
    <div class="card__header card__header--stack">
        <div>
            <h2 class="card__title">Project Types</h2>
            <p class="muted" style="margin:4px 0 0">A second, independent classification (e.g. Web App, Mobile App) -- pick both a Category and a Type on a project.</p>
        </div>
    </div>

    <?php if ($types): ?>
        <form method="post" action="index.php" onsubmit="return confirm('Delete the selected types? Projects already using them keep their label, but it won\'t be offered going forward.');">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_selected_types">
            <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:36px"><input type="checkbox" id="type-check-all"></th>
                        <th>Type</th>
                        <th>Projects</th>
                        <th>Managers</th>
                        <th>Testers</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($types as $t): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" class="type-check"></td>
                        <td><?= projectTypeIconHtml($t['name']) ?> <?= e($t['name']) ?></td>
                        <td><a href="<?= BASE_URL ?>/projects/index.php?type=<?= urlencode($t['name']) ?>"><?= (int) $t['project_count'] ?> project<?= (int) $t['project_count'] === 1 ? '' : 's' ?></a></td>
                        <td><?= (int) $t['manager_count'] ?> running</td>
                        <td><?= (int) $t['tester_count'] ?> running</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--danger btn--small">Delete selected</button>
            </div>
        </form>
    <?php else: ?>
        <p class="muted">No types yet.</p>
    <?php endif; ?>

    <form method="post" action="index.php" enctype="multipart/form-data" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="create_type">
        <div class="form-inline-row">
            <input type="text" name="icon" placeholder="🏷️" maxlength="8" style="width:70px;flex:none;text-align:center">
            <input type="text" name="name" placeholder="New type name…" required style="flex:2">
            <button type="submit" class="btn btn--small btn--primary">+ Add type</button>
        </div>
        <div class="form-group" style="margin-top:10px;margin-bottom:0">
            <label for="type_icon_file" class="muted" style="font-weight:400">Or upload an icon image <span class="muted">(favicon, JPG, PNG, GIF, or WEBP -- max 512KB; overrides the emoji above)</span></label>
            <input type="file" id="type_icon_file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
        </div>
    </form>
</div>

<script>
    (function () {
        var catAll = document.getElementById('cat-check-all');
        if (catAll) {
            catAll.addEventListener('change', function () {
                document.querySelectorAll('.cat-check').forEach(function (cb) { cb.checked = catAll.checked; });
            });
        }
        var typeAll = document.getElementById('type-check-all');
        if (typeAll) {
            typeAll.addEventListener('change', function () {
                document.querySelectorAll('.type-check').forEach(function (cb) { cb.checked = typeAll.checked; });
            });
        }
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
