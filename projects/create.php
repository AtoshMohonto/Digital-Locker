<?php
/**
 * Create a project.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$pageTitle = 'New project';
$activePage = 'projects';

$categories = projectCategoryOptions();
$types = projectTypeOptions();
$errors = [];
$old = ['name' => '', 'category' => '', 'type' => '', 'description' => ''];

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
                $stmt = $db->prepare('INSERT INTO projects (name, category, type, description) VALUES (:name, :category, :type, :description)');
                $stmt->execute(['name' => $old['name'], 'category' => $old['category'], 'type' => $old['type'], 'description' => $old['description']]);
                // Must be read before any other query runs -- lastInsertId() only
                // reflects the most recently executed statement, so even an
                // unrelated SELECT in between (e.g. inside isAdministrator()) would
                // reset it to 0.
                $newId = (int) $db->lastInsertId();

                // A Manager (not an Administrator) is scoped to only the projects
                // they're a member of, so join them to what they just created --
                // otherwise they'd immediately lose access to their own project.
                if (!isAdministrator()) {
                    $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, project_role) VALUES (:pid, :uid, :role)')
                        ->execute(['pid' => $newId, 'uid' => (int) currentUser()['id'], 'role' => 'manager']);
                }

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
            <button type="submit" class="btn btn--primary">Create project</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
