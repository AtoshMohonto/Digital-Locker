<?php
/**
 * AJAX endpoint: returns the decrypted secret for a Personal Vault entry.
 * Strictly scoped to the owning user -- no role or admin bypass exists here.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

header('Content-Type: application/json');

$userId = currentUser()['id'];
$id = isset($_GET['reveal']) ? (int) $_GET['reveal'] : 0;
if ($id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare('SELECT title, encrypted FROM personal_passwords WHERE id = :id AND user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $userId]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Entry not found.']);
    exit;
}

$secret = decrypt_password($row['encrypted'], $appConfig);
if ($secret === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to decrypt secret. Check the master key.']);
    exit;
}

logAudit('personal_reveal', null, $row['title']);

echo json_encode(['secret' => $secret]);
