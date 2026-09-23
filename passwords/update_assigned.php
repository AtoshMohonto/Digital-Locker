<?php
/**
 * Inline "Assign Manager" / "Assign Tester" update from the Credential
 * Assignments table (POST only). Each credential can have several Managers
 * and several Testers marked at once (password_assignees), picked
 * independently -- this endpoint replaces the whole set for one credential
 * and one kind at a time.
 *
 * Only an Administrator may change who is assigned as Manager. A Manager may
 * only (re)assign Testers -- they never get to reassign the Manager slot,
 * even on projects they manage themselves.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/passwords/index.php');
}

$id = (int) ($_POST['id'] ?? 0);
$kind = (string) ($_POST['kind'] ?? '');

if (!in_array($kind, ['manager', 'tester'], true)) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/passwords/index.php');
}

if (!canAccessCredentialProject($id) || !canAccessCredential($id)) {
    logAudit('edit_denied', $id, '');
    require __DIR__ . '/../403.php';
    exit;
}

if ($kind === 'manager' && !isAdministrator()) {
    require __DIR__ . '/../403.php';
    exit;
}

$userIds = array_map('intval', (array) ($_POST['user_ids'] ?? []));
$userIds = array_values(array_unique(array_filter($userIds, static fn ($v) => $v > 0)));

// Defense in depth: only accept ids that actually hold the matching role,
// even though the picker on the page only ever offers the right people.
$roleName = $kind === 'manager' ? 'Manager' : 'Tester';
$validStmt = $db->prepare(
    'SELECT DISTINCT ur.user_id FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id
      JOIN users u ON u.id = ur.user_id
     WHERE r.name = :role AND u.is_active = 1'
);
$validStmt->execute(['role' => $roleName]);
$validIds = array_map('intval', array_column($validStmt->fetchAll(), 'user_id'));
$userIds = array_values(array_intersect($userIds, $validIds));

$db->beginTransaction();
$db->prepare('DELETE FROM password_assignees WHERE password_id = :pid AND kind = :kind')
    ->execute(['pid' => $id, 'kind' => $kind]);
if ($userIds) {
    $insert = $db->prepare(
        'INSERT INTO password_assignees (password_id, user_id, kind) VALUES (:pid, :uid, :kind)'
    );
    foreach ($userIds as $uid) {
        $insert->execute(['pid' => $id, 'uid' => $uid, 'kind' => $kind]);
    }
}
$db->commit();

flash('success', 'Assignment updated.');

// Return to wherever the row was (preserves filters/view/group). Only ever
// redirects back into this same app -- never trust a client-supplied URL.
$returnTo = (string) ($_POST['return_to'] ?? '');
if (strpos($returnTo, BASE_URL . '/passwords/') !== 0) {
    $returnTo = BASE_URL . '/passwords/index.php';
}
redirect($returnTo);
