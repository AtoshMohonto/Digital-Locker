<?php
/**
 * Password settings: password policy, auto-lock, reveal confirmation.
 */
require_once __DIR__ . '/../includes/init.php';
requirePermission('settings.manage');

$pageTitle = 'Password Settings';
$activePage = 'settings';

$errors = [];
$old = [
    'app_name'             => appName(),
    'min_length'           => (int) setting('policy_min_length', '12'),
    'require_upper'        => (int) setting('policy_require_upper', '1'),
    'require_lower'        => (int) setting('policy_require_lower', '1'),
    'require_digit'        => (int) setting('policy_require_digit', '1'),
    'require_special'      => (int) setting('policy_require_special', '1'),
    'auto_lock_minutes'    => (int) setting('auto_lock_minutes', '5'),
    'require_confirm_reveal' => (int) setting('require_confirm_reveal', '1'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please try again.';
    } else {
        $old = [
            'app_name'             => trim($_POST['app_name'] ?? ''),
            'min_length'           => max(4, (int) ($_POST['min_length'] ?? 12)),
            'require_upper'        => isset($_POST['require_upper']) ? 1 : 0,
            'require_lower'        => isset($_POST['require_lower']) ? 1 : 0,
            'require_digit'        => isset($_POST['require_digit']) ? 1 : 0,
            'require_special'      => isset($_POST['require_special']) ? 1 : 0,
            'auto_lock_minutes'    => max(0, (int) ($_POST['auto_lock_minutes'] ?? 5)),
            'require_confirm_reveal' => isset($_POST['require_confirm_reveal']) ? 1 : 0,
        ];

        if ($old['app_name'] === '') {
            $errors[] = 'App name is required.';
        }

        if (!$errors) {
            $values = [
                'app_name'              => $old['app_name'],
                'policy_min_length'     => (string) $old['min_length'],
                'policy_require_upper'  => (string) $old['require_upper'],
                'policy_require_lower'  => (string) $old['require_lower'],
                'policy_require_digit'  => (string) $old['require_digit'],
                'policy_require_special' => (string) $old['require_special'],
                'auto_lock_minutes'     => (string) $old['auto_lock_minutes'],
                'require_confirm_reveal' => (string) $old['require_confirm_reveal'],
            ];

            $stmt = $db->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($values as $key => $value) {
                $stmt->execute(['key' => $key, 'value' => $value]);
            }

            flash('success', 'Settings saved.');
            redirect(BASE_URL . '/settings/index.php');
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="card card--narrow">
    <div class="card__header">
        <h2 class="card__title">Password settings</h2>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <form method="post" action="index.php" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="app_name">Application name</label>
            <input type="text" id="app_name" name="app_name" value="<?= e($old['app_name']) ?>" required>
        </div>

        <h3 class="perm-group-title">Password policy</h3>
        <div class="form-group">
            <label for="min_length">Minimum length</label>
            <input type="number" id="min_length" name="min_length" min="4" max="64" value="<?= (int) $old['min_length'] ?>">
        </div>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="require_upper" value="1" <?= $old['require_upper'] ? 'checked' : '' ?>>
                <span>Require uppercase letters (A-Z)</span>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="require_lower" value="1" <?= $old['require_lower'] ? 'checked' : '' ?>>
                <span>Require lowercase letters (a-z)</span>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="require_digit" value="1" <?= $old['require_digit'] ? 'checked' : '' ?>>
                <span>Require digits (0-9)</span>
            </label>
            <label class="checkbox">
                <input type="checkbox" name="require_special" value="1" <?= $old['require_special'] ? 'checked' : '' ?>>
                <span>Require special characters (!@#$...)</span>
            </label>
        </div>

        <h3 class="perm-group-title">Security</h3>
        <div class="form-group">
            <label for="auto_lock_minutes">Auto-lock after inactivity (minutes, 0 = never)</label>
            <input type="number" id="auto_lock_minutes" name="auto_lock_minutes" min="0" max="720" value="<?= (int) $old['auto_lock_minutes'] ?>">
        </div>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="require_confirm_reveal" value="1" <?= $old['require_confirm_reveal'] ? 'checked' : '' ?>>
                <span>Ask for confirmation before revealing a secret</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save settings</button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
