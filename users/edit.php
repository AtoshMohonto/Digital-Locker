<?php
/**
 * Edit a user account: profile, roles, password reset, enable/disable.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('users.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isSelf = $id === (int) currentUser()['id'];

$stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    flash('error', 'User not found.');
    redirect(BASE_URL . '/users/index.php');
}

$pageTitle = 'Edit user';
$activePage = 'users';

$roles = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();

$stmt = $db->prepare('SELECT role_id FROM user_roles WHERE user_id = :uid');
$stmt->execute(['uid' => $id]);
$selectedRoles = array_map('intval', array_column($stmt->fetchAll(), 'role_id'));
$wasAdministrator = (int) $item['is_active'] === 1 && in_array(1, $selectedRoles, true);

$errors = [];
$old = [
    'username'  => $item['username'],
    'email'     => $item['email'],
    'full_name' => $item['full_name'],
    'password'  => '',
    'is_active' => (int) $item['is_active'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'username'  => trim($_POST['username'] ?? ''),
            'email'     => trim($_POST['email'] ?? ''),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'password'  => (string) ($_POST['password'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        $selectedRoles = array_map('intval', $_POST['roles'] ?? []);

        if ($old['username'] === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $old['username'])) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore).';
        }
        if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($old['password'] !== '') {
            $errors = array_merge($errors, validatePasswordPolicy($old['password'], effectivePolicy()));
        }
        if ($isSelf && $old['is_active'] === 0) {
            $errors[] = 'You cannot disable your own account.';
        }

        $willStayAdministrator = $old['is_active'] === 1 && in_array(1, $selectedRoles, true);
        if ($wasAdministrator && !$willStayAdministrator && activeAdministratorCount($id) === 0) {
            $errors[] = 'You cannot remove, disable, or strip Administrator from the last active Administrator account.';
        }

        if (!$errors) {
            try {
                $db->beginTransaction();

                $sql = 'UPDATE users
                           SET username = :username, email = :email, full_name = :full_name, is_active = :is_active';
                $params = [
                    'username'  => $old['username'],
                    'email'     => $old['email'],
                    'full_name' => $old['full_name'],
                    'is_active' => $old['is_active'],
                ];

                if ($old['password'] !== '') {
                    $sql .= ', password_hash = :hash';
                    $params['hash'] = password_hash($old['password'], PASSWORD_BCRYPT);
                }

                $sql .= ' WHERE id = :id';
                $params['id'] = $id;

                $db->prepare($sql)->execute($params);

                $stmt = $db->prepare('DELETE FROM user_roles WHERE user_id = :uid');
                $stmt->execute(['uid' => $id]);

                $stmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
                foreach ($selectedRoles as $roleId) {
                    $stmt->execute(['uid' => $id, 'rid' => $roleId]);
                }

                $db->commit();
                flash('success', 'User updated.');

                if ($isSelf && $old['username'] !== $item['username']) {
                    $_SESSION['user']['username'] = $old['username'];
                }

                redirect(BASE_URL . '/users/index.php');
            } catch (PDOException $e) {
                $db->rollBack();
                $errors[] = 'Unable to save user. Username or email may already be in use.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Edit user<?= $isSelf ? ' (you)' : '' ?></h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="edit.php?id=<?= (int) $id ?>" novalidate>
        <?= csrf_field() ?>

        <div class="grid grid--two">
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>">
        </div>

        <div class="form-group">
            <label for="password">Reset password <span class="muted">(leave empty to keep current)</span></label>
            <div class="input-row">
                <input type="text" id="password" name="password" value="<?= e($old['password']) ?>">
                <button type="button" class="btn" id="generate-btn">Generate</button>
            </div>
        </div>

        <div class="form-group">
            <label>Roles</label>
            <?php foreach ($roles as $role): ?>
                <label class="checkbox">
                    <input type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                           <?= in_array((int) $role['id'], $selectedRoles, true) ? 'checked' : '' ?>>
                    <span><?= e($role['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" <?= $old['is_active'] === 1 ? 'checked' : '' ?>>
                <span>Account is active</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save changes</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
