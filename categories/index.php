<?php
/**
 * Project Categories & Types: three independent catalogues (Project
 * Categories, Project Types, and Credential Types) managed from one page.
 * Everyone can view it; an Administrator or Manager can add/edit entries;
 * only an Administrator can delete one. Deleting an entry only removes it
 * from its picker -- anything already using it keeps that text value, it
 * just won't be offered going forward.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$isAdmin = isAdministrator();
$canManage = false;
foreach (userRoleNames() as $role) {
    if ($role['name'] === 'Manager') {
        $canManage = true;
        break;
    }
}
$canManage = $canManage || $isAdmin;

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
    $mutations = ['create_category', 'edit_category', 'create_type', 'edit_type', 'create_credtype', 'edit_credtype'];
    $deletions = ['delete_selected_categories', 'delete_selected_types', 'delete_selected_credtypes'];

    if (in_array($do, $mutations, true) && !$canManage) {
        require __DIR__ . '/../403.php';
        exit;
    }
    if (in_array($do, $deletions, true) && !$isAdmin) {
        require __DIR__ . '/../403.php';
        exit;
    }

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

    if ($do === 'edit_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        [$icon, $uploadError] = handleIconUpload('categories', trim($_POST['icon'] ?? ''), '📁');
        if ($uploadError) {
            flash('error', $uploadError);
        } elseif ($name === '') {
            flash('error', 'Category name is required.');
        } else {
            try {
                $db->prepare('UPDATE project_categories SET name = :name, icon = :icon WHERE id = :id')
                    ->execute(['name' => $name, 'icon' => $icon, 'id' => $id]);
                flash('success', 'Category updated.');
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

    if ($do === 'edit_type') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        [$icon, $uploadError] = handleIconUpload('types', trim($_POST['icon'] ?? ''), '🏷️');
        if ($uploadError) {
            flash('error', $uploadError);
        } elseif ($name === '') {
            flash('error', 'Type name is required.');
        } else {
            try {
                $db->prepare('UPDATE project_types SET name = :name, icon = :icon WHERE id = :id')
                    ->execute(['name' => $name, 'icon' => $icon, 'id' => $id]);
                flash('success', 'Type updated.');
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

    if ($do === 'create_credtype') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Type name is required.');
        } else {
            try {
                $db->prepare('INSERT INTO credential_types (name) VALUES (:name)')->execute(['name' => $name]);
                flash('success', 'Credential type created.');
            } catch (PDOException $e) {
                flash('error', 'A credential type with that name already exists.');
            }
        }
        redirect(BASE_URL . '/categories/index.php');
    }

    if ($do === 'edit_credtype') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Type name is required.');
        } else {
            try {
                $db->prepare('UPDATE credential_types SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $id]);
                flash('success', 'Credential type updated.');
            } catch (PDOException $e) {
                flash('error', 'A credential type with that name already exists.');
            }
        }
        redirect(BASE_URL . '/categories/index.php');
    }

    if ($do === 'delete_selected_credtypes') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM credential_types WHERE id IN ($placeholders)")->execute($ids);
            flash('success', count($ids) . ' credential type' . (count($ids) === 1 ? '' : 's') . ' deleted.');
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

$credTypes = $db->query(
    "SELECT ct.id, ct.name,
            (SELECT COUNT(*) FROM passwords p WHERE p.title LIKE CONCAT(ct.name, ' — %')) AS credential_count
       FROM credential_types ct
      ORDER BY ct.name"
)->fetchAll();

$editCategoryId = isset($_GET['edit_category']) ? (int) $_GET['edit_category'] : 0;
$editTypeId = isset($_GET['edit_type']) ? (int) $_GET['edit_type'] : 0;
$editCredTypeId = isset($_GET['edit_credtype']) ? (int) $_GET['edit_credtype'] : 0;

$editCategory = null;
foreach ($categories as $c) {
    if ((int) $c['id'] === $editCategoryId) {
        $editCategory = $c;
        break;
    }
}
$editType = null;
foreach ($types as $t) {
    if ((int) $t['id'] === $editTypeId) {
        $editType = $t;
        break;
    }
}
$editCredType = null;
foreach ($credTypes as $ct) {
    if ((int) $ct['id'] === $editCredTypeId) {
        $editCredType = $ct;
        break;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<details class="vault-group" open>
    <summary class="vault-group__summary">
        <span class="card__title">Project Categories</span>
    </summary>
    <div style="padding:16px 20px">
        <p class="muted" style="margin:0 0 12px">Mark and delete categories you don't need, or add new ones below.</p>

        <?php if ($categories): ?>
            <form method="post" action="index.php" onsubmit="return confirm('Delete the selected categories? Projects already using them keep their label, but it won\'t be offered going forward.');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete_selected_categories">
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <?php if ($isAdmin): ?><th style="width:36px"><input type="checkbox" id="cat-check-all"></th><?php endif; ?>
                            <th>Category</th>
                            <th>Projects</th>
                            <th>Managers</th>
                            <th>Testers</th>
                            <?php if ($canManage): ?><th class="table__actions">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <?php if ($isAdmin): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" class="cat-check"></td><?php endif; ?>
                            <td><?= projectCategoryIconHtml($c['name']) ?> <?= e($c['name']) ?></td>
                            <td><a href="<?= BASE_URL ?>/projects/index.php?category=<?= urlencode($c['name']) ?>"><?= (int) $c['project_count'] ?> project<?= (int) $c['project_count'] === 1 ? '' : 's' ?></a></td>
                            <td><?= (int) $c['manager_count'] ?> running</td>
                            <td><?= (int) $c['tester_count'] ?> running</td>
                            <?php if ($canManage): ?>
                                <td class="table__actions"><a class="btn btn--small btn--ghost" href="index.php?edit_category=<?= (int) $c['id'] ?>#edit-category">✏️ Edit</a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($isAdmin): ?>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--danger btn--small">Delete selected</button>
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <p class="muted">No categories yet.</p>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <?php if ($editCategory): ?>
                <form method="post" action="index.php" enctype="multipart/form-data" id="edit-category" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="edit_category">
                    <input type="hidden" name="id" value="<?= (int) $editCategory['id'] ?>">
                    <p class="muted" style="margin:0 0 8px">Editing "<?= e($editCategory['name']) ?>"</p>
                    <div class="form-inline-row">
                        <input type="text" name="icon" value="<?= e(strpos($editCategory['icon'], '/') === false ? $editCategory['icon'] : '') ?>" placeholder="📁" maxlength="8" style="width:70px;flex:none;text-align:center">
                        <input type="text" name="name" value="<?= e($editCategory['name']) ?>" required style="flex:2">
                        <button type="submit" class="btn btn--small btn--primary">Save changes</button>
                        <a class="btn btn--small btn--ghost" href="index.php">Cancel</a>
                    </div>
                    <div class="form-group" style="margin-top:10px;margin-bottom:0">
                        <label class="muted" style="font-weight:400">Or upload a new icon image <span class="muted">(replaces the current one)</span></label>
                        <input type="file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
                    </div>
                </form>
            <?php else: ?>
                <form method="post" action="index.php" enctype="multipart/form-data" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="create_category">
                    <div class="form-inline-row">
                        <input type="text" name="icon" placeholder="📁" maxlength="8" style="width:70px;flex:none;text-align:center">
                        <input type="text" name="name" placeholder="New category name…" required style="flex:2">
                        <button type="submit" class="btn btn--small btn--primary">+ Add category</button>
                    </div>
                    <div class="form-group" style="margin-top:10px;margin-bottom:0">
                        <label class="muted" style="font-weight:400">Or upload an icon image <span class="muted">(favicon, JPG, PNG, GIF, or WEBP -- max 512KB; overrides the emoji above)</span></label>
                        <input type="file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>

<details class="vault-group" open>
    <summary class="vault-group__summary">
        <span class="card__title">Project Types</span>
    </summary>
    <div style="padding:16px 20px">
        <p class="muted" style="margin:0 0 12px">A second, independent classification (e.g. Web App, Mobile App) -- pick both a Category and a Type on a project.</p>

        <?php if ($types): ?>
            <form method="post" action="index.php" onsubmit="return confirm('Delete the selected types? Projects already using them keep their label, but it won\'t be offered going forward.');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete_selected_types">
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <?php if ($isAdmin): ?><th style="width:36px"><input type="checkbox" id="type-check-all"></th><?php endif; ?>
                            <th>Type</th>
                            <th>Projects</th>
                            <th>Managers</th>
                            <th>Testers</th>
                            <?php if ($canManage): ?><th class="table__actions">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($types as $t): ?>
                        <tr>
                            <?php if ($isAdmin): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $t['id'] ?>" class="type-check"></td><?php endif; ?>
                            <td><?= projectTypeIconHtml($t['name']) ?> <?= e($t['name']) ?></td>
                            <td><a href="<?= BASE_URL ?>/projects/index.php?type=<?= urlencode($t['name']) ?>"><?= (int) $t['project_count'] ?> project<?= (int) $t['project_count'] === 1 ? '' : 's' ?></a></td>
                            <td><?= (int) $t['manager_count'] ?> running</td>
                            <td><?= (int) $t['tester_count'] ?> running</td>
                            <?php if ($canManage): ?>
                                <td class="table__actions"><a class="btn btn--small btn--ghost" href="index.php?edit_type=<?= (int) $t['id'] ?>#edit-type">✏️ Edit</a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($isAdmin): ?>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--danger btn--small">Delete selected</button>
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <p class="muted">No types yet.</p>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <?php if ($editType): ?>
                <form method="post" action="index.php" enctype="multipart/form-data" id="edit-type" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="edit_type">
                    <input type="hidden" name="id" value="<?= (int) $editType['id'] ?>">
                    <p class="muted" style="margin:0 0 8px">Editing "<?= e($editType['name']) ?>"</p>
                    <div class="form-inline-row">
                        <input type="text" name="icon" value="<?= e(strpos($editType['icon'], '/') === false ? $editType['icon'] : '') ?>" placeholder="🏷️" maxlength="8" style="width:70px;flex:none;text-align:center">
                        <input type="text" name="name" value="<?= e($editType['name']) ?>" required style="flex:2">
                        <button type="submit" class="btn btn--small btn--primary">Save changes</button>
                        <a class="btn btn--small btn--ghost" href="index.php">Cancel</a>
                    </div>
                    <div class="form-group" style="margin-top:10px;margin-bottom:0">
                        <label class="muted" style="font-weight:400">Or upload a new icon image <span class="muted">(replaces the current one)</span></label>
                        <input type="file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
                    </div>
                </form>
            <?php else: ?>
                <form method="post" action="index.php" enctype="multipart/form-data" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="create_type">
                    <div class="form-inline-row">
                        <input type="text" name="icon" placeholder="🏷️" maxlength="8" style="width:70px;flex:none;text-align:center">
                        <input type="text" name="name" placeholder="New type name…" required style="flex:2">
                        <button type="submit" class="btn btn--small btn--primary">+ Add type</button>
                    </div>
                    <div class="form-group" style="margin-top:10px;margin-bottom:0">
                        <label class="muted" style="font-weight:400">Or upload an icon image <span class="muted">(favicon, JPG, PNG, GIF, or WEBP -- max 512KB; overrides the emoji above)</span></label>
                        <input type="file" name="icon_file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,.ico">
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>

<details class="vault-group" open>
    <summary class="vault-group__summary">
        <span class="card__title">Credential Types</span>
    </summary>
    <div style="padding:16px 20px">
        <p class="muted" style="margin:0 0 12px">The "Type" on a credential (e.g. Admin, Manager, Teacher). Typing a brand-new one on the New Credential form adds it here automatically.</p>

        <?php if ($credTypes): ?>
            <form method="post" action="index.php" onsubmit="return confirm('Delete the selected credential types? Credentials already using them keep their label, but it won\'t be offered going forward.');">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete_selected_credtypes">
                <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <?php if ($isAdmin): ?><th style="width:36px"><input type="checkbox" id="credtype-check-all"></th><?php endif; ?>
                            <th>Type</th>
                            <th>Credentials</th>
                            <?php if ($canManage): ?><th class="table__actions">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($credTypes as $ct): ?>
                        <tr>
                            <?php if ($isAdmin): ?><td><input type="checkbox" name="ids[]" value="<?= (int) $ct['id'] ?>" class="credtype-check"></td><?php endif; ?>
                            <td><?= e($ct['name']) ?></td>
                            <td><a href="<?= BASE_URL ?>/passwords/index.php?type=<?= urlencode($ct['name']) ?>"><?= (int) $ct['credential_count'] ?> credential<?= (int) $ct['credential_count'] === 1 ? '' : 's' ?></a></td>
                            <?php if ($canManage): ?>
                                <td class="table__actions"><a class="btn btn--small btn--ghost" href="index.php?edit_credtype=<?= (int) $ct['id'] ?>#edit-credtype">✏️ Edit</a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($isAdmin): ?>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--danger btn--small">Delete selected</button>
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <p class="muted">No credential types yet.</p>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <?php if ($editCredType): ?>
                <form method="post" action="index.php" id="edit-credtype" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="edit_credtype">
                    <input type="hidden" name="id" value="<?= (int) $editCredType['id'] ?>">
                    <p class="muted" style="margin:0 0 8px">Editing "<?= e($editCredType['name']) ?>"</p>
                    <div class="form-inline-row">
                        <input type="text" name="name" value="<?= e($editCredType['name']) ?>" required style="flex:2">
                        <button type="submit" class="btn btn--small btn--primary">Save changes</button>
                        <a class="btn btn--small btn--ghost" href="index.php">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <form method="post" action="index.php" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--border)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="create_credtype">
                    <div class="form-inline-row">
                        <input type="text" name="name" placeholder="New credential type name…" required style="flex:2">
                        <button type="submit" class="btn btn--small btn--primary">+ Add credential type</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</details>

<script>
    (function () {
        function wireCheckAll(allId, cls) {
            var all = document.getElementById(allId);
            if (!all) { return; }
            all.addEventListener('change', function () {
                document.querySelectorAll(cls).forEach(function (cb) { cb.checked = all.checked; });
            });
        }
        wireCheckAll('cat-check-all', '.cat-check');
        wireCheckAll('type-check-all', '.type-check');
        wireCheckAll('credtype-check-all', '.credtype-check');
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
