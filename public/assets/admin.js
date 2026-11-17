/* Admin: upload shared recipient files and assign each to one employee. */
(() => {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const CHUNK_SIZE = Number($('#files').dataset.chunkBytes) || 1024 * 1024;
    const data = { files: [], users: [] };
    let confirmDelete = null;

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = (n) => Number(n || 0).toLocaleString();
    const randHex = (bytes) => Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (b) => b.toString(16).padStart(2, '0')).join('');

    async function api(path, { method = 'POST', body = null, formData = null } = {}) {
        const opts = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' } };
        if (formData) opts.body = formData;
        else if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        let res;
        try { res = await fetch(path, opts); } catch { throw new Error('Network error. Please check your connection.'); }
        let d = {};
        try { d = await res.json(); } catch { /* non-JSON */ }
        if (res.status === 401) { location.href = '/login'; throw new Error('Session expired.'); }
        if (!res.ok || d.ok === false) throw new Error(d.error || `Request failed (${res.status}).`);
        return d;
    }

    function alertBox(type, html, autoHideMs = 0) {
        const box = document.createElement('div');
        box.className = `alert alert-${type} alert-dismissible fade show py-2`;
        box.innerHTML = `${html}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        $('#alerts').prepend(box);
        while ($('#alerts').children.length > 4) $('#alerts').lastElementChild.remove();
        if (autoHideMs) setTimeout(() => box.remove(), autoHideMs);
        return box;
    }

    // ------------------------------------------------------------------ upload

    $('#files').addEventListener('change', async (e) => {
        const files = Array.from(e.target.files);
        e.target.value = '';
        if (!files.length) return;
        e.target.disabled = true;
        const list = $('#uploadList');
        list.innerHTML = files.map((f, i) => `<li id="up${i}"><span class="up-name">${esc(f.name)}</span><span class="up-state muted">waiting</span></li>`).join('');
        let ok = 0;
        for (let i = 0; i < files.length; i++) {
            const row = $(`#up${i} .up-state`);
            try {
                const res = await uploadFile(files[i], (pct) => { row.textContent = pct < 100 ? `uploading ${pct}%` : 'reading rows…'; });
                const f = res.file;
                const skipped = f.invalid + f.duplicates;
                row.className = 'up-state ok';
                row.textContent = `${num(f.recipients)} recipients` + (skipped ? ` (${num(f.invalid)} invalid, ${num(f.duplicates)} duplicates skipped)` : '');
                ok++;
            } catch (err) {
                row.className = 'up-state bad';
                row.textContent = err.message;
            }
        }
        e.target.disabled = false;
        if (ok) alertBox('success', `${ok} of ${files.length} file(s) uploaded. Assign each one to an employee below.`, 8000);
        load();
    });

    async function uploadFile(file, onProgress) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['csv', 'txt', 'xlsx', 'ods'].includes(ext)) throw new Error('Not a .csv or .xlsx file.');
        const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        const id = randHex(16);
        let res;
        for (let i = 0; i < total; i++) {
            const fd = new FormData();
            fd.append('upload_id', id);
            fd.append('index', i);
            fd.append('total', total);
            fd.append('name', file.name);
            fd.append('size', file.size);
            fd.append('chunk', file.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE), 'chunk');
            onProgress(Math.round((i / total) * 100));
            if (i === total - 1) onProgress(100);
            res = await api('/api/admin/upload/chunk', { formData: fd });
        }
        return res;
    }

    // ------------------------------------------------------------------- table

    async function load() {
        try {
            const d = await api('/api/admin/files', { method: 'GET' });
            data.files = d.files;
            data.users = d.users;
            render();
        } catch (err) {
            alertBox('danger', esc(err.message));
        }
    }

    function userOptions(selected) {
        return '<option value="0">— Not assigned —</option>' + data.users.map((u) =>
            `<option value="${u.id}" ${u.id === selected ? 'selected' : ''}>${esc(u.name)} (${esc(u.email)})${u.role === 'admin' ? ' · admin' : ''}</option>`).join('');
    }

    function render() {
        const q = $('#search').value.trim().toLowerCase();
        const rows = data.files.filter((f) => !q || f.name.toLowerCase().includes(q)
            || (f.assignee && `${f.assignee.name} ${f.assignee.email}`.toLowerCase().includes(q)));
        $('#fileCount').textContent = data.files.length ? `(${num(data.files.length)})` : '';
        if (!rows.length) {
            $('#fileBody').innerHTML = `<tr><td colspan="6" class="muted">${data.files.length ? 'No files match your search.' : 'No files yet. Upload one above.'}</td></tr>`;
            return;
        }
        $('#fileBody').innerHTML = rows.map((f) => {
            const p = f.progress;
            const done = p.sent + p.failed + p.unknown;
            const pct = p.total ? Math.floor((done / p.total) * 100) : 0;
            const skipped = f.invalid + f.duplicates;
            const deleting = confirmDelete === f.id;
            return `<tr data-id="${f.id}">
                <td class="fname"><code>${esc(f.name)}</code>${skipped ? `<div class="small muted">${num(f.invalid)} invalid, ${num(f.duplicates)} duplicates skipped${f.has_skipped ? ` · <a href="/api/admin/files/skipped?id=${f.id}">download</a>` : ''}</div>` : ''}</td>
                <td class="text-end num">${num(f.recipients)}</td>
                <td class="prog-cell">
                    <div class="progress mini" role="progressbar" aria-label="Progress"><div class="progress-bar bg-success" style="width:${pct}%"></div></div>
                    <div class="small muted num">${num(p.sent)} sent · ${num(p.failed + p.unknown)} failed · ${num(p.remaining)} left</div>
                </td>
                <td><select class="form-select form-select-sm assign" aria-label="Assign ${esc(f.name)} to">${userOptions(f.assigned_user_id)}</select></td>
                <td class="small text-nowrap">${esc(String(f.created_at).slice(0, 16))}</td>
                <td class="text-end text-nowrap">${deleting
                    ? `<span class="small me-1">Delete with its progress?</span><button class="btn btn-danger btn-sm del-yes" type="button">Delete</button> <button class="btn btn-outline-secondary btn-sm del-no" type="button">Keep</button>`
                    : '<button class="btn btn-outline-danger btn-sm del" type="button">Delete</button>'}</td>
            </tr>`;
        }).join('');
    }

    $('#search').addEventListener('input', render);

    $('#fileBody').addEventListener('change', async (e) => {
        const sel = e.target.closest('select.assign');
        if (!sel) return;
        const id = +sel.closest('tr').dataset.id;
        const file = data.files.find((f) => f.id === id);
        const userId = +sel.value;
        sel.disabled = true;
        try {
            await api('/api/admin/files/assign', { body: { id, user_id: userId } });
            const u = data.users.find((x) => x.id === userId);
            alertBox('success', u ? `<code>${esc(file.name)}</code> is now assigned to ${esc(u.name)}.` : `<code>${esc(file.name)}</code> is no longer assigned.`, 5000);
        } catch (err) {
            alertBox('danger', esc(err.message));
        }
        load();
    });

    $('#fileBody').addEventListener('click', async (e) => {
        const tr = e.target.closest('tr[data-id]');
        if (!tr) return;
        const id = +tr.dataset.id;
        if (e.target.closest('.del')) { confirmDelete = id; render(); return; }
        if (e.target.closest('.del-no')) { confirmDelete = null; render(); return; }
        if (e.target.closest('.del-yes')) {
            const file = data.files.find((f) => f.id === id);
            try {
                await api('/api/admin/files/delete', { body: { id } });
                alertBox('success', `<code>${esc(file.name)}</code> was deleted.`, 5000);
            } catch (err) {
                alertBox('danger', esc(err.message));
            }
            confirmDelete = null;
            load();
        }
    });

    load();
    setInterval(() => { if (!document.hidden && confirmDelete === null && !document.activeElement?.matches('select')) load(); }, 10000);
})();
