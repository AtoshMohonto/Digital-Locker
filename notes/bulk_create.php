<?php
/**
 * Add several to-do items at once (POST only) -- one per non-empty line,
 * all optionally tagged to the same project. Strictly private to the creator.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/notes/index.php');
}

$userId = currentUser()['id'];
$projects = array_column(myAccessibleProjects(), 'id');
$projectId = (int) ($_POST['project_id'] ?? 0);
$projectId = in_array($projectId, array_map('intval', $projects), true) ? $projectId : null;

$lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['titles'] ?? ''));
$titles = array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));

if ($titles) {
    $insert = $db->prepare(
        "INSERT INTO personal_notes (user_id, project_id, type, title) VALUES (:uid, :pid, 'todo', :title)"
    );
    foreach ($titles as $title) {
        $insert->execute(['uid' => $userId, 'pid' => $projectId, 'title' => $title]);
    }
    flash('success', count($titles) . ' to-do item(s) added.');
} else {
    flash('error', 'Nothing to add -- enter at least one line.');
}

if ($projectId) {
    redirect(BASE_URL . '/projects/view.php?id=' . $projectId . '#notes');
}
redirect(BASE_URL . '/notes/index.php');
