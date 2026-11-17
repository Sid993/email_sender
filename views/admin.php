<?php
/** @var array $user @var string $csrf @var string $appName @var int $uploadMaxMb @var int $chunkBytes */
$title = 'Manage files · ' . $appName;
require __DIR__ . '/partials/head.php';
$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<body>
<header class="topbar">
    <span class="topbar-user">Signed in as <strong><?= $e($user['name']) ?></strong> <span class="badge text-bg-primary">Admin</span></span>
    <a class="btn btn-link btn-sm p-0" href="/">Back to sending</a>
    <form method="post" action="/logout" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-link btn-sm p-0" type="submit">Log out</button>
    </form>
</header>

<main class="page">
    <section class="panel" aria-labelledby="adminTitle">
        <h1 class="app-title" id="adminTitle">Shared Recipient Files</h1>
        <div id="alerts" aria-live="polite"></div>

        <div class="admin-upload">
            <label for="files" class="admin-label">Upload files to the server</label>
            <input class="form-control" type="file" id="files" multiple data-chunk-bytes="<?= (int) $chunkBytes ?>"
                   accept=".csv,.txt,.xlsx,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            <div class="hint">CSV or Excel (.xlsx), up to <?= (int) $uploadMaxMb ?> MB each. You can choose several files. First row = column names, one column must hold email addresses. Each file name must be unique: it is what the employee types to load the file.</div>
            <ul class="upload-list" id="uploadList"></ul>
        </div>

        <div class="failed-head admin-head">
            <h2 class="failed-title">Files on the server <span class="muted" id="fileCount"></span></h2>
            <input class="form-control form-control-sm admin-search" id="search" type="search" placeholder="Search file or employee">
        </div>
        <div class="table-responsive">
            <table class="table table-sm admin-table">
                <thead>
                <tr>
                    <th>File name</th>
                    <th class="text-end">Recipients</th>
                    <th>Progress</th>
                    <th>Assigned to</th>
                    <th>Uploaded</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="fileBody"><tr><td colspan="6" class="muted">Loading…</td></tr></tbody>
            </table>
        </div>
        <p class="hint muted">Each file is assigned to one employee. Progress belongs to the file, so if you reassign it, the new employee continues from where it stopped and nobody is emailed twice. A file with a batch running or paused can't be reassigned or deleted until that batch ends.</p>
    </section>
</main>

<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/admin.js?v=1"></script>
</body>
</html>
