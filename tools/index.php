<?php
/**
 * Tools hub: CSV backup/restore and htdocs project import.
 */
require_once __DIR__ . '/../includes/init.php';
requireLogin();

$pageTitle = 'Import / Export';
$activePage = 'tools';

require __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card__header">
        <h2 class="card__title">Backup &amp; import tools</h2>
    </div>

    <div class="grid grid--two">
        <div class="card">
            <div class="card__header">
                <h2 class="card__title">Export passwords (CSV)</h2>
            </div>
            <p class="muted">
                Download every vault entry as a CSV file. Secrets are decrypted in the file,
                so treat it as sensitive backup data.
            </p>
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn btn--primary" href="<?= BASE_URL ?>/passwords/export.php">Download CSV</a>
            <?php else: ?>
                <p class="muted">You need the <code>passwords.manage</code> permission to export.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card__header">
                <h2 class="card__title">Import passwords (CSV)</h2>
            </div>
            <p class="muted">
                Restore or bulk-add entries from a CSV file (same format as the export).
                Projects are created automatically; roles are matched by name.
            </p>
            <?php if (hasPermission('passwords.manage')): ?>
                <a class="btn" href="<?= BASE_URL ?>/passwords/import.php">Import CSV</a>
            <?php else: ?>
                <p class="muted">You need the <code>passwords.manage</code> permission to import.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
