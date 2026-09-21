<?php
/**
 * Shared definition of the permission catalogue used by roles create/edit.
 */
function permissionCatalogue(): array
{
    return [
        'passwords.view'    => ['group' => 'Passwords', 'label' => 'View passwords & reveal secrets'],
        'passwords.manage'  => ['group' => 'Passwords', 'label' => 'Create, edit and delete passwords'],
        'projects.manage'   => ['group' => 'Vault',     'label' => 'Manage projects'],
        'tasks.manage'      => ['group' => 'Project workflow', 'label' => 'Assign project team members and manage tasks'],
        'tasks.view'        => ['group' => 'Project workflow', 'label' => 'View and act on assigned tasks'],
        'roles.manage'      => ['group' => 'Administration', 'label' => 'Manage roles & permissions'],
        'users.manage'      => ['group' => 'Administration', 'label' => 'Manage user accounts'],
        'settings.manage'   => ['group' => 'Administration', 'label' => 'Change password settings'],
    ];
}
