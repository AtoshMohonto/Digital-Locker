<?php
/**
 * Generic helper functions.
 */

declare(strict_types=1);

/**
 * Absolute application base URL for links and redirects.
 * Falls back to auto-detection when config/app/base_url is empty.
 */
function baseUrl(array $config): string
{
    $configured = trim((string) ($config['app']['base_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $appDir  = realpath(__DIR__ . '/..');
    $path    = '';

    if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
        $path = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    }

    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . $path;
}

/**
 * URL for a static asset (under /assets), cache-busted with the file's
 * mtime so an edited style.css/app.js is picked up immediately instead of
 * being served stale from the browser's cache on returning visits.
 */
function assetUrl(string $relativePath): string
{
    $relativePath = '/' . ltrim($relativePath, '/');
    $diskPath = __DIR__ . '/..' . $relativePath;
    $version = is_file($diskPath) ? filemtime($diskPath) : time();

    return BASE_URL . $relativePath . '?v=' . $version;
}

/**
 * HTML-escape a value for safe output.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect and stop execution.
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * CSRF protection.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Flash messages. usage: flash('success', 'Saved'); then <?= renderFlash() ?>
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function renderFlash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $msg) {
        $html .= '<div class="alert alert-' . e($msg['type']) . '">' . e($msg['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/**
 * Encryption helpers (AES-256-GCM).
 */
function encrypt_password(string $plain, array $config): string
{
    $key = hex2bin($config['crypto']['master_key']);
    if ($key === false) {
        throw new RuntimeException('Invalid master key in config. It must be a 64 char hex string.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, $config['crypto']['cipher'], $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_password(string $payload, array $config): ?string
{
    $key = hex2bin($config['crypto']['master_key']);
    if ($key === false) {
        return null;
    }
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 12 + 16) {
        return null;
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $data = substr($raw, 12 + 16);
    $plain = openssl_decrypt($data, $config['crypto']['cipher'], $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/**
 * Password policy. Returns list of violated rules (empty = ok).
 */
function validatePasswordPolicy(string $password, array $policy): array
{
    $errors = [];
    if (strlen($password) < $policy['min_length']) {
        $errors[] = 'Password must be at least ' . $policy['min_length'] . ' characters long.';
    }
    if (!empty($policy['require_upper']) && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain an uppercase letter.';
    }
    if (!empty($policy['require_lower']) && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain a lowercase letter.';
    }
    if (!empty($policy['require_digit']) && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain a digit.';
    }
    if (!empty($policy['require_special']) && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain a special character.';
    }
    return $errors;
}

/**
 * Generate a strong random password.
 */
function generatePassword(int $length = 18): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $special = '!@#$%^&*()-_=+[]{}';

    $all = $upper . $lower . $digits . $special;
    $password = '';
    $charsets = [$upper, $lower, $digits, $special];

    foreach ($charsets as $set) {
        $password .= $set[random_int(0, strlen($set) - 1)];
    }
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($password);
}

/**
 * Get a setting value from the settings table.
 */
function setting(string $key, ?string $default = null): ?string
{
    global $db;
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $rows = $db->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Active password policy: DB settings override config defaults.
 */
function effectivePolicy(): array
{
    global $appConfig;

    $policy = $appConfig['policy'];
    $policy['min_length'] = (int) setting('policy_min_length', (string) $policy['min_length']);
    $policy['require_upper']   = (int) setting('policy_require_upper',   $policy['require_upper']   ? '1' : '0') === 1;
    $policy['require_lower']   = (int) setting('policy_require_lower',   $policy['require_lower']   ? '1' : '0') === 1;
    $policy['require_digit']   = (int) setting('policy_require_digit',   $policy['require_digit']   ? '1' : '0') === 1;
    $policy['require_special'] = (int) setting('policy_require_special', $policy['require_special'] ? '1' : '0') === 1;

    return $policy;
}

function appName(): string
{
    return setting('app_name') ?: 'Digital Locker';
}

/**
 * Consistent page title suffix.
 */
function pageTitle(string $title): string
{
    return $title . ' | ' . appName();
}

/**
 * Credential Vault: fixed category list offered in the create/edit forms.
 */
function categoryOptions(): array
{
    return ['Server', 'Router/Network', 'Application', 'Database', 'Email Account', 'Domain/DNS', 'Software License', 'Other'];
}

/**
 * Credential Vault: icon shown next to the system name, keyed by category.
 */
function categoryIcon(string $category): string
{
    $icons = [
        'Server'            => '🖥️',
        'Router/Network'    => '📡',
        'Application'       => '🧩',
        'Database'          => '🗄️',
        'Email Account'     => '📧',
        'Domain/DNS'        => '🌐',
        'Software License'  => '📄',
        'Other'             => '🔐',
    ];
    return $icons[$category] ?? '🔐';
}

/**
 * Credential Vault: friendlier label for a role's access level.
 * Falls back to the role's own name for custom roles.
 */
function accessLabel(?string $roleName): string
{
    $labels = [
        'Administrator' => 'Admin Only',
        'Manager'       => 'Management+',
        'Tester'        => 'Testers',
        'Viewer'        => 'All Staff',
    ];
    return $labels[$roleName] ?? ($roleName ?: '—');
}

/**
 * Projects: fixed category list offered in the create/edit forms, so a user
 * can tell their own work apart from freelancing, demos, and personal projects.
 */
/**
 * Project categories: admin-editable (see categories/index.php), not a fixed
 * list -- new ones can be created and unwanted ones deleted.
 */
function projectCategories(): array
{
    global $db;
    static $rows = null;
    if ($rows === null) {
        $rows = $db->query('SELECT name, icon FROM project_categories ORDER BY name')->fetchAll();
    }
    return $rows;
}

function projectCategoryOptions(): array
{
    return array_column(projectCategories(), 'name');
}

/**
 * Plain-text-safe icon (a typed emoji, or a generic folder glyph when the
 * category's icon is actually an uploaded image -- an <img> can't render
 * inside a dropdown <option> or a plain e()-escaped string). Use
 * projectCategoryIconHtml() instead anywhere real HTML can render.
 */
function projectCategoryIcon(string $category): string
{
    $icon = projectCategoryIconRaw($category);
    return strpos($icon, '/') !== false ? '📁' : $icon;
}

function projectCategoryIconRaw(string $category): string
{
    foreach (projectCategories() as $row) {
        if ($row['name'] === $category) {
            return $row['icon'];
        }
    }
    return '📁';
}

/**
 * Real HTML rendering: shows the uploaded image when the category has one,
 * otherwise the plain emoji. Caller must NOT wrap this in e() -- it's
 * pre-escaped/safe HTML already.
 */
function projectCategoryIconHtml(string $category): string
{
    $icon = projectCategoryIconRaw($category);
    if (strpos($icon, '/') !== false) {
        return '<img src="' . e(BASE_URL . '/' . $icon) . '" class="category-icon-img" alt="">';
    }
    return e($icon);
}

/**
 * Project types: a second, independent classification alongside category
 * (e.g. Web App, Mobile App, API/Service). Same catalogue-table pattern as
 * projectCategories() and friends above.
 */
function projectTypes(): array
{
    global $db;
    static $rows = null;
    if ($rows === null) {
        $rows = $db->query('SELECT name, icon FROM project_types ORDER BY name')->fetchAll();
    }
    return $rows;
}

function projectTypeOptions(): array
{
    return array_column(projectTypes(), 'name');
}

function projectTypeIcon(string $type): string
{
    $icon = projectTypeIconRaw($type);
    return strpos($icon, '/') !== false ? '🏷️' : $icon;
}

function projectTypeIconRaw(string $type): string
{
    foreach (projectTypes() as $row) {
        if ($row['name'] === $type) {
            return $row['icon'];
        }
    }
    return '🏷️';
}

function projectTypeIconHtml(string $type): string
{
    $icon = projectTypeIconRaw($type);
    if (strpos($icon, '/') !== false) {
        return '<img src="' . e(BASE_URL . '/' . $icon) . '" class="category-icon-img" alt="">';
    }
    return e($icon);
}

/**
 * Credential "Type" catalog (the "Role — System Name" prefix, e.g. Admin,
 * Teacher). A real, admin/manager-editable table, same pattern as project
 * categories/types -- new values typed on the credential form get remembered
 * here too (see passwords/create.php and edit.php).
 */
function credentialTypeOptions(): array
{
    global $db;
    return $db->query('SELECT name FROM credential_types ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

/** Remembers a newly-typed credential Type so it shows up as a suggestion next time. */
function rememberCredentialType(string $name): void
{
    if ($name === '') {
        return;
    }
    global $db;
    $db->prepare('INSERT IGNORE INTO credential_types (name) VALUES (:name)')->execute(['name' => $name]);
}

/**
 * Project Category and Type are now free-text-with-suggestions on the New/
 * Edit Project form (select an existing one, or just type a new one) rather
 * than a fixed dropdown -- a typed value gets remembered into the same
 * catalog tables the Categories admin page manages, default icon until
 * someone gives it a real one there.
 */
function rememberProjectCategory(string $name): void
{
    if ($name === '') {
        return;
    }
    global $db;
    $db->prepare('INSERT IGNORE INTO project_categories (name, icon) VALUES (:name, :icon)')
        ->execute(['name' => $name, 'icon' => '📁']);
}

function rememberProjectType(string $name): void
{
    if ($name === '') {
        return;
    }
    global $db;
    $db->prepare('INSERT IGNORE INTO project_types (name, icon) VALUES (:name, :icon)')
        ->execute(['name' => $name, 'icon' => '🏷️']);
}

/**
 * Project "Role": the web app's functional purpose (Data Collection,
 * E-commerce, Login System, ...). A flat catalog of known values (seeded
 * with common ones, grows as people type new ones), separate from the
 * per-(category, type) suggestion map built from actual projects in
 * projectPurposesByContext() -- that's what makes the datalist "show
 * according to type and category" on the form.
 */
function projectPurposeOptions(): array
{
    global $db;
    return $db->query('SELECT name FROM project_purposes ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

/** Remembers a newly-typed project Role so it shows up as a suggestion next time. */
function rememberProjectPurpose(string $name): void
{
    if ($name === '') {
        return;
    }
    global $db;
    $db->prepare('INSERT IGNORE INTO project_purposes (name) VALUES (:name)')->execute(['name' => $name]);
}

/**
 * Every Role value already used on a project, grouped by that project's
 * (category, type) pair -- so the Role datalist on the form can be
 * narrowed to "what's usually picked for a Freelancing + Web App project"
 * instead of the whole flat catalog every time.
 */
function projectPurposesByContext(): array
{
    global $db;
    $map = [];
    $rows = $db->query(
        "SELECT DISTINCT category, type, role FROM projects WHERE role != '' ORDER BY role"
    )->fetchAll();
    foreach ($rows as $row) {
        $key = $row['category'] . '|' . $row['type'];
        $map[$key][] = $row['role'];
    }
    return $map;
}

/**
 * Every active user's single "primary" role name (Manager, Tester,
 * Administrator, or Other for anyone with no recognized role), in that
 * priority order for a user holding more than one. Shared by
 * usersGroupedByRole() (for the grouped dropdown) and anywhere that just
 * needs a quick "what role is this person" badge next to their name.
 */
function primaryRoleNameByUser(): array
{
    global $db;
    $order = ['Manager', 'Tester', 'Administrator'];

    $roleByUser = [];
    foreach (
        $db->query('SELECT ur.user_id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id')->fetchAll()
        as $row
    ) {
        $roleByUser[(int) $row['user_id']][] = $row['name'];
    }

    $primary = [];
    foreach ($db->query('SELECT id FROM users WHERE is_active = 1')->fetchAll() as $u) {
        $uid = (int) $u['id'];
        $userRoles = $roleByUser[$uid] ?? [];
        $primary[$uid] = 'Other';
        foreach ($order as $roleName) {
            if (in_array($roleName, $userRoles, true)) {
                $primary[$uid] = $roleName;
                break;
            }
        }
    }
    return $primary;
}

/**
 * Active users for an "Assigned To" picker, grouped for a proper <optgroup>
 * dropdown in priority order: Managers first, then Testers, then
 * Administrators, then anyone with no recognized role. A user holding
 * several roles is listed once, under their highest-priority group.
 */
function usersGroupedByRole(): array
{
    global $db;
    $labels = ['Manager' => 'Managers', 'Tester' => 'Testers', 'Administrator' => 'Administrators', 'Other' => 'Other'];

    $users = $db->query('SELECT id, username, full_name FROM users WHERE is_active = 1 ORDER BY username')->fetchAll();
    $primary = primaryRoleNameByUser();

    $groups = ['Manager' => [], 'Tester' => [], 'Administrator' => [], 'Other' => []];
    foreach ($users as $u) {
        $groups[$primary[(int) $u['id']] ?? 'Other'][] = $u;
    }

    $result = [];
    foreach ($groups as $key => $list) {
        if ($list) {
            $result[] = ['label' => $labels[$key], 'users' => $list];
        }
    }
    return $result;
}

/**
 * Project workflow: task status options and their badge styling.
 */
function taskStatusOptions(): array
{
    return ['open' => 'Open', 'in_progress' => 'In Progress', 'done' => 'Done'];
}

function taskStatusBadgeClass(string $status): string
{
    return [
        'open'        => 'badge--role',
        'in_progress' => 'badge--warning',
        'done'        => 'badge--success',
    ][$status] ?? 'badge--role';
}

/**
 * Renders a note/to-do's free-text body with simple list support: consecutive
 * lines starting with "-", "*", or "•" become a real <ul>; consecutive lines
 * starting with "1." / "1)" become a real <ol>. Everything else renders as
 * plain text with line breaks, same as before. All text is escaped either way.
 */
function renderNoteBody(string $body): string
{
    $lines = preg_split('/\r\n|\r|\n/', $body);
    $html = '';
    $listItems = [];
    $listTag = null;

    $flush = function () use (&$html, &$listItems, &$listTag) {
        if ($listTag !== null) {
            $html .= "<$listTag>" . implode('', array_map(static fn ($i) => '<li>' . e($i) . '</li>', $listItems)) . "</$listTag>";
            $listItems = [];
            $listTag = null;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^\s*[-*•]\s+(.*)$/', $line, $m)) {
            if ($listTag !== null && $listTag !== 'ul') {
                $flush();
            }
            $listTag = 'ul';
            $listItems[] = $m[1];
        } elseif (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
            if ($listTag !== null && $listTag !== 'ol') {
                $flush();
            }
            $listTag = 'ol';
            $listItems[] = $m[1];
        } else {
            $flush();
            if (trim($line) !== '') {
                $html .= '<p>' . e($line) . '</p>';
            }
        }
    }
    $flush();

    return $html;
}

/**
 * A credential's "login role" within the target system it belongs to --
 * distinct from Digital Locker's own Access Level. Convention: titles like
 * "Admin — admin1" or "Teacher — teacher1" name the role before an em dash;
 * anything without that separator is its own one-off group (e.g. "CRM Portal").
 */
function extractLoginRole(string $title): string
{
    $parts = preg_split('/\s*—\s*/u', $title, 2);
    return trim($parts[0]) ?: $title;
}

/**
 * Splits a title like "Admin — admin1" back into ['role' => 'Admin', 'base'
 * => 'admin1'] for the Edit form's separate Role/System Name fields. A title
 * with no em dash has no role: ['role' => '', 'base' => $title].
 */
function splitLoginRoleTitle(string $title): array
{
    $parts = preg_split('/\s*—\s*/u', $title, 2);
    if (count($parts) === 2) {
        return ['role' => trim($parts[0]), 'base' => trim($parts[1])];
    }
    return ['role' => '', 'base' => $title];
}

/**
 * Distinct login roles already in use across the vault (e.g. "Admin",
 * "Teacher"), for the Role field's autocomplete suggestions on the credential
 * create/edit forms.
 */
function existingLoginRoles(): array
{
    global $db;
    $roles = [];
    foreach ($db->query('SELECT title FROM passwords')->fetchAll() as $row) {
        $role = extractLoginRole($row['title']);
        if ($role !== $row['title']) {
            $roles[$role] = true;
        }
    }
    $roles = array_keys($roles);
    sort($roles, SORT_NATURAL | SORT_FLAG_CASE);
    return $roles;
}

/**
 * Everyone this user shares at least one project with, in a given project
 * role -- e.g. a Manager's "My Team" (their testers) or a Tester's "Mentors"
 * (their managers). Grouped so each teammate lists every shared project.
 */
function projectTeammates(int $userId, string $myProjectRole, string $wantProjectRole): array
{
    global $db;
    $stmt = $db->prepare(
        "SELECT u.id, u.username, u.full_name,
                GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR '||') AS shared_projects
           FROM project_members my
           JOIN project_members pm ON pm.project_id = my.project_id AND pm.project_role = :wantRole
           JOIN users u ON u.id = pm.user_id
           JOIN projects p ON p.id = pm.project_id
          WHERE my.user_id = :uid AND my.project_role = :myRole
          GROUP BY u.id
          ORDER BY u.full_name"
    );
    $stmt->execute(['uid' => $userId, 'myRole' => $myProjectRole, 'wantRole' => $wantProjectRole]);
    return $stmt->fetchAll();
}

/**
 * Renders the Project Discussion feed -- shared between the full project
 * page and the polling fragment endpoint so both stay pixel-identical.
 */
/** First letter of up to two words (e.g. "Atosh Mohonto" -> "AM"), for an avatar badge. */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = array_map(static fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_filter($parts));
    return implode('', array_slice($letters, 0, 2)) ?: '?';
}

/**
 * A short "Today" / "Yesterday" / "Mon, Sep 21" divider label for a chat
 * message's date, grouping the feed the way a real chat client does.
 */
function relativeDayLabel(string $datetime): string
{
    $day = date('Y-m-d', strtotime($datetime));
    if ($day === date('Y-m-d')) {
        return 'Today';
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('D, M j', strtotime($datetime));
}

/**
 * Renders the Project Discussion feed as chat bubbles: the current viewer's
 * own messages align right in the accent color (no name label, it's
 * obviously theirs), everyone else's align left with an avatar and name,
 * grouped under "Today" / "Yesterday" / date dividers like a real chat app.
 */
function renderProjectComments(array $comments): string
{
    if (!$comments) {
        return '<p class="muted" id="discussion-empty">No discussion yet. Be the first to post an update or review.</p>';
    }
    $myId = isLoggedIn() ? (int) currentUser()['id'] : 0;
    $html = '';
    $lastDay = '';
    foreach ($comments as $c) {
        $day = date('Y-m-d', strtotime($c['created_at']));
        if ($day !== $lastDay) {
            $html .= '<div class="chat-divider"><span>' . e(relativeDayLabel($c['created_at'])) . '</span></div>';
            $lastDay = $day;
        }

        $isMine = $myId > 0 && (int) $c['user_id'] === $myId;
        $html .= '<div class="chat-row' . ($isMine ? ' chat-row--mine' : '') . '">';
        if (!$isMine) {
            $html .= '<span class="chat-row__avatar">' . e(initials($c['author_name'])) . '</span>';
        }
        $html .= '<div class="chat-bubble' . ($isMine ? ' chat-bubble--mine' : '') . '">';
        if (!$isMine) {
            $html .= '<div class="chat-bubble__author">' . e($c['author_name']) . '</div>';
        }
        if (!empty($c['attachment_path'])) {
            $src = e(BASE_URL . '/' . $c['attachment_path']);
            $html .= '<a href="' . $src . '" target="_blank" rel="noopener" class="chat-bubble__attachment"><img src="' . $src . '" alt="Attached image" loading="lazy"></a>';
        }
        if (trim((string) $c['body']) !== '') {
            $html .= '<div class="chat-bubble__body">' . nl2br(e($c['body'])) . '</div>';
        }
        $html .= '<div class="chat-bubble__time">' . e(date('g:i A', strtotime($c['created_at']))) . '</div>'
            . '</div></div>';
    }
    return $html;
}

/**
 * Personal Vault: fixed category list offered in the create/edit forms.
 */
function personalCategoryOptions(): array
{
    return ['Social', 'Bank', 'Email', 'Shopping', 'Work', 'Other'];
}

/**
 * Personal Vault: icon shown next to the entry title, keyed by category.
 */
function personalCategoryIcon(string $category): string
{
    $icons = [
        'Social'   => '💬',
        'Bank'     => '🏦',
        'Email'    => '📧',
        'Shopping' => '🛒',
        'Work'     => '💼',
        'Other'    => '🔒',
    ];
    return $icons[$category] ?? '🔒';
}

/**
 * Whether the current user may view/reveal/edit/delete a specific credential,
 * based on its Access Level (assigned roles). Administrators always pass.
 * A credential with no roles assigned is unrestricted (open to anyone with
 * the base passwords.view/passwords.manage permission).
 */
function canAccessCredential(int $passwordId): bool
{
    global $db;

    if (isAdministrator()) {
        return true;
    }

    static $cache = [];
    if (array_key_exists($passwordId, $cache)) {
        return $cache[$passwordId];
    }

    $stmt = $db->prepare('SELECT role_id FROM password_roles WHERE password_id = :id');
    $stmt->execute(['id' => $passwordId]);
    $allowedRoleIds = array_map('intval', array_column($stmt->fetchAll(), 'role_id'));

    $result = !$allowedRoleIds || array_intersect($allowedRoleIds, currentUserRoleIds());
    $cache[$passwordId] = (bool) $result;
    return $cache[$passwordId];
}

/**
 * Record a vault action (reveal/create/update/delete) to the audit log.
 * Never throws: auditing must not block the primary action if it fails.
 */
function logAudit(string $action, ?int $passwordId, string $passwordTitle): void
{
    global $db;
    try {
        $stmt = $db->prepare(
            'INSERT INTO audit_log (user_id, action, password_id, password_title, ip_address)
             VALUES (:user_id, :action, :password_id, :password_title, :ip)'
        );
        $stmt->execute([
            'user_id'        => isLoggedIn() ? currentUser()['id'] : null,
            'action'         => $action,
            'password_id'    => $passwordId,
            'password_title' => $passwordTitle,
            'ip'             => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $e) {
        // Audit table may not exist yet if migrate.php hasn't run; don't break the request.
    }
}
