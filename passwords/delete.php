<?php
/**
 * Delete a password entry (POST only).
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/passwords/index.php');
}

$id = (int) ($_POST['id'] ?? 0);

$titleStmt = $db->prepare('SELECT title FROM passwords WHERE id = :id');
$titleStmt->execute(['id' => $id]);
$title = (string) $titleStmt->fetchColumn();

if (!canAccessCredentialProject($id) || !canAccessCredential($id)) {
    logAudit('delete_denied', $id, $title);
    require __DIR__ . '/../403.php';
    exit;
}

$stmt = $db->prepare('DELETE FROM passwords WHERE id = :id');
$stmt->execute(['id' => $id]);

logAudit('delete', null, $title);
flash('success', 'Credential deleted.');
redirect(BASE_URL . '/passwords/index.php');
