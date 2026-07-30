<?= $this->extend('layout/main') ?>

<?php /*
── ADAPT THIS TO YOUR APP ─────────────────────────────────────────────────
 - This file was copied into app/Views/admin/updates.php by
   `php spark updater:setup` — it's yours to edit, `composer update` never
   touches it again.
 - 'layout/main' must provide a 'content' section and (optionally) a
   'head'/'scripts' section, and render flash messages ('success'/'error').
 - Remove/replace the admin_subnav include below if you don't have one.
 - Replace "Your App" with your actual app name.
───────────────────────────────────────────────────────────────────────── */ ?>

<?= $this->section('head') ?>
<meta name="csrf-field" content="<?= csrf_token() ?>">
<meta name="csrf-hash"  content="<?= csrf_hash() ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php // If you have an admin subnav, add: $this->include('layout/admin_subnav') ?>

<div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-arrow-repeat text-warning fs-4"></i>
    <h2 class="h5 mb-0">Updates</h2>
</div>

<?php if ($pendingCount > 0 && ! $upgradePending): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= $pendingCount ?> pending SQL migration<?= $pendingCount > 1 ? 's' : '' ?>.</div>
</div>
<?php endif; ?>

<?php // ── Step 2: upgrade pending — show diff & apply ── ?>
<?php if ($upgradePending): ?>
<?php
    $diff         = $upgradePending['diff'];
    $changedFiles = array_merge($diff['added'], $diff['modified'], $diff['deleted']);
    $totalChanges = count($changedFiles);
?>
<div class="card mb-4 border-warning">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2 bg-warning bg-opacity-10 border-warning">
        <span class="fw-bold">
            <i class="bi bi-cloud-check-fill me-2"></i>
            Update v<?= esc($upgradePending['version']) ?> downloaded and ready
        </span>
        <form method="post" action="/admin/updates/cancel">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-lg me-1"></i>Cancel
            </button>
        </form>
    </div>
    <div class="card-body">

        <!-- Diff summary badges -->
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-success fs-6 px-3 py-2">
                <i class="bi bi-plus-lg me-1"></i><?= count($diff['added']) ?> added
            </span>
            <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                <i class="bi bi-pencil me-1"></i><?= count($diff['modified']) ?> modified
            </span>
            <span class="badge bg-danger fs-6 px-3 py-2">
                <i class="bi bi-dash-lg me-1"></i><?= count($diff['deleted']) ?> deleted
            </span>
            <span class="badge bg-secondary fs-6 px-3 py-2">
                <?= $diff['unchanged'] ?> unchanged
            </span>
        </div>

        <!-- File list (collapsible) -->
        <?php if ($totalChanges > 0): ?>
        <details <?= $totalChanges <= 15 ? 'open' : '' ?> class="mb-3">
            <summary class="text-secondary small mb-2" style="cursor:pointer;user-select:none">
                <i class="bi bi-list-ul me-1"></i><?= $totalChanges ?> file<?= $totalChanges > 1 ? 's' : '' ?> affected
            </summary>
            <div style="max-height:300px;overflow-y:auto;background:rgba(0,0,0,.25);border-radius:6px;">
                <table class="table table-dark table-sm mb-0">
                    <tbody>
                    <?php foreach ($diff['added'] as $f): ?>
                    <tr>
                        <td style="width:1.8rem" class="ps-2"><i class="bi bi-plus-circle-fill text-success small"></i></td>
                        <td><code class="text-success" style="font-size:.78rem"><?= esc($f) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($diff['modified'] as $f): ?>
                    <tr>
                        <td style="width:1.8rem" class="ps-2"><i class="bi bi-pencil-fill text-warning small"></i></td>
                        <td><code class="text-warning" style="font-size:.78rem"><?= esc($f) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach ($diff['deleted'] as $f): ?>
                    <tr>
                        <td style="width:1.8rem" class="ps-2"><i class="bi bi-dash-circle-fill text-danger small"></i></td>
                        <td><code class="text-secondary text-decoration-line-through" style="font-size:.78rem"><?= esc($f) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>

        <div class="alert alert-secondary py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            A backup of modified files will be created in <code>writable/backups/</code> before any change.
            Pending SQL migrations will be applied automatically.
        </div>

        <form method="post" action="/admin/updates/apply">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-success"
                    onclick="return confirm('Apply update v<?= esc($upgradePending['version'], 'js') ?>?\nModified files will be backed up before being overwritten.')">
                <i class="bi bi-play-fill me-1"></i>Apply update v<?= esc($upgradePending['version']) ?>
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">

    <!-- System info -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i>System info</div>
            <div class="card-body">
                <table class="table table-dark table-borderless table-sm mb-3">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width:45%">Your App</td>
                            <td>
                                <span class="badge bg-warning text-dark">v<?= esc($appVersion) ?></span>
                                <small class="text-secondary ms-1"><?= esc($appDate) ?></small>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-secondary">PHP</td>
                            <td class="text-light"><?= esc($phpVersion) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">CodeIgniter</td>
                            <td class="text-light"><?= esc($ciVersion) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">MySQL / MariaDB</td>
                            <td class="text-light"><?= esc($dbVersion) ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (! $upgradePending): ?>
                <button id="btn-check-remote" class="btn btn-outline-info btn-sm">
                    <i class="bi bi-cloud-arrow-down me-1"></i>Check for updates
                </button>
                <div id="remote-result" class="mt-2"></div>
                <?php else: ?>
                <p class="text-secondary small mb-0">
                    <i class="bi bi-hourglass-split me-1"></i>
                    Update v<?= esc($upgradePending['version']) ?> waiting to be applied.
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cache -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-lightning-charge me-1"></i>Application cache</div>
            <div class="card-body">
                <table class="table table-dark table-borderless table-sm mb-3">
                    <tbody>
                        <tr>
                            <td class="text-secondary" style="width:45%">Adapter</td>
                            <td class="text-light"><?= esc($cacheAdapter) ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Size (files)</td>
                            <td class="text-light">
                                <?php if ($cacheSize >= 1_048_576): ?>
                                    <?= number_format($cacheSize / 1_048_576, 2) ?> MB
                                <?php elseif ($cacheSize >= 1_024): ?>
                                    <?= number_format($cacheSize / 1_024, 1) ?> KB
                                <?php else: ?>
                                    <?= $cacheSize ?> B
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form method="post" action="/admin/updates/clear-cache">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-warning btn-sm"
                            onclick="return confirm('Clear the application cache?')">
                        <i class="bi bi-trash3 me-1"></i>Clear cache
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Migrations -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-database me-1"></i>Database migrations (<?= count($migrations) ?>)</span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($pendingCount > 0): ?>
            <form method="post" action="/admin/updates/migrate">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-warning btn-sm"
                        onclick="return confirm('Apply <?= $pendingCount ?> migration<?= $pendingCount > 1 ? 's' : '' ?>?')">
                    <i class="bi bi-play-fill me-1"></i>Apply <?= $pendingCount ?> migration<?= $pendingCount > 1 ? 's' : '' ?>
                </button>
            </form>
            <?php else: ?>
            <span class="badge bg-success px-3 py-2"><i class="bi bi-check-lg me-1"></i>Database up to date</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="px-3 pt-2 pb-0 d-flex gap-1" id="migration-tabs">
        <button class="btn btn-sm btn-warning active" data-filter="all">
            All <span class="badge bg-dark ms-1"><?= count($migrations) ?></span>
        </button>
        <button class="btn btn-sm btn-outline-warning" data-filter="pending">
            Pending <span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span>
        </button>
        <button class="btn btn-sm btn-outline-secondary" data-filter="applied">
            Applied <span class="badge bg-secondary ms-1"><?= count($migrations) - $pendingCount ?></span>
        </button>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-sm mb-0" id="migration-table">
            <thead>
                <tr>
                    <th style="width:2rem"></th>
                    <th>Version</th>
                    <th>Name</th>
                    <th>Applied at</th>
                    <th>Batch</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($migrations as $m): ?>
            <tr data-status="<?= $m['applied'] ? 'applied' : 'pending' ?>">
                <td class="text-center">
                    <?php if ($m['applied']): ?>
                        <i class="bi bi-check-circle-fill text-success" title="Applied"></i>
                    <?php else: ?>
                        <i class="bi bi-clock-fill text-warning" title="Pending"></i>
                    <?php endif; ?>
                </td>
                <td><code class="text-secondary small"><?= esc($m['version']) ?></code></td>
                <td class="<?= $m['applied'] ? '' : 'fw-bold text-warning' ?>">
                    <?= esc(str_replace('_', ' ', $m['name'])) ?>
                </td>
                <td class="text-secondary small">
                    <?php if ($m['applied']): ?>
                        <?= esc($m['ran_at']) ?>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">Pending</span>
                    <?php endif; ?>
                </td>
                <td class="text-secondary small">
                    <?= $m['batch'] !== null ? '#' . $m['batch'] : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($migrations)): ?>
            <tr><td colspan="5" class="text-center text-secondary py-3">No migrations found</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Backups -->
<?php if (! empty($backups)): ?>
<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-archive me-1"></i>Backups (<?= count($backups) ?>)</span>
        <span class="text-secondary small">Taken automatically before each update is applied</span>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-sm mb-0">
            <thead>
                <tr>
                    <th>Taken</th>
                    <th>Update</th>
                    <th>Files</th>
                    <th>Size</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
            <tr>
                <td>
                    <code class="text-secondary small"><?= esc(substr($b['name'], 7)) ?></code>
                </td>
                <td class="small">
                    <?php if ($b['to_version']): ?>
                        <span class="text-secondary"><?= esc($b['from_version'] ?? '?') ?></span>
                        <i class="bi bi-arrow-right mx-1 text-secondary"></i>
                        <span class="text-warning"><?= esc($b['to_version']) ?></span>
                    <?php else: ?>
                        <span class="text-secondary">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-secondary small">
                    <?= (int) $b['files'] ?>
                    <?php if ($b['migrations'] > 0): ?>
                        <span class="badge bg-danger ms-1"
                              title="That update shipped <?= (int) $b['migrations'] ?> migration file(s). Restoring reverts code only — the database is left as it is.">
                            <i class="bi bi-database-exclamation me-1"></i><?= (int) $b['migrations'] ?> migration<?= $b['migrations'] > 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="text-secondary small">
                    <?php if ($b['size'] >= 1_048_576): ?>
                        <?= number_format($b['size'] / 1_048_576, 1) ?> MB
                    <?php elseif ($b['size'] >= 1_024): ?>
                        <?= number_format($b['size'] / 1_024, 1) ?> KB
                    <?php else: ?>
                        <?= (int) $b['size'] ?> B
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php
                        // Spelled out at the point of no return, not just in the
                        // footnote: a rollback reverts code, never the database.
                        $warning = 'Restore this backup?\n\n'
                            . 'Files changed by that update will be put back as they were'
                            . ($b['has_manifest'] ? ', and files it added will be removed' : '')
                            . '.\n\n';

                        if ($b['migrations'] > 0) {
                            $warning .= 'WARNING: that update shipped ' . (int) $b['migrations'] . ' migration file(s). '
                                . 'Restoring does NOT roll the database back, so your schema will stay ahead '
                                . 'of the restored code. Revert those migrations yourself if needed.\n\n';
                        } else {
                            $warning .= 'Database migrations are never reverted by a restore.\n\n';
                        }

                        $warning .= 'This cannot be undone.';
                    ?>
                    <form method="post" action="/admin/updates/rollback" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="backup" value="<?= esc($b['name']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning"
                                onclick="return confirm('<?= $warning ?>')">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer small">
        <span class="text-warning"><i class="bi bi-database-exclamation me-1"></i><strong>Restoring reverts code, never the database.</strong></span>
        <span class="text-secondary">
            Migrations applied by an update stay applied, so a restored install can
            end up running older code against a newer schema. Roll those migrations
            back yourself when that matters.
        </span>
    </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// ── Migration filter tabs ────────────────────────────────────────────────────
document.querySelectorAll('#migration-tabs button').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#migration-tabs button').forEach(b => {
            b.classList.remove('active', 'btn-warning');
            b.classList.add(b.dataset.filter === 'applied' ? 'btn-outline-secondary' : 'btn-outline-warning');
        });
        this.classList.remove('btn-outline-warning', 'btn-outline-secondary');
        this.classList.add('active', 'btn-warning');

        const filter = this.dataset.filter;
        document.querySelectorAll('#migration-table tbody tr[data-status]').forEach(row => {
            row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
        });
    });
});

// ── Remote version check ─────────────────────────────────────────────────────
document.getElementById('btn-check-remote')?.addEventListener('click', function () {
    const btn    = this;
    const result = document.getElementById('remote-result');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking…';
    result.innerHTML = '';

    fetch('/admin/updates/check-remote')
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                result.innerHTML = `<div class="alert alert-secondary py-2 small mb-0">${h(data.error)}</div>`;
                return;
            }

            const current = '<?= esc($appVersion, 'js') ?>';
            const semverCmp = (a, b) => {
                const pa = a.split('.').map(Number), pb = b.split('.').map(Number);
                for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
                    const d = (pa[i] || 0) - (pb[i] || 0);
                    if (d !== 0) return d;
                }
                return 0;
            };
            const isNewer = semverCmp(data.version, current) > 0;

            let downloadForm = '';
            if (isNewer) {
                const csrfField = document.querySelector('meta[name="csrf-field"]').content;
                const csrfHash  = document.querySelector('meta[name="csrf-hash"]').content;
                downloadForm = `
                    <form method="post" action="/admin/updates/download" class="mt-2" id="download-form">
                        <input type="hidden" name="${h(csrfField)}" value="${h(csrfHash)}">
                        <input type="hidden" name="version"      value="${h(data.version)}">
                        <input type="hidden" name="zip_url"      value="${h(data.zip_url)}">
                        <input type="hidden" name="manifest_url" value="${h(data.manifest_url || '')}">
                        <button type="submit" class="btn btn-success btn-sm" id="btn-download">
                            <i class="bi bi-cloud-arrow-down me-1"></i>Download v${h(data.version)} and prepare
                        </button>
                    </form>`;
            }

            result.innerHTML = `
                <div class="alert ${isNewer ? 'alert-success' : 'alert-secondary'} py-2 mb-0">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <strong>Latest version: v${h(data.version)}</strong>
                        ${isNewer
                            ? '<span class="badge bg-success">New version available!</span>'
                            : '<span class="badge bg-secondary">You are up to date</span>'}
                        ${data.date ? `<span class="text-secondary small">Released ${h(data.date)}</span>` : ''}
                    </div>
                    ${data.changelog ? `<details class="mt-2">
                        <summary style="cursor:pointer" class="opacity-75 small">Changelog</summary>
                        <pre class="mt-1 p-2 rounded small mb-0" style="background:rgba(0,0,0,.3);white-space:pre-wrap;max-height:160px;overflow:auto">${h(data.changelog)}</pre>
                    </details>` : ''}
                    ${downloadForm}
                </div>`;

            // Disable button on submit — must be done via submit event, not onclick,
            // because setting disabled in onclick prevents form submission in Chrome/Firefox.
            const dlForm = document.getElementById('download-form');
            if (dlForm) {
                dlForm.addEventListener('submit', function () {
                    const b = document.getElementById('btn-download');
                    if (b) {
                        b.disabled = true;
                        b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Downloading…';
                    }
                });
            }
        })
        .catch(() => {
            result.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Network error while checking.</div>';
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-arrow-down me-1"></i>Check for updates';
        });
});

function h(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
<?= $this->endSection() ?>
