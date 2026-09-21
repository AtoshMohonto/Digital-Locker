<?php
/**
 * Team roster, framed differently per viewer:
 *  - Administrator : every Manager, and every Tester (system-wide).
 *  - Manager       : "My Team" -- testers who share at least one project with them.
 *  - Tester        : "Mentors" -- managers who share at least one project with them.
 * Read-only; team membership itself is edited from a project's own Team section.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$isAdmin = isAdministrator();
$isManager = !$isAdmin && hasPermission('tasks.manage');
$myUserId = (int) currentUser()['id'];

$activePage = 'team';

if ($isAdmin) {
    $pageTitle = 'Managers & Tester Team';
    $managers = $db->query(
        "SELECT u.id, u.username, u.full_name, u.email,
                (SELECT COUNT(*) FROM project_members pm WHERE pm.user_id = u.id AND pm.project_role = 'manager') AS project_count
           FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id
          WHERE r.name = 'Manager' AND u.is_active = 1
          ORDER BY u.full_name"
    )->fetchAll();
    $testers = $db->query(
        "SELECT u.id, u.username, u.full_name, u.email,
                (SELECT COUNT(*) FROM project_members pm WHERE pm.user_id = u.id AND pm.project_role = 'tester') AS project_count
           FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id
          WHERE r.name = 'Tester' AND u.is_active = 1
          ORDER BY u.full_name"
    )->fetchAll();
} elseif ($isManager) {
    $pageTitle = 'My Team';
    $teammates = projectTeammates($myUserId, 'manager', 'tester');
} else {
    $pageTitle = 'Mentors';
    $mentors = projectTeammates($myUserId, 'tester', 'manager');
}

require __DIR__ . '/../includes/header.php';
?>

<?php if ($isAdmin): ?>
    <div class="grid grid--two">
        <div class="card">
            <div class="card__header"><h2 class="card__title">Managers</h2></div>
            <?php if ($managers): ?>
                <div class="team-list">
                    <?php foreach ($managers as $m): ?>
                        <div class="team-list__row">
                            <span class="team-list__name"><?= e($m['full_name'] ?: $m['username']) ?></span>
                            <span class="muted"><?= e($m['email']) ?></span>
                            <span class="badge badge--role"><?= (int) $m['project_count'] ?> project<?= (int) $m['project_count'] === 1 ? '' : 's' ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">No managers yet.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__header"><h2 class="card__title">Tester Team</h2></div>
            <?php if ($testers): ?>
                <div class="team-list">
                    <?php foreach ($testers as $t): ?>
                        <div class="team-list__row">
                            <span class="team-list__name"><?= e($t['full_name'] ?: $t['username']) ?></span>
                            <span class="muted"><?= e($t['email']) ?></span>
                            <span class="badge badge--role"><?= (int) $t['project_count'] ?> project<?= (int) $t['project_count'] === 1 ? '' : 's' ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="muted">No testers yet.</p>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($isManager): ?>
    <div class="card">
        <div class="card__header"><h2 class="card__title">My Team</h2></div>
        <p class="muted" style="margin-top:-8px">Testers working with you, across every project you manage.</p>
        <?php if ($teammates): ?>
            <div class="team-list">
                <?php foreach ($teammates as $t): ?>
                    <div class="team-list__row">
                        <span class="team-list__name"><?= e($t['full_name'] ?: $t['username']) ?></span>
                        <?php foreach (explode('||', $t['shared_projects']) as $proj): ?>
                            <span class="badge badge--role">📁 <?= e($proj) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">No testers assigned to your projects yet. Add them from a project's Team section.</p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card__header"><h2 class="card__title">Mentors</h2></div>
        <p class="muted" style="margin-top:-8px">The managers overseeing the projects you're assigned to.</p>
        <?php if ($mentors): ?>
            <div class="team-list">
                <?php foreach ($mentors as $m): ?>
                    <div class="team-list__row">
                        <span class="team-list__name"><?= e($m['full_name'] ?: $m['username']) ?></span>
                        <?php foreach (explode('||', $m['shared_projects']) as $proj): ?>
                            <span class="badge badge--role">📁 <?= e($proj) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">You haven't been assigned to a project yet.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
