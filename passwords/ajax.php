<?php
/**
 * AJAX endpoint: returns the decrypted secret for a password entry.
 * Only reachable when the user holds passwords.view.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.view');

header('Content-Type: application/json');

$id = isset($_GET['reveal']) ? (int) $_GET['reveal'] : 0;
if ($id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare('SELECT title, encrypted, extra_info FROM passwords WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Entry not found.']);
    exit;
}

if (!canAccessCredential($id)) {
    logAudit('reveal_denied', $id, $row['title']);
    http_response_code(403);
    echo json_encode(['error' => 'You do not have access to this credential.']);
    exit;
}

$secret = decrypt_password($row['encrypted'], $appConfig);
if ($secret === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to decrypt secret. Check the master key.']);
    exit;
}

$recovery = $row['extra_info'] !== null && $row['extra_info'] !== ''
    ? decrypt_password($row['extra_info'], $appConfig)
    : null;

logAudit('reveal', $id, $row['title']);

echo json_encode(['secret' => $secret, 'recovery' => $recovery]);
