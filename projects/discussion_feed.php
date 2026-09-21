<?php
/**
 * Polling endpoint: returns just the Project Discussion feed's inner HTML,
 * so view.php can refresh it every few seconds without a full page reload.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!canAccessProject($id)) {
    http_response_code(403);
    exit;
}

$comments = $db->prepare('SELECT * FROM project_comments WHERE project_id = :pid ORDER BY created_at ASC');
$comments->execute(['pid' => $id]);

header('Content-Type: text/html; charset=utf-8');
echo renderProjectComments($comments->fetchAll());
