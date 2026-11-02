/* Bulk Mailer front-end: form, chunked upload, preview, test send, live dashboard. */
(() => {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const form = $('#mailForm');

    const FIELDS = ['host', 'port', 'security', 'username', 'from_name', 'from_email', 'subject', 'message',
        'send_limit', 'test_to', 'max_retries', 'timeout', 'rate_per_minute', 'unsubscribe'];
    const ACTIVE = ['starting', 'running', 'pausing', 'cancelling'];
    const IN_PROGRESS = [...ACTIVE, 'paused', 'interrupted'];
    const CHUNK_SIZE = Number(document.getElementById('file').dataset.chunkBytes) || 1024 * 1024;

    const state = { import: null, status: null, hasPassword: false, lastFailedKey: '' };
    let pollTimer = null;
    let saveTimer = null;
    let failedPage = 1;
    let previewRow = 1;
    let lastTextTarget = $('#message');

    // ------------------------------------------------------------------ helpers

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = (n) => Number(n || 0).toLocaleString();
    const randHex = (bytes) => Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (b) => b.toString(16).padStart(2, '0')).join('');

    function duration(sec) {
        if (sec == null || !isFinite(sec)) return '';
        sec = Math.max(0, Math.round(sec));
        const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        return h ? `${h}h ${m}m` : m ? `${m}m ${s}s` : `${s}s`;
    }

    async function api(path, { method = 'POST', body = null, formData = null } = {}) {
        const opts = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' } };
        if (formData) opts.body = formData;
        else if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        let res;
        try { res = await fetch(path, opts); } catch { throw new Error('Network error. Please check your connection.'); }
        let data = {};
        try { data = await res.json(); } catch { /* non-JSON */ }
        if (res.status === 401) { location.href = '/login'; throw new Error('Session expired.'); }
        if (!res.ok || data.ok === false) {
            const err = new Error(data.error || `Request failed (${res.status}).`);
            err.data = data;
            throw err;
        }
        return data;
    }

    function alertBox(type, html, autoHideMs = 0) {
        const box = document.createElement('div');
        box.className = `alert alert-${type} alert-dismissible fade show py-2`;
        box.innerHTML = `${html}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        $('#alerts').prepend(box);
        while ($('#alerts').children.length > 3) $('#alerts').lastElementChild.remove();
        if (autoHideMs) setTimeout(() => box.remove(), autoHideMs);
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        return box;
    }

    function busy(btn, on, label) {
        if (on) {
            btn.dataset.label = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>${label || btn.textContent}`;
        } else {
            btn.disabled = false;
            if (btn.dataset.label) btn.innerHTML = btn.dataset.label;
        }
    }

    // --------------------------------------------------------------- form data

    function collect() {
        const out = {};
        FIELDS.forEach((f) => { if (form.elements[f]) out[f] = form.elements[f].value; });
        const pw = $('#password').value;
        if (pw) out.password = pw;
        return out;
    }

    function fillForm(cfg) {
        FIELDS.forEach((f) => {
            const el = form.elements[f];
            if (el && cfg[f] != null) el.value = cfg[f];
        });
        setHasPassword(!!cfg.has_password);
    }

    function setHasPassword(has) {
        state.hasPassword = has;
        $('#password').placeholder = has ? '•••••••• (saved for this session)' : '';
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(async () => {
            try {
                const d = await api('/api/config', { body: collect() });
                setHasPassword(d.config.has_password);
            } catch { /* autosave is best effort */ }
        }, 700);
    }

    function clearInvalid() { form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid')); }

    function markInvalid(ids) { ids.forEach((id) => $('#' + id)?.classList.add('is-invalid')); }

    /** Client-side checks; the server validates again. */
    function validate(forSending) {
        clearInvalid();
        const v = collect();
        const bad = [];
        const msgs = [];
        const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!v.host) { bad.push('host'); msgs.push('Server is required.'); }
        if (!/^\d+$/.test(v.port) || +v.port < 1 || +v.port > 65535) { bad.push('port'); msgs.push('Port must be 1–65535.'); }
        if (!v.security) { bad.push('security'); msgs.push('Please select a TLS option.'); }
        if (!emailRe.test(v.from_email)) { bad.push('from_email'); msgs.push('From Email is not valid.'); }
        if (v.username && !v.password && !state.hasPassword) { bad.push('password'); msgs.push('Password is required.'); }
        if (!v.subject.trim()) { bad.push('subject'); msgs.push('Subject Line is required.'); }
        if (!v.message.trim()) { bad.push('message'); msgs.push('Message is required.'); }
        if (forSending) {
            if (!/^\d+$/.test(v.send_limit) || +v.send_limit < 1) { bad.push('send_limit'); msgs.push('Send Limit must be at least 1.'); }
            if (!state.import || state.import.purged) { bad.push('file'); msgs.push('Please upload a recipient file.'); }
        }
        markInvalid(bad);
        if (msgs.length) alertBox('danger', msgs.map(esc).join('<br>'));
        return msgs.length === 0;
    }

    form.addEventListener('input', (e) => {
        if (e.target.id === 'file') return;
        e.target.classList.remove('is-invalid');
        scheduleSave();
    });
    form.addEventListener('change', (e) => {
        if (e.target.id === 'security') {
            const port = form.elements.port;
            const defaults = { starttls: '587', ssl: '465', none: '25' };
            if (!port.value || Object.values(defaults).includes(port.value)) port.value = defaults[e.target.value] || port.value;
        }
    });

    // ------------------------------------------------------------------ upload

    $('#file').addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        await uploadFile(file);
        e.target.value = '';
    });

    async function uploadFile(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['csv', 'txt', 'xlsx', 'ods'].includes(ext)) {
            alertBox('danger', 'Please choose a .csv or .xlsx file. (Old .xls files: open in Excel and “Save As” .xlsx.)');
            return;
        }
        const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        const id = randHex(16);
        const bar = $('#uploadProgress');
        const fill = bar.firstElementChild;
        bar.classList.remove('d-none');
        fill.classList.remove('progress-bar-striped', 'progress-bar-animated');
        $('#btnSend').disabled = true;
        $('#fileSummary').classList.add('d-none');

        try {
            let data;
            for (let i = 0; i < total; i++) {
                const fd = new FormData();
                fd.append('upload_id', id);
                fd.append('index', i);
                fd.append('total', total);
                fd.append('name', file.name);
                fd.append('size', file.size);
                fd.append('chunk', file.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE), 'chunk');
                if (i === total - 1) {
                    fill.style.width = '100%';
                    fill.textContent = 'Processing…';
                    fill.classList.add('progress-bar-striped', 'progress-bar-animated');
                }
                data = await api('/api/upload/chunk', { formData: fd });
                if (i < total - 1) fill.style.width = `${Math.round(((i + 1) / total) * 100)}%`;
            }
            setImport(data.import);
            const imp = data.import;
            const skipped = imp.invalid + imp.duplicates;
            alertBox('success', `<strong>${esc(imp.file_name)}</strong> imported: ${num(imp.total)} recipients` +
                (skipped ? ` (${num(imp.invalid)} invalid and ${num(imp.duplicates)} duplicate rows skipped)` : '') + '.', 8000);
            applyStatus(null);
        } catch (err) {
            alertBox('danger', esc(err.message));
        } finally {
            bar.classList.add('d-none');
            fill.style.width = '0%';
            fill.textContent = '';
            refreshFormLock();
        }
    }

    function setImport(imp) {
        state.import = imp;
        const box = $('#fileSummary');
        const chips = $('#fieldChips');
        if (!imp) {
            box.classList.add('d-none');
            chips.classList.add('d-none');
            updateCountLine();
            return;
        }
        const skipped = imp.invalid + imp.duplicates;
        box.innerHTML = `<div class="fname">${esc(imp.file_name)}</div>
            <div>${num(imp.total)} recipients${skipped ? ` · ${num(imp.invalid)} invalid · ${num(imp.duplicates)} duplicates skipped` : ''}
            · email column: <code>${esc(imp.email_field)}</code></div>
            <div class="mt-1 d-flex gap-3 flex-wrap">
              ${skipped && !imp.purged ? '<a href="/api/upload/skipped">Download skipped rows</a>' : ''}
              ${imp.purged ? '<span class="muted">Recipient data was cleared after the campaign finished.</span>' : ''}
              <button type="button" class="btn btn-link p-0 text-danger" id="btnRemoveFile">Remove file</button>
            </div>`;
        box.classList.remove('d-none');
        $('#btnRemoveFile').addEventListener('click', removeFile);

        chips.innerHTML = '<span class="chips-label">Insert field:</span>' +
            imp.fields.map((f) => `<button type="button" class="chip" data-field="${esc(f)}">{{${esc(f)}}}</button>`).join('');
        chips.classList.toggle('d-none', imp.purged);
        updateCountLine();
    }

    async function removeFile() {
        try {
            await api('/api/campaign/reset');
            setImport(null);
            applyStatus(null);
        } catch (err) {
            alertBox('danger', esc(err.message));
        }
    }

    function updateCountLine() {
        const s = state.status;
        const total = s ? s.total : (state.import && !state.import.purged ? state.import.total : 0);
        const remaining = s ? s.remaining : total;
        $('#countLine').textContent = `Total Count: ${num(total)}, Remaining Count: ${num(remaining)}`;
    }

    // personalization chips insert at the cursor (subject or message, whichever was last used)
    ['#subject', '#message'].forEach((sel) => $(sel).addEventListener('focus', (e) => { lastTextTarget = e.target; }));
    $('#fieldChips').addEventListener('click', (e) => {
        const chip = e.target.closest('.chip');
        if (!chip) return;
        const el = lastTextTarget;
        const token = `{{${chip.dataset.field}}}`;
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        el.setRangeText(token, start, end, 'end');
        el.focus();
        scheduleSave();
    });

    // ----------------------------------------------------------------- preview

    const previewModal = new bootstrap.Modal('#previewModal');

    $('#btnPreview').addEventListener('click', () => {
        previewRow = 1;
        renderPreview(true);
    });
    $('#pvPrev').addEventListener('click', () => { previewRow = Math.max(1, previewRow - 1); renderPreview(); });
    $('#pvNext').addEventListener('click', () => { previewRow += 1; renderPreview(); });

    async function renderPreview(open = false) {
        const btn = $('#btnPreview');
        if (open) busy(btn, true, 'Preview Template');
        try {
            const d = await api('/api/preview', { body: { ...collect(), row: previewRow } });
            previewRow = d.row || 1;
            $('#pvFrom').textContent = d.from && d.from !== '<>' ? d.from : '(From Name / From Email not set)';
            $('#pvSubject').textContent = d.subject || '(no subject)';
            $('#pvFrame').srcdoc = d.html || '<p style="font-family:sans-serif;color:#888">(empty message)</p>';
            $('#pvText').textContent = d.text;
            $('#pvRowLabel').textContent = d.rows ? `Row ${d.row} of first ${d.rows} — ${d.data.email || ''}` : 'No file uploaded – placeholders are shown empty';
            $('#pvPrev').disabled = !d.rows || d.row <= 1;
            $('#pvNext').disabled = !d.rows || d.row >= d.rows;
            const warn = $('#pvWarn');
            if (d.unknown_fields.length) {
                warn.innerHTML = `These placeholders are not columns in the uploaded file and will be empty: ${d.unknown_fields.map((f) => `<code>{{${esc(f)}}}</code>`).join(', ')}`;
                warn.classList.remove('d-none');
            } else warn.classList.add('d-none');
            if (open) previewModal.show();
        } catch (err) {
            alertBox('danger', esc(err.message));
        } finally {
            if (open) busy(btn, false);
        }
    }

    // --------------------------------------------------------------- test send

    $('#btnTest').addEventListener('click', async () => {
        const btn = $('#btnTest');
        if (!form.elements.test_to.value.trim()) {
            markInvalid(['test_to']);
            alertBox('danger', 'Enter at least one address in “Send To (Test Email Id)”.');
            return;
        }
        if (!validate(false)) return;
        busy(btn, true, 'Sending…');
        try {
            const d = await api('/api/test-send', { body: collect() });
            const okCount = d.results.filter((r) => r.ok).length;
            const lines = d.results.map((r) => `${r.ok ? '✅' : '❌'} ${esc(r.email)} — ${esc(r.message)}`).join('<br>');
            const note = d.personalized_with ? `<div class="small muted mt-1">Personalized with the first row of your file (${esc(d.personalized_with)}).</div>` : '';
            alertBox(okCount === d.results.length ? 'success' : 'warning',
                `<strong>Test email: ${okCount} of ${d.results.length} accepted by the server.</strong><br>${lines}${note}`);
        } catch (err) {
            alertBox('danger', esc(err.message));
        } finally {
            busy(btn, false);
        }
    });

    // ------------------------------------------------------------ send / controls

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!validate(true)) return;
        const total = state.import.total;
        const limit = +form.elements.send_limit.value;
        if (!confirm(`Send this email to ${num(total)} recipients, ${num(limit)} at a time?`)) return;
        const btn = $('#btnSend');
        busy(btn, true, 'Starting…');
        try {
            const d = await api('/api/campaign/start', { body: collect() });
            state.lastFailedKey = '';
            applyStatus(d.status);
            loadFailed(1);
            poll();
            $('#dashboard').scrollIntoView({ behavior: 'smooth' });
        } catch (err) {
            if (err.data?.errors) markInvalidFromServer(err.data.errors);
            alertBox('danger', esc(err.message));
        } finally {
            busy(btn, false);
            refreshFormLock();
        }
    });

    function markInvalidFromServer(errors) {
        const map = { Server: 'host', Port: 'port', TLS: 'security', 'From Email': 'from_email', Password: 'password', Subject: 'subject', Message: 'message', 'Send Limit': 'send_limit' };
        errors.forEach((m) => Object.entries(map).forEach(([k, id]) => { if (m.startsWith(k)) markInvalid([id]); }));
    }

    async function control(action, btn, extra = null) {
        busy(btn, true);
        try {
            await api(`/api/campaign/${action}`, { body: extra || {} });
            await poll();
        } catch (err) {
            alertBox('danger', esc(err.message));
        } finally {
            busy(btn, false);
            applyStatus(state.status);
        }
    }

    $('#btnPause').addEventListener('click', (e) => control('pause', e.currentTarget));
    $('#btnResume').addEventListener('click', (e) => {
        const extra = { send_limit: form.elements.send_limit.value };
        if ($('#password').value) extra.password = $('#password').value;
        control('resume', e.currentTarget, extra);
    });
    $('#btnCancel').addEventListener('click', (e) => {
        if (confirm('Cancel this campaign? Emails already sent cannot be recalled; the remaining recipients will not be sent.')) {
            control('cancel', e.currentTarget);
        }
    });
    $('#btnNew').addEventListener('click', async (e) => {
        if (!confirm('Clear this campaign and its results to prepare a new one?')) return;
        busy(e.currentTarget, true);
        try {
            await api('/api/campaign/reset');
            setImport(null);
            applyStatus(null);
        } catch (err) {
            alertBox('danger', esc(err.message));
        } finally {
            busy(e.currentTarget, false);
        }
    });

    // ------------------------------------------------------------- live status

    async function poll() {
        clearTimeout(pollTimer);
        try {
            const d = await api('/api/campaign/status', { method: 'GET' });
            if (d.import && JSON.stringify(d.import) !== JSON.stringify(state.import)) setImport(d.import);
            applyStatus(d.status);
        } catch { /* keep polling; a transient error shouldn't stop the dashboard */ }
        if (state.status && ACTIVE.includes(state.status.state)) {
            pollTimer = setTimeout(poll, document.hidden ? 3000 : 1000);
        }
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && state.status && ACTIVE.includes(state.status.state)) poll();
    });

    const BADGES = {
        starting: ['secondary', 'Starting…'], running: ['primary', 'Sending'], pausing: ['warning', 'Pausing…'],
        paused: ['warning', 'Paused'], cancelling: ['danger', 'Cancelling…'], cancelled: ['dark', 'Cancelled'],
        completed: ['success', 'Completed'], interrupted: ['danger', 'Interrupted'],
    };

    function applyStatus(s) {
        state.status = s;
        const dash = $('#dashboard');
        updateCountLine();
        refreshFormLock();
        if (!s) { dash.classList.add('d-none'); return; }
        dash.classList.remove('d-none');

        const [cls, label] = BADGES[s.state] || ['secondary', s.state];
        const badge = $('#stateBadge');
        badge.className = `badge text-bg-${cls}`;
        badge.textContent = label;
        $('#dashFile').textContent = s.file_name ? `File: ${s.file_name}` : '';

        $('#stTotal').textContent = num(s.total);
        $('#stLimit').textContent = num(s.limit);
        let limitSub = '';
        if (s.effective_limit && s.effective_limit < s.limit) limitSub = `server granted ${num(s.effective_limit)}`;
        if (s.window_limit && s.effective_limit && s.window_limit < s.effective_limit && ACTIVE.includes(s.state)) limitSub = `throttled to ${num(s.window_limit)} by provider`;
        $('#stLimitSub').textContent = limitSub;
        $('#stSent').textContent = num(s.sent);
        $('#stFailed').textContent = num(s.failed);
        $('#stUnknown').textContent = s.unknown ? `+ ${num(s.unknown)} unknown` : '';
        $('#stRemaining').textContent = num(s.remaining);
        $('#stCancelled').textContent = s.cancelled ? `${num(s.cancelled)} cancelled` : '';

        const total = Math.max(1, s.total);
        const done = s.sent + s.failed + s.unknown;
        $('#pSentWrap').style.width = `${(s.sent / total) * 100}%`;
        $('#pFailWrap').style.width = `${((s.failed + s.unknown) / total) * 100}%`;
        $('#progressLabel').textContent = `${Math.floor((done / total) * 1000) / 10}% processed (${num(done)} of ${num(s.total)})`;
        $('#rateLabel').textContent = ACTIVE.includes(s.state) && s.rate_per_min
            ? `${num(s.rate_per_min)} emails/min${s.eta_seconds ? ` · about ${duration(s.eta_seconds)} left` : ''}` : '';

        const slots = s.window_limit || s.effective_limit || s.limit;
        const batches = s.limit ? Math.ceil((s.dispatched || 0) / s.limit) : 0;
        let line;
        switch (s.state) {
            case 'starting': line = 'Starting the sending process…'; break;
            case 'running': line = `<strong>${num(s.in_flight_count)}</strong> of ${num(slots)} slots sending now · ${num(s.dispatched)} sends dispatched (≈ ${num(batches)} batches of ${num(s.limit)})` +
                (s.retry_waiting ? ` · ${num(s.retry_waiting)} waiting to retry` : ''); break;
            case 'pausing': line = `Pausing: waiting for ${num(s.in_flight_count)} in-flight email(s) to finish…`; break;
            case 'cancelling': line = `Cancelling: waiting for ${num(s.in_flight_count)} in-flight email(s) to finish…`; break;
            case 'paused': line = `Paused. Nothing is being sent. ${num(s.remaining)} remaining — change Send Limit if needed and click Resume.`; break;
            case 'interrupted': line = 'The sending process stopped unexpectedly. Click Resume to continue where it left off.'; break;
            case 'completed': line = `Finished in ${duration((s.finished_at || s.updated_at) - s.started_at)}.`; break;
            case 'cancelled': line = `Cancelled. ${num(s.cancelled)} recipient(s) were not sent.`; break;
            default: line = '';
        }
        $('#batchLine').innerHTML = line;
        $('#slots').innerHTML = (s.in_flight || []).map((f) =>
            `<span class="slot" title="attempt ${f.attempt}, ${f.secs}s">${esc(f.email)}${f.attempt > 1 ? ` (try ${f.attempt})` : ''}</span>`).join('');

        const errBox = $('#dashError');
        errBox.classList.toggle('d-none', !s.last_error);
        errBox.textContent = s.last_error || '';

        const show = (id, on) => $(id).classList.toggle('d-none', !on);
        show('#btnPause', ['starting', 'running'].includes(s.state));
        show('#btnResume', ['paused', 'interrupted'].includes(s.state));
        show('#btnCancel', IN_PROGRESS.includes(s.state) && s.state !== 'cancelling');
        show('#btnNew', ['completed', 'cancelled'].includes(s.state));

        const failedKey = `${s.failed}:${s.unknown}`;
        if (failedKey !== state.lastFailedKey) {
            state.lastFailedKey = failedKey;
            loadFailed(failedPage);
        }
    }

    function refreshFormLock() {
        const st = state.status?.state;
        const locked = IN_PROGRESS.includes(st);
        const pausedLike = ['paused', 'interrupted'].includes(st);
        form.querySelectorAll('input, select, textarea, button').forEach((el) => {
            if (el.id === 'btnPreview' || el.classList.contains('advanced-toggle')) return;
            const keep = pausedLike && el.hasAttribute('data-keep-enabled');
            el.disabled = locked && !keep;
        });
        $('#btnPreview').disabled = false;
        $('#fieldChips').querySelectorAll('.chip').forEach((c) => { c.disabled = locked; });
    }

    // ------------------------------------------------------------ failed table

    let searchTimer;
    $('#failedSearch').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadFailed(1), 350);
    });

    async function loadFailed(page) {
        failedPage = page;
        const q = encodeURIComponent($('#failedSearch').value.trim());
        try {
            const d = await api(`/api/campaign/failed?page=${page}&q=${q}`, { method: 'GET' });
            $('#failedCount').textContent = d.total ? `(${num(d.total)})` : '';
            $('#failedBody').innerHTML = d.rows.length ? d.rows.map((r) => `<tr>
                <td class="email">${esc(r.email)}</td>
                <td><span class="badge text-bg-${r.status === 'failed' ? 'danger' : 'secondary'}">${esc(r.status)}</span></td>
                <td>${r.code || ''}</td>
                <td class="err">${esc(r.error)}</td>
                <td>${r.attempts}</td>
                <td class="text-nowrap">${new Date(r.time * 1000).toLocaleTimeString()}</td></tr>`).join('')
                : '<tr><td colspan="6" class="muted">No failures.</td></tr>';
            const pages = Math.ceil(d.total / d.per_page);
            $('#failedPager').innerHTML = pages > 1
                ? `<button class="btn btn-sm btn-outline-secondary" ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}">‹ Prev</button>
                   <span class="small align-self-center">Page ${page} of ${pages}</span>
                   <button class="btn btn-sm btn-outline-secondary" ${page >= pages ? 'disabled' : ''} data-page="${page + 1}">Next ›</button>` : '';
            $('#btnExport').classList.toggle('disabled', !d.total);
        } catch { /* ignore */ }
    }
    $('#failedPager').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-page]');
        if (b) loadFailed(+b.dataset.page);
    });

    // -------------------------------------------------------------------- init

    (async function init() {
        try {
            const d = await api('/api/state', { method: 'GET' });
            fillForm(d.config);
            setImport(d.import);
            applyStatus(d.status);
            if (d.status && ACTIVE.includes(d.status.state)) poll();
        } catch (err) {
            alertBox('danger', esc(err.message));
        }
    })();
})();
