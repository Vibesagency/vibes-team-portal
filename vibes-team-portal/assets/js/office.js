/* Vibes Office: who's in the virtual office right now, plus room &
 * analytics management for admins. Self-contained — no dependency on
 * portal.js internals or the VPM module helpers, so it keeps working even if
 * those plugins change independently. Mirrors the "Client Reviews" pattern:
 * poll location.hash, render into #vtp-view when the route matches. */
(() => {
  if (typeof VTP_OFFICE === 'undefined' || window.__vofficeInstalled) return;
  window.__vofficeInstalled = true;
  const ROUTE = 'office';
  const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const route = () => (location.hash || '').slice(1).split('?')[0];
  let token = 0;

  const api = (path, options = {}) => fetch(VTP_OFFICE.root + path, {
    ...options,
    headers: {'X-WP-Nonce': VTP_OFFICE.nonce, 'Content-Type': 'application/json', ...(options.headers || {})},
    credentials: 'same-origin',
    cache: 'no-store',
  }).then(async (r) => {
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || 'Something went wrong.');
    return data;
  });

  const dur = (secs) => {
    secs = Math.max(0, Math.round(secs));
    const h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60);
    return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
  };
  const ago = (iso) => {
    if (!iso) return 'never';
    const m = Math.round((Date.now() - new Date(iso + 'Z').getTime()) / 60000);
    if (m < 2) return 'just now';
    if (m < 60) return `${m} min ago`;
    return `${Math.round(m / 60)} h ago`;
  };

  let rooms = [];
  let analytics = null;
  let employees = [];
  let roomsError = '', analyticsError = '', employeesError = '';

  function employeeAccessPanel() {
    if (!VTP_OFFICE.canManage) return '';
    const rows = employees.map((e) => `
      <tr data-user-id="${e.userId}">
        <td class="vof-person"><img src="${esc(e.avatarUrl)}" alt=""><span>${esc(e.name)}</span></td>
        <td class="vof-muted">${esc(e.email)}</td>
        <td>
          <label class="vof-switch">
            <input type="checkbox" data-office-access-toggle ${e.officeEnabled ? 'checked' : ''}>
            <span>${e.officeEnabled ? 'Can sign in' : 'Blocked'}</span>
          </label>
        </td>
      </tr>`).join('');
    return `
      <article class="vtp-card vof-employees">
        <div class="vtp-card-head"><div><h2>Who can use Vibes Office</h2><p>Only Vibes employee/manager accounts can ever sign into the desktop app, and only the ones you switch on here. New employees start blocked until you turn them on.</p></div></div>
        ${employeesError ? `<div class="vtp-alert error">${esc(employeesError)}</div>` : ''}
        <table class="vof-table">
          <thead><tr><th>Employee</th><th>Email</th><th>Desktop app access</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="3" class="vof-muted">No eligible employees yet.</td></tr>'}</tbody>
        </table>
      </article>`;
  }

  function roomsAdminPanel() {
    if (!VTP_OFFICE.canManage) return '';
    const rows = rooms.map((r) => `
      <tr data-room-id="${r.id}">
        <td><input class="vtp-input" data-office-room-label value="${esc(r.label)}"></td>
        <td class="vof-muted">${esc(r.jitsiRoom)}</td>
        <td><button class="vtp-btn small" data-office-save-room>Save</button>
        <button class="vtp-btn small danger" data-office-delete-room>Remove</button></td>
      </tr>`).join('');
    return `
      <article class="vtp-card vof-rooms">
        <div class="vtp-card-head"><div><h2>Office rooms</h2><p>These are the rooms everyone's Vibes Office app connects to.</p></div></div>
        ${roomsError ? `<div class="vtp-alert error">${esc(roomsError)}</div>` : ''}
        <table class="vof-table">
          <thead><tr><th>Room</th><th>Jitsi room (internal)</th><th></th></tr></thead>
          <tbody>${rows || '<tr><td colspan="3" class="vof-muted">No rooms yet — add the first one below.</td></tr>'}</tbody>
        </table>
        <form id="vof-new-room" class="vof-inline-form">
          <input class="vtp-input" name="label" placeholder="e.g. Main Office, Lounge, Meeting Room 1" required>
          <button class="vtp-btn primary small" type="submit">Add room</button>
        </form>
      </article>`;
  }

  function analyticsPanel() {
    if (!VTP_OFFICE.canManage) return '';
    const date = analytics?.date || new Date().toISOString().slice(0, 10);
    const rows = (analytics?.employees || []).map((e) => `
      <tr>
        <td class="vof-person"><img src="${esc(e.avatarUrl)}" alt=""><span>${esc(e.name)}</span></td>
        <td>${e.onlineNow ? `<span class="vof-badge on">In ${esc(e.currentRoom || 'office')}</span>` : '<span class="vof-badge off">Offline</span>'}</td>
        <td>${dur(e.secondsToday)}</td>
        <td>${e.snapshotCount}</td>
        <td>${e.lastSnapshot ? `
          <a href="${esc(VTP_OFFICE.root)}office/snapshots/${e.lastSnapshot.id}?_wpnonce=${esc(VTP_OFFICE.nonce)}" target="_blank" rel="noopener">
            <img class="vof-thumb" src="${esc(VTP_OFFICE.root)}office/snapshots/${e.lastSnapshot.id}?_wpnonce=${esc(VTP_OFFICE.nonce)}" alt="Last snapshot">
          </a>
          <div class="vof-muted">${e.lastSnapshot.faceDetected ? 'Person detected' : 'No face detected'} · ${ago(e.lastSnapshot.capturedAt)}</div>
        ` : '<span class="vof-muted">No snapshot yet</span>'}</td>
      </tr>`).join('');
    return `
      <article class="vtp-card vof-analytics">
        <div class="vtp-card-head">
          <div><h2>Office analytics</h2><p>Presence and snapshot history. Employees were informed this is tracked.</p></div>
          <input type="date" id="vof-date" class="vtp-input" value="${esc(date)}" max="${new Date().toISOString().slice(0, 10)}">
        </div>
        ${analyticsError ? `<div class="vtp-alert error">${esc(analyticsError)}</div>` : ''}
        <table class="vof-table">
          <thead><tr><th>Employee</th><th>Status</th><th>Time today</th><th>Snapshots</th><th>Last snapshot</th></tr></thead>
          <tbody>${rows || '<tr><td colspan="5" class="vof-muted">No office activity for this day.</td></tr>'}</tbody>
        </table>
      </article>`;
  }

  function onlineNowPanel() {
    const online = (analytics?.employees || []).filter((e) => e.onlineNow);
    if (VTP_OFFICE.canManage) return ''; // admins get the full analytics table instead
    return `
      <article class="vtp-card vof-online">
        <div class="vtp-card-head"><div><h2>In the office right now</h2><p>Open the Vibes Office app on your computer to join a room.</p></div></div>
        <div class="vof-online-grid">
          ${online.length ? online.map((e) => `
            <div class="vof-online-chip"><img src="${esc(e.avatarUrl)}" alt="">
              <span>${esc(e.name)}</span><small>${esc(e.currentRoom || '')}</small></div>`).join('')
            : '<p class="vof-muted">Nobody is online right now.</p>'}
        </div>
      </article>`;
  }

  function view() {
    return `<section class="vtp-page vof-page">
      ${typeof pageHead === 'function' ? pageHead('Office', 'Your always-on virtual office.') : '<div class="vtp-page-head"><h1>Office</h1></div>'}
      ${employeeAccessPanel()}
      ${roomsAdminPanel()}
      ${analyticsPanel()}
      ${onlineNowPanel()}
    </section>`;
  }

  async function loadRooms() {
    try { rooms = await api('office/rooms'); roomsError = ''; }
    catch (e) { roomsError = e.message; }
  }
  async function loadEmployees() {
    if (!VTP_OFFICE.canManage) return;
    try { employees = await api('office/employees'); employeesError = ''; }
    catch (e) { employeesError = e.message; }
  }
  async function loadAnalytics(date) {
    try {
      if (VTP_OFFICE.canManage) {
        const qs = date ? `?date=${encodeURIComponent(date)}` : '';
        analytics = await api('office/analytics' + qs);
      } else {
        analytics = await api('office/online');
      }
      analyticsError = '';
    } catch (e) { analyticsError = e.message; }
  }

  async function render() {
    const el = document.getElementById('vtp-view');
    if (!el || route() !== ROUTE) return;
    const t = ++token;
    if (!el.querySelector('.vof-page')) el.innerHTML = '<section class="vtp-page vof-page"><div class="vcl-load">Loading your office…</div></section>';
    await Promise.all([loadRooms(), loadAnalytics(), loadEmployees()]);
    if (t !== token || route() !== ROUTE) return;
    el.innerHTML = view();
  }

  document.addEventListener('submit', async (e) => {
    const form = e.target.closest('#vof-new-room');
    if (!form) return;
    e.preventDefault();
    const label = form.querySelector('[name=label]').value.trim();
    if (!label) return;
    try {
      await api('office/rooms', {method: 'POST', body: JSON.stringify({label})});
      await loadRooms();
      const el = document.getElementById('vtp-view');
      if (el && route() === ROUTE) el.innerHTML = view();
    } catch (err) { roomsError = err.message; const el = document.getElementById('vtp-view'); if (el) el.innerHTML = view(); }
  });

  document.addEventListener('click', async (e) => {
    const saveBtn = e.target.closest('[data-office-save-room]');
    const delBtn = e.target.closest('[data-office-delete-room]');
    if (saveBtn) {
      const row = saveBtn.closest('tr');
      const id = row.dataset.roomId;
      const label = row.querySelector('[data-office-room-label]').value.trim();
      try { await api(`office/rooms/${id}`, {method: 'PATCH', body: JSON.stringify({label})}); await loadRooms(); const el = document.getElementById('vtp-view'); if (el) el.innerHTML = view(); }
      catch (err) { roomsError = err.message; }
    }
    if (delBtn) {
      const row = delBtn.closest('tr');
      const id = row.dataset.roomId;
      if (!confirm('Remove this room? Existing sessions keep their history.')) return;
      try { await api(`office/rooms/${id}`, {method: 'DELETE'}); await loadRooms(); const el = document.getElementById('vtp-view'); if (el) el.innerHTML = view(); }
      catch (err) { roomsError = err.message; }
    }
  });

  document.addEventListener('change', async (e) => {
    if (e.target.id === 'vof-date') {
      await loadAnalytics(e.target.value);
      const el = document.getElementById('vtp-view');
      if (el && route() === ROUTE) el.innerHTML = view();
      return;
    }
    const toggle = e.target.closest('[data-office-access-toggle]');
    if (toggle) {
      const row = toggle.closest('tr');
      const id = row.dataset.userId;
      const enabled = toggle.checked;
      toggle.disabled = true;
      try {
        await api(`office/employees/${id}`, {method: 'PATCH', body: JSON.stringify({enabled})});
        await loadEmployees();
      } catch (err) {
        employeesError = err.message;
        toggle.checked = !enabled; // revert on failure
      } finally {
        const el = document.getElementById('vtp-view');
        if (el && route() === ROUTE) el.innerHTML = view();
      }
    }
  });

  const kick = () => { if (route() === ROUTE) render(); };
  window.addEventListener('hashchange', kick);
  [0, 500, 1400, 3000].forEach((ms) => setTimeout(kick, ms));
  kick();
  // Keep the "online now" numbers fresh while the tab is open on this page.
  setInterval(() => { if (route() === ROUTE) render(); }, 30000);
})();
