<?php
/**
 * Delete a role (POST only). Administrator (id 1) is protected.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('roles.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/roles/index.php');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id === 1) {
    flash('error', 'The Administrator role cannot be deleted.');
    redirect(BASE_URL . '/roles/index.php');
}

$stmt = $db->prepare('DELETE FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);

flash('success', 'Role deleted.');
redirect(BASE_URL . '/roles/index.php');
