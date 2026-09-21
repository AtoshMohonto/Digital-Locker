<?php
/**
 * Edit a project.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Project not found.');
    redirect(BASE_URL . '/projects/index.php');
}

// A Manager may only edit projects they're a member of; an Administrator can
// edit any of them.
if (!isAdministrator() && !canAccessProject($id)) {
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Edit project';
$activePage = 'projects';

$categories = projectCategoryOptions();
$types = projectTypeOptions();
$errors = [];
$old = ['name' => $item['name'], 'category' => (string) $item['category'], 'type' => (string) $item['type'], 'description' => (string) $item['description']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'name'        => trim($_POST['name'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'type'        => trim($_POST['type'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        if ($old['name'] === '') {
            $errors[] = 'Project name is required.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare('UPDATE projects SET name = :name, category = :category, type = :type, description = :description WHERE id = :id');
                $stmt->execute(['name' => $old['name'], 'category' => $old['category'], 'type' => $old['type'], 'description' => $old['description'], 'id' => $id]);
                flash('success', 'Project updated.');
                redirect(BASE_URL . '/projects/index.php');
            } catch (PDOException $e) {
                $errors[] = 'A project with that name already exists.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit project</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required>
        </div>

        <div class="form-group">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">— None —</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>><?= e(projectCategoryIcon($cat)) ?> <?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">— None —</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>" <?= $old['type'] === $t ? 'selected' : '' ?>><?= e(projectTypeIcon($t)) ?> <?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?= e($old['description']) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save changes</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
