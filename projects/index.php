<?php
/**
 * Projects list. Requires projects.manage.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$pageTitle = 'Projects';
$activePage = 'projects';

$projects = $db->query(
    'SELECT p.id, p.name, p.description,
            (SELECT COUNT(*) FROM passwords pw WHERE pw.project_id = p.id) AS password_count
       FROM projects p
      ORDER BY p.name'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header card__header--stack">
        <h2 class="card__title">Projects</h2>
        <div class="quick-actions quick-actions--row">
            <a class="btn" href="<?= BASE_URL ?>/tools/import_htdocs.php">Import from htdocs</a>
            <a class="btn btn--primary" href="create.php">+ New project</a>
        </div>
    </div>

    <?php if ($projects): ?>
        <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Passwords</th>
                    <th class="table__actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $p): ?>
                <tr>
                    <td><strong><?= e($p['name']) ?></strong></td>
                    <td><?= e($p['description'] ?: '—') ?></td>
                    <td><?= (int) $p['password_count'] ?></td>
                    <td class="table__actions">
                        <a class="btn btn--small" href="edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
                        <form class="inline-form" method="post" action="delete.php"
                              onsubmit="return confirm('Delete this project? Its passwords will be unassigned.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn btn--small btn--danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <p class="muted">No projects yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
