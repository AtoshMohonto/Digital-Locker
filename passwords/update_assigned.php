<?php
/**
 * Inline "Assigned To" update from the vault table row (POST only). Updates
 * just that one field -- everything else about the credential is untouched.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/passwords/index.php');
}

$id = (int) ($_POST['id'] ?? 0);
$assignedTo = (int) ($_POST['assigned_to'] ?? 0);

if (!canAccessCredential($id)) {
    logAudit('edit_denied', $id, '');
    require __DIR__ . '/../403.php';
    exit;
}

$db->prepare('UPDATE passwords SET assigned_to = :assigned_to WHERE id = :id')
    ->execute(['assigned_to' => $assignedTo > 0 ? $assignedTo : null, 'id' => $id]);

flash('success', 'Assignment updated.');

// Return to wherever the row was (preserves filters/view/group). Only ever
// redirects back into this same app -- never trust a client-supplied URL.
$returnTo = (string) ($_POST['return_to'] ?? '');
if (strpos($returnTo, BASE_URL . '/passwords/') !== 0) {
    $returnTo = BASE_URL . '/passwords/index.php';
}
redirect($returnTo);
