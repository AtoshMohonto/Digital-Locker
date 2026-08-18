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

$pageTitle = 'Edit project';
$activePage = 'projects';

$errors = [];
$old = ['name' => $item['name'], 'description' => (string) $item['description']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        if ($old['name'] === '') {
            $errors[] = 'Project name is required.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare('UPDATE projects SET name = :name, description = :description WHERE id = :id');
                $stmt->execute(['name' => $old['name'], 'description' => $old['description'], 'id' => $id]);
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
