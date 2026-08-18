<?php
/**
 * Create a project.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$pageTitle = 'New project';
$activePage = 'projects';

$errors = [];
$old = ['name' => '', 'description' => ''];

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
                $stmt = $db->prepare('INSERT INTO projects (name, description) VALUES (:name, :description)');
                $stmt->execute(['name' => $old['name'], 'description' => $old['description']]);
                flash('success', 'Project created.');
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
        <h2 class="card__title">New project</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="create.php" novalidate>
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
            <button type="submit" class="btn btn--primary">Create project</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
