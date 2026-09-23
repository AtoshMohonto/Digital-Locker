<?php
/**
 * Edit a project.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('projects.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $db->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'Project not found.');
    redirect(BASE_URL . '/projects/index.php');
}

// A Manager may only edit projects they're a member of; an Administrator can
// edit any of them.
if (!isAdministrator() && !canAccessProject($id)) {
    require __DIR__ . '/../403.php';
    exit;
}

$pageTitle = 'Edit project';
$activePage = 'projects';

$categories = projectCategoryOptions();
$types = projectTypeOptions();
$purposes = projectPurposeOptions();
$purposesByContext = projectPurposesByContext();
$errors = [];
$old = ['name' => $item['name'], 'category' => (string) $item['category'], 'type' => (string) $item['type'], 'role' => (string) $item['role'], 'description' => (string) $item['description']];

// Team: manage which Managers/Testers this project needs right from the edit
// form. Each user appears under only one of these two lists (their
// highest-priority role), same grouping as the Credential Assignments pickers.
$canManageTeam = hasPermission('tasks.manage');
$managerCandidates = [];
$testerCandidates = [];
foreach (usersGroupedByRole() as $ug) {
    if ($ug['label'] === 'Managers') {
        $managerCandidates = $ug['users'];
    } elseif ($ug['label'] === 'Testers') {
        $testerCandidates = $ug['users'];
    }
}
$currentMembers = $db->prepare('SELECT user_id, project_role FROM project_members WHERE project_id = :id');
$currentMembers->execute(['id' => $id]);
$currentManagerIds = [];
$currentTesterIds = [];
foreach ($currentMembers->fetchAll() as $m) {
    if ($m['project_role'] === 'manager') {
        $currentManagerIds[] = (int) $m['user_id'];
    } else {
        $currentTesterIds[] = (int) $m['user_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'name'        => trim($_POST['name'] ?? ''),
            'category'    => trim($_POST['category'] ?? ''),
            'type'        => trim($_POST['type'] ?? ''),
            'role'        => trim($_POST['role'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        if ($old['name'] === '') {
            $errors[] = 'Project name is required.';
        }

        if (!$errors) {
            try {
                $stmt = $db->prepare('UPDATE projects SET name = :name, category = :category, type = :type, role = :role, description = :description WHERE id = :id');
                $stmt->execute(['name' => $old['name'], 'category' => $old['category'], 'type' => $old['type'], 'role' => $old['role'], 'description' => $old['description'], 'id' => $id]);
                rememberProjectCategory($old['category']);
                rememberProjectType($old['type']);
                rememberProjectPurpose($old['role']);

                if ($canManageTeam) {
                    $managerIds = array_intersect(
                        array_map('intval', $_POST['manager_ids'] ?? []),
                        array_column($managerCandidates, 'id')
                    );
                    $testerIds = array_intersect(
                        array_map('intval', $_POST['tester_ids'] ?? []),
                        array_column($testerCandidates, 'id')
                    );
                    // Replace membership only for the candidates actually shown on
                    // this form -- leaves any other existing row (e.g. a now-
                    // inactive user) untouched instead of silently dropping it.
                    $allCandidateIds = array_merge(array_column($managerCandidates, 'id'), array_column($testerCandidates, 'id'));
                    if ($allCandidateIds) {
                        $placeholders = implode(',', array_fill(0, count($allCandidateIds), '?'));
                        $del = $db->prepare("DELETE FROM project_members WHERE project_id = ? AND user_id IN ($placeholders)");
                        $del->execute(array_merge([$id], $allCandidateIds));
                    }
                    $memberStmt = $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, project_role) VALUES (:pid, :uid, :role)');
                    foreach ($managerIds as $uid) {
                        $memberStmt->execute(['pid' => $id, 'uid' => $uid, 'role' => 'manager']);
                    }
                    foreach ($testerIds as $uid) {
                        $memberStmt->execute(['pid' => $id, 'uid' => $uid, 'role' => 'tester']);
                    }
                }

                flash('success', 'Project updated.');
                redirect(BASE_URL . '/projects/index.php');
            } catch (PDOException $e) {
                $errors[] = 'A project with that name already exists.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit project</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required>
        </div>

        <div class="grid grid--two">
            <div class="form-group">
                <label for="category">Category <span class="muted">(pick one or type a new one)</span></label>
                <input type="text" id="category" name="category" value="<?= e($old['category']) ?>" list="category-suggestions" placeholder="e.g. Freelancing">
                <datalist id="category-suggestions">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label for="type">Type <span class="muted">(pick one or type a new one)</span></label>
                <input type="text" id="type" name="type" value="<?= e($old['type']) ?>" list="type-suggestions" placeholder="e.g. Web App">
                <datalist id="type-suggestions">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= e($t) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <div class="form-group">
            <label for="role">Role <span class="muted">(what the web app is for -- e.g. Data Collection, E-commerce, Login System; suggestions narrow to your Category/Type)</span></label>
            <input type="text" id="role" name="role" value="<?= e($old['role']) ?>" list="role-suggestions" placeholder="e.g. Data Collection">
            <datalist id="role-suggestions">
                <?php foreach ($purposes as $r): ?>
                    <option value="<?= e($r) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="3"><?= e($old['description']) ?></textarea>
        </div>

        <?php if ($canManageTeam && ($managerCandidates || $testerCandidates)): ?>
            <div class="form-group">
                <label>Team</label>
                <p class="muted" style="margin:-4px 0 10px">Which Managers and Testers this project needs.</p>
                <div class="team-picker">
                    <div class="team-picker__col">
                        <h4 class="perm-group-title">Managers</h4>
                        <?php foreach ($managerCandidates as $u): ?>
                            <label class="assignee-picker__option">
                                <input type="checkbox" name="manager_ids[]" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $currentManagerIds, true) ? 'checked' : '' ?>>
                                <?= e($u['full_name'] ?: $u['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="team-picker__col">
                        <h4 class="perm-group-title">Testers</h4>
                        <?php foreach ($testerCandidates as $u): ?>
                            <label class="assignee-picker__option">
                                <input type="checkbox" name="tester_ids[]" value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $currentTesterIds, true) ? 'checked' : '' ?>>
                                <?= e($u['full_name'] ?: $u['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save changes</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<script>
    (function () {
        var purposesByContext = <?= json_encode($purposesByContext, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        var allPurposes = <?= json_encode($purposes, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

        var categoryInput = document.getElementById('category');
        var typeInput = document.getElementById('type');
        var roleList = document.getElementById('role-suggestions');
        if (!categoryInput || !typeInput || !roleList) { return; }

        function refreshRoleSuggestions() {
            var key = categoryInput.value.trim() + '|' + typeInput.value.trim();
            var contextual = purposesByContext[key] || [];
            var combined = contextual.concat(allPurposes.filter(function (p) { return contextual.indexOf(p) === -1; }));
            roleList.textContent = '';
            combined.forEach(function (name) {
                var opt = document.createElement('option');
                opt.value = name;
                roleList.appendChild(opt);
            });
        }

        categoryInput.addEventListener('input', refreshRoleSuggestions);
        typeInput.addEventListener('input', refreshRoleSuggestions);
    })();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
