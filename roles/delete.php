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

/**
 * Block deleting a role that is the ONLY Access Level restriction on a
 * credential -- canAccessCredential() treats an empty role list as
 * unrestricted, so removing the role would silently make that credential
 * visible to every user instead of erroring or leaving it locked.
 */
$stmt = $db->prepare(
    'SELECT COUNT(DISTINCT pr.password_id) FROM password_roles pr
      WHERE pr.role_id = :id1
        AND NOT EXISTS (
            SELECT 1 FROM password_roles pr2
             WHERE pr2.password_id = pr.password_id AND pr2.role_id != :id2
        )'
);
$stmt->execute(['id1' => $id, 'id2' => $id]);
$soleRestriction = (int) $stmt->fetchColumn();

if ($soleRestriction > 0) {
    flash('error', "Cannot delete: this role is the only Access Level restriction on {$soleRestriction} credential(s). Update their Access Level first, or those credentials would become visible to everyone.");
    redirect(BASE_URL . '/roles/index.php');
}

$stmt = $db->prepare('DELETE FROM roles WHERE id = :id');
$stmt->execute(['id' => $id]);

flash('success', 'Role deleted.');
redirect(BASE_URL . '/roles/index.php');
