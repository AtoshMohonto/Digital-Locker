<?php
/**
 * Toggle a to-do item's done state (POST only). Strictly scoped to the
 * owning user.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/notes/index.php');
}

$userId = currentUser()['id'];
$id = (int) ($_POST['id'] ?? 0);

$stmt = $db->prepare("SELECT project_id FROM personal_notes WHERE id = :id AND user_id = :uid AND type = 'todo'");
$stmt->execute(['id' => $id, 'uid' => $userId]);
$projectId = $stmt->fetchColumn();

if ($projectId !== false) {
    $db->prepare('UPDATE personal_notes SET is_done = NOT is_done WHERE id = :id AND user_id = :uid')
        ->execute(['id' => $id, 'uid' => $userId]);
}

if ($projectId) {
    redirect(BASE_URL . '/projects/view.php?id=' . (int) $projectId . '#notes');
}
redirect(BASE_URL . '/notes/index.php');
