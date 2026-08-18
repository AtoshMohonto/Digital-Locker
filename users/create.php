<?php
/**
 * Create a user account and assign roles.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('users.manage');

$pageTitle = 'New user';
$activePage = 'users';

$roles = $db->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();

$errors = [];
$old = ['username' => '', 'email' => '', 'full_name' => '', 'password' => ''];
$selectedRoles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'username'  => trim($_POST['username'] ?? ''),
            'email'     => trim($_POST['email'] ?? ''),
            'full_name' => trim($_POST['full_name'] ?? ''),
            'password'  => (string) ($_POST['password'] ?? ''),
        ];
        $selectedRoles = array_map('intval', $_POST['roles'] ?? []);

        if ($old['username'] === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $old['username'])) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, dot, dash, underscore).';
        }
        if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (validatePasswordPolicy($old['password'], effectivePolicy())) {
            $errors = array_merge($errors, validatePasswordPolicy($old['password'], effectivePolicy()));
        }

        if (!$errors) {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare(
                    'INSERT INTO users (username, email, full_name, password_hash, is_active)
                     VALUES (:username, :email, :full_name, :hash, 1)'
                );
                $stmt->execute([
                    'username'  => $old['username'],
                    'email'     => $old['email'],
                    'full_name' => $old['full_name'],
                    'hash'      => password_hash($old['password'], PASSWORD_BCRYPT),
                ]);
                $userId = (int) $db->lastInsertId();

                $stmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
                foreach ($selectedRoles as $roleId) {
                    $stmt->execute(['uid' => $userId, 'rid' => $roleId]);
                }

                $db->commit();
                flash('success', 'User "' . $old['username'] . '" created.');
                redirect(BASE_URL . '/users/index.php');
            } catch (PDOException $e) {
                $db->rollBack();
                $errors[] = 'Unable to create user. Username or email may already be in use.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">New user</h2>
        <a class="btn btn--small" href="index.php">&larr; Back</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="create.php" novalidate>
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
            <label for="password">Password *</label>
            <div class="input-row">
                <input type="text" id="password" name="password" value="<?= e($old['password']) ?>" required>
                <button type="button" class="btn" id="generate-btn">Generate</button>
            </div>
            <p class="muted">Must meet the policy configured under Password Settings.</p>
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

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Create user</button>
            <a class="btn btn--ghost" href="index.php">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
