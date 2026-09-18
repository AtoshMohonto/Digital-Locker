<?php
/**
 * Authentication and RBAC helpers.
 */

declare(strict_types=1);

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user']['id']);
}

/**
 * Redirect to login when not authenticated.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        redirect(BASE_URL . '/login.php');
    }
}

/**
 * Permission cache for the current user, keyed by permission string.
 */
function userPermissions(): array
{
    static $permissions = null;
    if ($permissions !== null) {
        return $permissions;
    }

    global $db;
    $permissions = [];

    if (!isLoggedIn()) {
        return $permissions;
    }

    $stmt = $db->prepare(
        'SELECT DISTINCT rp.permission
           FROM role_permissions rp
           JOIN user_roles ur ON ur.role_id = rp.role_id
          WHERE ur.user_id = :uid'
    );
    $stmt->execute(['uid' => $_SESSION['user']['id']]);
    foreach ($stmt->fetchAll() as $row) {
        $permissions[] = $row['permission'];
    }
    return $permissions;
}

function hasPermission(string $permission): bool
{
    return in_array($permission, userPermissions(), true);
}

/**
 * Hard requirement: 403 when the user lacks a permission.
 */
function requirePermission(string $permission): void
{
    requireLogin();
    if (!hasPermission($permission)) {
        require __DIR__ . '/../403.php';
        exit;
    }
}

/**
 * Role names assigned to the current user (used for filtering & display).
 */
function userRoleNames(): array
{
    static $roles = null;
    if ($roles !== null) {
        return $roles;
    }

    global $db;
    $roles = [];

    if (!isLoggedIn()) {
        return $roles;
    }

    $stmt = $db->prepare(
        'SELECT r.name, r.id
           FROM roles r
           JOIN user_roles ur ON ur.role_id = r.id
          WHERE ur.user_id = :uid'
    );
    $stmt->execute(['uid' => $_SESSION['user']['id']]);
    $roles = $stmt->fetchAll();
    return $roles;
}

/**
 * Role IDs assigned to the current user (for matching against per-entry access lists).
 */
function currentUserRoleIds(): array
{
    return array_map(static fn ($r) => (int) $r['id'], userRoleNames());
}

/**
 * Administrators bypass per-credential Access Level restrictions.
 */
function isAdministrator(): bool
{
    foreach (userRoleNames() as $role) {
        if ($role['name'] === 'Administrator') {
            return true;
        }
    }
    return false;
}

/**
 * How many active users currently hold the Administrator role, optionally
 * excluding one user id. Used to stop an edit/delete/disable from leaving
 * the vault with zero administrators (an unrecoverable lockout).
 */
function activeAdministratorCount(?int $excludeUserId = null): int
{
    global $db;
    $sql = "SELECT COUNT(DISTINCT u.id)
              FROM users u
              JOIN user_roles ur ON ur.user_id = u.id
              JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'Administrator' AND u.is_active = 1";
    $params = [];
    if ($excludeUserId !== null) {
        $sql .= ' AND u.id != :exclude';
        $params['exclude'] = $excludeUserId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}
