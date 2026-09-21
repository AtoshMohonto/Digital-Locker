<?php
/**
 * Delete a project (POST only). Passwords are kept but unassigned.
 * Administrator only -- a Manager can manage the projects assigned to them,
 * but deleting the project record itself is a catalogue-level action.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

if (!isAdministrator()) {
    require __DIR__ . '/../403.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/projects/index.php');
}

// Supports both the single-row Delete button and the "mark and delete"
// bulk action (checkboxes + ids[]) on the projects list.
$ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : [];
if (isset($_POST['id'])) {
    $ids[] = (int) $_POST['id'];
}
$ids = array_values(array_unique(array_filter($ids)));

if (!$ids) {
    flash('error', 'Nothing selected to delete.');
    redirect(BASE_URL . '/projects/index.php');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$db->prepare("DELETE FROM projects WHERE id IN ($placeholders)")->execute($ids);

flash('success', count($ids) === 1 ? 'Project deleted.' : count($ids) . ' projects deleted.');
redirect(BASE_URL . '/projects/index.php');
