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

function navIcon(string $name): string
{
    $icons = [
        'dashboard' => '<path d="M3 3h8v8H3zM13 3h8v5h-8zM13 12h8v9h-8zM3 15h8v6H3z"/>',
        'passwords' => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L21 2M18 5l3 3M15.5 7.5l2 2"/>',
        'personal'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'export'    => '<path d="M12 3v12M8 11l4 4 4-4M4 17v3h16v-3"/>',
        'projects'  => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'roles'     => '<path d="M12 3l8 3v6c0 4.5-3.2 7.6-8 9-4.8-1.4-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M17.5 14.5c2.5.6 4 2.3 4 5.5"/>',
        'settings'  => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2.5"/><circle cx="8" cy="17" r="2.5"/>',
        'logout'    => '<path d="M14 4H5v16h9M10 12h11M18 8l4 4-4 4"/>',
        'lock'      => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3M12 15v3"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(pageTitle($pageTitle)) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='5' y='11' width='14' height='10' rx='2' fill='%232563eb'/%3E%3Cpath d='M8 11V8a4 4 0 0 1 8 0v3' fill='none' stroke='%232563eb' stroke-width='2'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body data-autolock="<?= (int) setting('auto_lock_minutes', '0') ?>" data-confirm-reveal="<?= (int) setting('require_confirm_reveal', '0') ?>" data-lock-url="<?= e(BASE_URL . '/lock.php') ?>">
<div class="layout">

    <aside class="sidebar" id="sidebar">
        <a class="sidebar__brand" href="<?= BASE_URL ?>/dashboard.php" aria-label="<?= e(appName()) ?>">
            <?= navIcon('lock') ?>
            <span class="sidebar__brand-text"><?= e(appName()) ?></span>
        </a>

        <nav class="sidebar__nav" aria-label="Main navigation">
            <div class="sidebar__section-label">General</div>
            <a class="sidebar__link<?= isActive('dashboard', $activePage) ?>" href="<?= BASE_URL ?>/dashboard.php">
                <?= navIcon('dashboard') ?>
                <span class="sidebar__link-text">Dashboard</span>
            </a>

            <div class="sidebar__section-label">Vault</div>
            <?php if (hasPermission('passwords.view')): ?>
                <a class="sidebar__link<?= isActive('passwords', $activePage) ?>" href="<?= BASE_URL ?>/passwords/index.php">
                    <?= navIcon('passwords') ?>
                    <span class="sidebar__link-text">Credential Vault</span>
                </a>
            <?php endif; ?>

            <a class="sidebar__link<?= isActive('personal', $activePage) ?>" href="<?= BASE_URL ?>/personal/index.php">
                <?= navIcon('personal') ?>
                <span class="sidebar__link-text">Personal Vault</span>
            </a>

            <a class="sidebar__link<?= isActive('tools', $activePage) ?>" href="<?= BASE_URL ?>/tools/index.php">
                <?= navIcon('export') ?>
                <span class="sidebar__link-text">Import / Export</span>
            </a>

            <?php if (hasPermission('projects.manage')): ?>
                <a class="sidebar__link<?= isActive('projects', $activePage) ?>" href="<?= BASE_URL ?>/projects/index.php">
                    <?= navIcon('projects') ?>
                    <span class="sidebar__link-text">Projects</span>
                </a>
            <?php endif; ?>

            <div class="sidebar__section-label">Administration</div>
            <?php if (hasPermission('roles.manage')): ?>
                <a class="sidebar__link<?= isActive('roles', $activePage) ?>" href="<?= BASE_URL ?>/roles/index.php">
                    <?= navIcon('roles') ?>
                    <span class="sidebar__link-text">Roles &amp; Permissions</span>
                </a>
            <?php endif; ?>

            <?php if (hasPermission('users.manage')): ?>
                <a class="sidebar__link<?= isActive('users', $activePage) ?>" href="<?= BASE_URL ?>/users/index.php">
                    <?= navIcon('users') ?>
                    <span class="sidebar__link-text">User Accounts</span>
                </a>
            <?php endif; ?>

            <?php if (hasPermission('settings.manage')): ?>
                <a class="sidebar__link<?= isActive('settings', $activePage) ?>" href="<?= BASE_URL ?>/settings/index.php">
                    <?= navIcon('settings') ?>
                    <span class="sidebar__link-text">Password Settings</span>
                </a>
            <?php endif; ?>
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
            <div class="topbar__user">
                <?php foreach ($userRoles as $role): ?>
                    <span class="badge badge--role"><?= e($role['name']) ?></span>
                <?php endforeach; ?>
                <span class="topbar__user-name"><?= e($currentUser['full_name'] ?: $currentUser['username']) ?></span>
                <a class="btn btn--small" href="<?= BASE_URL ?>/logout.php">Sign out</a>
            </div>
        </header>

        <main class="content">
            <?= renderFlash() ?>
