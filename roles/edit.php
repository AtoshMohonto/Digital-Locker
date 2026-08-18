<?php
/**
 * Edit a role and its permissions.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('roles.manage');

require __DIR__ . '/permissions.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Role not found.');
    redirect(BASE_URL . '/roles/index.php');
}

$pageTitle = 'Edit role';
$activePage = 'roles';

$catalogue = permissionCatalogue();
$errors = [];
$old = ['name' => $item['name'], 'description' => (string) $item['description']];

$stmt = $db->prepare('SELECT permission FROM role_permissions WHERE role_id = :id');
$stmt->execute(['id' => $id]);
$selected = array_column($stmt->fetchAll(), 'permission');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];
        $selected = array_keys($_POST['permissions'] ?? []);

        if ($old['name'] === '') {
            $errors[] = 'Role name is required.';
        }
        foreach ($selected as $perm) {
            if (!isset($catalogue[$perm])) {
                $errors[] = 'Invalid permission selected.';
                break;
            }
        }

        if (!$errors) {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare('UPDATE roles SET name = :name, description = :description WHERE id = :id');
                $stmt->execute(['name' => $old['name'], 'description' => $old['description'], 'id' => $id]);

                $stmt = $db->prepare('DELETE FROM role_permissions WHERE role_id = :rid');
                $stmt->execute(['rid' => $id]);

                $stmt = $db->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (:rid, :perm)');
                foreach ($selected as $perm) {
                    $stmt->execute(['rid' => $id, 'perm' => $perm]);
                }

                $db->commit();
                flash('success', 'Role updated.');
                redirect(BASE_URL . '/roles/index.php');
            } catch (PDOException $e) {
                $db->rollBack();
                $errors[] = 'A role with that name already exists.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit role</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="name">Role name *</label>
            <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="2"><?= e($old['description']) ?></textarea>
        </div>

        <div class="form-group">
            <label>Permissions</label>
            <?php
            $currentGroup = null;
            foreach ($catalogue as $key => $perm):
                if ($perm['group'] !== $currentGroup):
                    $currentGroup = $perm['group'];
            ?>
                <h3 class="perm-group-title"><?= e($currentGroup) ?></h3>
                <?php endif; ?>
                <label class="checkbox">
                    <input type="checkbox" name="permissions[<?= e($key) ?>]" value="1"
                           <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
                    <span><?= e($perm['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save changes</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
