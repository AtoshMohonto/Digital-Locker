<?php
/**
 * Export all password entries as a CSV file.
 * Secrets are decrypted so this export is a full backup / migration tool --
 * requires passwords.manage (Administrator/Manager only). A Tester has
 * read-only vault access and never gets export capability. A Manager's
 * export is scoped to their own accessible projects, same as everywhere
 * else in the vault; an Administrator gets everything.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.manage');

$sql = 'SELECT p.id, p.title, p.category, p.username, p.encrypted, p.url, p.notes, p.extra_info,
               u.username AS assigned_username, u.full_name AS assigned_full_name, pj.name AS project_name,
               GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR "; ") AS role_names
          FROM passwords p
          LEFT JOIN users u ON u.id = p.assigned_to
          LEFT JOIN projects pj ON pj.id = p.project_id
          LEFT JOIN password_roles pr ON pr.password_id = p.id
          LEFT JOIN roles r ON r.id = pr.role_id';
$params = [];
if (!isAdministrator()) {
    $sql .= ' WHERE p.project_id IS NOT NULL AND EXISTS (
                  SELECT 1 FROM project_members pm WHERE pm.project_id = p.project_id AND pm.user_id = :uid
              )';
    $params['uid'] = (int) currentUser()['id'];
}
$sql .= ' GROUP BY p.id ORDER BY p.title ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);

$filename = 'digital-locker-passwords-' . date('Y-m-d-His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'wb');

// UTF-8 BOM so Excel opens the file correctly.
fwrite($out, "\xEF\xBB\xBF");

/**
 * Neutralize CSV formula injection: a cell starting with =, +, -, @, tab or CR
 * is interpreted as a formula by Excel/Sheets and can execute code on open.
 * Prefixing with a single quote forces it to be read as plain text.
 */
function csvSafe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
}

$headers = ['title', 'category', 'project', 'username', 'password', 'url', 'notes', 'recovery_info', 'assigned_to', 'access_roles'];
fputcsv($out, $headers);

foreach ($stmt->fetchAll() as $row) {
    $allowed = canAccessCredential((int) $row['id']);

    $recovery = $allowed && $row['extra_info'] !== null && $row['extra_info'] !== ''
        ? (string) decrypt_password($row['extra_info'], $appConfig)
        : '';

    fputcsv($out, array_map('csvSafe', [
        $row['title'],
        $row['category'],
        (string) $row['project_name'],
        $row['username'],
        $allowed ? (string) decrypt_password($row['encrypted'], $appConfig) : '(restricted)',
        $row['url'],
        (string) $row['notes'],
        $recovery,
        (string) ($row['assigned_full_name'] ?: $row['assigned_username']),
        (string) $row['role_names'],
    ]));
}

fclose($out);
exit;
