<?php
/**
 * Layout header: document head, topbar, responsive sidebar navigation.
 * Pages must define $pageTitle (string) and optionally $activePage (string).
 */
require_once __DIR__ . '/init.php';

if (!isset($pageTitle)) {
    $pageTitle = 'Dashboard';
}
if (!isset($activePage)) {
    $activePage = '';
}

$currentUser = currentUser();
$userRoles   = isLoggedIn() ? userRoleNames() : [];

function isActive(string $name, string $current): string
{
    return $name === $current ? ' active' : '';
}

/**
 * Sidebar nav: Dashboard is a standalone always-visible link (like myself's
 * layout), the rest live in collapsible sections -- all start expanded so
 * nothing is ever hidden by default; collapsing a section is purely a
 * user choice, not something that happens automatically based on the page.
 */
function sidebarGroups(): array
{
    return [
        'vault' => ['label' => 'Vault', 'items' => [
            ['key' => 'passwords', 'href' => '/passwords/index.php', 'icon' => 'passwords', 'label' => 'Credential Vault', 'show' => hasPermission('passwords.view')],
            ['key' => 'personal', 'href' => '/personal/index.php', 'icon' => 'personal', 'label' => 'Personal Vault', 'show' => true],
            ['key' => 'projects', 'href' => '/projects/index.php', 'icon' => 'projects', 'label' => hasPermission('projects.manage') ? 'Projects' : 'My Projects', 'show' => hasPermission('projects.manage') || hasAnyProjectMembership()],
        ]],
        'workspace' => ['label' => 'Workspace', 'items' => [
            ['key' => 'tasks', 'href' => '/tasks/index.php', 'icon' => 'tasks', 'label' => 'My Tasks', 'show' => hasPermission('tasks.view')],
            ['key' => 'notes', 'href' => '/notes/index.php', 'icon' => 'notes', 'label' => 'My Notes & To-Do', 'show' => true],
            ['key' => 'team', 'href' => '/team/index.php', 'icon' => 'team', 'label' => isAdministrator() ? 'Managers & Testers' : (hasPermission('tasks.manage') ? 'My Team' : 'Mentors'), 'show' => true],
        ]],
        'administration' => ['label' => 'Administration', 'items' => [
            ['key' => 'assignments', 'href' => '/passwords/assignments.php', 'icon' => 'assignments', 'label' => 'Credential Assignments', 'show' => isAdministrator()],
            ['key' => 'categories', 'href' => '/categories/index.php', 'icon' => 'categories', 'label' => 'Project Categories & Types', 'show' => isAdministrator()],
            ['key' => 'roles', 'href' => '/roles/index.php', 'icon' => 'roles', 'label' => 'Roles &amp; Permissions', 'show' => hasPermission('roles.manage')],
            ['key' => 'users', 'href' => '/users/index.php', 'icon' => 'users', 'label' => 'User Accounts', 'show' => hasPermission('users.manage')],
            ['key' => 'settings', 'href' => '/settings/index.php', 'icon' => 'settings', 'label' => 'Password Settings', 'show' => hasPermission('settings.manage')],
        ]],
    ];
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $letters = array_map(static fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_filter($parts));
    return implode('', array_slice($letters, 0, 2)) ?: '?';
}

function navIcon(string $name): string
{
    $icons = [
        'dashboard' => '<path d="M3 3h8v8H3zM13 3h8v5h-8zM13 12h8v9h-8zM3 15h8v6H3z"/>',
        'passwords' => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L21 2M18 5l3 3M15.5 7.5l2 2"/>',
        'personal'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'export'    => '<path d="M12 3v12M8 11l4 4 4-4M4 17v3h16v-3"/>',
        'projects'  => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'tasks'     => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/>',
        'notes'     => '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M15 3v5h5M8 13h8M8 17h5"/>',
        'team'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M17.5 14.5c2.5.6 4 2.3 4 5.5"/>',
        'categories' => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
        'assignments' => '<circle cx="9" cy="7" r="4"/><path d="M2.5 21c0-4 3-6.5 6.5-6.5s6.5 2.5 6.5 6.5"/><path d="M17 8l2 2 4-4"/>',
        'roles'     => '<path d="M12 3l8 3v6c0 4.5-3.2 7.6-8 9-4.8-1.4-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M17.5 14.5c2.5.6 4 2.3 4 5.5"/>',
        'settings'  => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2.5"/><circle cx="8" cy="17" r="2.5"/>',
        'logout'    => '<path d="M14 4H5v16h9M10 12h11M18 8l4 4-4 4"/>',
        'lock'      => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3M12 15v3"/>',
    ];
    return '<svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(pageTitle($pageTitle)) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='5' y='11' width='14' height='10' rx='2' fill='%232563eb'/%3E%3Cpath d='M8 11V8a4 4 0 0 1 8 0v3' fill='none' stroke='%232563eb' stroke-width='2'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= e(assetUrl('/assets/css/style.css')) ?>">
</head>
<body data-autolock="<?= (int) setting('auto_lock_minutes', '0') ?>" data-confirm-reveal="<?= (int) setting('require_confirm_reveal', '0') ?>" data-lock-url="<?= e(BASE_URL . '/lock.php') ?>">
<div class="layout">

    <aside class="sidebar" id="sidebar">
        <a class="sidebar__brand" href="<?= BASE_URL ?>/dashboard.php" aria-label="<?= e(appName()) ?>">
            <?= navIcon('lock') ?>
            <span class="sidebar__brand-text"><?= e(appName()) ?></span>
        </a>

        <nav class="sidebar__nav" aria-label="Main navigation">
            <a class="sidebar__link<?= isActive('dashboard', $activePage) ?>" href="<?= BASE_URL ?>/dashboard.php">
                <?= navIcon('dashboard') ?>
                <span class="sidebar__link-text">Dashboard</span>
            </a>

            <?php foreach (sidebarGroups() as $navGroup):
                // Deliberately namespaced ($navGroup/$navItem, not $group/$item):
                // this file is require()d into every page in the *same* variable
                // scope, so a generic name here would silently clobber a caller's
                // own $item/$group (this exact collision broke passwords/view.php,
                // which sets $item to the credential row before requiring header.php).
                $navItems = array_filter($navGroup['items'], static fn ($it) => $it['show']);
                if (!$navItems) {
                    continue;
                }
            ?>
                <details class="sidebar__group" open>
                    <summary class="sidebar__group-label"><?= e($navGroup['label']) ?></summary>
                    <?php foreach ($navItems as $navItem): ?>
                        <a class="sidebar__link<?= isActive($navItem['key'], $activePage) ?>" href="<?= BASE_URL . $navItem['href'] ?>">
                            <?= navIcon($navItem['icon']) ?>
                            <span class="sidebar__link-text"><?= $navItem['label'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar__footer">
            <a class="sidebar__link" href="<?= BASE_URL ?>/logout.php">
                <?= navIcon('logout') ?>
                <span class="sidebar__link-text">Sign out</span>
            </a>
        </div>
    </aside>

    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="main">
        <header class="topbar">
            <button class="topbar__toggle" id="sidebar-toggle" aria-label="Toggle navigation" aria-expanded="true">
                <span></span><span></span><span></span>
            </button>
            <h1 class="topbar__title"><?= e($pageTitle) ?></h1>
            <div class="topbar__user" id="user-menu">
                <button type="button" class="topbar__user-btn" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                    <span class="topbar__user-name"><?= e($currentUser['full_name'] ?: $currentUser['username']) ?></span>
                    <span class="topbar__avatar"><?= e(initials($currentUser['full_name'] ?: $currentUser['username'])) ?></span>
                </button>
                <div class="topbar__dropdown" id="user-menu-dropdown">
                    <div class="topbar__dropdown-header">
                        <div class="topbar__dropdown-name"><?= e($currentUser['full_name'] ?: $currentUser['username']) ?></div>
                        <div class="topbar__dropdown-email"><?= e($currentUser['email'] ?? '') ?></div>
                        <div class="topbar__dropdown-roles">
                            <?php foreach ($userRoles as $navRole): ?>
                                <span class="badge badge--role"><?= e($navRole['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <a class="topbar__dropdown-item" href="<?= BASE_URL ?>/logout.php">
                        <?= navIcon('logout') ?> Sign out
                    </a>
                </div>
            </div>
        </header>

        <main class="content">
            <?= renderFlash() ?>
