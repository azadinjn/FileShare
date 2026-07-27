/* =========================================================
   FileVault — front-end logic
   - Chunked upload with progress + speed
   - Drag & drop + file picker
   - QR code generation (qr-code-styling)
   - Copy / share buttons on the download page
   ========================================================= */

(function () {
  'use strict';

  const CHUNK_SIZE = 4 * 1024 * 1024; // 4 MB per chunk — keeps requests small
  const csrf = readCsrf();

  function readCsrf() {
    const el = document.querySelector('input[name="csrf"]');
    return el ? el.value : '';
  }

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function toast(msg, type) {
    let wrap = $('.toast-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toast-wrap'; document.body.appendChild(wrap); }
    const t = document.createElement('div');
    t.className = 'toast ' + (type || 'ok');
    t.textContent = msg;
    wrap.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 250); }, 2600);
  }

  function humanSize(bytes) {
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0, b = bytes;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? Math.round(b) : b.toFixed(2)) + ' ' + u[i];
  }

  // ====================================================================
  //  Upload page
  // ====================================================================
  function initUpload() {
    const form = $('#uploadForm');
    if (!form) return;
    const dropZone = $('#dropZone');
    const fileInput = $('#fileInput');
    const queue = $('#fileQueue');
    const uploadBtn = $('#uploadBtn');
    const resultCard = $('#resultCard');
    const resultCode = $('#resultCode');
    const resultLink = $('#resultLink');

    let selected = []; // [{file, id, status, el}]
    let uploading = false;

    dropZone.addEventListener('click', function () { fileInput.click(); });
    dropZone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      dropZone.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation(); dropZone.classList.add('is-drag');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dropZone.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('is-drag');
      });
    });
    dropZone.addEventListener('drop', function (e) {
      const dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length) addFiles(dt.files);
    });
    fileInput.addEventListener('change', function () {
      if (fileInput.files.length) addFiles(fileInput.files);
      fileInput.value = '';
    });

    function addFiles(fileList) {
      Array.prototype.forEach.call(fileList, function (file) {
        const id = 'f' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
        selected.push({ file: file, id: id, status: 'pending' });
        renderQueue();
      });
      queue.hidden = false;
      uploadBtn.disabled = selected.length === 0;
    }

    function renderQueue() {
      queue.innerHTML = '';
      selected.forEach(function (item) {
        const li = document.createElement('li');
        li.className = 'file-row-item';
        li.dataset.id = item.id;
        const info = document.createElement('div'); info.className = 'finfo';
        const name = document.createElement('div'); name.className = 'fname'; name.textContent = item.file.name;
        const meta = document.createElement('div'); meta.className = 'fmeta';
        meta.textContent = humanSize(item.file.size) + (item.status === 'ok' ? ' · done' : '');
        info.appendChild(name); info.appendChild(meta);

        const status = document.createElement('div'); status.className = 'fstatus ' + statusClass(item.status);
        status.textContent = statusText(item.status);

        const track = document.createElement('div'); track.className = 'progress-track';
        const fill = document.createElement('div'); fill.className = 'progress-fill';
        if (item.progress) fill.style.width = item.progress + '%';
        track.appendChild(fill);

        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'remove-btn'; remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        remove.addEventListener('click', function () {
          if (uploading) { toast('Wait for the upload to finish.', 'err'); return; }
          selected = selected.filter(function (x) { return x.id !== item.id; });
          renderQueue();
          if (!selected.length) { queue.hidden = true; uploadBtn.disabled = true; }
        });

        li.appendChild(info); li.appendChild(status); li.appendChild(track); li.appendChild(remove);
        queue.appendChild(li);
        item.el = li;
        item.fillEl = fill;
        item.statusEl = status;
        item.metaEl = meta;
      });
    }
    function statusClass(s) { return s === 'ok' ? 'ok' : s === 'error' ? 'err' : s === 'uploading' ? 'uploading' : ''; }
    function statusText(s) {
      return s === 'ok' ? 'Ready' : s === 'error' ? 'Failed' : s === 'uploading' ? 'Uploading' : 'Queued';
    }
    function updateItem(item, pct, speed) {
      if (item.fillEl) item.fillEl.style.width = pct + '%';
      if (item.statusEl) {
        item.statusEl.className = 'fstatus ' + statusClass(item.status);
        item.statusEl.textContent = statusText(item.status) + (speed ? ' · ' + humanSize(speed) + '/s' : '');
      }
      if (item.metaEl) item.metaEl.textContent = humanSize(item.file.size) + ' · ' + Math.round(pct) + '%';
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (uploading) return;
      if (!selected.length) { toast('Add at least one file.', 'err'); return; }
      startUpload();
    });

    async function startUpload() {
      uploading = true;
      uploadBtn.disabled = true;
      const originalLabel = uploadBtn.innerHTML;
      uploadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';

      try {
        // 1) create session
        const createRes = await postForm('upload.php', {
          action: 'create',
          csrf: csrf,
          package_name: $('#packageName').value || '',
          expires_at: $('#packageExpiry').value || ''
        });
        if (!createRes.ok) throw new Error(createRes.error || 'Could not start upload.');
        const uploadId = createRes.upload_id;

        // 2) upload each file's chunks
        const manifest = [];
        for (const item of selected) {
          item.status = 'uploading';
          updateItem(item, 0, 0);
          const fileId = item.id;
          const total = Math.max(1, Math.ceil(item.file.size / CHUNK_SIZE));
          let sent = 0;
          const t0 = Date.now();
          for (let i = 0; i < total; i++) {
            const start = i * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, item.file.size);
            const blob = item.file.slice(start, end);
            const r = await postChunk('upload.php', {
              action: 'append',
              csrf: csrf,
              upload_id: uploadId,
              file_id: fileId,
              chunk_index: String(i),
              file_name: item.file.name
            }, blob, 'file');
            if (!r.ok) throw new Error(r.error || 'Chunk failed.');
            sent += (end - start);
            const pct = (sent / item.file.size) * 100;
            const elapsed = (Date.now() - t0) / 1000;
            const speed = elapsed > 0 ? sent / elapsed : 0;
            updateItem(item, pct, speed);
          }
          item.status = 'ok';
          updateItem(item, 100, 0);
          manifest.push({ file_id: fileId, name: item.file.name, size: item.file.size });
        }

        // 3) finish
        const fin = await postForm('upload.php', {
          action: 'finish',
          csrf: csrf,
          upload_id: uploadId,
          manifest: JSON.stringify(manifest)
        });
        if (!fin.ok) throw new Error(fin.error || 'Could not finalize package.');

        showResult(fin);
        toast('Package ready!', 'ok');
      } catch (err) {
        console.error(err);
        toast(err.message || 'Upload failed.', 'err');
        selected.forEach(function (it) {
          if (it.status === 'uploading') { it.status = 'error'; updateItem(it, 0, 0); }
        });
      } finally {
        uploading = false;
        uploadBtn.disabled = selected.length === 0;
        uploadBtn.innerHTML = originalLabel;
      }
    }

    function showResult(res) {
      resultCode.textContent = res.code;
      resultLink.href = res.download_url;
      resultCard.hidden = false;
      resultCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ----- XHR helpers -----
    function postForm(url, fields) {
      return new Promise(function (resolve) {
        const fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.onload = function () {
          try { resolve(JSON.parse(xhr.responseText)); }
          catch (e) { resolve({ ok: false, error: 'Bad server response.' }); }
        };
        xhr.onerror = function () { resolve({ ok: false, error: 'Network error.' }); };
        xhr.send(fd);
      });
    }

    function postChunk(url, fields, blob, fieldName) {
      return new Promise(function (resolve) {
        const fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append(fieldName, blob, fields.file_name || 'chunk');
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.onload = function () {
          try { resolve(JSON.parse(xhr.responseText)); }
          catch (e) { resolve({ ok: false, error: 'Bad server response.' }); }
        };
        xhr.onerror = function () { resolve({ ok: false, error: 'Network error.' }); };
        xhr.send(fd);
      });
    }
  }

  // ====================================================================
  //  Download page — QR code + share buttons
  // ====================================================================
  function initDownload() {
    const qrHost = document.getElementById('qrcode');
    if (!qrHost) return;

    const url = window.PACKAGE_DOWNLOAD_URL || (window.location.origin + window.location.pathname + '?code=' + (window.PACKAGE_CODE || ''));

    // qr-code-styling: high error correction, black on white, scannable everywhere.
    if (window.QRCodeStyling) {
      const qr = new QRCodeStyling({
        width: 220,
        height: 220,
        type: 'svg',
        data: url,
        margin: 6,
        qrOptions: {
          typeNumber: 0,           // auto
          mode: 'Byte',
          errorCorrectionLevel: 'H' // ~30% recovery
        },
        dotsOptions: { color: '#000000', type: 'square' },
        backgroundOptions: { color: '#ffffff' },
        cornersSquareOptions: { type: 'square', color: '#000000' },
        cornersDotOptions: { type: 'square', color: '#000000' }
      });
      qr.append(qrHost);
    } else {
      // Fallback link if the library fails to load.
      qrHost.innerHTML = '<a href="' + escapeHtml(url) + '" style="color:#0a0e1a">Open link</a>';
    }

    const copyLink = document.getElementById('copyLinkBtn');
    const copyCode = document.getElementById('copyCodeBtn');
    const shareBtn = document.getElementById('shareBtn');

    if (copyLink) copyLink.addEventListener('click', function () {
      copy(copyLink.dataset.url || url, 'Link copied');
    });
    if (copyCode) copyCode.addEventListener('click', function () {
      copy(copyCode.dataset.code || (window.PACKAGE_CODE || ''), 'Code copied');
    });
    if (shareBtn) shareBtn.addEventListener('click', function () {
      const data = { title: shareBtn.dataset.title || 'Download my files', url: shareBtn.dataset.url || url };
      if (navigator.share) {
        navigator.share(data).catch(function () {});
      } else {
        copy(data.url, 'Link copied — paste to share');
      }
    });

    function copy(text, msg) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { toast(msg, 'ok'); })
          .catch(function () { fallbackCopy(text, msg); });
      } else {
        fallbackCopy(text, msg);
      }
    }
    function fallbackCopy(text, msg) {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); toast(msg, 'ok'); }
      catch (e) { toast('Copy failed', 'err'); }
      ta.remove();
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ====================================================================
  //  Boot
  // ====================================================================
  document.addEventListener('DOMContentLoaded', function () {
    initUpload();
    initDownload();
  });
})();
