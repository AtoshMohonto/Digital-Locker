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

/**
 * Whether the current user can see a project's credentials/Team/Tasks/
 * Discussion. Administrators reach every project unconditionally; everyone
 * else (Manager or Tester) is scoped to specifically the project(s) they've
 * been added to as a team member -- a Manager does not automatically manage
 * every project, only the ones assigned to them.
 */
function canAccessProject(int $projectId): bool
{
    if (isAdministrator()) {
        return true;
    }
    global $db;
    $stmt = $db->prepare('SELECT 1 FROM project_members WHERE project_id = :pid AND user_id = :uid');
    $stmt->execute(['pid' => $projectId, 'uid' => (int) currentUser()['id']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Projects this user may pick from a project dropdown: every project for an
 * Administrator, or only the ones they're a member of otherwise. Used
 * anywhere a project gets attached to something (a credential, a note) so
 * the picker itself can't be used to discover projects you're not on.
 */
function myAccessibleProjects(): array
{
    global $db;
    if (isAdministrator()) {
        return $db->query('SELECT id, name, category, type FROM projects ORDER BY name')->fetchAll();
    }
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.category, p.type FROM projects p
           JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = :uid
          ORDER BY p.name'
    );
    $stmt->execute(['uid' => (int) currentUser()['id']]);
    return $stmt->fetchAll();
}

/**
 * Whether the current user may see/reveal/edit this credential at all, based
 * on project membership -- an Administrator always can; a Manager or Tester
 * only if the credential belongs to a project they're a member of. A
 * credential with no project (project_id NULL) is out of scope for everyone
 * but an Administrator, same as everything else here: this is a distinct
 * check from canAccessCredential(), which is the separate Access Level (RBAC
 * role) gate on top of this project scope.
 */
function canAccessCredentialProject(int $passwordId): bool
{
    if (isAdministrator()) {
        return true;
    }
    global $db;
    $stmt = $db->prepare('SELECT project_id FROM passwords WHERE id = :id');
    $stmt->execute(['id' => $passwordId]);
    $projectId = $stmt->fetchColumn();
    if (!$projectId) {
        return false;
    }
    return canAccessProject((int) $projectId);
}

/**
 * This user's membership row for a project, or null if they're not on the team.
 */
function myProjectRole(int $projectId): ?string
{
    global $db;
    $stmt = $db->prepare('SELECT project_role FROM project_members WHERE project_id = :pid AND user_id = :uid');
    $stmt->execute(['pid' => $projectId, 'uid' => (int) currentUser()['id']]);
    $role = $stmt->fetchColumn();
    return $role === false ? null : $role;
}

/**
 * Whether this user is on the team of at least one project -- used to decide
 * if a Tester (no projects.manage) should see "My Projects" at all.
 */
function hasAnyProjectMembership(): bool
{
    global $db;
    $stmt = $db->prepare('SELECT 1 FROM project_members WHERE user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => (int) currentUser()['id']]);
    return (bool) $stmt->fetchColumn();
}
