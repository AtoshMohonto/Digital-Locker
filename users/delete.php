<?php
/**
 * Delete a user account (POST only). Cannot delete your own account.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('users.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/users/index.php');
}

$id = (int) ($_POST['id'] ?? 0);

if ($id === (int) currentUser()['id']) {
    flash('error', 'You cannot delete your own account.');
    redirect(BASE_URL . '/users/index.php');
}

$stmt = $db->prepare('DELETE FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);

flash('success', 'User deleted.');
redirect(BASE_URL . '/users/index.php');
