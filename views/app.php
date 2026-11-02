<?php
/** @var array $user @var string $csrf @var string $appName @var bool $smtpSupported @var int $uploadMaxMb @var int $chunkBytes */
$title = $appName;
require __DIR__ . '/partials/head.php';
$e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<body>
<header class="topbar">
    <span class="topbar-user">Signed in as <strong><?= $e($user['name']) ?></strong> <span class="text-muted d-none d-sm-inline">(<?= $e($user['email']) ?>)</span></span>
    <form method="post" action="/logout" class="d-inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-link btn-sm p-0" type="submit">Log out</button>
    </form>
</header>

<main class="page">
    <section class="panel" aria-labelledby="appTitle">
        <h1 class="app-title" id="appTitle"><?= $e($appName) ?></h1>

        <?php if (!$smtpSupported): ?>
            <div class="alert alert-danger">The PHP OpenSSL extension is not enabled on this server, so STARTTLS / SSL connections are not possible. Ask your host to enable ext-openssl.</div>
        <?php endif; ?>
        <div id="alerts" aria-live="polite"></div>

        <form id="mailForm" novalidate autocomplete="off">
            <fieldset id="formFields">
            <div class="form-grid">
                <!-- Column 1 -->
                <div class="form-col">
                    <div class="field">
                        <label for="port">Port</label>
                        <input class="form-control" id="port" name="port" inputmode="numeric" placeholder="587">
                    </div>
                    <div class="field">
                        <label for="host">Server</label>
                        <input class="form-control" id="host" name="host" placeholder="smtp.example.com" autocapitalize="off" spellcheck="false">
                    </div>
                    <div class="field">
                        <label for="from_name">From Name</label>
                        <input class="form-control" id="from_name" name="from_name" placeholder="Your Company">
                    </div>
                    <div class="field">
                        <label for="file">File Name</label>
                        <input class="form-control" type="file" id="file" data-chunk-bytes="<?= (int) $chunkBytes ?>" accept=".csv,.txt,.xlsx,.ods,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                        <div class="hint">CSV or Excel (.xlsx), up to <?= (int) $uploadMaxMb ?> MB. First row = column names, one column must hold email addresses.</div>
                        <div class="progress upload-progress d-none" id="uploadProgress" role="progressbar" aria-label="Upload progress">
                            <div class="progress-bar" style="width:0%"></div>
                        </div>
                        <div class="file-summary d-none" id="fileSummary"></div>
                    </div>
                </div>

                <!-- Column 2 -->
                <div class="form-col">
                    <div class="field">
                        <label for="test_to">Send To (Test Email Id)</label>
                        <input class="form-control" id="test_to" name="test_to" placeholder="you@example.com, colleague@example.com" autocapitalize="off" spellcheck="false">
                        <div class="hint">You can add multiple email comma separated.</div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btnTest">Send Test Email</button>
                    </div>
                    <div class="field">
                        <label for="username">Username</label>
                        <input class="form-control" id="username" name="username" autocapitalize="off" spellcheck="false" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="from_email">From Email</label>
                        <input class="form-control" type="email" id="from_email" name="from_email" placeholder="news@example.com" autocapitalize="off" spellcheck="false">
                    </div>
                    <div class="field">
                        <label for="send_limit">Send Limit</label>
                        <input class="form-control" type="number" min="1" step="1" id="send_limit" name="send_limit" placeholder="e.g. 10" data-keep-enabled>
                        <div class="hint" id="countLine">Total Count: 0, Remaining Count: 0</div>
                        <div class="hint muted">Number of emails sent at the same time.</div>
                    </div>
                    <div class="field">
                        <button class="btn btn-link btn-sm p-0 advanced-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#advanced" aria-expanded="false" aria-controls="advanced">Advanced settings</button>
                        <div class="collapse" id="advanced">
                            <div class="advanced-box">
                                <label for="max_retries">Max retries per email</label>
                                <input class="form-control form-control-sm" type="number" min="0" max="10" id="max_retries" name="max_retries">
                                <label for="timeout">Timeout per email (seconds)</label>
                                <input class="form-control form-control-sm" type="number" min="5" max="300" id="timeout" name="timeout">
                                <label for="rate_per_minute">Max emails per minute <span class="muted">(0 = no limit)</span></label>
                                <input class="form-control form-control-sm" type="number" min="0" id="rate_per_minute" name="rate_per_minute">
                                <label for="unsubscribe">Unsubscribe link <span class="muted">(mailto: or https://)</span></label>
                                <input class="form-control form-control-sm" id="unsubscribe" name="unsubscribe" placeholder="https://example.com/unsubscribe">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Column 3 -->
                <div class="form-col">
                    <div class="field">
                        <label for="security">TLS</label>
                        <select class="form-select" id="security" name="security">
                            <option value="">-- Please Select --</option>
                            <option value="starttls">STARTTLS (usually port 587)</option>
                            <option value="ssl">SSL/TLS (usually port 465)</option>
                            <option value="none">None (usually port 25)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" data-keep-enabled>
                    </div>
                    <div class="field">
                        <label for="subject">Subject Line</label>
                        <input class="form-control" id="subject" name="subject" placeholder="Hello {{first_name|there}}">
                    </div>
                    <div class="field">
                        <label for="message">Message</label>
                        <div class="chips d-none" id="fieldChips" aria-label="Insert personalization field"></div>
                        <textarea class="form-control message" id="message" name="message" rows="14" spellcheck="false" placeholder="<p>Hi {{first_name}},</p>&#10;<p>Your HTML email here…</p>"></textarea>
                        <button type="button" class="btn btn-primary w-100 mt-1" id="btnPreview">Preview Template</button>
                    </div>
                </div>
            </div>
            </fieldset>

            <div class="text-center send-row">
                <button type="submit" class="btn btn-primary btn-send" id="btnSend">Send Now</button>
            </div>
        </form>
    </section>

    <!-- Dashboard -->
    <section class="panel d-none" id="dashboard" aria-labelledby="dashTitle">
        <div class="dash-head">
            <div>
                <h2 class="dash-title" id="dashTitle">Campaign <span class="badge" id="stateBadge">—</span></h2>
                <div class="muted small" id="dashFile"></div>
            </div>
            <div class="dash-actions">
                <button class="btn btn-warning btn-sm" id="btnPause" type="button">Pause</button>
                <button class="btn btn-success btn-sm" id="btnResume" type="button">Resume</button>
                <button class="btn btn-outline-danger btn-sm" id="btnCancel" type="button">Cancel</button>
                <button class="btn btn-outline-secondary btn-sm" id="btnNew" type="button">New Campaign</button>
            </div>
        </div>

        <div class="alert alert-danger d-none" id="dashError"></div>

        <div class="stats">
            <div class="stat"><span class="stat-label">Total Recipients</span><span class="stat-value" id="stTotal">0</span></div>
            <div class="stat"><span class="stat-label">Send Limit</span><span class="stat-value" id="stLimit">0</span><span class="stat-sub" id="stLimitSub"></span></div>
            <div class="stat ok"><span class="stat-label">Successful</span><span class="stat-value" id="stSent">0</span></div>
            <div class="stat bad"><span class="stat-label">Failed</span><span class="stat-value" id="stFailed">0</span><span class="stat-sub" id="stUnknown"></span></div>
            <div class="stat"><span class="stat-label">Remaining</span><span class="stat-value" id="stRemaining">0</span><span class="stat-sub" id="stCancelled"></span></div>
        </div>

        <div class="progress-wrap">
            <div class="d-flex justify-content-between small mb-1">
                <span id="progressLabel">0%</span><span id="rateLabel" class="muted"></span>
            </div>
            <div class="progress-stacked" aria-label="Sending progress">
                <div class="progress" role="progressbar" id="pSentWrap" style="width:0%"><div class="progress-bar bg-success"></div></div>
                <div class="progress" role="progressbar" id="pFailWrap" style="width:0%"><div class="progress-bar bg-danger"></div></div>
            </div>
        </div>

        <div class="batch">
            <div class="batch-line" id="batchLine">Waiting to start…</div>
            <div class="slots" id="slots" aria-label="Emails currently being sent"></div>
        </div>

        <div class="failed-head">
            <h3 class="failed-title">Failed email details <span class="muted" id="failedCount"></span></h3>
            <div class="d-flex gap-2">
                <input class="form-control form-control-sm" id="failedSearch" type="search" placeholder="Search email or error">
                <a class="btn btn-outline-secondary btn-sm text-nowrap" id="btnExport" href="/api/campaign/failed/export">Export CSV</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm failed-table">
                <thead><tr><th>Email</th><th>Status</th><th>Code</th><th>Error</th><th>Attempts</th><th>Time</th></tr></thead>
                <tbody id="failedBody"><tr><td colspan="6" class="muted">No failures.</td></tr></tbody>
            </table>
        </div>
        <nav class="d-flex justify-content-end gap-2" id="failedPager"></nav>
    </section>
</main>

<!-- Preview modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewTitle">Template Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="preview-meta">
                    <div><span class="muted">From:</span> <span id="pvFrom"></span></div>
                    <div><span class="muted">Subject:</span> <strong id="pvSubject"></strong></div>
                    <div class="pv-row" id="pvRowNav">
                        <span class="muted">Recipient data:</span>
                        <button class="btn btn-outline-secondary btn-sm" id="pvPrev" type="button" aria-label="Previous row">‹</button>
                        <span id="pvRowLabel"></span>
                        <button class="btn btn-outline-secondary btn-sm" id="pvNext" type="button" aria-label="Next row">›</button>
                    </div>
                </div>
                <div class="alert alert-warning py-2 d-none" id="pvWarn"></div>
                <ul class="nav nav-tabs mb-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pvHtmlTab" type="button">HTML</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pvTextTab" type="button">Plain text</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane show active" id="pvHtmlTab">
                        <!-- sandbox without allow-scripts: template scripts can't run or reach the app -->
                        <iframe id="pvFrame" class="preview-frame" sandbox="" title="Email preview"></iframe>
                    </div>
                    <div class="tab-pane" id="pvTextTab"><pre id="pvText" class="preview-text"></pre></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="/assets/app.js?v=1"></script>
</body>
</html>
