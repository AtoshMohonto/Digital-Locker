<?php
/**
 * Delete a Personal Vault entry (POST only). Strictly scoped to the owning user.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/personal/index.php');
}

$userId = currentUser()['id'];
$id = (int) ($_POST['id'] ?? 0);

$titleStmt = $db->prepare('SELECT title FROM personal_passwords WHERE id = :id AND user_id = :uid');
$titleStmt->execute(['id' => $id, 'uid' => $userId]);
$title = (string) $titleStmt->fetchColumn();

$stmt = $db->prepare('DELETE FROM personal_passwords WHERE id = :id AND user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $userId]);

logAudit('personal_delete', null, $title);
flash('success', 'Entry deleted.');
redirect(BASE_URL . '/personal/index.php');
