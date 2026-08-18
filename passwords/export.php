<?php
/**
 * Export all password entries as a CSV file.
 * Secrets are decrypted so this export is a full backup / migration tool.
 * Requires passwords.view (the same level needed to reveal a secret).
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('passwords.view');

$stmt = $db->query(
    'SELECT p.id, p.title, p.category, p.username, p.encrypted, p.url, p.notes, p.extra_info,
            u.username AS assigned_username, u.full_name AS assigned_full_name,
            GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR "; ") AS role_names
       FROM passwords p
       LEFT JOIN users u ON u.id = p.assigned_to
       LEFT JOIN password_roles pr ON pr.password_id = p.id
       LEFT JOIN roles r ON r.id = pr.role_id
      GROUP BY p.id
      ORDER BY p.title ASC'
);

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

$headers = ['title', 'category', 'username', 'password', 'url', 'notes', 'recovery_info', 'assigned_to', 'access_roles'];
fputcsv($out, $headers);

foreach ($stmt->fetchAll() as $row) {
    $allowed = canAccessCredential((int) $row['id']);

    $recovery = $allowed && $row['extra_info'] !== null && $row['extra_info'] !== ''
        ? (string) decrypt_password($row['extra_info'], $appConfig)
        : '';

    fputcsv($out, array_map('csvSafe', [
        $row['title'],
        $row['category'],
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
