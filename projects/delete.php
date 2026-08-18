<?php
/**
 * Delete a project (POST only). Passwords are kept but unassigned.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/projects/index.php');
}

$id = (int) ($_POST['id'] ?? 0);

$stmt = $db->prepare('DELETE FROM projects WHERE id = :id');
$stmt->execute(['id' => $id]);

flash('success', 'Project deleted.');
redirect(BASE_URL . '/projects/index.php');
