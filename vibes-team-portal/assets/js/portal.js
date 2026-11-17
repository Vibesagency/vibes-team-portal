(() => {
  'use strict';
  window.VTPWorkNavManaged = true;

  const $ = (selector, root = document) => root.querySelector(selector);
  const state = { boot: null, employees: [], inquiries: [], quotations: [], invoices: [], projects: [], tasks: [], leave: [], holidays: [], announcements: [], catalog: null, filesBrand: null, announcementFilters: { query: '', audience: 'all', status: 'published' }, employeeFilters: { query: '', role: 'all', availability: 'all', status: 'active' } };
  const view = $('#vtp-view');
  const drawer = $('#vtp-drawer');
  const drawerContent = $('#vtp-drawer-content');
  const toastEl = $('#vtp-toast');
  const PORTAL_TIME_ZONE = 'Asia/Beirut';
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  const dateParts = value => Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: PORTAL_TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(value).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
  const parsePortalDate = value => {
    if (!value) return null;
    const raw = String(value).trim();
    const match = raw.match(/^(\d{4})[-/](\d{2})[-/](\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    const parsed = match
      ? new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4] || 12), Number(match[5] || 0), Number(match[6] || 0)))
      : new Date(raw);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  };
  const fmt = value => { const parsed = parsePortalDate(value); return parsed ? parsed.toLocaleDateString('en-GB', { timeZone: PORTAL_TIME_ZONE, day: 'numeric', month: 'short', year: 'numeric' }) : 'No date'; };
  const fmtDateTime = value => { const parsed = parsePortalDate(value); return parsed ? parsed.toLocaleString('en-GB', { timeZone: PORTAL_TIME_ZONE, day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'No date'; };
  const money = (value, currency = 'SAR') => new Intl.NumberFormat('en-SA', { style: 'currency', currency }).format(Number(value || 0));
  const moneyUsd = value => money(value, 'USD');
  const catalogExchangeRate = () => Number(state.catalog?.sarPerUsd || 3.75);
  const roundPrice = value => Math.round((Number(value) + Number.EPSILON) * 100) / 100;
  function catalogPriceFields(item) {
    const sar = roundPrice(item?.oneTimePrice || 0);
    return `<input type="hidden" name="priceCurrency" value="SAR"><div class="vtp-price-grid"><div class="vtp-field"><label for="vtp-base-price-sar">Base price (SAR)</label><input id="vtp-base-price-sar" name="oneTimePrice" data-price-currency="SAR" type="number" min="0" step="0.01" value="${sar}" required></div><div class="vtp-field"><label for="vtp-base-price-usd">Base price (USD)</label><input id="vtp-base-price-usd" name="oneTimePriceUsd" data-price-currency="USD" type="number" min="0" step="0.01" value="${roundPrice(sar / catalogExchangeRate())}" required></div></div><small class="vtp-price-help">Edit either price to update the other. 1 USD = SAR ${catalogExchangeRate()}. Contract totals and discounts are calculated from this base price.</small>`;
  }
  function syncCatalogPrice(input) {
    const form = input.closest('#vtp-service-form, #vtp-package-form');
    if (!form || !input.matches('[data-price-currency]')) return;
    const currency = input.dataset.priceCurrency;
    const counterpart = form.elements[currency === 'USD' ? 'oneTimePrice' : 'oneTimePriceUsd'];
    form.elements.priceCurrency.value = currency;
    if (input.value === '') { counterpart.value = ''; return; }
    const value = Number(input.value);
    if (!Number.isFinite(value) || value < 0) return;
    counterpart.value = roundPrice(currency === 'USD' ? value * catalogExchangeRate() : value / catalogExchangeRate()).toFixed(2);
  }
  const catalogPricePayload = form => ({
    oneTimePrice: Number(form.elements.oneTimePrice.value || 0),
    oneTimePriceUsd: Number(form.elements.oneTimePriceUsd.value || 0),
    priceCurrency: form.elements.priceCurrency.value === 'USD' ? 'USD' : 'SAR',
  });
  const todayIso = () => {
    const date = dateParts(new Date());
    return `${date.year}-${date.month}-${date.day}`;
  };
  const dateAfter = days => { const now = dateParts(new Date()); const date = new Date(Date.UTC(Number(now.year), Number(now.month) - 1, Number(now.day) + days)); return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`; };
  const driveTaskFolder = 'https://drive.google.com/drive/folders/1YVg8h2jQllWIQYMJnGFLmPJlpKPtTIjj';

  async function api(path, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const headers = { 'X-WP-Nonce': VTP.nonce, ...(options.headers || {}) };
    if (!(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    // WordPress/LiteSpeed can cache authenticated REST GET responses. Add a
    // request nonce and no-store policy so each panel sees newly created or
    // edited tasks, leave requests, and catalog prices immediately.
    const endpoint = method === 'GET'
      ? `${VTP.root + path}${(VTP.root + path).includes('?') ? '&' : '?'}vtp_refresh=${Date.now()}`
      : VTP.root + path;
    const response = await fetch(endpoint, { credentials: 'same-origin', ...options, cache: 'no-store', headers });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
    return data;
  }

  // A project's owner falls back to the people its tasks are assigned to.
  function projectAssignees(project) {
    const names = [...new Set((state.tasks || []).filter(task => Number(task.project_id) === Number(project.id) && task.assignee_name).map(task => task.assignee_name.split(' ')[0]))];
    return names.join(', ');
  }

  async function load(key, refresh = false) {
    if (!refresh && state[key]?.length) return state[key];
    state[key] = await api(key);
    return state[key];
  }

  async function loadCatalog(refresh = false) {
    if (!refresh && state.catalog) return state.catalog;
    state.catalog = await api('catalog');
    return state.catalog;
  }

  function toast(message, error = false) {
    toastEl.textContent = message;
    toastEl.classList.toggle('error', error);
    toastEl.classList.add('show');
    window.setTimeout(() => toastEl.classList.remove('show'), 3200);
  }

  const MARITAL = [['single','Single'],['married','Married'],['divorced','Divorced'],['widowed','Widowed'],['prefer_not_to_say','Prefer not to say']];
  const maritalOptions = (v) => '<option value="">Select</option>' + MARITAL.map(([k, l]) => '<option value="' + k + '"' + (v === k ? ' selected' : '') + '>' + l + '</option>').join('');
  const maritalLabel = (v) => (MARITAL.find(([k]) => k === v) || [])[1] || '';
  function openDrawer(html) {
    drawerContent.innerHTML = html;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    drawer.querySelector('.vtp-drawer-panel').scrollTop = 0;
  }

  function closeDrawer() {
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
  }

  const chip = (label, tone = '') => `<span class="vtp-chip ${tone}">${esc(String(label || '').replaceAll('_', ' '))}</span>`;
  const empty = message => `<div class="vtp-empty"><i aria-hidden="true">${statIcon('inbox')}</i><strong>${esc(message)}</strong></div>`;
  const pageHead = (title, subtitle, actions = '') => `<header class="vtp-page-head"><div><h1>${title}</h1><p>${esc(subtitle)}</p></div>${actions ? `<div class="vtp-head-actions">${actions}</div>` : ''}</header>`;
  const person = user => `<div class="vtp-person"><img src="${esc(user.avatar)}" alt="${esc(user.name)}"><span><strong>${esc(user.name)}</strong><small>${esc(user.email)}</small></span></div>`;
  const statIcon = name => ({
    inbox: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"></path><path d="M4 14h4l1.5 2h5L16 14h4"></path></svg>',
    message: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H8l-4 3z"></path><path d="M8 9h8M8 12h5"></path></svg>',
    quote: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"></path><path d="M14 3v4h4M9 11h6M9 15h6M9 19h4"></path></svg>',
    briefcase: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"></path></svg>',
    film: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 8h18M8 4v4M16 4v4M8 16v4M16 16v4M8 12h.01M12 12h.01M16 12h.01"></path></svg>',
    palette: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 0 0 0 18h1.4a1.8 1.8 0 0 0 1.2-3.1 1.8 1.8 0 0 1 1.2-3.1H18a3 3 0 0 0 3-3A8.8 8.8 0 0 0 12 3Z"></path><circle cx="7.5" cy="10" r=".8"></circle><circle cx="10" cy="7" r=".8"></circle><circle cx="14" cy="7" r=".8"></circle></svg>',
    video: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2"></rect><path d="m16 10 5-3v10l-5-3z"></path></svg>',
    megaphone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 13-6v12L4 12Z"></path><path d="M4 12v4a2 2 0 0 0 2 2h1.5M17 9h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2"></path></svg>',
    monitor: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2"></rect><path d="M8 21h8M12 17v4"></path></svg>',
    checklist: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="m8 9 1.5 1.5L12 8M14 10h2M8 15l1.5 1.5L12 14M14 16h2"></path></svg>',
    receipt: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-3-2z"></path><path d="M8 8h8M8 12h8M8 16h5"></path></svg>',
    users: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><circle cx="17" cy="9" r="2.5"></circle><path d="M3.5 19c.4-3.2 2.4-5 5.5-5s5.1 1.8 5.5 5M14 14.5c2.8-.2 4.8 1.3 5.2 4.5"></path></svg>',
    manager: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="8" r="3"></circle><path d="M4 19c.4-3.2 2.4-5 6-5s5.6 1.8 6 5"></path><path d="m17 13 .7 1.5 1.6.2-1.2 1.1.3 1.6-1.4-.8-1.4.8.3-1.6-1.2-1.1 1.6-.2z"></path></svg>',
    available: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="8" r="3"></circle><path d="M4 19c.4-3.2 2.4-5 6-5 2.1 0 3.8.6 4.8 1.8"></path><circle cx="18" cy="17" r="3"></circle><path d="m16.7 17 1 1 1.8-2"></path></svg>',
    eye: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-5 9-5 9 5 9 5-3.5 5-9 5-9-5-9-5Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>',
    send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 4 18 8-18 8 3.5-8z"></path><path d="M6.5 12H21"></path></svg>',
    categories: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"></rect><path d="M9.5 5v14M14.5 5v14M5 9.5h14M5 14.5h14"></path></svg>',
    packages: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"></path><path d="m4 7.5 8 4.5 8-4.5M12 12v9M8.5 5l8 4.5"></path></svg>',
    analytics: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5M4 19h16"></path><path d="m7 15 3-4 3 2 5-6"></path></svg>',
    pending: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10M7 20h10M8 4c0 4 4 4 4 8s-4 4-4 8M16 4c0 4-4 4-4 8s4 4 4 8"></path></svg>',
    approved: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="m8 12 2.7 2.7L16.5 9"></path></svg>',
    vacation: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"></path></svg>',
    sick: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 8v8M8 12h8"></path></svg>',
    plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 8v8M8 12h8"></path></svg>'
  }[name] || '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7"></circle></svg>');
  const stat = (label, value, symbol, target = '') => '<article class="vtp-card vtp-stat' + (target ? ' vtp-stat-clickable' : '') + '"' + (target ? ' data-stat-target="' + esc(target) + '" role="button" tabindex="0" aria-label="View ' + esc(label) + '"' : '') + '><span class="vtp-stat-icon">' + statIcon(symbol) + '</span><div><small>' + esc(label) + '</small><strong>' + esc(value) + '</strong></div></article>';
  const projectIsReadyForReview = project => {
    if (String(project?.status || '').toLowerCase() === 'finished') return false;
    const progress = Number(project?.progress || 0);
    const taskCount = Number(project?.task_count || 0);
    const submittedCount = Number(project?.submitted_count || 0);
    return project?.client_review_status === 'ready'
      || String(project?.status || '').toLowerCase() === 'review'
      || progress >= 100
      || (taskCount > 0 && submittedCount >= taskCount);
  };
  const projectCard = project => {
    const finished = project.status === 'finished';
    const ready = projectIsReadyForReview(project);
    const structure = project.driveStructure || {};
    const monthOptions = (structure.months || []).map(month => `<option value="${esc(month.key)}">${esc(month.label)}</option>`).join('');
    return `<article class="vtp-card vtp-project-card"><div class="vtp-card-head">${chip(finished ? 'Finished' : ready ? 'Ready for review' : (project.status || 'Active'), finished ? 'blue' : ready ? 'amber' : 'green')}<small>${fmt(project.due_date)}</small></div><h3>${esc(project.name)}</h3><p><strong>${esc(project.client || 'Internal')}</strong>${project.quote_number ? `<br>${esc(project.quote_number)} · ${Number(project.submitted_count || 0)}/${Number(project.task_count || 0)} tasks submitted` : ''}<br>${esc(project.description || 'No brief added yet.')}</p><div class="vtp-progress"><span style="width:${Number(project.progress || 0)}%"></span></div><div class="vtp-meta"><span>${esc(project.lead_name || projectAssignees(project) || 'Unassigned')}</span><b>${Number(project.progress || 0)}%</b></div><div class="vtp-card-actions">${!finished && (state.boot.capabilities.manageProjects || state.boot.capabilities.manageSales) ? `<button class="vtp-btn small" data-open-project="${project.id}">Manage project</button>` : ''}${!finished && ready && state.boot.capabilities.manageProjects ? `<button class="vtp-btn small primary" data-project-review="${project.id}">Send client review${monthOptions ? ' ↗' : ''}</button>` : ''}${project.client_review_token ? `<button class="vtp-btn small" data-copy-project-review="${project.id}">Copy review link</button>` : ''}${finished && project.invoice_id ? `<a class="vtp-btn small" href="#invoices">View invoice</a>` : ''}${state.boot.capabilities.manageProjects ? ` <button class="vtp-btn small danger" data-delete-project="${project.id}">Delete</button>` : ''}</div></article>`;
  };

  function formObject(form) { return Object.fromEntries(new FormData(form).entries()); }

  function installConnectLauncher() {
    if (!document.querySelector('script[src*="/vibes-portal-modules/assets/modules.js"]') || document.getElementById('vtp-connect-launcher')) return;
    const launcher = document.createElement('aside');
    launcher.id = 'vtp-connect-launcher';
    launcher.className = 'vtp-connect-launcher';
    const connectIcon = '<svg class="vtp-connect-icon" viewBox="0 0 40 32" aria-hidden="true"><path class="vtp-connect-icon-bubble" d="M4 7.5A7.5 7.5 0 0 1 11.5 0h17A7.5 7.5 0 0 1 36 7.5v7A7.5 7.5 0 0 1 28.5 22H19l-7.6 7v-7.2A7.5 7.5 0 0 1 4 14.5Z"></path><circle class="vtp-connect-icon-dot" cx="13" cy="11" r="2.2"></circle><circle class="vtp-connect-icon-dot" cx="20" cy="11" r="2.2"></circle><circle class="vtp-connect-icon-dot" cx="27" cy="11" r="2.2"></circle></svg>';
    const bellIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 10.5a5.5 5.5 0 0 1 11 0c0 5 2 5.5 2 7H4.5c0-1.5 2-2 2-7Z"></path><path d="M10 20h4"></path></svg>';
    launcher.innerHTML = `<div class="vtp-connect-launcher-row"><button type="button" class="vtp-connect-bell" data-connect-bell aria-label="Unread Vibes Connect messages" hidden>${bellIcon}<span class="vtp-connect-bell-badge" aria-hidden="true">0</span></button><button type="button" class="vtp-connect-label" data-connect-toggle>Vibes Connect</button><button type="button" class="vtp-connect-fab" data-connect-toggle aria-label="Open Vibes Connect">${connectIcon}</button></div>`;
    const routeIsConnect = () => (location.hash || '').slice(1).split('?')[0] === 'connect';
    const toggleRoute = () => {
      if (!routeIsConnect() && typeof window.VPMOpenConnectOverlay === 'function') { window.VPMOpenConnectOverlay(); return; }
      if (routeIsConnect() && typeof window.VPMCloseConnectOverlay === 'function') { window.VPMCloseConnectOverlay(); return; }
      if (routeIsConnect()) {
        const returnHash = sessionStorage.getItem('vtp-connect-return-hash') || '#dashboard';
        sessionStorage.removeItem('vtp-connect-return-hash');
        history.pushState({}, '', returnHash);
        window.setTimeout(() => window.dispatchEvent(new Event('hashchange')), 30);
        return;
      }
      // The core portal router can win a same-page hash race on dashboard.
      // A single direct route load keeps the Connect overlay deterministic.
      sessionStorage.setItem('vtp-connect-return-hash', location.hash || '#dashboard');
      window.location.hash = '#connect';
      window.location.reload();
    };
    launcher.querySelectorAll('[data-connect-toggle]').forEach(button => button.addEventListener('click', toggleRoute));
    launcher.querySelector('[data-connect-bell]')?.addEventListener('click', () => { if (typeof window.VPMOpenConnectOverlay === 'function') window.VPMOpenConnectOverlay(); });
    const bell = launcher.querySelector('[data-connect-bell]');
    const badge = launcher.querySelector('.vtp-connect-bell-badge');
    let unreadTimer = null;
    const updateUnreadBell = count => {
      const unread = Math.max(0, Number(count || 0));
      if (badge) badge.textContent = unread > 9 ? '9+' : String(unread);
      if (bell) {
        bell.hidden = unread < 1 || routeIsConnect();
        bell.setAttribute('aria-label', unread ? `${unread} unread Vibes Connect message${unread === 1 ? '' : 's'}` : 'Unread Vibes Connect messages');
      }
    };
    const scheduleUnreadCheck = () => {
      if (unreadTimer) window.clearTimeout(unreadTimer);
      unreadTimer = window.setTimeout(checkUnread, 20000);
    };
    const checkUnread = async () => {
      if (document.hidden || routeIsConnect() || document.body.classList.contains('vcx-open')) { if (!document.body.classList.contains('vcx-open')) updateUnreadBell(0); scheduleUnreadCheck(); return; }
      const config = window.VPM || (typeof VPM !== 'undefined' ? VPM : null);
      if (!config?.root || !config?.nonce) { scheduleUnreadCheck(); return; }
      try {
        const response = await fetch(`${config.root}connect/summary`, {headers: {'X-WP-Nonce': config.nonce}, credentials: 'same-origin', cache: 'no-store'});
        const data = response.ok ? await response.json() : {};
        updateUnreadBell(Number(data.unread || 0));
      } catch (error) { /* Keep the launcher quiet if the optional unread check is unavailable. */ }
      scheduleUnreadCheck();
    };
    VTPR.onHashChange(() => { if (routeIsConnect()) updateUnreadBell(0); });
    window.addEventListener('vpm-connect-unread', event => updateUnreadBell(event.detail && event.detail.count));
    window.setTimeout(checkUnread, 1800);
    document.body.appendChild(launcher);
  }

  function ensureConnectModuleFresh() {
    const source = document.querySelector('script[src*="/vibes-portal-modules/assets/modules.js"]');
    if (!source || document.querySelector('script[data-vtp-connect-refresh]')) return;
    const fresh = document.createElement('script');
    fresh.dataset.vtpConnectRefresh = '1';
    fresh.src = `${source.src}${source.src.includes('?') ? '&' : '?'}vtp_connect_refresh=20261103`;
    document.head.appendChild(fresh);
  }

  function updateInquiryBadge(count) {
    const badge = $('#vtp-inquiry-badge');
    if (!badge) return;
    const value = Number(count || 0);
    badge.textContent = value ? String(value) : '';
    badge.classList.toggle('show', value > 0);
  }

  async function boot() {
    state.boot = await api('bootstrap');
    const unread = $('#vtp-unread');
    if (state.boot.stats.unread) {
      unread.textContent = state.boot.stats.unread;
      unread.classList.add('show');
    }
    updateInquiryBadge(state.boot.stats.newInquiries);
    // The module bundle now carries its own cache-busting version. Injecting a
    // second copy here races the first Connect bootstrap and can duplicate REST
    // polling, so keep a single module runtime per portal page.
    installConnectLauncher();
    await route();
  }

  async function refreshBoot() {
    state.boot = await api('bootstrap');
    const unread = $('#vtp-unread');
    const unreadCount = Number(state.boot.stats.unread || 0);
    unread.textContent = unreadCount ? String(unreadCount) : '';
    unread.classList.toggle('show', unreadCount > 0);
    updateInquiryBadge(state.boot.stats.newInquiries);
  }

  async function route() {
    const key = (location.hash || '#dashboard').slice(1).split('?')[0];
    document.querySelectorAll('#vtp-nav a').forEach(link => link.classList.toggle('active', link.dataset.route === key));
	      const moduleRoutes = ['connect', 'finance', 'crm', 'payslips', 'client-reviews', 'my-day']; if (moduleRoutes.includes(key)) return;
    // The portfolio/content-management routes are rendered by the dedicated
    // work-library script. The portal shell must leave those views alone;
    // otherwise its dashboard fallback wins the race and replaces the editor.
    if (key === 'our-work') {
      const links = [
        ['video-library', 'AI Videos', 'AI-generated video work and published experiments.', 'film'],
        ['graphic-library', 'Graphic Design', 'Branding, design systems, and visual identity work.', 'palette'],
        ['motion-library', 'Video & Motion Design', 'Explainers, motion graphics, and video production.', 'video'],
        ['social-library', 'Social & Marketing', 'Social campaigns, content, and marketing work.', 'megaphone'],
        ['digital-library', 'Digital & Web', 'Websites, digital products, and interactive work.', 'monitor'],
      ];
      const cards = links.map(([routeKey, title, text, icon]) => `<a class="vtp-card vtp-work-hub-card" href="#${routeKey}" aria-label="Open ${esc(title)} work"><span class="vtp-stat-icon" aria-hidden="true">${statIcon(icon)}</span><div class="vtp-work-hub-copy"><h3>${esc(title)}</h3><p>${esc(text)}</p><span class="vtp-btn small">Open work ↗</span></div></a>`).join('');
      view.innerHTML = pageHead('Our Work', 'Portfolio work published to the Vibes Agency website.') + `<section class="vtp-card vtp-work-hub"><div class="vtp-card-head"><div><h2>Website management</h2><p>Choose a discipline to review or publish work.</p></div></div><div class="vtp-work-hub-grid">${cards}</div></section>`;
      return;
    }
    if (['video-library', 'graphic-library', 'motion-library', 'social-library', 'digital-library'].includes(key)) return;
    view.innerHTML = '<div class="vtp-loader"><span></span><p>Loading your workspace…</p></div>';
    const renderer = routes[key] || routes.dashboard;
    try {
      if (state.boot) await refreshBoot();
      await renderer();
    } catch (error) { view.innerHTML = empty(error.message); }
  }

  function normalizeOurWorkNav() {
    const nav = $('#vtp-nav');
    if (!nav) return;
    window.VTPWorkNavManaged = true;
    const group = nav.querySelector('[data-vtp-work-group], .vtp-nav-work-group, .vtp-work-nav-group');
    const direct = nav.querySelector('a[data-route="our-work"]');
    if (group && direct && group.contains(direct) && group.parentElement) group.parentElement.insertBefore(direct, group);
    if (group) {
      group.querySelectorAll('[data-route="video-library"],[data-route="graphic-library"],[data-route="motion-library"],[data-route="social-library"],[data-route="digital-library"]').forEach(link => link.remove());
      if (!group.querySelector('a')) group.remove();
    }
    if (direct) {
      direct.href = '#our-work';
      direct.dataset.route = 'our-work';
      direct.removeAttribute('aria-haspopup');
      direct.removeAttribute('aria-expanded');
      direct.removeAttribute('role');
      if (!direct.dataset.vtpOurWorkBound) {
        direct.dataset.vtpOurWorkBound = '1';
        direct.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          if (location.hash !== '#our-work') location.hash = '#our-work';
          else route();
        }, true);
      }
    }
  }
  function normalizeReportsNav() {
    const nav = $('#vtp-nav');
    if (!nav) return;
    const finance = nav.querySelector('[data-nav-group="finance"]');
    const reports = nav.querySelector('[data-nav-group="reports"]');
    const canView = Boolean(nav.querySelector('a[data-route="invoices"]'));
    if (!finance || !canView) {
      if (reports) reports.remove();
      return;
    }
    let link = nav.querySelector('a[data-route="analytics"]');
    if (!link) {
      link = document.createElement('a');
      link.href = '#analytics';
      link.dataset.route = 'analytics';
      link.innerHTML = `<i>${statIcon('analytics')}</i><span>Reports &amp; Insights</span>`;
    }
    if (link.parentElement !== finance) finance.appendChild(link);
    if (reports) reports.remove();
  }
  document.addEventListener('click', event => {
    const link = event.target.closest?.('#vtp-nav a[data-route="our-work"]');
    if (!link) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    normalizeOurWorkNav();
    if (location.hash !== '#our-work') location.hash = '#our-work';
    else route();
  }, true);
  let ourWorkNavObserverTimer;
  let ourWorkNavWatchRegistered = false;
  function observeOurWorkNav() {
    const nav = $('#vtp-nav');
    if (!nav) return;
    normalizeOurWorkNav();
    normalizeReportsNav();
    // This runs at 4 fixed delays after load (nav may not exist yet at delay 0, hence the
    // retries), but the watcher itself is registered with VTPR only once, so repeated calls
    // here don't accumulate duplicate permanent callbacks.
    if (ourWorkNavWatchRegistered) return;
    ourWorkNavWatchRegistered = true;
    if (window.VTPR) {
      window.VTPR.watch(() => {
        clearTimeout(ourWorkNavObserverTimer);
        ourWorkNavObserverTimer = window.setTimeout(() => { normalizeOurWorkNav(); normalizeReportsNav(); }, 0);
      });
    } else {
      const observer = new MutationObserver(() => {
        clearTimeout(ourWorkNavObserverTimer);
        ourWorkNavObserverTimer = window.setTimeout(() => { normalizeOurWorkNav(); normalizeReportsNav(); }, 0);
      });
      observer.observe(nav, {childList: true, subtree: true});
    }
  }
  [0, 300, 1000, 2000].forEach(delay => window.setTimeout(observeOurWorkNav, delay));

  function pricingCategoryId() {
    const value = new URLSearchParams(location.hash.split('?')[1] || '').get('category');
    return value !== null && /^\d+$/.test(value) ? Number(value) : null;
  }
  async function showPricingCategory(id = null) {
    const hash = id === null ? '#pricing' : `#pricing?category=${Number(id)}`;
    if (location.hash !== hash) { location.hash = hash; return; }
    await route();
  }
  function pricingGroups(catalog) {
    const groups = catalog.categories.map(category => ({...category, services:catalog.services.filter(service => Number(service.categoryId) === Number(category.id))}));
    const ids = new Set(catalog.categories.map(category => Number(category.id)));
    const unassigned = catalog.services.filter(service => !ids.has(Number(service.categoryId)));
    if (unassigned.length) groups.push({id:0,name:'Uncategorised',code:'',active:false,unassigned:true,services:unassigned});
    return groups;
  }
  function pricingCategoryGrid(groups) {
    return `<div class="vtp-card-head"><div><h2>Service categories</h2><p>Open a category to manage its details and services.</p></div></div><div class="vtp-category-grid">${groups.map(group => `<a class="vtp-category-card" href="#pricing?category=${Number(group.id)}" aria-label="Open ${esc(group.name)} category"><div class="vtp-category-card-top"><span class="vtp-category-code">${esc(group.code || '—')}</span>${group.active ? '' : chip('Hidden','coral')}</div><h3>${esc(group.name)}</h3><p>${group.services.length} ${group.services.length === 1 ? 'service' : 'services'}</p><span class="vtp-category-open">View services <span aria-hidden="true">→</span></span></a>`).join('') || empty('Add a category to organise your services.')}</div>`;
  }
  function pricingCategoryPanel(group) {
    const rows = group.services.map(item => { const priceLabel = item.custom ? 'Price on request' : item.startingPrice ? 'Starting from' : 'Fixed price'; const sar = item.custom ? 'Custom' : `${item.startingPrice ? 'From ' : ''}${money(item.oneTimePrice)}`; const usd = item.custom ? 'Custom' : `${item.startingPrice ? 'From ' : ''}${moneyUsd(item.oneTimePriceUsd)}`; return `<tr><td data-label="Service"><strong>${esc(item.name)}</strong><small class="vtp-cell-sub">${esc(item.label)} · per ${esc(item.unit)}</small><span class="vtp-price-mode ${item.startingPrice ? 'from' : item.custom ? 'request' : ''}">${priceLabel}</span></td><td data-label="Base price SAR">${sar}</td><td data-label="Base price USD">${usd}</td><td data-label="Status">${chip(item.active ? 'Active' : 'Hidden',item.active ? 'green' : 'coral')}</td><td data-label="Action"><button class="vtp-btn small" data-edit-service="${item.id}">Edit service</button> <button class="vtp-btn small danger" data-delete-service="${item.id}">Delete</button></td></tr>`; }).join('');
    return `<a href="#pricing" class="vtp-category-back">← All categories</a><div class="vtp-card-head vtp-category-panel-head"><div><span class="vtp-eyebrow">${esc(group.code || 'Services')}</span><h2>${esc(group.name)}</h2><p>${group.services.length} ${group.services.length === 1 ? 'service' : 'services'} · Base prices in SAR and USD</p></div><div class="vtp-category-actions">${group.unassigned ? '' : `<button class="vtp-btn" data-edit-category="${group.id}">Edit category</button>`}<button class="vtp-btn primary" data-open="service" data-category-id="${group.unassigned ? '' : group.id}">＋ Add service</button>${group.unassigned ? '' : `<button class="vtp-btn small danger" data-delete-category="${group.id}">Delete category</button>`}</div></div>${rows ? `<div class="vtp-table-scroll"><table class="vtp-table"><thead><tr><th>Service</th><th>Base price SAR</th><th>Base price USD</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows}</tbody></table></div>` : empty('No services in this category yet. Add the first service above.')}`;
  }

  function calendarMonth() {
    const raw = new URLSearchParams(location.hash.split('?')[1] || '').get('month');
    if (/^\d{4}-\d{2}$/.test(raw || '')) {
      const [year, month] = raw.split('-').map(Number);
      if (month >= 1 && month <= 12) return new Date(Date.UTC(year, month - 1, 1));
    }
    const current = dateParts(new Date());
    return new Date(Date.UTC(Number(current.year), Number(current.month) - 1, 1));
  }
  const calendarMonthHash = date => `#calendar?month=${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;

  const routes = {
    dashboard: async () => {
      // 2026-11 redesign: lead with decisions, use one definition per stage, show money in USD (book currency).
      const caps = state.boot.capabilities || {};
      const sales = !!caps.manageSales;
      const admin = !!(caps.employees || caps.isAdmin);
      await Promise.all([
        load('tasks', true), load('announcements', true),
        ...(sales ? [load('inquiries', true), load('quotations', true), load('invoices', true)] : []),
        ...(caps.manageProjects || sales ? [load('projects', true)] : []),
        ...(admin ? [load('employees', true)] : []),
      ]);
      const today = todayIso();
      const low = value => String(value || '').toLowerCase();
      const usd = (amount, currency) => low(currency) === 'sar' ? Number(amount || 0) / 3.75 : Number(amount || 0);
      const fmtUsd = value => 'USD ' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
      const daysLate = date => Math.max(1, Math.round((Date.parse(today) - Date.parse(String(date).slice(0, 10))) / 86400000));
      const inquiries = state.inquiries || [];
      const quotations = state.quotations || [];
      const invoices = state.invoices || [];
      const projects = (state.projects || []).filter(project => low(project.status) !== 'finished');
      const tasks = state.tasks || [];
      const people = (state.employees || []).filter(user => user.active !== false);

      // One pipeline, shared definitions.
      const startedQuotes = new Set((state.projects || []).map(project => Number(project.quotation_id)).filter(Boolean));
      const stage = {
        inquiry: inquiries.filter(item => ['new', 'contacted', 'brief_prepared'].includes(low(item.status))),
        quote: quotations.filter(item => ['sent', 'changes_requested'].includes(low(item.status))),
        approved: quotations.filter(item => low(item.status) === 'approved' && !startedQuotes.has(Number(item.id))),
        delivery: projects.filter(project => !projectIsReadyForReview(project)),
        review: projects.filter(project => projectIsReadyForReview(project)),
        paid: invoices.filter(item => low(item.status) === 'paid'),
      };

      // Needs your decision.
      const needs = [];
      const need = (tone, tag, title, detail, href, action) => needs.push({ tone, tag, title, detail, href, action });
      stage.review.forEach(project => need('good', 'Ready for review', `${project.client || ''}${project.client ? ' — ' : ''}${project.name}`, 'All tasks submitted' + (project.due_date ? ` · due ${fmt(project.due_date)}` : ''), '#projects', 'Send to client'));
      inquiries.filter(item => item.deadline && item.deadline < today && !['approved', 'closed'].includes(low(item.status))).forEach(item => need('late', `${daysLate(item.deadline)} day${daysLate(item.deadline) > 1 ? 's' : ''} overdue`, `Inquiry ${item.request_number} · ${item.client_name}`, `Deadline was ${fmt(item.deadline)} · status “${String(item.status).replaceAll('_', ' ')}”`, '#inquiries', 'Update status'));
      quotations.filter(item => low(item.status) === 'changes_requested').forEach(item => need('info', 'Changes requested', `${item.quote_number} · ${item.client_name}`, 'The client asked for changes', '#quotations', 'Revise quote'));
      stage.approved.forEach(item => need('info', 'Approved', `${item.quote_number} · ${item.client_name}`, 'Approved by the client — no project yet', '#quotations', 'Start project'));
      invoices.filter(item => ['sent', 'overdue'].includes(low(item.status)) && item.due_date && item.due_date < today).forEach(item => need('late', 'Payment overdue', `${item.invoice_number} · ${item.client_name}`, `${money(item.total, item.currency || 'SAR')} · due ${fmt(item.due_date)}`, '#invoices', 'Follow up'));
      invoices.filter(item => low(item.status) === 'draft').forEach(item => need('info', 'Draft invoice', `${item.invoice_number} · ${item.client_name}`, money(item.total, item.currency || 'SAR'), '#invoices', 'Send invoice'));
      tasks.filter(task => task.due_date && task.due_date < today && !['done', 'completed', 'approved'].includes(low(task.status))).forEach(task => need('late', 'Task overdue', task.title || 'Task', `${task.assignee_name || 'Unassigned'} · due ${fmt(task.due_date)}`, '#tasks', 'Open task'));
      if (admin) {
        try {
          const drive = await fetch('/wp-json/vibes-drive/v1/status', { headers: { 'X-WP-Nonce': VTP.nonce }, credentials: 'same-origin', cache: 'no-store' }).then(r => r.ok ? r.json() : null);
          if (drive && drive.connected && drive.working === false) need('late', 'Google Drive', 'Google Drive connection expired', 'Quotation PDFs and project folders can’t sync until you reconnect', '/wp-admin/options-general.php?page=vibes-drive-automation', 'Reconnect');
        } catch (error) { /* Drive plugin not available */ }
      }
      const tagClass = { good: 'vtp-d-tag good', late: 'vtp-d-tag late', info: 'vtp-d-tag info' };
      const needsHtml = needs.length
        ? needs.slice(0, 6).map(item => `<div class="vtp-d-need"><span class="${tagClass[item.tone]}">${esc(item.tag)}</span><div class="vtp-d-need-copy"><strong>${esc(item.title)}</strong><small>${esc(item.detail)}</small></div><a class="vtp-btn small" href="${item.href}">${esc(item.action)}</a></div>`).join('')
        : '<div class="vtp-d-empty"><strong>You’re all caught up</strong><span>Nothing needs a decision right now.</span></div>';

      // Key numbers.
      const collected = stage.paid.reduce((sum, item) => sum + usd(item.total, item.currency), 0);
      const open = invoices.filter(item => ['sent', 'overdue'].includes(low(item.status)));
      const outstanding = open.reduce((sum, item) => sum + usd(item.total, item.currency), 0);
      const available = people.filter(user => ['available', ''].includes(low(user.availability))).length;
      const myOpen = tasks.filter(task => !['done', 'completed', 'approved'].includes(low(task.status)));
      const weekEnd = new Date(Date.parse(today) + 7 * 86400000).toISOString().slice(0, 10);
      const stat = (label, value, sub, href) => `<a class="vtp-card vtp-d-stat" href="${href}"><small>${esc(label)}</small><strong>${esc(value)}</strong><span>${esc(sub)}</span></a>`;
      const stats = sales
        ? stat('Collected', fmtUsd(collected), `${stage.paid.length} paid invoice${stage.paid.length === 1 ? '' : 's'} · SAR at 3.75`, '#invoices')
          + stat('Outstanding', fmtUsd(outstanding), open.length ? `${open.length} unpaid invoice${open.length === 1 ? '' : 's'}` : 'No unpaid invoices', '#invoices')
          + stat('Active projects', String(projects.length), stage.review.length ? `${stage.review.length} ready for client review` : 'None waiting on review', '#projects')
          + (admin ? stat('Team available', `${available} of ${people.length}`, 'Based on availability status', '#employees') : stat('Open tasks', String(myOpen.length), 'Across all projects', '#tasks'))
        : stat('My open tasks', String(myOpen.length), 'Assigned to you', '#tasks')
          + stat('Due this week', String(myOpen.filter(task => task.due_date && task.due_date <= weekEnd).length), 'Next 7 days', '#calendar')
          + stat('Waiting review', String(tasks.filter(task => low(task.status) === 'review').length), 'Submitted for approval', '#tasks');

      const flow = sales ? `<article class="vtp-card vtp-d-panel"><div class="vtp-d-head"><h2>Work flow</h2><span>From first inquiry to payment</span></div><ol class="vtp-d-flow">${[
          ['New inquiry', stage.inquiry.length, '#inquiries'], ['Quote sent', stage.quote.length, '#quotations'], ['Approved', stage.approved.length, '#quotations'],
          ['In delivery', stage.delivery.length, '#projects'], ['Client review', stage.review.length, '#projects'], ['Paid', stage.paid.length, '#invoices'],
        ].map(([label, count, href], index) => `<li class="s${index}"><a href="${href}"><strong>${count}</strong><span>${label}</span></a></li>`).join('')}</ol></article>` : '';

      const ownerOf = project => project.lead_name || projectAssignees(project) || 'Unassigned';
      const projectRows = projects.slice(0, 6).map(project => {
        const total = Number(project.task_count || 0); const done = Number(project.submitted_count || 0);
        const pct = Number(project.progress || (total ? Math.round(done / total * 100) : 0));
        const ready = projectIsReadyForReview(project);
        return `<tr><td><strong>${esc(project.name)}</strong><small>${esc(project.client || '')}</small></td><td>${esc(ownerOf(project))}</td><td><span class="vtp-d-bar"><span style="width:${pct}%"></span></span> <small>${total ? `${done}/${total}` : `${pct}%`}</small></td><td>${project.due_date ? esc(fmt(project.due_date)) : '—'}</td><td>${ready ? '<span class="vtp-d-tag good">Client review</span>' : '<span class="vtp-d-tag info">In delivery</span>'}</td></tr>`;
      }).join('');
      const projectsPanel = (caps.manageProjects || sales) ? `<article class="vtp-card vtp-d-panel vtp-d-wide"><div class="vtp-d-head"><h2>Projects</h2><a href="#projects">All projects</a></div>${projectRows ? `<div class="vtp-d-table"><table><thead><tr><th scope="col">Project</th><th scope="col">Owner</th><th scope="col">Progress</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead><tbody>${projectRows}</tbody></table></div>` : '<div class="vtp-d-empty"><strong>No active projects</strong><span>Approved quotations become projects here.</span></div>'}</article>` : '';

      const initials = name => String(name || '?').split(/\s+/).map(part => part[0]).join('').slice(0, 2).toUpperCase();
      const teamPanel = admin ? `<article class="vtp-card vtp-d-panel"><div class="vtp-d-head"><h2>Team today</h2><a href="#employees">People</a></div>${people.map(user => `<div class="vtp-d-person"><span class="vtp-d-avatar">${esc(initials(user.name))}</span><div><strong>${esc(user.name)}</strong><small>${esc([user.position, user.department].filter(Boolean).join(' · '))}</small></div><span class="vtp-d-dot ${esc(low(user.availability) || 'available')}">${esc((user.availability || 'available').replace(/^./, c => c.toUpperCase()))}</span></div>`).join('')}</article>` : '';
      const myTasks = !sales ? `<article class="vtp-card vtp-d-panel vtp-d-wide"><div class="vtp-d-head"><h2>My tasks</h2><a href="#tasks">All tasks</a></div>${myOpen.length ? myOpen.slice(0, 6).map(taskCard).join('') : '<div class="vtp-d-empty"><strong>No open tasks</strong><span>New assignments will appear here.</span></div>'}</article>` : '';
      const recent = `<article class="vtp-card vtp-d-panel"><div class="vtp-d-head"><h2>Announcements</h2><a href="#announcements">All updates</a></div><div class="vtp-preview-list">${announcementPreview() || '<div class="vtp-d-empty"><strong>No announcements yet</strong><span>Company updates will show here.</span></div>'}</div></article>`;

      const hour = Number(new Date().toLocaleString('en-GB', { hour: 'numeric', hour12: false, timeZone: typeof PORTAL_TIME_ZONE !== 'undefined' ? PORTAL_TIME_ZONE : undefined }));
      const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
      const firstName = String(state.boot.user.name || '').split(' ')[0];
      const actions = sales ? '<a class="vtp-btn" href="#inquiries">＋ Inquiry</a><a class="vtp-btn" href="#quotations">＋ Quotation</a><a class="vtp-btn primary" href="#projects">＋ Project</a>' : '';
      view.innerHTML = `<section class="vtp-page vtp-d">${pageHead(`${greeting}, <span>${esc(firstName)}</span>`, new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' }), actions)}
        <article class="vtp-card vtp-d-panel vtp-d-needs"><div class="vtp-d-head"><h2>Needs your decision${needs.length ? ` <em>· ${needs.length}</em>` : ''}</h2></div>${needsHtml}</article>
        <div class="vtp-d-stats">${stats}</div>
        ${flow}
        <div class="vtp-d-grid">${projectsPanel}${myTasks}<div class="vtp-d-side">${teamPanel}${recent}</div></div>
      </section>`;
    },

    employees: async () => {
      const capabilities = state.boot.capabilities || {};
      if (!capabilities.employees && !capabilities.isAdmin) return routes.dashboard();
      const rows = await load('employees', true);
      const activeRows = rows.filter(item => item.active !== false);
      const managers = rows.filter(item => ['manager', 'administrator', 'admin'].includes(String(item.role || '').toLowerCase()));
      const available = activeRows.filter(item => String(item.availability || 'available').toLowerCase() === 'available');
      const availabilityTone = { available: 'green', busy: 'amber', leave: 'coral', offline: 'blue' };
      const availabilityLabel = { available: 'Available', busy: 'Busy', leave: 'On leave', offline: 'Offline' };
      const filters = state.employeeFilters || { query: '', role: 'all', availability: 'all', status: 'active' };
      const query = String(filters.query || '').trim().toLowerCase();
      const filteredRows = rows.filter(item => {
        const role = String(item.role || 'employee').toLowerCase();
        const availability = String(item.availability || 'available').toLowerCase();
        const matchesQuery = !query || [item.name, item.email, item.department, item.position].some(value => String(value || '').toLowerCase().includes(query));
        const matchesRole = filters.role === 'all' || role === filters.role;
        const matchesAvailability = filters.availability === 'all' || availability === filters.availability;
        const matchesStatus = filters.status === 'all' || (filters.status === 'active' ? item.active !== false : item.active === false);
        return matchesQuery && matchesRole && matchesAvailability && matchesStatus;
      });
      const employeeRows = filteredRows.map(item => {
        const availability = String(item.availability || 'available').toLowerCase();
        const role = String(item.role || 'employee').toLowerCase();
        const roleLabel = role === 'admin' || role === 'administrator' ? 'Admin' : (role === 'manager' ? 'Manager' : 'Employee');
        const action = role === 'admin' || role === 'administrator' ? '' : '<button class="vtp-btn small ' + (item.active === false ? '' : 'danger') + '" data-toggle-employee="' + item.id + '" data-current-active="' + (item.active === false ? 'false' : 'true') + '">' + (item.active === false ? 'Restore' : 'Deactivate') + '</button>';
        const tasks = item.taskSummary || {};
        return '<tr><td data-label="Employee"><div class="vtp-person"><img src="' + esc(item.avatar || '') + '" alt="' + esc(item.name) + '"><span><strong>' + esc(item.name) + '</strong><small>' + esc(item.email) + '</small></span></div></td><td data-label="Role">' + chip(roleLabel, role === 'manager' ? 'purple' : '') + '</td><td data-label="Department / position"><strong>' + esc(item.department || '—') + '</strong><small>' + esc(item.position || '—') + '</small></td><td data-label="Availability">' + chip(availabilityLabel[availability] || availability, availabilityTone[availability] || '') + '</td><td data-label="Workload"><span class="vtp-muted">' + Number(tasks.in_progress || 0) + ' active · ' + Number(tasks.review || 0) + ' review</span></td><td data-label="Status">' + chip(item.active === false ? 'Inactive' : 'Active', item.active === false ? 'coral' : 'green') + '</td><td data-label="Actions"><div class="vtp-table-actions"><button class="vtp-btn small" data-view-employee="' + item.id + '">View profile</button><button class="vtp-btn small" data-edit-employee="' + item.id + '">Edit</button>' + action + '</div></td></tr>';
      }).join('');
      const filterForm = '<form id="vtp-employee-filter-form" class="vtp-filter-bar"><input name="query" data-employee-search placeholder="Search name, email, department..." value="' + esc(filters.query || '') + '"><select name="role"><option value="all" ' + (filters.role === 'all' ? 'selected' : '') + '>All roles</option><option value="admin" ' + (filters.role === 'admin' ? 'selected' : '') + '>Admin</option><option value="manager" ' + (filters.role === 'manager' ? 'selected' : '') + '>Manager</option><option value="employee" ' + (filters.role === 'employee' ? 'selected' : '') + '>Employee</option></select><select name="availability"><option value="all" ' + (filters.availability === 'all' ? 'selected' : '') + '>All availability</option><option value="available" ' + (filters.availability === 'available' ? 'selected' : '') + '>Available</option><option value="busy" ' + (filters.availability === 'busy' ? 'selected' : '') + '>Busy</option><option value="leave" ' + (filters.availability === 'leave' ? 'selected' : '') + '>On leave</option><option value="offline" ' + (filters.availability === 'offline' ? 'selected' : '') + '>Offline</option></select><select name="status"><option value="all" ' + (filters.status === 'all' ? 'selected' : '') + '>All status</option><option value="active" ' + (filters.status === 'active' ? 'selected' : '') + '>Active</option><option value="inactive" ' + (filters.status === 'inactive' ? 'selected' : '') + '>Inactive</option></select><button class="vtp-btn small primary">Apply filters</button></form>';
      const availabilityBoard = ['available', 'busy', 'leave', 'offline'].map(key => '<div class="vtp-availability-item"><span class="vtp-dot ' + (availabilityTone[key] || '') + '"></span><strong>' + availabilityLabel[key] + '</strong><span>' + rows.filter(item => String(item.availability || 'available').toLowerCase() === key && item.active !== false).length + '</span></div>').join('');
      view.innerHTML = '<section class="vtp-page vtp-employees-page">' + pageHead('Employees', 'Manage profiles, roles, availability and account access in one place.', '<button class="vtp-btn primary" data-open="employee">＋ Add employee</button>') + '<div class="vtp-grid four">' + stat('Team members', rows.length, 'users') + stat('Active', activeRows.length, 'approved') + stat('Managers', managers.length, 'manager') + stat('Available now', available.length, 'available') + '</div><article class="vtp-card"><div class="vtp-card-head"><div><h2>Availability overview</h2><p>Use availability and workload before assigning new work.</p></div></div><div class="vtp-availability-board">' + availabilityBoard + '</div></article><article class="vtp-card"><div class="vtp-card-head"><div><h2>Team directory</h2><p>Search, filter and manage profile access safely.</p></div><span class="vtp-muted">Showing ' + filteredRows.length + ' of ' + rows.length + '</span></div>' + filterForm + (employeeRows ? '<div class="vtp-table-scroll"><table class="vtp-table"><thead><tr><th>Employee</th><th>Role</th><th>Department / position</th><th>Availability</th><th>Workload</th><th>Status</th><th>Actions</th></tr></thead><tbody>' + employeeRows + '</tbody></table></div>' : empty('No employees match these filters.')) + '</article></section>';
    },
    inquiries: async () => {
      if (!state.boot.capabilities.manageSales) return routes.dashboard();
      const rows = await load('inquiries', true);
      const newCount = rows.filter(item => item.status === 'new').length;
      state.boot.stats.newInquiries = newCount;
      updateInquiryBadge(newCount);
      view.innerHTML = `<section class="vtp-page">${pageHead('Client inquiries', 'Capture requests from the website, email, phone and WhatsApp before work begins.', '<button class="vtp-btn primary" data-open="inquiry">＋ Create inquiry</button>')}<div class="vtp-grid four">${stat('New', rows.filter(x => x.status === 'new').length, 'inbox')}${stat('Contacted', rows.filter(x => x.status === 'contacted').length, 'message')}${stat('Quotes prepared', rows.filter(x => ['brief_prepared','quote_sent'].includes(x.status)).length, 'quote')}${stat('Approved', rows.filter(x => x.status === 'approved').length, 'approved')}</div><div class="vtp-card vtp-data-card"><div class="vtp-table-scroll"><table class="vtp-table"><thead><tr><th>Request</th><th>Client</th><th>Services</th><th>Deadline</th><th>Source</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map(item => `<tr><td data-label="Request"><strong>${esc(item.request_number)}</strong><small class="vtp-cell-sub">${fmt(item.created_at)}</small></td><td data-label="Client"><strong>${esc(item.client_name)}</strong><small class="vtp-cell-sub">${esc(item.company || item.email)}</small></td><td data-label="Services">${(item.services || []).length ? item.services.map(service => chip(service)).join(' ') : 'Custom request'}</td><td data-label="Deadline">${fmt(item.deadline)}</td><td data-label="Source">${chip(item.source, 'blue')}</td><td data-label="Status">${chip(item.status, item.status === 'approved' ? 'green' : item.status === 'new' ? 'coral' : 'amber')}</td><td data-label="Action"><button class="vtp-btn small" data-view-inquiry="${item.id}">View</button> ${item.status === 'new' ? `<button class="vtp-btn small" data-inquiry-status="${item.id}" data-status="contacted">Mark contacted</button>` : ''} <button class="vtp-btn small primary" data-quote-inquiry="${item.id}">Create quote</button> <button class="vtp-btn small danger" data-delete-inquiry="${item.id}">Delete</button></td></tr>`).join('') : `<tr><td colspan="7">${empty('No inquiries yet.')}</td></tr>`}</tbody></table></div></div></section>`;
    },

    quotations: async () => {
      if (!state.boot.capabilities.manageSales) return routes.dashboard();
      const rows = await load('quotations', true);
      view.innerHTML = `<section class="vtp-page">${pageHead('Quotations', 'Send secure proposals, collect client approval and activate approved work.', '<button class="vtp-btn" data-preview-template>Preview design</button><button class="vtp-btn primary" data-open="quotation">＋ New quotation</button>')}<div class="vtp-grid three">${rows.length ? rows.map(quote => `<article class="vtp-card vtp-quote-card" data-quote-id="${quote.id}" data-drive-folder-url="${esc(quote.drive_folder_url || '')}"><div class="vtp-card-head"><div><strong>${esc(quote.quote_number)}</strong>${Number(quote.period_index || 0) ? `<small class="vtp-cell-sub">Monthly draft ${Number(quote.period_index)} of ${Number(quote.contract_months || 0)}</small>` : Number(quote.contract_months || 0) > 1 ? `<small class="vtp-cell-sub">${Number(quote.contract_months)}-month master quotation · monthly drafts created</small>` : ''}</div>${chip(quote.status, quote.status === 'approved' ? 'green' : quote.status === 'changes_requested' ? 'coral' : quote.status === 'sent' ? 'amber' : '')}</div><h3>${esc(quote.company || quote.client_name)}</h3><p>${esc(quote.client_name)} · ${esc(quote.email)}</p><div class="vtp-quote-total">${money(quote.total, quote.currency)}</div><small>Valid until ${fmt(quote.valid_until)}</small>${quote.drive_file_url || quote.drive_folder_url ? `<div class="vtp-quote-drive-links"><small>Drive source of truth</small><div>${quote.drive_file_url ? `<a class="vtp-btn small" href="${esc(quote.drive_file_url)}" target="_blank" rel="noopener noreferrer">Open PDF in Drive ↗</a>` : ''}${quote.drive_folder_url ? `<a class="vtp-btn small" href="${esc(quote.drive_folder_url)}" target="_blank" rel="noopener noreferrer">Open quotation folder ↗</a>` : ''}</div><small data-mapped-path-status data-folder="${esc(quote.drive_folder_url || '')}"></small></div>` : ''}<div class="vtp-card-actions"><button class="vtp-btn small" data-preview-quote="${quote.id}">Preview</button>${quote.status !== 'approved' ? `<button class="vtp-btn small primary" data-send-quote="${quote.id}">${quote.status === 'sent' ? 'Resend' : 'Send to client'}</button><button class="vtp-btn small" data-quote-status="${quote.id}" data-status="approved">Approve manually</button>` : ''}${quote.approval_token && quote.status !== 'draft' ? `<button class="vtp-btn small" data-copy-review="${quote.id}">Copy review link</button>` : ''}${!Number(quote.parent_quotation_id || 0) ? `<button class="vtp-btn small primary" data-quote-project="${quote.id}">Create project</button>` : ''} <button class="vtp-btn small danger" data-delete-quotation="${quote.id}">Delete</button></div>${quote.client_message ? `<div class="vtp-quote-response"><strong>Client response</strong><p>${esc(quote.client_message)}</p></div>` : ''}</article>`).join('') : empty('No quotations yet.')}</div></section>`;
    },

    invoices: async () => {
      if (!state.boot.capabilities.manageSales) return routes.dashboard();
      const rows = await load('invoices', true);
      view.innerHTML = `<section class="vtp-page">${pageHead('Invoices & payments', 'Approved quotation totals become draft invoices automatically after every assigned service is submitted.', '<button class="vtp-btn primary" data-open="invoice">＋ New invoice</button>')}<div class="vtp-grid four">${stat('Draft', rows.filter(x => x.status === 'draft').length, 'quote')}${stat('Sent', rows.filter(x => x.status === 'sent').length, 'send')}${stat('Paid', rows.filter(x => x.status === 'paid').length, 'approved')}${stat('Overdue', rows.filter(x => x.status === 'overdue').length, 'pending')}</div><div class="vtp-card vtp-data-card"><div class="vtp-table-scroll"><table class="vtp-table"><thead><tr><th>Invoice</th><th>Client</th><th>Total</th><th>Due</th><th>Status</th><th>Payment</th><th>Action</th></tr></thead><tbody>${rows.length ? rows.map(item => `<tr><td data-label="Invoice"><strong>${esc(item.invoice_number)}</strong>${Number(item.generated_automatically) ? '<small class="vtp-cell-sub">Generated from approved quotation</small>' : ''}</td><td data-label="Client">${esc(item.company || item.client_name)}<small class="vtp-cell-sub">${esc(item.email)}</small></td><td data-label="Total"><strong>${money(item.total, item.currency)}</strong></td><td data-label="Due">${fmt(item.due_date)}</td><td data-label="Status">${chip(item.status, item.status === 'paid' ? 'green' : item.status === 'overdue' ? 'coral' : 'amber')}</td><td data-label="Payment">${item.status === 'paid' ? '<span class="vtp-chip green">Recorded in Finance Overview</span>' : item.payment_link ? `<a href="${esc(item.payment_link)}" target="_blank" rel="noopener noreferrer">Open link ↗</a>` : 'Not added'}</td><td data-label="Action">${item.status === 'draft' ? `<button class="vtp-btn small" data-invoice-status="${item.id}" data-status="sent">Mark sent</button>` : ''}${item.status !== 'paid' ? ` <button class="vtp-btn small primary" title="Record the full invoice payment in Finance Overview" data-invoice-status="${item.id}" data-invoice-date="${esc(item.created_at || item.issue_date || '')}" data-status="paid">Mark as paid</button>` : ''} <button class="vtp-btn small danger" data-delete-invoice="${item.id}">Delete</button></td></tr>`).join('') : `<tr><td colspan="7">${empty('No invoices yet.')}</td></tr>`}</tbody></table></div></div></section>`;
    },

    analytics: async () => {      if (!state.boot.capabilities.manageSales) return routes.dashboard();      const [invoices, quotations, projects, tasks, inquiries] = await Promise.all([        load('invoices', true), load('quotations', true), load('projects', true), load('tasks', true), load('inquiries', true)      ]);      const sarPerUsd = 3.75;      const toUsd = (value, currency = 'SAR') => { const amount = Number(value || 0); return String(currency || 'SAR').toUpperCase() === 'SAR' ? amount / sarPerUsd : amount; };      const totalUsd = rows => rows.reduce((sum, item) => sum + toUsd(item.total || item.amount, item.currency), 0);      const paid = invoices.filter(item => item.status === 'paid');      const outstanding = invoices.filter(item => !['paid', 'cancelled', 'void'].includes(String(item.status || '').toLowerCase()));      const activeProjects = projects.filter(item => item.status !== 'finished');      const openTasks = tasks.filter(item => !['done', 'completed', 'approved'].includes(String(item.status || '').toLowerCase()));      const approvedQuotes = quotations.filter(item => item.status === 'approved');      const conversion = quotations.length ? Math.round((approvedQuotes.length / quotations.length) * 100) : 0;      const now = new Date();      const toMonthKey = value => { const parsed = parsePortalDate(value); if (!parsed) return ''; const parts = dateParts(parsed); return parts.year + '-' + parts.month; };      const monthLabel = key => { const parts = String(key).split('-').map(Number); return parts[0] && parts[1] ? new Date(Date.UTC(parts[0], parts[1] - 1, 1)).toLocaleString('en-US', {month:'short', timeZone:'UTC'}) : key; };      const months = Array.from({length:6}, (_, index) => { const date = new Date(now.getFullYear(), now.getMonth() - (5 - index), 1); return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0'); });      const monthRows = months.map(month => ({month, label:monthLabel(month), revenue:0, quotes:0, inquiries:0}));      const monthMap = Object.fromEntries(monthRows.map(row => [row.month, row]));      const recordDate = item => item?.paid_at || item?.payment_date || item?.issue_date || item?.created_at || item?.updated_at || item?.date;      paid.forEach(item => { const row = monthMap[toMonthKey(recordDate(item))]; if (row) row.revenue += toUsd(item.total || item.amount, item.currency); });      quotations.forEach(item => { const row = monthMap[toMonthKey(item.created_at || item.updated_at)]; if (row) row.quotes += 1; });      inquiries.forEach(item => { const row = monthMap[toMonthKey(item.created_at || item.updated_at)]; if (row) row.inquiries += 1; });      const chartSvg = (rows, valueKey, color, formatter) => { const width=720; const height=220; const pad=28; const values=rows.map(row => Number(row[valueKey] || 0)); const max=Math.max(...values, 1); const pointFor=(value,index) => { const x=pad + (index * (width - (pad * 2)) / Math.max(rows.length - 1, 1)); const y=height - pad - ((value / max) * (height - (pad * 2))); return {x,y}; }; const points=values.map((value,index) => { const point=pointFor(value,index); return point.x.toFixed(1) + ',' + point.y.toFixed(1); }).join(' '); const area=pad + ',' + (height - pad) + ' ' + points + ' ' + (width - pad) + ',' + (height - pad); return '<div class="vtp-trend-chart"><svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + esc(valueKey) + ' trend"><defs><linearGradient id="vtp-grad-' + valueKey + '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="' + color + '" stop-opacity=".28"></stop><stop offset="1" stop-color="' + color + '" stop-opacity="0"></stop></linearGradient></defs><line x1="' + pad + '" y1="' + (height - pad) + '" x2="' + (width - pad) + '" y2="' + (height - pad) + '" stroke="#e8e0f2"></line><polygon points="' + area + '" fill="url(#vtp-grad-' + valueKey + ')"></polygon><polyline points="' + points + '" fill="none" stroke="' + color + '" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></polyline>' + values.map((value,index) => { const point=pointFor(value,index); return '<circle cx="' + point.x.toFixed(1) + '" cy="' + point.y.toFixed(1) + '" r="5" fill="#fff" stroke="' + color + '" stroke-width="3"><title>' + esc(rows[index].label) + ': ' + esc(formatter(value)) + '</title></circle>'; }).join('') + '</svg><div class="vtp-trend-labels">' + rows.map(row => '<span>' + esc(row.label) + '</span>').join('') + '</div></div>'; };      const compare = (current, previous) => { if (!previous) return current ? 'New activity' : 'No activity'; const change=Math.round(((current - previous) / previous) * 100); return (change >= 0 ? '+' : '') + change + '%'; };      const currentHalf=monthRows.slice(-3); const previousHalf=monthRows.slice(0,3); const currentRevenue=currentHalf.reduce((sum,row) => sum + row.revenue, 0); const previousRevenue=previousHalf.reduce((sum,row) => sum + row.revenue, 0); const currentQuotes=currentHalf.reduce((sum,row) => sum + row.quotes, 0); const previousQuotes=previousHalf.reduce((sum,row) => sum + row.quotes, 0);      const dueSoonLimit=Date.now() + (14 * 86400000); const overdueTasks=openTasks.filter(item => { const due=parsePortalDate(item.due_date || item.deadline); return due && due.getTime() < Date.now(); }); const dueSoonTasks=openTasks.filter(item => { const due=parsePortalDate(item.due_date || item.deadline); return due && due.getTime() >= Date.now() && due.getTime() <= dueSoonLimit; }); const reviewProjects=activeProjects.filter(project => projectIsReadyForReview(project));      const statusChip=(label,tone,detail) => '<div class="vtp-report-status"><span class="vtp-chip ' + tone + '">' + esc(label) + '</span><small>' + esc(detail) + '</small></div>';      view.innerHTML = '<section class="vtp-page vtp-reports-page">' + pageHead('Reports & Insights', 'Finance, sales and delivery signals from the records already in your portal. All financial totals are shown in USD.') + '<div class="vtp-grid four">' + stat('Collected', money(totalUsd(paid), 'USD'), 'approved') + stat('Outstanding', money(totalUsd(outstanding), 'USD'), 'pending') + stat('Active projects', activeProjects.length, 'briefcase') + stat('Quote conversion', conversion + '%', 'quote') + '</div><div class="vtp-dashboard-enhanced-grid"><article class="vtp-card vtp-dashboard-panel"><div class="vtp-card-head"><div><h2>Finance overview</h2><p class="vtp-panel-subtitle">Invoice value converted to USD at 1 USD = 3.75 SAR.</p></div></div><div class="vtp-list"><div class="vtp-list-row"><span>Paid invoices</span><strong>' + paid.length + ' · ' + money(totalUsd(paid), 'USD') + '</strong></div><div class="vtp-list-row"><span>Open invoices</span><strong>' + outstanding.length + ' · ' + money(totalUsd(outstanding), 'USD') + '</strong></div><div class="vtp-list-row"><span>All issued invoices</span><strong>' + invoices.length + ' · ' + money(totalUsd(invoices), 'USD') + '</strong></div></div></article><article class="vtp-card vtp-dashboard-panel"><div class="vtp-card-head"><div><h2>Sales funnel</h2><p class="vtp-panel-subtitle">From inquiry to approved quotation.</p></div></div><div class="vtp-mini-stats"><div><strong>' + inquiries.length + '</strong><small>Inquiries</small></div><div><strong>' + quotations.length + '</strong><small>Quotations</small></div><div><strong>' + approvedQuotes.length + '</strong><small>Approved</small></div><div><strong>' + conversion + '%</strong><small>Conversion</small></div></div></article></div><div class="vtp-reports-trend-grid"><article class="vtp-card vtp-dashboard-panel"><div class="vtp-card-head"><div><h2>Revenue trend</h2><p class="vtp-panel-subtitle">Payments recorded over the last six months.</p></div><span class="vtp-chip ' + (currentRevenue >= previousRevenue ? 'green' : 'coral') + '">' + esc(compare(currentRevenue, previousRevenue)) + '</span></div>' + chartSvg(monthRows, 'revenue', '#60338d', value => money(value, 'USD')) + '<div class="vtp-trend-summary"><strong>' + money(currentRevenue, 'USD') + '</strong><small>Last 3 months · previous 3 months ' + money(previousRevenue, 'USD') + '</small></div></article><article class="vtp-card vtp-dashboard-panel"><div class="vtp-card-head"><div><h2>Sales activity</h2><p class="vtp-panel-subtitle">Quotes created compared with the prior period.</p></div><span class="vtp-chip ' + (currentQuotes >= previousQuotes ? 'green' : 'coral') + '">' + esc(compare(currentQuotes, previousQuotes)) + '</span></div>' + chartSvg(monthRows, 'quotes', '#d87860', value => value + ' quote' + (value === 1 ? '' : 's')) + '<div class="vtp-trend-summary"><strong>' + currentQuotes + '</strong><small>Quotes in the last 3 months · ' + previousQuotes + ' previously</small></div></article></div><div class="vtp-dashboard-bottom"><article class="vtp-card vtp-dashboard-panel"><div class="vtp-card-head"><div><h2>Delivery health</h2><p class="vtp-panel-subtitle">Live status from open tasks and active projects.</p></div></div><div class="vtp-list"><div class="vtp-list-row"><span>Active projects</span><strong>' + activeProjects.length + '</strong></div><div class="vtp-list-row"><span>Open tasks</span><strong>' + openTasks.length + '</strong></div><div class="vtp-list-row"><span>Completed tasks</span><strong>' + (tasks.length - openTasks.length) + '</strong></div></div><div class="vtp-report-status-list">' + statusChip(overdueTasks.length ? 'At risk' : 'On track', overdueTasks.length ? 'coral' : 'green', overdueTasks.length ? overdueTasks.length + ' overdue open task' + (overdueTasks.length === 1 ? '' : 's') : 'No overdue open tasks') + statusChip(dueSoonTasks.length ? 'Due soon' : 'Clear runway', dueSoonTasks.length ? 'amber' : 'blue', dueSoonTasks.length ? dueSoonTasks.length + ' task' + (dueSoonTasks.length === 1 ? '' : 's') + ' due within 14 days' : 'No task due in the next 14 days') + statusChip(reviewProjects.length ? 'Review ready' : 'No review queue', reviewProjects.length ? 'purple' : 'blue', reviewProjects.length ? reviewProjects.length + ' project' + (reviewProjects.length === 1 ? '' : 's') + ' ready for review' : 'No project awaiting review') + '</div></article><article class="vtp-card vtp-dashboard-panel"><div class="vtp-card-head"><div><h2>Next decision</h2><p class="vtp-panel-subtitle">Use this view in the weekly finance and delivery review.</p></div></div><div class="vtp-preview-empty"><strong>' + (outstanding.length ? 'Follow up on outstanding invoices' : overdueTasks.length ? 'Clear overdue delivery work' : 'Finance and delivery are up to date') + '</strong><span>' + (outstanding.length ? money(totalUsd(outstanding), 'USD') + ' remains open across ' + outstanding.length + ' invoice' + (outstanding.length === 1 ? '' : 's') + '.' : overdueTasks.length ? overdueTasks.length + ' open task' + (overdueTasks.length === 1 ? '' : 's') + ' need an owner or revised deadline.' : 'No unpaid invoices or overdue tasks need attention.') + '</span></div></article></div></section>';    },    pricing: async () => {
      if (!state.boot.capabilities.catalogAdmin) return routes.dashboard();
      const catalog = await loadCatalog(true);
      const groups = pricingGroups(catalog);
      const selected = groups.find(group => Number(group.id) === pricingCategoryId());
      view.innerHTML = `<section class="vtp-page vtp-pricing-page">
        ${pageHead('Services & pricing', 'Choose a category to edit its details and manage the services inside it.', '<button class="vtp-btn" data-open="category">＋ Category</button><button class="vtp-btn" data-open="package">＋ Package</button><button class="vtp-btn primary" data-open="service">＋ Service</button>')}
        <div class="vtp-grid three">${stat('Active services', catalog.services.filter(item => item.active).length, 'quote', 'vtp-service-catalog')}${stat('Categories', catalog.categories.filter(item => item.active).length, 'categories', 'vtp-pricing-categories')}${stat('Packages', catalog.packages.filter(item => item.active).length, 'packages', 'vtp-pricing-packages')}</div>
        <section id="vtp-pricing-categories" class="vtp-card vtp-pricing-section"><div id="vtp-service-catalog">${selected ? pricingCategoryPanel(selected) : pricingCategoryGrid(groups)}</div></section>
        <article id="vtp-pricing-packages" class="vtp-card vtp-pricing-section"><div class="vtp-card-head"><h2>Our Packages</h2><button class="vtp-btn small" data-open="package">＋ Add</button></div><div class="vtp-package-admin-list">${catalog.packages.map(item => `<div class="vtp-package-admin"><div class="vtp-card-head"><div><strong>${esc(item.name)}</strong><small>${item.items.length} services</small></div>${chip(item.active ? 'Active' : 'Hidden', item.active ? 'green' : 'coral')}</div><p>${esc(item.description || '')}</p><div class="vtp-package-prices"><span>Base price SAR <b>${money(item.oneTimePrice)}</b></span><span>Base price USD <b>${moneyUsd(item.oneTimePriceUsd)}</b></span></div><div class="vtp-card-actions"><button class="vtp-btn small" data-edit-package="${item.id}">Edit package</button><button class="vtp-btn small danger" data-delete-package="${item.id}">Delete</button></div></div>`).join('')}</div></article>
      </section>`;
    },

    projects: async () => {
      const [rows] = await Promise.all([load('projects', true), load('tasks')]);
      const pending = rows.filter(project => project.status !== 'finished' && !projectIsReadyForReview(project));
      const ready = rows.filter(project => projectIsReadyForReview(project));
      const finished = rows.filter(project => project.status === 'finished');
      view.innerHTML = `<section class="vtp-page">${pageHead('Projects', 'Keep briefs, service owners, delivery reviews and archives in one clear place.', state.boot.capabilities.manageProjects ? '<button class="vtp-btn primary" data-open="project">＋ New project</button>' : '')}<div class="vtp-grid four">${stat('Pending projects', pending.length, 'briefcase')}${stat('Ready for review', ready.length, 'eye')}${stat('Finished projects', finished.length, 'approved')}${stat('Open tasks', pending.reduce((sum, project) => sum + Math.max(0, Number(project.task_count || 0) - Number(project.submitted_count || 0)), 0), 'checklist')}</div><section class="vtp-project-section"><div class="vtp-card-head"><div><h2>Pending projects</h2><p>Active work with outstanding tasks or delivery work still in progress.</p></div></div><div class="vtp-projects">${pending.length ? pending.map(projectCard).join('') : empty('No pending projects.')}</div></section><section class="vtp-project-section"><div class="vtp-card-head"><div><h2>Ready for review</h2><p>All assigned work is complete and ready to send to the client.</p></div></div><div class="vtp-projects">${ready.length ? ready.map(projectCard).join('') : empty('No projects ready for review.')}</div></section><section class="vtp-project-section"><div class="vtp-card-head"><div><h2>Finished projects</h2><p>Completed projects and their issued invoices.</p></div></div><div class="vtp-projects">${finished.length ? finished.map(projectCard).join('') : empty('No finished projects yet.')}</div></section></section>`;
    },

    tasks: async () => {
      const rows = await load('tasks', true);
      const columns = [['todo', 'To do'], ['in_progress', 'In progress'], ['review', 'Waiting review'], ['done', 'Done']];
      view.innerHTML = `<section class="vtp-page">${pageHead(state.boot.capabilities.manageTasks ? 'Tasks' : 'My tasks', state.boot.capabilities.manageTasks ? 'Assign work, track progress and receive completion notifications.' : 'Only tasks assigned to you appear here.', state.boot.capabilities.manageTasks ? '<button class="vtp-btn primary" data-open="task">＋ Create task</button>' : '')}<div class="vtp-board">${columns.map(([status, label]) => `<section class="vtp-column"><h3>${label}<span>${rows.filter(task => task.status === status).length}</span></h3>${rows.filter(task => task.status === status).map(taskCard).join('') || empty('Nothing here')}</section>`).join('')}</div></section>`;
    },

    announcements: async () => {
      const rows = await load('announcements', true);
      const filters = state.announcementFilters || (state.announcementFilters = { query: '', audience: 'all', status: 'published' });
      const canManage = Boolean(state.boot.capabilities.announcements);
      const render = () => {
        const query = String(filters.query || '').trim().toLowerCase();
        const filtered = rows.filter(item => {
          const text = [item.title, item.content, item.author_name, item.audience].map(value => String(value || '').toLowerCase()).join(' ');
          const scheduled = item.publish_at && new Date(String(item.publish_at).replace(' ', 'T')).getTime() > Date.now();
          const audienceMatch = filters.audience === 'all' || String(item.audience || 'everyone') === filters.audience;
          const statusMatch = filters.status === 'all' || (filters.status === 'scheduled' ? scheduled : !scheduled);
          return (!query || text.includes(query)) && audienceMatch && statusMatch;
        });
        const list = document.querySelector('#vtp-announcement-list');
        const count = document.querySelector('#vtp-announcement-count');
        if (count) count.textContent = filtered.length + ' shown';
        if (!list) return;
        list.innerHTML = filtered.length ? filtered.map(item => {
          const scheduled = item.publish_at && new Date(String(item.publish_at).replace(' ', 'T')).getTime() > Date.now();
          const statusLabel = scheduled ? 'Scheduled' : 'Published';
          const statusTone = scheduled ? 'amber' : 'green';
          const readableDate = scheduled ? 'Scheduled for ' + fmtDateTime(item.publish_at) : fmt(item.created_at);
          const body = esc(String(item.content || '').replace(/<[^>]*>/g, ''));
          return `<article class="vtp-card vtp-announcement-card"><div class="vtp-card-head"><div>${item.is_pinned == 1 ? chip('Pinned', 'coral') : chip(statusLabel, statusTone)}</div><small>${esc(readableDate)}</small></div><h2>${esc(item.title)}</h2><p>${body}</p><div class="vtp-meta"><span>By ${esc(item.author_name || 'Vibes Agency')}</span>${chip(item.audience || 'everyone')}</div>${canManage ? `<div class="vtp-card-actions"><button class="vtp-btn small" data-edit-announcement="${Number(item.id)}">Edit</button><button class="vtp-btn small" data-archive-announcement="${Number(item.id)}">Archive</button><button class="vtp-btn small danger" data-delete-announcement="${Number(item.id)}">Delete</button></div>` : ''}</article>`;
        }).join('') : empty(query || filters.audience !== 'all' || filters.status !== 'published' ? 'No announcements match these filters.' : 'No announcements yet.');
      };
      window.VTPAnnouncementRender = render;
      view.innerHTML = `<section class="vtp-page vtp-announcements-page">${pageHead('Announcements', 'Company updates and information in one place.', state.boot.capabilities.announcements ? `<div class="vtp-head-actions"><button class="vtp-btn" data-refresh-announcements>↻ Refresh</button><button class="vtp-btn primary" data-open="announcement">＋ New announcement</button></div>` : '')}<article class="vtp-card vtp-announcement-tools"><div class="vtp-filter-row"><label class="vtp-field"><span>Search updates</span><input type="search" data-ann-filter="query" value="${esc(filters.query)}" placeholder="Search title or message"></label><label class="vtp-field"><span>Audience</span><select data-ann-filter="audience"><option value="all" ${filters.audience === 'all' ? 'selected' : ''}>All audiences</option><option value="everyone" ${filters.audience === 'everyone' ? 'selected' : ''}>Everyone</option><option value="creative" ${filters.audience === 'creative' ? 'selected' : ''}>Creative team</option><option value="managers" ${filters.audience === 'managers' ? 'selected' : ''}>Managers</option></select></label><label class="vtp-field"><span>Status</span><select data-ann-filter="status"><option value="published" ${filters.status === 'published' ? 'selected' : ''}>Published</option>${canManage ? `<option value="scheduled" ${filters.status === 'scheduled' ? 'selected' : ''}>Scheduled</option>` : ''}<option value="all" ${filters.status === 'all' ? 'selected' : ''}>All visible</option></select></label><span id="vtp-announcement-count" class="vtp-muted"></span></div></article><div id="vtp-announcement-list" class="vtp-grid two"></div></section>`;
      render();
    },    leave: async () => {
      const rows = await load('leave', true);
      const admin = state.boot.capabilities.manageLeave;
      view.innerHTML = `<section class="vtp-page">${pageHead(admin ? 'Leave & vacations' : 'Request time off', admin ? 'Review requests and keep the team calendar clear.' : 'Plan ahead and keep your team informed.', `<button class="vtp-btn primary" data-open="leave">＋ ${admin ? 'Add time off' : 'Request leave'}</button>`)}<div class="vtp-grid four">${stat('Pending', rows.filter(x => x.status === 'pending').length, 'pending')}${stat('Approved', rows.filter(x => x.status === 'approved').length, 'approved')}${stat('Vacation', rows.filter(x => x.leave_type === 'vacation').length, 'vacation')}${stat('Sick leave', rows.filter(x => x.leave_type === 'sick').length, 'sick')}</div><div class="vtp-card vtp-data-card"><div class="vtp-table-scroll"><table class="vtp-table"><thead><tr><th>Employee</th><th>Leave type</th><th>Dates</th><th>Status</th>${admin ? '<th>Action</th>' : ''}</tr></thead><tbody>${rows.map(item => `<tr data-leave-row="${item.id}" data-leave-status="${item.status}" data-leave-start="${item.start_date}"><td data-label="Employee">${esc(item.user_name || state.boot.user.name)}</td><td data-label="Leave type">${chip(item.leave_type)}</td><td data-label="Dates">${fmt(item.start_date)} – ${fmt(item.end_date)}</td><td data-label="Status">${chip(item.status, item.status === 'approved' ? 'green' : item.status === 'declined' ? 'coral' : 'amber')}</td>${admin ? `<td data-label="Action">${item.status === 'pending' ? `<button class="vtp-btn small" data-leave="${item.id}" data-status="approved">Approve</button> <button class="vtp-btn small danger" data-leave="${item.id}" data-status="declined">Decline</button>` : '—'}</td>` : ''}</tr>`).join('') || `<tr><td colspan="5">${empty('No leave requests yet.')}</td></tr>`}</tbody></table></div></div></section>`;
    },

    calendar: async () => {
      await Promise.all([load('tasks', true), load('leave', true), load('holidays', true)]);
      const first = calendarMonth();
      const year = first.getUTCFullYear();
      const month = first.getUTCMonth();
      const monthKey = `${year}-${String(month + 1).padStart(2, '0')}`;
      const days = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
      const leading = (first.getUTCDay() + 6) % 7;
      const today = todayIso();
      let cells = Array.from({ length: leading }, () => '<div class="vtp-day muted" aria-hidden="true"></div>').join('');
      for (let day = 1; day <= days; day += 1) {
        const dateString = `${monthKey}-${String(day).padStart(2, '0')}`;
        const taskEvents = state.tasks.filter(task => task.due_date === dateString).map(task => ({ text: `${task.title || task.service_label || 'Task'}${(task.client_name || task.project_name) ? ` · ${task.client_name || task.project_name}` : ''}`, kind: 'task' }));
        const leaveEvents = state.leave.filter(item => item.status === 'approved' && item.start_date <= dateString && item.end_date >= dateString).map(item => ({ text: `${item.user_name || 'Team member'} · ${item.leave_type}`, kind: 'leave' }));
        const holidayEvents = state.holidays.filter(item => item.holiday_date <= dateString && (item.end_date || item.holiday_date) >= dateString).map(item => ({ text: item.name, kind: 'holiday' }));
        const events = [...holidayEvents, ...taskEvents, ...leaveEvents];
        const visibleEvents = events.slice(0, 3).map(event => `<span class="vtp-event ${event.kind}" title="${esc(event.text)}">${esc(event.text)}</span>`).join('');
        const more = events.length > 3 ? `<span class="vtp-calendar-more">＋ ${events.length - 3} more</span>` : '';
        const dow = new Date(Date.UTC(year, month, day)).getUTCDay(); const weekend = dow === 0 || dow === 6;
        cells += `<div class="vtp-day${dateString === today ? ' today' : ''}${weekend ? ' weekend' : ''}" data-calendar-date="${dateString}"><b>${day}</b>${weekend ? '<span class="vtp-weekend-tag">Weekly off</span>' : ''}${visibleEvents}${more}</div>`;
      }
      const trailing = (7 - ((leading + days) % 7)) % 7;
      cells += Array.from({ length: trailing }, () => '<div class="vtp-day muted" aria-hidden="true"></div>').join('');
      const visibleTasks = state.tasks.filter(task => String(task.due_date || '').startsWith(monthKey)).length;
      const visibleLeave = state.leave.filter(item => item.status === 'approved' && item.start_date <= `${monthKey}-${days}` && item.end_date >= `${monthKey}-01`).length;
      const visibleHolidays = state.holidays.filter(item => item.holiday_date <= `${monthKey}-${days}` && (item.end_date || item.holiday_date) >= `${monthKey}-01`).length;
      const actions = state.boot.capabilities.manageTasks ? '<button class="vtp-btn" data-open="holiday">＋ Add holiday</button><button class="vtp-btn primary" data-open="task">＋ Add task</button>' : '';
      const monthLabel = first.toLocaleString('en', { timeZone: 'UTC', month: 'long', year: 'numeric' });
      view.innerHTML = `<section class="vtp-page vtp-calendar-page">${pageHead('Team calendar', 'Tasks, approved leave and company holidays together.', actions)}<article class="vtp-card vtp-calendar-card"><div class="vtp-calendar-toolbar"><div class="vtp-calendar-nav"><button class="vtp-calendar-arrow" data-calendar-shift="-1" aria-label="Previous month">‹</button><h2>${monthLabel}</h2><button class="vtp-calendar-arrow" data-calendar-shift="1" aria-label="Next month">›</button><button class="vtp-btn small" data-calendar-today>Today</button></div><div class="vtp-calendar-legend"><span class="task">${visibleTasks} tasks</span><span class="leave">${visibleLeave} leave</span><span class="holiday">${visibleHolidays} holidays</span></div></div><div class="vtp-calendar-scroll"><div class="vtp-calendar">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(day => `<div class="vtp-day vtp-weekday${day === 'Sat' || day === 'Sun' ? ' weekend' : ''}"><b>${day}</b></div>`).join('')}${cells}</div></div></article>${state.boot.capabilities.manageLeave ? holidayList() : ''}</section>`;
    },

    files: async () => {
      const data = await api('files-brand');
      state.filesBrand = data;
      const admin = Boolean(data.admin && state.boot.capabilities.filesAdmin);
      const icon = item => {
        const stroke = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
        const icons = {
          profile: `<svg viewBox="0 0 24 24" aria-hidden="true"><path ${stroke} d="M7 3h8l3 3v15H7z"/><path ${stroke} d="M15 3v4h4M10 12h5M10 16h5"/><circle cx="12" cy="9" r="1.4" fill="currentColor"/></svg>`,
          guidelines: `<svg viewBox="0 0 24 24" aria-hidden="true"><path ${stroke} d="M5 4h14v16H5z"/><path ${stroke} d="M8 8h8M8 12h8M8 16h5"/><circle cx="17" cy="17" r="2.4" fill="currentColor" opacity=".16" stroke="currentColor" stroke-width="1.4"/></svg>`,
          presentation: `<svg viewBox="0 0 24 24" aria-hidden="true"><rect ${stroke} x="4" y="4" width="16" height="11" rx="1.5"/><path ${stroke} d="M12 15v4M9 20h6M8 9h8M8 12h5"/></svg>`,
          social: `<svg viewBox="0 0 24 24" aria-hidden="true"><path ${stroke} d="M5 6.5h14v10H8l-3 3z"/><path ${stroke} d="M9 10h6M9 13h4"/><circle cx="18" cy="6" r="2.2" fill="currentColor"/></svg>`,
          assets: `<svg viewBox="0 0 24 24" aria-hidden="true"><path ${stroke} d="M3.5 7.5h6l2 2h9v9h-17z"/><path ${stroke} d="M3.5 7.5v-2h6l2 2"/></svg>`,
          access: `<svg viewBox="0 0 24 24" aria-hidden="true"><rect ${stroke} x="5" y="10" width="14" height="10" rx="2"/><path ${stroke} d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>`,
          client: `<svg viewBox="0 0 24 24" aria-hidden="true"><path ${stroke} d="M3.5 7.5h6l2 2h9v9h-17z"/><path ${stroke} d="M3.5 7.5v-2h6l2 2"/></svg>`,
        };
        return icons[item.key] || icons[item.kind] || icons.profile;
      };
      const fileCard = item => {
        const adminOnly = Boolean(item.adminOnly);
        const canOpen = Boolean(item.url) && (!adminOnly || admin);
        const open = canOpen ? `<a class="vtp-btn small" href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">Open ↗</a>` : `<span class="vtp-file-locked">${adminOnly ? 'Admin only' : 'Add file'}</span>`;
        const upload = admin && item.kind === 'pdf' ? `<label class="vtp-btn small vtp-file-upload-label">${item.url ? 'Replace PDF' : 'Upload PDF'}<input type="file" accept="application/pdf" data-files-upload-key="${esc(item.key)}"></label>` : '';
        const linkForm = admin && item.kind !== 'pdf' ? `<form class="vtp-files-link-form" data-files-link-form><input type="hidden" name="type" value="vibes"><input type="hidden" name="key" value="${esc(item.key)}"><input name="url" type="url" value="${esc(item.url || '')}" placeholder="Paste Google Drive or Doc link" aria-label="${esc(item.title)} link"><button class="vtp-btn small primary">Save link</button></form>` : '';
      const meta = String(item.meta || '').replace(/^(?:PDF\s*·\s*)+/i, '');
        return `<article class="vtp-card vtp-file-card"><div class="vtp-file-icon ${esc(item.kind)} vtp-file-icon-${esc(item.key)}">${icon(item)}</div><div class="vtp-file-copy"><div class="vtp-card-head"><div><h3>${esc(item.title)}</h3><small>${item.kind === 'pdf' ? 'PDF · ' : ''}${esc(meta)}</small></div>${adminOnly ? chip('Admin only', 'amber') : ''}</div><p>${adminOnly ? 'Restricted resource. Only administrators can open or change this link.' : 'Shared with the Vibes team. Employees can open this file but cannot edit it.'}</p><div class="vtp-card-actions">${open}${upload}</div>${linkForm}</div></article>`;
      };
      const quotationTemplateCard = item => {
        const open = item.url ? `<a class="vtp-btn small" href="${esc(item.url)}" target="_blank" rel="noopener noreferrer">Open Drive sample ↗</a>` : '<span class="vtp-file-locked">Link not added</span>';
        const edit = admin ? `<form class="vtp-files-link-form" data-quotation-template-form><input type="hidden" name="key" value="${esc(item.key)}"><input name="url" type="url" value="${esc(item.url || '')}" placeholder="Google Drive PDF link" aria-label="${esc(item.label)} link" required><button class="vtp-btn small primary">Save sample link</button></form>` : '';
        return `<article class="vtp-card vtp-file-card"><div class="vtp-file-icon drive">${icon({ key: 'profile', kind: 'drive' })}</div><div class="vtp-file-copy"><div class="vtp-card-head"><div><h3>${esc(item.label)}</h3><small>Google Drive · Replaceable quotation reference</small></div>${chip('Admin only', 'amber')}</div><p>Use this design as the reference when preparing this quotation type. The Drive file stays private; the portal keeps only its link.</p><div class="vtp-card-actions">${open}</div>${edit}</div></article>`;
      };
      const clientCard = client => {
        const socials = client.social || {};
        const socialIcons = {
          facebook: '<svg class="vtp-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M14 8h3V4h-3c-3 0-5 2-5 5v3H6v4h3v6h4v-6h3.2l.8-4H13V9c0-.7.3-1 1-1z"/></svg>',
          instagram: '<svg class="vtp-social-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.2" fill="currentColor"/></svg>',
          youtube: '<svg class="vtp-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.6 7.2a2.8 2.8 0 0 0-2-2C17.9 4.7 12 4.7 12 4.7s-5.9 0-7.6.5a2.8 2.8 0 0 0-2 2C2 8.9 2 12 2 12s0 3.1.4 4.8a2.8 2.8 0 0 0 2 2c1.7.5 7.6.5 7.6.5s5.9 0 7.6-.5a2.8 2.8 0 0 0 2-2c.4-1.7.4-4.8.4-4.8s0-3.1-.4-4.8zM10 15.5v-7l6 3.5-6 3.5z"/></svg>',
          tiktok: '<svg class="vtp-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15 3h3.2c.2 1.7 1.2 3.1 2.8 4v3.3c-1.2 0-2.5-.4-3.6-1.1v6.6a5.7 5.7 0 1 1-4.9-5.6v3.5a2.4 2.4 0 1 0 1.7 2.3V3h.8z"/></svg>',
          linkedin: '<svg class="vtp-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 8.5H2V21h3V8.5zM3.5 3A1.8 1.8 0 1 0 3.5 6.6 1.8 1.8 0 0 0 3.5 3zM22 13.8c0-3.8-2-5.6-4.8-5.6-2.2 0-3.2 1.2-3.8 2v-1.7h-3V21h3v-6.1c0-1.6.3-3.2 2.3-3.2 2 0 2 1.8 2 3.3V21h3v-7.2z"/></svg>',
          snapchat: '<svg class="vtp-social-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 3.2c-2.7 0-4.8 2-4.8 4.8v2.1c0 .4-.2.8-.6 1l-1.4.8c-.6.3-.5 1.2.1 1.4l2.1.6c.3.1.5.3.6.6.2.8.7 1.4 1.4 1.8.4.2.5.6.2.9-.4.5-1 .8-1.7.9-.4.1-.6.5-.4.9.3.6 1 .8 1.8.7.8-.1 1.5.1 2.2.6.3.2.7.4 1.1.4s.8-.1 1.1-.4c.7-.5 1.4-.7 2.2-.6.8.1 1.5-.1 1.8-.7.2-.4 0-.8-.4-.9-.7-.1-1.3-.4-1.7-.9-.3-.3-.2-.7.2-.9.7-.4 1.2-1 1.4-1.8.1-.3.3-.5.6-.6l2.1-.6c.6-.2.7-1.1.1-1.4l-1.4-.8c-.4-.2-.6-.6-.6-1V8c0-2.8-2.1-4.8-4.8-4.8z"/></svg>'
        };
        const socialLinks = [
          ['facebook', 'Facebook'], ['instagram', 'Instagram'], ['youtube', 'YouTube'],
          ['tiktok', 'TikTok'], ['linkedin', 'LinkedIn'], ['snapchat', 'Snapchat']
        ].filter(([key]) => socials[key]).map(([key, label]) => '<a class="vtp-client-social-link" href="' + esc(socials[key]) + '" target="_blank" rel="noopener noreferrer" aria-label="' + label + '" title="' + label + '">' + socialIcons[key] + '<span class="vtp-sr-only">' + label + '</span></a>').join('');
        const details = [
          client.location ? `<span class="vtp-client-location">⌖ ${esc(client.location)}</span>` : '',
          client.website ? `<a class="vtp-client-website" href="${esc(client.website)}" target="_blank" rel="noopener noreferrer">Website ↗</a>` : '',
          client.accounts_sheet ? `<a class="vtp-client-sheet" href="${esc(client.accounts_sheet)}" target="_blank" rel="noopener noreferrer">Accounts sheet ↗</a>` : ''
        ].filter(Boolean).join('');
        const logo = client.logo
          ? `<img class="vtp-client-logo-image" src="${esc(client.logo)}" alt="${esc(client.name)} logo" loading="lazy">`
          : icon({ key: 'client', kind: 'drive' });
        const logoClass = client.logo ? ' has-logo' : '';
        const editFields = `<div class="vtp-client-form-fields">
          <label>Client name<input name="name" value="${esc(client.name)}" required aria-label="Client name"></label>
          <label>Location<input name="location" value="${esc(client.location || '')}" placeholder="City, country" aria-label="Client location"></label>
          <label>Website<input name="website" type="url" value="${esc(client.website || '')}" placeholder="https://client.com" aria-label="Client website"></label>
          <label>Google Drive folder<input name="url" type="url" value="${esc(client.url || '')}" placeholder="Google Drive folder link" aria-label="Google Drive folder link"></label>
          <label>Accounts Google Sheet<input name="accounts_sheet" type="url" value="${esc(client.accounts_sheet || '')}" placeholder="Google Sheets link" aria-label="Accounts Google Sheet link"></label>
          <label>Client logo<input name="logoFile" type="file" accept="image/png,image/jpeg,image/webp" data-client-logo aria-label="Client logo"></label>
          <div class="vtp-client-social-fields"><span>Social media accounts</span>
            <input name="facebook" type="url" value="${esc(socials.facebook || '')}" placeholder="Facebook URL" aria-label="Facebook URL">
            <input name="instagram" type="url" value="${esc(socials.instagram || '')}" placeholder="Instagram URL" aria-label="Instagram URL">
            <input name="youtube" type="url" value="${esc(socials.youtube || '')}" placeholder="YouTube URL" aria-label="YouTube URL">
            <input name="tiktok" type="url" value="${esc(socials.tiktok || '')}" placeholder="TikTok URL" aria-label="TikTok URL">
            <input name="linkedin" type="url" value="${esc(socials.linkedin || '')}" placeholder="LinkedIn URL" aria-label="LinkedIn URL">
            <input name="snapchat" type="url" value="${esc(socials.snapchat || '')}" placeholder="Snapchat URL" aria-label="Snapchat URL">
          </div>
        </div>`;
        return `<article class="vtp-card vtp-file-card vtp-client-folder"><div class="vtp-file-icon drive vtp-file-icon-client${logoClass}">${logo}</div><div class="vtp-file-copy"><div class="vtp-card-head"><div><h3>${esc(client.name)}</h3><small>${esc(client.meta || 'Google Drive · Client folder')}</small></div>${chip('Client folder', 'blue')}</div>${details ? `<div class="vtp-client-details">${details}</div>` : ''}${socialLinks ? `<div class="vtp-client-socials">${socialLinks}</div>` : ''}<p>Keep this client’s briefs, deliverables and approvals together in one linked folder.</p><div class="vtp-card-actions">${client.url ? `<a class="vtp-btn small" href="${esc(client.url)}" target="_blank" rel="noopener noreferrer">Open folder ↗</a>` : '<span class="vtp-file-locked">Link not added</span>'}${admin ? `<button class="vtp-btn small danger" data-delete-file-client="${esc(client.id)}">Remove link</button>` : ''}</div>${admin ? `<details class="vtp-file-admin-panel"><summary><span>Edit client details</span><span>Admin only</span></summary><form class="vtp-files-client-edit" data-files-client-form><input type="hidden" name="type" value="client"><input type="hidden" name="id" value="${esc(client.id)}">${editFields}<button class="vtp-btn small">Save changes</button></form></details>` : ''}</div></article>`;
      };
      const addClient = admin ? `<article class="vtp-card vtp-file-card vtp-file-add"><div class="vtp-file-icon">＋</div><div class="vtp-file-copy"><h3>Add a client folder</h3><p>Add the client’s identity, location, website, social accounts and linked Google Drive folder.</p><details class="vtp-file-admin-panel"><summary><span>Add client details</span><span>Admin only</span></summary><form class="vtp-files-client-edit" data-files-client-form><input type="hidden" name="type" value="client"><div class="vtp-client-form-fields"><label>Client name<input name="name" placeholder="Client name" required aria-label="Client name"></label><label>Location<input name="location" placeholder="City, country" aria-label="Client location"></label><label>Website<input name="website" type="url" placeholder="https://client.com" aria-label="Client website"></label><label>Google Drive folder<input name="url" type="url" placeholder="Google Drive folder link" aria-label="Google Drive folder link"></label><label>Accounts Google Sheet<input name="accounts_sheet" type="url" placeholder="Google Sheets link" aria-label="Accounts Google Sheet link"></label><label>Client logo<input name="logoFile" type="file" accept="image/png,image/jpeg,image/webp" data-client-logo aria-label="Client logo"></label><div class="vtp-client-social-fields"><span>Social media accounts</span><input name="facebook" type="url" placeholder="Facebook URL" aria-label="Facebook URL"><input name="instagram" type="url" placeholder="Instagram URL" aria-label="Instagram URL"><input name="youtube" type="url" placeholder="YouTube URL" aria-label="YouTube URL"><input name="tiktok" type="url" placeholder="TikTok URL" aria-label="TikTok URL"><input name="linkedin" type="url" placeholder="LinkedIn URL" aria-label="LinkedIn URL"><input name="snapchat" type="url" placeholder="Snapchat URL" aria-label="Snapchat URL"></div></div><button class="vtp-btn small primary">Add client</button></form></details></div></article>` : '';
      view.innerHTML = `<section class="vtp-page vtp-files-page">${pageHead('Files & Brand', 'Keep Vibes resources and client folders organized in one clear workspace.', admin ? '<span class="vtp-admin-note">Admin controls are visible only to you.</span>' : '')}<section class="vtp-files-section"><div class="vtp-section-heading"><div><span class="vtp-eyebrow">VIBES AGENCY</span><h2>Vibes Agency Files</h2><p>Core brand resources for the team. Employees can open files; only the administrator can replace or link them.</p></div>${admin ? chip('Admin managed', 'green') : chip('Read only', 'blue')}</div><div class="vtp-grid two vtp-files-brand-grid">${(data.vibes || []).map(fileCard).join('')}</div></section><section class="vtp-files-section vtp-files-legacy-quotes" data-files-legacy-quotes="1"><div class="vtp-section-heading"><div><span class="vtp-eyebrow">QUOTATION REFERENCES</span><h2>Drive quotation samples</h2><p>These private Google Drive PDFs are replaceable references for one-time, six-month and one-year quotations.</p></div>${admin ? chip('Admin managed', 'green') : chip('Read only', 'blue')}</div><div class="vtp-grid two vtp-files-brand-grid">${(data.quotationTemplates || []).map(quotationTemplateCard).join('')}</div></section><section class="vtp-files-section"><div class="vtp-section-heading"><div><span class="vtp-eyebrow">CLIENT WORKSPACES</span><h2>Clients</h2><p>Each client has one linked folder for briefs, work-in-progress and final delivery.</p></div>${admin ? chip('Admin managed', 'green') : chip('Team access', 'blue')}</div><div class="vtp-grid two vtp-files-brand-grid">${(data.clients || []).map(clientCard).join('')}${addClient}</div>${!data.clients?.length && !admin ? empty('Client folders will appear here when the administrator adds them.') : ''}</section></section>`;
      const cleanFileMetadata = () => view.querySelectorAll('.vtp-files-page .vtp-file-card small').forEach(metaEl => {
        const clean = String(metaEl.textContent || '').replace(/^(?:PDF\s*·\s*)+/i, '');
        // Only uploaded PDFs are PDFs; Drive folders and Google Docs keep their own label.
        if (clean) metaEl.textContent = /google|drive|folder|doc\b/i.test(clean) ? clean : `PDF · ${clean}`;
      });
      cleanFileMetadata();
      window.setTimeout(cleanFileMetadata, 350);
      const removeDuplicateQuotationSection = () => {
        const page = view.querySelector('.vtp-files-page');
        const legacy = page?.querySelector('[data-files-legacy-quotes]');
        if (legacy && page.querySelector('[data-vce-template-section]')) legacy.remove();
      };
      removeDuplicateQuotationSection();
      window.setTimeout(removeDuplicateQuotationSection, 250);
      window.setTimeout(removeDuplicateQuotationSection, 900);
    },

    directory: async () => {
      const users = await load('employees', true);
      const admins = users.filter(user => user.role === 'admin');
      const managers = users.filter(user => user.role === 'manager');
      const employees = users.filter(user => user.role === 'employee');
      const root = admins[0] || state.boot.user;
      const card = (user, rootCard = false) => `<article class="vtp-org-card ${rootCard ? 'root' : ''}"><img src="${esc(user.avatar)}" alt="${esc(user.name)}"><h3>${esc(user.name)}</h3><p>${esc(user.position || user.role || 'Team member')}</p><p>${esc(user.department || 'Vibes Agency')}</p><span class="vtp-org-role">${esc(user.role || 'employee')}</span><a href="mailto:${esc(user.email)}">${esc(user.email)}</a></article>`;
      const departments = [...new Set(employees.map(user => user.department || 'Vibes Agency'))];
      const branches = departments.map(department => `<section class="vtp-org-branch"><div class="vtp-org-department">${esc(department)}</div><div class="vtp-org-members">${employees.filter(user => (user.department || 'Vibes Agency') === department).map(user => card(user)).join('')}</div></section>`).join('');
      view.innerHTML = `<section class="vtp-page">${pageHead('Employee directory', 'See the Vibes team structure, roles and departments at a glance.')}<div class="vtp-card vtp-org-chart"><div class="vtp-org-level">${card(root, true)}</div>${managers.length ? `<div class="vtp-org-level">${managers.map(user => card(user)).join('')}</div>` : ''}<div class="vtp-org-level branches">${branches || '<div class="vtp-org-empty">Employees will appear here as the team grows.</div>'}</div></div></section>`;
    },

    profile: async () => {
      const user = state.boot.user;
      view.innerHTML = `<section class="vtp-page">${pageHead('My profile', 'Manage your photo, account details and password.')}<div class="vtp-grid two"><article class="vtp-card"><h2>Profile</h2><div class="vtp-profile-card"><div><div class="vtp-profile-photo"><img id="vtp-profile-preview" src="${esc(user.avatar)}" alt="${esc(user.name)}"></div><form id="vtp-avatar-form" class="vtp-avatar-form" enctype="multipart/form-data"><input id="vtp-avatar-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required><button class="vtp-btn">Upload new photo</button><p class="vtp-avatar-help">JPG, PNG or WebP · maximum 5 MB.</p></form></div><div><h3>${esc(user.name)}</h3><div class="vtp-list"><div class="vtp-list-row"><span>Work email</span><strong>${esc(user.email)}</strong></div><div class="vtp-list-row"><span>Position</span><strong>${esc(user.position || 'Administrator')}</strong></div><div class="vtp-list-row"><span>Department</span><strong>${esc(user.department || 'Vibes Agency')}</strong></div><div class="vtp-list-row"><span>Phone</span><strong>${esc(user.phone || '—')}</strong></div><div class="vtp-list-row"><span>Joined</span><strong>${user.dateJoining ? fmt(user.dateJoining) : '—'}</strong></div></div></div></div></article>${profileDetailsForm(user)}<article class="vtp-card"><h2>Change password</h2><form id="vtp-password-form" class="vtp-form"><div class="vtp-field"><label for="vtp-current-password">Current password</label><input id="vtp-current-password" type="password" name="currentPassword" autocomplete="current-password" required></div><div class="vtp-field"><label>New password</label><input type="password" name="password" minlength="10" required></div><div class="vtp-field"><label>Confirm password</label><input type="password" name="confirm" minlength="10" required></div><button class="vtp-btn primary">Update password</button></form></article></div></section>`;
    }
  };

  function profileDetailsForm(user) {
    if (!state.boot.capabilities.employees) return '';
    return `<article class="vtp-card"><h2>Employee details</h2><form id="vtp-profile-details-form" class="vtp-form"><div class="vtp-field"><label for="vtp-profile-position">Position</label><input id="vtp-profile-position" name="position" value="${esc(user.position || '')}" required></div><div class="vtp-field"><label for="vtp-profile-phone">Phone number</label><input id="vtp-profile-phone" name="phone" type="tel" value="${esc(user.phone || '')}" required></div><div class="vtp-field"><label for="vtp-profile-gender">Gender</label><select id="vtp-profile-gender" name="gender" required><option value="">Choose gender</option>${[['male','Male'],['female','Female'],['other','Other'],['prefer_not_to_say','Prefer not to say']].map(([value,label])=>`<option value="${value}" ${user.gender===value?'selected':''}>${label}</option>`).join('')}</select></div><div class="vtp-form-row"><div class="vtp-field"><label for="vtp-profile-joined">Date of joining</label><input id="vtp-profile-joined" name="dateJoining" type="date" value="${esc(user.dateJoining || '')}" required></div><div class="vtp-field"><label for="vtp-profile-birth">Date of birth</label><input id="vtp-profile-birth" name="dateBirth" type="date" value="${esc(user.dateBirth || '')}" required></div></div><div class="vtp-form-row"><div class="vtp-field"><label for="vtp-profile-pemail">Personal email</label><input id="vtp-profile-pemail" name="personalEmail" type="email" value="${esc(user.personalEmail || '')}" placeholder="name@gmail.com"></div><div class="vtp-field"><label for="vtp-profile-marital">Marital status</label><select id="vtp-profile-marital" name="maritalStatus">${maritalOptions(user.maritalStatus || '')}</select></div></div><div class="vtp-form-row"><div class="vtp-field"><label for="vtp-profile-ename">Emergency contact name</label><input id="vtp-profile-ename" name="emergencyName" value="${esc(user.emergencyName || '')}"></div><div class="vtp-field"><label for="vtp-profile-ephone">Emergency contact phone</label><input id="vtp-profile-ephone" name="emergencyPhone" type="tel" value="${esc(user.emergencyPhone || '')}"></div></div><div class="vtp-field"><label for="vtp-profile-address">Home address</label><input id="vtp-profile-address" name="address" value="${esc(user.address || '')}"></div><button class="vtp-btn primary">Save profile details</button></form></article>`;
  }

  function staticPage(title, subtitle, names, statuses) {
    view.innerHTML = `<section class="vtp-page">${pageHead(title, subtitle)}<div class="vtp-grid three">${names.map((name, index) => `<article class="vtp-card"><div class="vtp-card-head">${chip(statuses[index], index === 2 ? 'green' : index === 1 ? 'coral' : 'amber')}</div><div class="vtp-placeholder-art">✦</div><h3>${esc(name)}</h3><p>Updated recently by the Vibes team.</p><button class="vtp-btn">Open</button></article>`).join('')}</div></section>`;
  }

  function announcementPreview() {
    const item = state.announcements[0];
    return item ? `<h3>${esc(item.title)}</h3><p>${esc(String(item.content).replace(/<[^>]*>/g, '').slice(0, 180))}</p><a href="#announcements">Read more →</a>` : empty('No announcements yet.');
  }

  function taskCard(task) {
    const files = Array.isArray(task.files) ? task.files : [];
    const canManage = state.boot.capabilities.manageTasks;
    const action = task.status === 'todo'
      ? `<button class="vtp-btn small" data-task="${task.id}" data-status="in_progress">Start task</button>`
      : task.status === 'in_progress'
        ? `<button class="vtp-btn small primary" data-submit-task="${task.id}">Submit work</button>`
        : task.status === 'review' && canManage
          ? `<button class="vtp-btn small primary" data-task="${task.id}" data-status="done">Approve deliverable</button>`
          : task.status === 'review' ? chip('Submitted for review', 'amber') : chip('Completed', 'green');
    return `<article class="vtp-task">
      <div class="vtp-card-head"><span class="vtp-task-id">ID ${task.id} · ${esc(task.task_number || 'No number')}</span>${chip(task.status, task.status === 'done' ? 'green' : task.status === 'review' ? 'amber' : '')}</div>
      <h4>${esc(task.title)}</h4>
      <dl class="vtp-task-details"><div><dt>Client</dt><dd>${esc(task.client_name || task.project_name || 'Internal')}</dd></div><div><dt>Service</dt><dd>${esc(task.service_label || 'Custom')} · Qty ${Number(task.service_quantity || 1)}</dd></div><div><dt>Assigned to</dt><dd>${esc(task.assignee_name || state.boot.user.name)}</dd></div><div><dt>Cycle</dt><dd>${esc(task.delivery_month || task.service_term || 'once')}</dd></div><div><dt>Due</dt><dd>${fmt(task.due_date)}</dd></div><div><dt>Priority</dt><dd>${esc(task.priority)}</dd></div></dl>
      ${task.description ? `<p class="vtp-task-description">${esc(task.description)}</p>` : ''}
      ${task.submission_note ? `<div class="vtp-task-submission"><strong>Submitted work</strong><p>${esc(task.submission_note)}</p></div>` : ''}
      ${files.length ? `<div class="vtp-task-files">${files.map(file => `<a href="${esc(file.url)}" target="_blank" rel="noopener noreferrer">${file.source === 'google_drive' ? '↗' : '📎'} ${esc(file.name)}</a>`).join('')}</div>` : ''}
      <div class="vtp-task-foot">${action}${canManage ? ` <button class="vtp-btn small danger" data-delete-task="${task.id}">Delete</button>` : ''}</div>
    </article>`;
  }

  function holidayList() {
    return `<article class="vtp-card vtp-holiday-list"><div class="vtp-card-head"><h2>Company holidays</h2><button class="vtp-btn small" data-open="holiday">＋ Add holiday</button></div>${state.holidays.length ? `<div class="vtp-list">${state.holidays.map(item => `<div class="vtp-list-row"><div><strong>${esc(item.name)}</strong><small>${fmt(item.holiday_date)}${item.end_date && item.end_date !== item.holiday_date ? ` – ${fmt(item.end_date)}` : ''}</small></div><button class="vtp-btn small danger" data-delete-holiday="${item.id}">Delete</button></div>`).join('')}</div>` : empty('No company holidays added yet.')}</article>`;
  }

  const employeePermissionMatrix = {
    admin: ['All portal modules', 'Employee and permission management', 'Finance and reporting controls'],
    manager: ['Projects and task assignment', 'Team availability and reviews', 'Sales records needed for delivery'],
    employee: ['Assigned tasks and project files', 'Vibes Connect conversations', 'Personal profile and availability']
  };
  const permissionMarkup = role => (employeePermissionMatrix[String(role || 'employee').toLowerCase()] || employeePermissionMatrix.employee).map(item => '<li>' + esc(item) + '</li>').join('');
  const skillMarkup = skills => String(skills || '').split(/[,\n]/).map(item => item.trim()).filter(Boolean).map(item => '<span class="vtp-chip purple">' + esc(item) + '</span>').join('') || '<span class="vtp-muted">No skills recorded yet.</span>';
  const taskSummaryMarkup = summary => { const value = summary || {}; return '<div class="vtp-mini-stats"><div><strong>' + Number(value.total || 0) + '</strong><small>Total tasks</small></div><div><strong>' + Number(value.in_progress || 0) + '</strong><small>In progress</small></div><div><strong>' + Number(value.review || 0) + '</strong><small>In review</small></div><div><strong>' + Number(value.done || 0) + '</strong><small>Completed</small></div></div>'; };
  const auditMarkup = audit => { const entries = Array.isArray(audit) ? audit.filter(entry => entry && typeof entry === 'object') : []; return entries.length ? '<div class="vtp-audit-list">' + entries.map(entry => '<div><strong>' + esc(entry.summary || entry.action || 'Profile change') + '</strong><small>' + esc(fmtDateTime(entry.at || entry.time || entry.created_at)) + '</small></div>').join('') + '</div>' : '<p class="vtp-muted">No profile changes recorded yet.</p>'; };

  function employeeProfileDrawer(item) {
    const availability = String(item.availability || 'available').replaceAll('_', ' ');
    const canViewSalary = Boolean(state.boot?.capabilities?.employees) || Number(state.boot?.user?.id) === Number(item.id);
    const roleName = String(item.role || 'employee').replaceAll('_', ' ');
    const salaryMarkup = canViewSalary ? '<article class="vtp-card vtp-salary-card"><div class="vtp-card-head"><div><h3>Monthly salary</h3><p>Visible only to this employee and authorized administrators.</p></div></div><strong>' + (item.salaryAmount ? money(item.salaryAmount, item.salaryCurrency || 'USD') + ' / month' : (['admin', 'manager'].includes(String(item.role || '').toLowerCase()) ? 'Not tracked for manager profile' : 'Not set')) + '</strong></article>' : '';
    openDrawer('<div class="vtp-drawer-profile"><div class="vtp-profile-hero"><img src="' + esc(item.avatar || '') + '" alt="' + esc(item.name) + '"><div><h2>' + esc(item.name) + '</h2><p>' + esc(item.position || 'Team member') + ' · ' + esc(item.department || 'Vibes Agency') + '</p></div></div><div class="vtp-profile-grid"><div><small>Work email</small><strong>' + esc(item.email) + '</strong></div><div><small>Role</small><strong>' + esc(roleName) + '</strong></div><div><small>Availability</small><strong>' + esc(availability) + '</strong></div><div><small>Account status</small><strong>' + (item.active === false ? 'Inactive' : 'Active') + '</strong></div><div><small>Phone</small><strong>' + esc(item.phone || 'Not provided') + '</strong></div><div><small>Joined</small><strong>' + esc(fmt(item.dateJoining)) + '</strong></div><div><small>Personal email</small><strong>' + esc(item.personalEmail || 'Not provided') + '</strong></div><div><small>Marital status</small><strong>' + esc(maritalLabel(item.maritalStatus) || 'Not provided') + '</strong></div><div><small>Emergency contact</small><strong>' + esc([item.emergencyName, item.emergencyPhone].filter(Boolean).join(' · ') || 'Not provided') + '</strong></div><div><small>Home address</small><strong>' + esc(item.address || 'Not provided') + '</strong></div></div>' + salaryMarkup + '<article class="vtp-card"><div class="vtp-card-head"><div><h3>Workload</h3><p>Current task volume from the task manager.</p></div></div>' + taskSummaryMarkup(item.taskSummary) + '</article><article class="vtp-card"><div class="vtp-card-head"><div><h3>Skills and services</h3><p>Use these skills when assigning work.</p></div></div><div class="vtp-chip-row">' + skillMarkup(item.skills) + '</div></article><article class="vtp-card"><div class="vtp-card-head"><div><h3>Access and permissions</h3><p>Permissions are determined by the employee role.</p></div></div><ul class="vtp-permission-list">' + permissionMarkup(item.role) + '</ul></article><article class="vtp-card"><div class="vtp-card-head"><div><h3>Recent profile activity</h3><p>Administrative changes only.</p></div></div>' + auditMarkup(item.audit) + '</article><div class="vtp-drawer-actions"><button class="vtp-btn primary" data-edit-employee="' + item.id + '">Edit profile</button><button class="vtp-btn vtp-drawer-close">Close</button></div></div>');
  }
  function employeeEditDrawer(item) {
    const role = String(item.role || 'employee').toLowerCase();
    const availability = String(item.availability || 'available').toLowerCase();
    openDrawer('<h2>Edit employee</h2><p>Update this employee’s profile, role, skills and current availability.</p><form id="vtp-employee-edit-form" class="vtp-form"><input type="hidden" name="id" value="' + item.id + '"><div class="vtp-field"><label>Full name</label><input name="name" value="' + esc(item.name) + '" required></div><div class="vtp-field"><label>Work email</label><input value="' + esc(item.email) + '" disabled></div><div class="vtp-form-row"><div class="vtp-field"><label>Position</label><input name="position" value="' + esc(item.position || '') + '" required></div><div class="vtp-field"><label>Department</label><select name="department"><option ' + (item.department === 'Creative' ? 'selected' : '') + '>Creative</option><option ' + (item.department === 'Video & Motion' ? 'selected' : '') + '>Video & Motion</option><option ' + (item.department === 'Marketing' ? 'selected' : '') + '>Marketing</option><option ' + (item.department === 'Operations' ? 'selected' : '') + '>Operations</option><option ' + (item.department === 'Management' ? 'selected' : '') + '>Management</option></select></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Role</label><select name="role"><option value="employee" ' + (role === 'employee' ? 'selected' : '') + '>Employee</option><option value="manager" ' + (role === 'manager' ? 'selected' : '') + '>Manager</option></select><small>Permissions: ' + esc((employeePermissionMatrix[role] || employeePermissionMatrix.employee).join(' · ')) + '</small></div><div class="vtp-field"><label>Availability</label><select name="availability"><option value="available" ' + (availability === 'available' ? 'selected' : '') + '>Available</option><option value="busy" ' + (availability === 'busy' ? 'selected' : '') + '>Busy</option><option value="leave" ' + (availability === 'leave' ? 'selected' : '') + '>On leave</option><option value="offline" ' + (availability === 'offline' ? 'selected' : '') + '>Offline</option></select></div></div><div class="vtp-field"><label>Phone number</label><input name="phone" type="tel" value="' + esc(item.phone || '') + '" required></div><div class="vtp-form-row"><div class="vtp-field"><label>Personal email</label><input name="personalEmail" type="email" value="' + esc(item.personalEmail || '') + '" placeholder="name@gmail.com"></div><div class="vtp-field"><label>Marital status</label><select name="maritalStatus">' + maritalOptions(item.maritalStatus || '') + '</select></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Emergency contact name</label><input name="emergencyName" value="' + esc(item.emergencyName || '') + '"></div><div class="vtp-field"><label>Emergency contact phone</label><input name="emergencyPhone" type="tel" value="' + esc(item.emergencyPhone || '') + '"></div></div><div class="vtp-field"><label>Home address</label><input name="address" value="' + esc(item.address || '') + '"></div><div class="vtp-field"><label>Skills and services</label><textarea name="skills" rows="3" placeholder="Graphic Design, Motion Graphics, Social Media">' + esc(item.skills || '') + '</textarea><small>Separate skills with commas.</small></div><label class="vtp-check"><input type="checkbox" name="active" ' + (item.active === false ? '' : 'checked') + '> Account is active</label><button class="vtp-btn primary">Save employee</button></form>');
  }
  function employeeDrawer() {
    openDrawer(`<h2>Add employee</h2><p>Create a Vibes account and complete the employee record.</p><form id="vtp-employee-form" class="vtp-form"><div class="vtp-field"><label>Full name</label><input name="name" required></div><div class="vtp-field"><label>Work email</label><input name="email" type="email" placeholder="name@vibesagency.co" required></div><div class="vtp-form-row"><div class="vtp-field"><label>Position</label><input name="position" placeholder="Graphic Designer" required></div><div class="vtp-field"><label>Department</label><select name="department"><option>Creative</option><option>Video & Motion</option><option>Marketing</option><option>Operations</option><option>Management</option></select></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Gender</label><select name="gender" required><option value="">Select gender</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option><option value="prefer_not_to_say">Prefer not to say</option></select></div><div class="vtp-field"><label>Phone number</label><input name="phone" type="tel" placeholder="+966…" required></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Date of joining</label><input name="dateJoining" type="date" value="${todayIso()}" required></div><div class="vtp-field"><label>Date of birth</label><input name="dateBirth" type="date" required></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Personal email</label><input name="personalEmail" type="email" placeholder="name@gmail.com"></div><div class="vtp-field"><label>Marital status</label><select name="maritalStatus">${maritalOptions('')}</select></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Emergency contact name</label><input name="emergencyName"></div><div class="vtp-field"><label>Emergency contact phone</label><input name="emergencyPhone" type="tel"></div></div><div class="vtp-field"><label>Home address</label><input name="address" placeholder="City, street, building"></div><div class="vtp-field"><label>Role</label><select name="role"><option value="employee">Employee</option><option value="manager">Manager</option></select></div><div class="vtp-alert">A random temporary password will be shown once after the account is created.</div><button class="vtp-btn primary">Create account</button></form>`);
  }

  function categoryDrawer(item = null) {
    openDrawer(`<h2>${item ? 'Edit' : 'Add'} category</h2><p>Manage the category name, short code and order shown in the calculator.</p><form id="vtp-category-form" class="vtp-form"><input type="hidden" name="id" value="${item?.id || ''}"><div class="vtp-field"><label for="vtp-category-name">Category name</label><input id="vtp-category-name" name="name" value="${esc(item?.name || '')}" required></div><div class="vtp-form-row"><div class="vtp-field"><label for="vtp-category-code">Short code</label><input id="vtp-category-code" name="code" maxlength="20" value="${esc(item?.code || '')}" placeholder="SOC"></div><div class="vtp-field"><label for="vtp-category-order">Order</label><input id="vtp-category-order" name="sortOrder" type="number" min="0" value="${Number(item?.sortOrder ?? 999)}"></div></div><label class="vtp-check"><input type="checkbox" name="active" ${item?.active === false ? '' : 'checked'}> Show category in calculator</label><button class="vtp-btn primary">Save category</button></form>`);
  }

  function serviceDrawer(item = null, categoryId = pricingCategoryId()) {
    const catalog = state.catalog;
    const selectedCategoryId = item?.categoryId ?? categoryId;
    const selectedCategory = catalog.categories.find(category => Number(category.id) === Number(selectedCategoryId));
    const priceDisplay = item?.custom ? 'request' : item?.startingPrice ? 'from' : 'fixed';
    openDrawer(`<h2>${item ? 'Edit' : 'Add'} service</h2><p>Set one base price and choose how this individual service should display it.</p><form id="vtp-service-form" class="vtp-form"><input type="hidden" name="id" value="${item?.id || ''}"><div class="vtp-field"><label>Service name</label><input name="name" value="${esc(item?.name || '')}" required></div><div class="vtp-form-row"><div class="vtp-field"><label>Category</label><select name="categoryId" required><option value="">Choose category</option>${catalog.categories.map(cat => `<option value="${cat.id}" ${Number(selectedCategoryId) === Number(cat.id) ? 'selected' : ''}>${esc(cat.name)}</option>`).join('')}</select></div><div class="vtp-field"><label>Service label</label><input name="label" maxlength="20" value="${esc(item?.label || selectedCategory?.code || '')}" placeholder="SOC" required></div></div><div class="vtp-field"><label>Description</label><textarea name="description" rows="3">${esc(item?.description || '')}</textarea></div><div class="vtp-form-row"><div class="vtp-field"><label>Unit</label><input name="unit" value="${esc(item?.unit || 'service')}" required></div><div class="vtp-field"><label>Order</label><input name="sortOrder" type="number" min="0" value="${Number(item?.sortOrder ?? 999)}"></div></div>${catalogPriceFields(item)}<div class="vtp-field vtp-price-display-field"><label>Price display</label><select name="priceDisplay"><option value="fixed" ${priceDisplay === 'fixed' ? 'selected' : ''}>Fixed price</option><option value="from" ${priceDisplay === 'from' ? 'selected' : ''}>Starting from</option><option value="request" ${priceDisplay === 'request' ? 'selected' : ''}>Price on request</option></select><small>Choose this separately for each service. “Starting from” keeps the base amount and labels it as a minimum.</small></div><div class="vtp-check-grid"><label class="vtp-check"><input type="checkbox" name="active" ${item?.active === false ? '' : 'checked'}> Visible in calculator</label></div><button type="button" data-save-service class="vtp-btn primary">Save service</button></form>`);
  }

  const packageItemRow = (item = {}) => `<div class="vtp-package-item-row"><select name="packageServiceId" required><option value="">Choose service</option>${state.catalog.services.filter(service => service.active).map(service => `<option value="${service.id}" ${Number(item.serviceId) === Number(service.id) ? 'selected' : ''}>${esc(service.label)} · ${esc(service.name)}</option>`).join('')}</select><input name="packageQuantity" type="number" min="0.01" step="0.01" value="${Number(item.quantity || 1)}" aria-label="Quantity"><button type="button" class="vtp-drive-remove" data-remove-package-item aria-label="Remove service">×</button></div>`;

  function packageDrawer(item = null) {
    const items = item?.items?.length ? item.items : [{}];
    openDrawer(`<h2>${item ? 'Edit' : 'Add'} package</h2><p>Choose the included services and set one base package price. Contract terms control recurring totals and discounts.</p><form id="vtp-package-form" class="vtp-form"><input type="hidden" name="id" value="${item?.id || ''}"><div class="vtp-field"><label>Package name</label><input name="name" value="${esc(item?.name || '')}" placeholder="Silver" required></div><div class="vtp-field"><label>Description</label><textarea name="description" rows="2">${esc(item?.description || '')}</textarea></div><div class="vtp-field"><div class="vtp-card-head"><label>Included services</label><button type="button" class="vtp-btn small" data-add-package-item>＋ Add service</button></div><div id="vtp-package-items" class="vtp-package-items">${items.map(packageItemRow).join('')}</div></div>${catalogPriceFields(item)}<div class="vtp-form-row"><div class="vtp-field"><label>Order</label><input name="sortOrder" type="number" min="0" value="${Number(item?.sortOrder ?? 999)}"></div><label class="vtp-check"><input type="checkbox" name="active" ${item?.active === false ? '' : 'checked'}> Visible in calculator</label></div><button class="vtp-btn primary">Save package</button></form>`);
  }

  function inquiryDrawer() {
    openDrawer(`<h2>Create client inquiry</h2><p>Use this for requests received by email, phone, WhatsApp or referral.</p><form id="vtp-inquiry-form" class="vtp-form"><div class="vtp-form-row"><div class="vtp-field"><label>Client name</label><input name="clientName" required></div><div class="vtp-field"><label>Company</label><input name="company"></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Email</label><input name="email" type="email" required></div><div class="vtp-field"><label>Phone</label><input name="phone" type="tel"></div></div><div class="vtp-field"><label>Request source</label><select name="source"><option value="email">Email</option><option value="phone">Phone</option><option value="whatsapp">WhatsApp</option><option value="referral">Referral</option><option value="manual">Other</option></select></div><div class="vtp-field"><label>Requested services</label><textarea name="services" rows="3" placeholder="Graphic design, video editing, social media…"></textarea><small>Separate services with commas or new lines.</small></div><div class="vtp-field"><label>Project brief</label><textarea name="brief" rows="5" required></textarea></div><div class="vtp-form-row"><div class="vtp-field"><label>Budget</label><input name="budget" placeholder="Optional"></div><div class="vtp-field"><label>Preferred deadline</label><input name="deadline" type="date"></div></div><button class="vtp-btn primary">Save inquiry</button></form>`);
  }

  function viewInquiryDrawer(item) {
    openDrawer(`<div class="vtp-card-head"><div><span class="vtp-eyebrow">${esc(item.request_number)}</span><h2>${esc(item.company || item.client_name)}</h2></div>${chip(item.status, item.status === 'approved' ? 'green' : 'amber')}</div><div class="vtp-list"><div class="vtp-list-row"><span>Client</span><strong>${esc(item.client_name)}</strong></div><div class="vtp-list-row"><span>Email</span><strong>${esc(item.email)}</strong></div><div class="vtp-list-row"><span>Phone</span><strong>${esc(item.phone || '—')}</strong></div><div class="vtp-list-row"><span>Source</span><strong>${esc(item.source)}</strong></div><div class="vtp-list-row"><span>Deadline</span><strong>${fmt(item.deadline)}</strong></div></div><h3>Requested services</h3><div class="vtp-tag-list">${(item.services || []).map(service => chip(service)).join('') || 'Custom request'}</div><h3>Project brief</h3><div class="vtp-brief-box">${esc(item.brief || 'No brief provided.')}</div><button class="vtp-btn primary" data-quote-inquiry="${item.id}">Create quotation</button>`);
  }

  // Lazily loads pdf.js (only needed when an admin chooses "Upload PDF
  // quotation"), so most visits to the drawer never pay for it.
  let pdfJsPromise = null;
  function ensurePdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfJsPromise) return pdfJsPromise;
    pdfJsPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.onload = () => {
        if (!window.pdfjsLib) { reject(new Error('PDF reader failed to load.')); return; }
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror = () => reject(new Error('PDF reader could not be loaded. Check your connection and try again.'));
      document.head.appendChild(script);
    });
    return pdfJsPromise;
  }

  // Reads the raw text layer of every page of a PDF. Illustrator exports
  // that are pure vector artwork (text converted to outlines) have no text
  // layer at all, in which case this returns an empty string and the admin
  // is told to fill in the services and total manually.
  async function extractPdfText(file) {
    const pdfjsLib = await ensurePdfJs();
    const buffer = await file.arrayBuffer();
    const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
    let text = '';
    for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
      const page = await pdf.getPage(pageNumber);
      const content = await page.getTextContent();
      // pdf.js returns text as a flat list of positioned runs with no line
      // breaks of its own, so joining item.str with spaces collapses an
      // entire page (every service row, every price) into one giant line
      // and no row-level pattern can ever match it. Rebuild visual lines by
      // grouping runs that share a y-position (their vertical baseline, with
      // a small tolerance for rounding/kerning noise), then order those
      // lines top-to-bottom and the runs within each line left-to-right.
      const lines = [];
      for (const item of content.items) {
        if (!item.str || !item.str.trim()) continue;
        const y = item.transform[5];
        const x = item.transform[4];
        let line = lines.find(candidate => Math.abs(candidate.y - y) < 3);
        if (!line) {
          line = { y, parts: [] };
          lines.push(line);
        }
        line.parts.push({ x, str: item.str });
      }
      lines.sort((a, b) => b.y - a.y);
      for (const line of lines) {
        line.parts.sort((a, b) => a.x - b.x);
        text += line.parts.map(part => part.str).join(' ').replace(/\s+/g, ' ').trim() + '\n';
      }
    }
    return text;
  }

  // Best-effort heuristic parse of a quotation/invoice-style PDF's text into
  // candidate service rows and a total. This is intentionally approximate —
  // the admin reviews and edits every row before the quotation is saved, so
  // a missed or misread line never reaches a client or Finance unseen.
  function parseQuotationPdfText(rawText) {
    const text = String(rawText || '').replace(/\r/g, '');
    const numberToken = '[\\d,]+(?:\\.\\d{1,2})?';
    const toNumber = token => Number(String(token).replace(/,/g, ''));

    // Total: prefer the last explicit "grand total" / "total due" / "total"
    // line in the document (totals are almost always near the bottom).
    const totalRegex = new RegExp(`(grand\\s*total|total\\s*due|total\\s*amount|total)\\s*[:\\-]?\\s*(?:SAR|SR|USD|AED|\\$|ر\\.س)?\\s*(${numberToken})`, 'gi');
    let total = 0;
    let totalMatch;
    while ((totalMatch = totalRegex.exec(text)) !== null) total = toNumber(totalMatch[2]);

    // Line items: lines of reasonable length ending in a currency-like
    // number, skipping obvious non-service lines (totals, tax, headers).
    const skipWords = /\b(sub ?total|vat|tax|total|grand total|page \d|quotation|invoice|date|valid until|client|prepared by|prepared for)\b/i;
    const lineRegex = new RegExp(`^(.{3,90}?)\\s+(?:SAR|SR|USD|AED|\\$|ر\\.س)?\\s*(${numberToken})\\s*(?:SAR|SR|USD|AED|ر\\.س)?$`, 'i');
    const items = [];
    for (const rawLine of text.split('\n')) {
      const line = rawLine.replace(/\s+/g, ' ').trim();
      if (!line || skipWords.test(line)) continue;
      const match = line.match(lineRegex);
      if (!match) continue;
      const description = match[1].replace(/[-•.\s]+$/, '').trim();
      const price = toNumber(match[2]);
      if (!description || description.length < 3 || !(price > 0)) continue;
      items.push({ description, quantity: 1, unitPrice: price });
      if (items.length >= 25) break;
    }
    return { items, total };
  }

  async function quotationDrawerLegacy(inquiryId = 0) {
    const inquiries = await load('inquiries', true);
    const inquiry = inquiries.find(item => Number(item.id) === Number(inquiryId));
    const validUntil = dateAfter(14);
    const serviceRows = inquiry?.services?.length ? inquiry.services : [''];
    openDrawer(`<h2>Create quotation</h2><p>Prepare a branded scope and price for client approval before production.</p><form id="vtp-quotation-form" class="vtp-form"><input type="hidden" name="inquiryId" value="${inquiry?.id || ''}"><div class="vtp-form-row"><div class="vtp-field"><label>Client name</label><input name="clientName" value="${esc(inquiry?.client_name || '')}" required></div><div class="vtp-field"><label>Company</label><input name="company" value="${esc(inquiry?.company || '')}"></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Email</label><input name="email" type="email" value="${esc(inquiry?.email || '')}" required></div><div class="vtp-field"><label>Phone</label><input name="phone" value="${esc(inquiry?.phone || '')}"></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Currency</label><select name="currency"><option value="USD">USD</option></select></div><div class="vtp-field"><label>Valid until</label><input name="validUntil" type="date" value="${validUntil}" required></div></div><div class="vtp-field"><div class="vtp-card-head"><label>Services and pricing</label><button type="button" class="vtp-btn small" data-add-quote-item>＋ Add item</button></div><div id="vtp-quote-items" class="vtp-quote-items">${serviceRows.map((service, index) => `<div class="vtp-quote-item"><input name="itemDescription" value="${esc(service)}" placeholder="Service or deliverable" required><input name="itemQuantity" type="number" min="0.01" step="0.01" value="1" aria-label="Quantity"><input name="itemPrice" type="number" min="0" step="0.01" placeholder="Unit price" aria-label="Unit price" required>${index ? '<button type="button" class="vtp-drive-remove" data-remove-quote-item aria-label="Remove item">×</button>' : ''}</div>`).join('')}</div></div><div class="vtp-field"><label>VAT rate (%)</label><input name="vatRate" type="number" min="0" max="100" step="0.01" value="15"></div><div class="vtp-field"><label>Terms</label><textarea name="terms" rows="5">Work begins after written approval of this quotation. Payment is due after delivery according to the agreed payment term. The quotation covers only the listed scope; additional work may require a revised quotation.</textarea></div><div class="vtp-field"><label>Notes</label><textarea name="notes" rows="3" placeholder="Optional client-facing notes"></textarea></div><button class="vtp-btn primary">Save quotation</button></form>`);
  }

  async function quotationDrawer(inquiryId = 0) {
    const inquiries = await load('inquiries', true);
    const inquiry = inquiries.find(item => Number(item.id) === Number(inquiryId));
    const validUntil = dateAfter(14);
    const detailed = inquiry?.serviceItems?.length ? inquiry.serviceItems : (inquiry?.services || []).map(name => ({ name, label: '', quantity: 1, unitPrice: 0, currency: 'SAR' }));
    const serviceRows = detailed.length ? detailed : [{ name: '', label: '', quantity: 1, unitPrice: 0, currency: 'SAR' }];
    const currency = serviceRows[0]?.currency === 'USD' ? 'USD' : 'SAR';
    const contractMonths = [...new Set(serviceRows.map(item => Number(item.durationMonths || 0)).filter(months => months > 0))];
    const contractRates = [...new Set(serviceRows.map(item => Number(item.durationDiscount ?? item.annualDiscount ?? 0)).filter(rate => rate > 0))];
    const contractLabel = contractMonths.length === 1 ? `${contractMonths[0]} month${contractMonths[0] === 1 ? '' : 's'}` : '';
    const contractRate = contractRates.length === 1 ? contractRates[0] : (contractMonths[0] === 6 ? 5 : (contractMonths[0] === 12 ? 10 : 0));
    const detectedMonths = contractMonths.length === 1 ? contractMonths[0] : 1;
    const detectedType = detectedMonths > 1 ? 'long_term' : 'one_time';
    const defaultContractStart = new Date().toISOString().slice(0, 10);
    const contractNotice = contractLabel ? `<div class="vtp-alert"><strong>Contract term detected: ${esc(contractLabel)}${contractRate ? ` · ${contractRate}% discount` : ''}.</strong> The service prices below already include the full contract duration and applicable discount.</div>` : '';
    const defaultTerms = contractLabel ? `Contract term: ${contractLabel}${contractRate ? ` with a ${contractRate}% contract discount included in the listed prices.` : '.'} Work begins after written approval. Payment is due monthly upon submission of each month's completed services. Additional scope may require a revised quotation.` : 'Work begins after written approval. Payment is due after final delivery according to the agreed payment term. Additional scope may require a revised quotation.';
    const itemRow = (item, index) => `<div class="vtp-quote-item"><input type="hidden" name="itemTerm" value="${esc(item.term || 'once')}"><input type="hidden" name="itemDurationMonths" value="${Number(item.durationMonths || 0)}"><input type="hidden" name="itemDurationDiscount" value="${Number(item.durationDiscount ?? item.annualDiscount ?? 0)}"><input type="hidden" name="itemAnnualDiscount" value="${Number(item.annualDiscount ?? item.durationDiscount ?? 0)}"><input type="hidden" name="itemPackageName" value="${esc(item.packageName || '')}"><input name="itemLabel" value="${esc(item.label || '')}" placeholder="Label" aria-label="Service label"><input name="itemDescription" value="${esc(item.name || item.description || '')}" placeholder="Service or deliverable" required><input name="itemQuantity" type="number" min="0.01" step="0.01" value="${Number(item.quantity || 1)}" aria-label="Quantity"><input name="itemPrice" type="number" min="0" step="0.01" value="${Number(item.unitPrice || 0)}" placeholder="Unit price" aria-label="Unit price" required>${index ? '<button type="button" class="vtp-drive-remove" data-remove-quote-item aria-label="Remove item">×</button>' : ''}</div>`;
    const pdfUploadBlock = `<div class="vtp-field" id="vtp-quote-pdf-upload" hidden><label>Quotation PDF</label><input type="file" id="vtp-quote-pdf-file" accept="application/pdf"><small id="vtp-quote-pdf-status">Upload the PDF designed in Illustrator (or another tool). We'll try to read the services and total from it and fill them in below — review and fix them before saving. The PDF is kept attached to this quotation for reference.</small></div>`;
    openDrawer(`<h2>Create quotation</h2><p>Review the requested quantities, service labels and prices before sending the secure approval link.</p><div class="vtp-quote-mode-toggle" role="tablist"><button type="button" class="vtp-btn small active" data-quote-mode="form" aria-pressed="true">Build from form</button><button type="button" class="vtp-btn small" data-quote-mode="pdf" aria-pressed="false">Upload PDF quotation</button></div>${contractNotice}<form id="vtp-quotation-form" class="vtp-form"><input type="hidden" name="inquiryId" value="${inquiry?.id || ''}"><input type="hidden" name="sourceType" value="form"><input type="hidden" name="sourceFileUrl" value="">${pdfUploadBlock}<div class="vtp-form-row"><div class="vtp-field"><label>Client name</label><input name="clientName" value="${esc(inquiry?.client_name || '')}" required></div><div class="vtp-field"><label>Company</label><input name="company" value="${esc(inquiry?.company || '')}"></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Email</label><input name="email" type="email" value="${esc(inquiry?.email || '')}" required></div><div class="vtp-field"><label>Phone</label><input name="phone" value="${esc(inquiry?.phone || '')}"></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Currency</label><select name="currency"><option value="SAR" ${currency === 'SAR' ? 'selected' : ''}>SAR</option><option value="USD" ${currency === 'USD' ? 'selected' : ''}>USD</option></select></div><div class="vtp-field"><label>Valid until</label><input name="validUntil" type="date" value="${validUntil}" required></div></div><div class="vtp-field"><div class="vtp-card-head"><label>Contract and billing cycle</label><span class="vtp-admin-note">Long-term quotes create one master plus monthly drafts.</span></div><div class="vtp-form-row"><div class="vtp-field"><label>Quotation type</label><select name="contractType"><option value="one_time" ${detectedType === 'one_time' ? 'selected' : ''}>One-time quotation</option><option value="long_term" ${detectedType === 'long_term' ? 'selected' : ''}>Long-term monthly quotation</option></select></div><div class="vtp-field"><label>Number of months</label><input name="contractMonths" type="number" min="1" max="60" value="${detectedMonths}" required></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Contract start date</label><input name="contractStart" type="date" value="${defaultContractStart}"></div><div class="vtp-field"><label>Contract end date</label><input name="contractEnd" type="date" readonly><small>Calculated automatically from the start date and number of months.</small></div></div></div><div class="vtp-field"><div class="vtp-card-head"><label>Services and pricing</label><button type="button" class="vtp-btn small" data-add-quote-item>＋ Add item</button></div><div class="vtp-quote-item-head"><span>Label</span><span>Service / deliverable</span><span>Qty</span><span>Unit price</span></div><div id="vtp-quote-items" class="vtp-quote-items">${serviceRows.map(itemRow).join('')}</div></div><div class="vtp-field"><label>VAT rate (%)</label><input name="vatRate" type="number" min="0" max="100" step="0.01" value="15"></div><div class="vtp-field"><label>Terms</label><textarea name="terms" rows="5">${esc(defaultTerms)}</textarea></div><div class="vtp-field"><label>Notes</label><textarea name="notes" rows="3" placeholder="Optional client-facing notes"></textarea></div><button class="vtp-btn primary">Save quotation</button></form>`);
  }

  function quotationPaperLegacy(quote) {
    const items = Array.isArray(quote.items) ? quote.items : [];
    return `<article class="vtp-quote-paper"><header><img src="${esc(VTP.logo)}" alt="Vibes Agency"><div><span>QUOTATION</span><strong>${esc(quote.quote_number)}</strong></div></header><section class="vtp-quote-hero"><div><p class="vtp-eyebrow">CREATIVE SERVICES PROPOSAL</p><h1>Ideas made<br><span>to move.</span></h1></div><dl><div><dt>Issued</dt><dd>${fmt(quote.created_at)}</dd></div><div><dt>Valid until</dt><dd>${fmt(quote.valid_until)}</dd></div><div><dt>Currency</dt><dd>${esc(quote.currency)}</dd></div></dl></section><section class="vtp-quote-client"><div><small>PREPARED FOR</small><h2>${esc(quote.company || quote.client_name)}</h2><p>${esc(quote.client_name)}<br>${esc(quote.email)}${quote.phone ? `<br>${esc(quote.phone)}` : ''}</p></div><div><small>PREPARED BY</small><h2>Vibes Agency</h2><p>Saudi Arabia<br>info@vibesagency.co<br>vibesagency.co</p></div></section><table><thead><tr><th>Service / deliverable</th><th>Qty</th><th>Unit price</th><th>Total</th></tr></thead><tbody>${items.map(item => `<tr><td>${esc(item.description)}</td><td>${Number(item.quantity)}</td><td>${money(item.unitPrice, quote.currency)}</td><td>${money(item.total, quote.currency)}</td></tr>`).join('')}</tbody></table><section class="vtp-quote-summary"><div>${quote.notes ? `<small>NOTES</small><p>${esc(quote.notes)}</p>` : ''}</div><dl><div><dt>Subtotal</dt><dd>${money(quote.subtotal, quote.currency)}</dd></div><div><dt>VAT (${Number(quote.vat_rate)}%)</dt><dd>${money(quote.vat_amount, quote.currency)}</dd></div><div class="total"><dt>Total</dt><dd>${money(quote.total, quote.currency)}</dd></div></dl></section><section class="vtp-quote-terms"><small>TERMS</small><p>${esc(quote.terms || '')}</p></section><footer><strong>We don’t follow trends. We set vibes.</strong><span>vibesagency.co · info@vibesagency.co</span></footer></article>`;
  }

  function quotationPaper(quote) {
    const reference = window.VTPQuoteReference?.render(quote, VTP.quoteBackground);
    if (reference) return reference;
    const items = Array.isArray(quote.items) ? quote.items : [];
    const explicitMonths = Number(quote.contract_months || 0);
    const contractMonths = explicitMonths > 1 ? [explicitMonths] : [...new Set(items.map(item => Number(item.durationMonths || 0)).filter(months => months > 1))];
    const contractRates = [...new Set(items.map(item => Number(item.durationDiscount ?? item.annualDiscount ?? 0)).filter(rate => rate > 0))];
    const contractLabel = Number(quote.period_index || 0) > 0 ? `Month ${Number(quote.period_index)} of ${explicitMonths || contractMonths[0]}` : (contractMonths.length === 1 ? `${contractMonths[0]} month${contractMonths[0] === 1 ? '' : 's'}` : '');
    const contractRate = Number(quote.discount_rate || 0) || (contractRates.length === 1 ? contractRates[0] : 0);
    const contractBlock = contractLabel ? `<section class="vtp-quote-contract"><div><small>${Number(quote.period_index || 0) > 0 ? 'MONTHLY PERIOD' : 'CONTRACT TERM'}</small><strong>${esc(contractLabel)}</strong></div><div><small>DISCOUNT</small><strong>${contractRate ? `${contractRate}% included` : 'Included in prices'}</strong></div><p>${Number(quote.period_index || 0) > 0 ? 'This quotation covers the listed deliverables for this monthly service period.' : `Prices shown cover the full contract term${contractRate ? ' and include the applicable contract discount' : ''}.`}</p></section>` : '';
    const clientLocation = quote.country || quote.location || '';
    const discountRow = Number(quote.discount_amount || 0) > 0 ? `<div><dt>Discount (${Number(quote.discount_rate || 0)}%)</dt><dd>-${money(quote.discount_amount, quote.currency)}</dd></div>` : '';
    return `<article class="vtp-quote-paper"><header><img src="${esc(VTP.logo)}" alt="Vibes Agency"><div class="vtp-quote-heading"><span>QUOTATION</span><strong aria-hidden="true">${esc(quote.quote_number)}</strong><dl><div><dt>Issued</dt><dd>${fmt(String(quote.created_at || '').slice(0,10))}</dd></div><div><dt>Valid until</dt><dd>${fmt(quote.valid_until)}</dd></div><div><dt>Currency</dt><dd>${esc(quote.currency)}</dd></div></dl></div></header><section class="vtp-quote-client"><div><small>PREPARED FOR</small><h2>${esc(quote.company || quote.client_name)}</h2><p>${quote.company ? `${esc(quote.client_name)}<br>` : ''}${clientLocation ? esc(clientLocation) + '<br>' : ''}${esc(quote.email)}${quote.phone ? `<br>${esc(quote.phone)}` : ''}</p></div><div><small>PREPARED BY</small><h2>Vibes Agency</h2><p>Beirut, Lebanon<br>finance@vibesagency.co<br>+9615271247&nbsp;&nbsp;+96170606046</p></div></section>${contractBlock}<table><thead><tr><th>No.</th><th>Label</th><th>Service / deliverable</th><th>Qty</th><th>Unit price</th><th>Total</th></tr></thead><tbody>${items.map((item,index) => `<tr><td>${index + 1}</td><td><strong>${esc(item.label || '—')}</strong></td><td>${esc(item.description)}</td><td>${Number(item.quantity)}</td><td>${money(item.unitPrice, quote.currency)}</td><td>${money(item.total, quote.currency)}</td></tr>`).join('')}</tbody></table><section class="vtp-quote-summary"><div></div><dl><div><dt>Subtotal</dt><dd>${money(quote.subtotal, quote.currency)}</dd></div>${discountRow}<div><dt>VAT (${Number(quote.vat_rate)}%)</dt><dd>${money(quote.vat_amount, quote.currency)}</dd></div><div class="total"><dt>Total</dt><dd>${money(quote.total, quote.currency)}</dd></div></dl></section><section class="vtp-quote-terms"><small>TERMS</small><p>${esc(quote.terms || '')}</p></section><div class="vtp-quote-accent" aria-hidden="true"><i></i><i></i><i></i></div><footer><strong>We don’t follow trends, <b>WE SET VIBES.</b></strong><span>vibesagency.co</span></footer></article>`;
  }

  function previewQuotation(quote) {
    const samples=String(quote.quote_number || '').startsWith('VQ-SAMPLE') ? '<div class="vtp-card-actions" aria-label="Sample quotation term"><button class="vtp-btn small" data-preview-template="once">One-time project</button><button class="vtp-btn small" data-preview-template="m6">6 months</button><button class="vtp-btn small" data-preview-template="year">1 year</button></div>' : '';
    openDrawer(`<div class="vtp-print-actions">${samples}<button class="vtp-btn primary" id="vtp-print-quote">Print / Save PDF</button></div>${quotationPaper(quote)}`);
  }

  async function quoteProjectDrawerLegacy(quote) {
    const employees = await load('employees', true);
    openDrawer(`<h2>Activate project</h2><p>Create an active project from ${esc(quote.quote_number)}. Tasks can then be assigned to employees.</p><form id="vtp-quote-project-form" class="vtp-form"><input type="hidden" name="quotationId" value="${quote.id}"><div class="vtp-field"><label>Project name</label><input name="name" value="${esc((quote.company || quote.client_name) + ' — ' + quote.quote_number)}" required></div><div class="vtp-field"><label>Project lead</label><select name="leadUserId"><option value="">Unassigned</option>${employees.map(user => `<option value="${user.id}">${esc(user.name)} — ${esc(user.position || user.role)}</option>`).join('')}</select></div><div class="vtp-field"><label>Delivery date</label><input name="dueDate" type="date"></div><div class="vtp-alert">This marks the quotation approved and copies its services into the project brief.</div><button class="vtp-btn primary">Create active project</button></form>`);
  }

  async function quoteProjectDrawer(quote) {
    if (quote.status !== 'approved') {
      toast('Approve the quotation before creating the project.', true);
      return;
    }
    const employees = (await load('employees', true)).filter(user => user.active);
    const items = Array.isArray(quote.items) ? quote.items : [];
      const dueDate = dateAfter(14);
    const employeeOptions = employees.map(user => `<option value="${user.id}">${esc(user.name)} — ${esc(user.position || user.role)}</option>`).join('');
    const assignments = items.map((item, index) => `<div class="vtp-assignment-row" data-item-index="${index}"><div><strong>${esc(item.label || `Service ${index + 1}`)}</strong><span>${esc(item.description)}</span><small>Qty ${Number(item.quantity || 1)} · ${money(item.total, quote.currency)}</small></div><label>Assign to<select name="assignmentAssignee" required><option value="">Select employee</option>${employeeOptions}</select></label><label>Due date<input name="assignmentDue" type="date" value="${dueDate}" required></label><label>Priority<select name="assignmentPriority"><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select></label></div>`).join('');
    openDrawer(`<h2>Assign quotation services</h2><p>Create one task for each approved service. Every service can go to a different employee. Yearly services automatically receive twelve monthly delivery tasks.</p><form id="vtp-quote-project-form" class="vtp-form"><input type="hidden" name="quotationId" value="${quote.id}"><div class="vtp-field"><label>Project name</label><input name="name" value="${esc((quote.company || quote.client_name) + ' — ' + quote.quote_number)}" required></div><div class="vtp-form-row"><div class="vtp-field"><label>Project lead</label><select name="leadUserId"><option value="">Unassigned</option>${employeeOptions}</select></div><div class="vtp-field"><label>Overall delivery date</label><input name="dueDate" type="date" value="${dueDate}"></div></div><div class="vtp-field"><label>Internal Vibes Drive folder (optional)</label><input name="internalDriveUrl" type="url" value="${esc(driveTaskFolder)}" placeholder="Paste the project folder link"><small>The portal stores the folder plan and links each service task to its delivery cycle.</small></div><div class="vtp-field"><label>Client Drive folder (optional)</label><input name="clientDriveUrl" type="url" placeholder="Add the private client folder link when ready"></div><div class="vtp-field"><label>Service assignments</label><div class="vtp-assignment-list">${assignments}</div></div><div class="vtp-alert">Employees see only their own tasks. Once a delivery cycle is complete, send the client a secure review link. Invoice creation waits for client approval.</div><button class="vtp-btn primary">Create project, tasks & notifications</button></form>`);
  }

  async function projectManageDrawer(project, publish = false) {
    const structure = project.driveStructure || {};
    const months = structure.months || [];
    const defaultMonth = project.client_review_month || months.find(item => item.key)?.key || '';
    const internalDriveUrl = project.drive_internal_url || driveTaskFolder;
    const mappedDriveQuickLink = `<section class="vtp-project-drive-link"><div><small>MAPPED PROJECT FOLDER</small><strong>Vibes Active Projects</strong><p>Clients and employees use the Drive link. On your PC, the same folder is in your mirrored Google Drive:</p><small data-mapped-path-status data-folder="${esc(internalDriveUrl)}"></small></div><a class="vtp-btn small" href="${esc(internalDriveUrl)}" target="_blank" rel="noopener noreferrer">Open mapped Drive folder ↗</a></section>`;
    openDrawer(`<h2>${publish ? 'Send delivery for client review' : 'Manage project'}</h2><p>${esc(project.name)} · ${esc(project.client || 'Internal client')}</p>${mappedDriveQuickLink}<form id="vtp-project-review-form" class="vtp-form"><input type="hidden" name="projectId" value="${project.id}"><input type="hidden" name="publish" value="${publish ? '1' : '0'}"><div class="vtp-field"><label>Internal Vibes Drive folder</label><input name="internalDriveUrl" type="url" value="${esc(internalDriveUrl)}" placeholder="Paste the internal project folder link"></div><div class="vtp-field"><label>Private client Drive folder</label><input name="clientDriveUrl" type="url" value="${esc(project.drive_client_url || '')}" placeholder="Paste the folder shared only with the client and admin"></div>${months.length ? `<div class="vtp-field"><label>Delivery cycle to send</label><select name="month">${months.map(item => `<option value="${esc(item.key)}" ${item.key === defaultMonth ? 'selected' : ''}>${esc(item.label)}</option>`).join('')}</select><small>Only completed tasks in the selected cycle will be sent for review.</small></div>` : '<input type="hidden" name="month" value="">'}<div class="vtp-alert">The client receives a secure review page where they can open delivered files, comment, request changes, approve the work, or ask for a meeting or direct call.</div><button class="vtp-btn primary">${publish ? 'Send client review link' : 'Save project links'}</button></form>${project.status === 'finished' ? `<p class="vtp-alert">This project is archived as finished.</p>` : ''}`);
  }

  async function invoiceDrawer() {
    const [quotes, projects] = await Promise.all([load('quotations', true), load('projects', true)]);
    openDrawer(`<h2>Create delivery invoice</h2><p>Issue this after the work has been delivered.</p><form id="vtp-invoice-form" class="vtp-form"><div class="vtp-field"><label>Related quotation</label><select name="quotationId" data-invoice-quote><option value="">No quotation</option>${quotes.map(quote => `<option value="${quote.id}">${esc(quote.quote_number)} — ${esc(quote.company || quote.client_name)}</option>`).join('')}</select></div><div class="vtp-field"><label>Related project</label><select name="projectId"><option value="">No project</option>${projects.map(project => `<option value="${project.id}">${esc(project.name)}</option>`).join('')}</select></div><div class="vtp-form-row"><div class="vtp-field"><label>Client</label><input name="clientName" required></div><div class="vtp-field"><label>Email</label><input name="email" type="email" required></div></div><div class="vtp-form-row"><div class="vtp-field"><label>Currency</label><select name="currency"><option value="USD">USD</option></select></div><div class="vtp-field"><label>Total</label><input name="total" type="number" min="0.01" step="0.01" required></div></div><div class="vtp-field"><label>Due date</label><input name="dueDate" type="date" required></div><div class="vtp-field"><label>Payment link</label><input name="paymentLink" type="url" placeholder="Add the payment link when available"></div><button class="vtp-btn primary">Create invoice</button></form>`);
  }

  async function taskDrawer() {
    await Promise.all([load('employees', true), load('projects', true)]);
    const suggested = `VT-${todayIso().replaceAll('-', '')}-${String(Math.floor(Math.random() * 900) + 100)}`;
    openDrawer(`<h2>Create task</h2><p>Add it to the calendar and notify the assigned employee.</p><form id="vtp-task-form" class="vtp-form"><div class="vtp-form-row"><div class="vtp-field"><label>Task number</label><input name="taskNumber" value="${suggested}" required></div><div class="vtp-field"><label>Client name</label><input name="clientName" required></div></div><div class="vtp-field"><label>Task title</label><input name="title" required></div><div class="vtp-field"><label>Project</label><select name="projectId"><option value="">Internal / no project</option>${state.projects.map(project => `<option value="${project.id}">${esc(project.name)}</option>`).join('')}</select></div><div class="vtp-field"><label>Description</label><textarea name="description" rows="4" required></textarea></div><div class="vtp-field"><label>Assign to</label><select name="assigneeId" required><option value="">Select employee</option>${state.employees.filter(user => user.active && user.role !== 'admin').map(user => `<option value="${user.id}">${esc(user.name)} — ${esc(user.position)}</option>`).join('')}</select></div><div class="vtp-form-row"><div class="vtp-field"><label>Calendar date / deadline</label><input type="date" name="dueDate" required></div><div class="vtp-field"><label>Status</label><select name="status" disabled><option>To do</option></select></div></div><div class="vtp-field"><label>Priority</label><select name="priority"><option value="medium">Medium</option><option value="high">High</option><option value="low">Low</option></select></div><div class="vtp-field"><label>Google Drive links</label><div id="vtp-drive-links" class="vtp-drive-links"><div class="vtp-drive-row"><input name="driveLabel" value="Project files" aria-label="Link label"><input name="driveUrl" type="url" placeholder="Paste a Google Drive file or folder link" aria-label="Google Drive link"></div></div><div class="vtp-drive-actions"><button type="button" class="vtp-btn small" data-add-drive-link>＋ Add another link</button><a href="${driveTaskFolder}" target="_blank" rel="noopener noreferrer">Open Active Projects in Vibes Drive ↗</a></div><small>Create or open the client project in Vibes Drive, then paste up to five file or folder links here. The portal stores only the links.</small></div><div class="vtp-alert">The employee receives a notification now. When they complete it, administrators receive a notification.</div><button class="vtp-btn primary">Create task & notify</button></form>`);
  }

  function taskSubmissionDrawer(task) {
    openDrawer(`<h2>Submit completed work</h2><p>${esc(task.task_number)} · ${esc(task.title)}</p><form id="vtp-task-submission-form" class="vtp-form"><input type="hidden" name="taskId" value="${task.id}"><div class="vtp-field"><label>Delivery note</label><textarea name="note" rows="4" placeholder="Describe what was completed and anything the reviewer should know."></textarea></div><div class="vtp-field"><label>Google Drive delivery link</label><div class="vtp-drive-links"><div class="vtp-drive-row"><input name="driveLabel" value="Completed work" aria-label="Link label"><input name="driveUrl" type="url" placeholder="Paste the final Drive file or folder link" aria-label="Google Drive link"></div></div><small>Add a delivery note, a Google Drive link, or both.</small></div><div class="vtp-alert">The administrator will be notified. When all quoted services have been submitted, a draft invoice is generated automatically from the approved quotation.</div><button class="vtp-btn primary">Submit work for review</button></form>`);
  }

  async function projectDrawer() {
    await load('employees', true);
    openDrawer(`<h2>New project</h2><form id="vtp-project-form" class="vtp-form"><div class="vtp-field"><label>Project name</label><input name="name" required></div><div class="vtp-field"><label>Client</label><input name="client"></div><div class="vtp-field"><label>Brief</label><textarea name="description" rows="4"></textarea></div><div class="vtp-field"><label>Project lead</label><select name="leadUserId"><option value="">Unassigned</option>${state.employees.map(user => `<option value="${user.id}">${esc(user.name)}</option>`).join('')}</select></div><div class="vtp-field"><label>Due date</label><input type="date" name="dueDate"></div><button class="vtp-btn primary">Create project</button></form>`);
  }

  function leaveDrawer() {
    openDrawer('<h2>Request time off</h2><p class="vtp-muted">Vacation accrues at 1.25 days per month (15 days per employment year). Sick leave is limited to 10 days per employment year.</p><form id="vtp-leave-form" class="vtp-form"><div class="vtp-field"><label>Leave type</label><select name="leaveType"><option value="vacation">Vacation</option><option value="sick">Sick leave</option><option value="remote">Remote work</option><option value="other">Other</option></select></div><div class="vtp-form-row"><div class="vtp-field"><label>Start date</label><input type="date" name="startDate" required></div><div class="vtp-field"><label>End date</label><input type="date" name="endDate" required></div></div><div class="vtp-field"><label>Reason</label><textarea name="reason" rows="3"></textarea></div><div class="vtp-field"><label>Handover notes</label><textarea name="handoverNotes" rows="3"></textarea></div><button class="vtp-btn primary">Submit request</button></form>');
  }

  function holidayDrawer() {
    openDrawer(`<h2>Add company holiday</h2><p>The holiday will appear on every employee calendar.</p><form id="vtp-holiday-form" class="vtp-form"><div class="vtp-field"><label>Holiday name</label><input name="name" required></div><div class="vtp-form-row"><div class="vtp-field"><label>Start date</label><input type="date" name="date" required></div><div class="vtp-field"><label>End date</label><input type="date" name="endDate"></div></div><div class="vtp-field"><label>Description</label><textarea name="description" rows="3"></textarea></div><button class="vtp-btn primary">Add to calendar</button></form>`);
  }

  function announcementDrawer(item = null) {
    const editing = item && item.id;
    const publishAt = item && item.publish_at ? String(item.publish_at).replace(' ', 'T').slice(0, 16) : '';
    const formId = editing ? ` data-announcement-id="${Number(item.id)}"` : '';
    openDrawer(`<h2>${editing ? 'Edit announcement' : 'New announcement'}</h2><p>${editing ? 'Update the message without creating a duplicate.' : 'Share a clear update with the team.'}</p><form id="vtp-announcement-form" class="vtp-form"${formId}><div class="vtp-field"><label>Title</label><input name="title" value="${esc(item?.title || '')}" required></div><div class="vtp-field"><label>Message</label><textarea name="content" rows="6" required>${esc(String(item?.content || '').replace(/<[^>]*>/g, ''))}</textarea></div><div class="vtp-form-row"><div class="vtp-field"><label>Audience</label><select name="audience"><option value="everyone" ${!item || item.audience === 'everyone' ? 'selected' : ''}>Everyone</option><option value="creative" ${item?.audience === 'creative' ? 'selected' : ''}>Creative team</option><option value="managers" ${item?.audience === 'managers' ? 'selected' : ''}>Managers</option></select></div><div class="vtp-field"><label>Publish time <span class="vtp-muted">(optional)</span></label><input name="publishAt" type="datetime-local" value="${esc(publishAt)}"><small>Leave blank to publish immediately.</small></div></div><div class="vtp-field"><label>Resource link <span class="vtp-muted">(optional)</span></label><input name="resourceUrl" type="url" placeholder="https://…"><small>Add a secure link to a document or project update.</small></div><label><input type="checkbox" name="isPinned" ${item?.is_pinned == 1 ? 'checked' : ''}> Pin announcement</label><button class="vtp-btn primary">${editing ? 'Save changes' : 'Publish announcement'}</button></form>`);
  }
  async function notifications() {
    const rows = await api('notifications');
    state.boot.stats.unread = 0;
    $('#vtp-unread').classList.remove('show');
    const targetFor = item => {
      if (item.object_type === 'leave' || String(item.type || '').startsWith('leave_')) return '#leave';
      if (item.object_type === 'task' || String(item.type || '').startsWith('task_')) return '#tasks';
      if (item.object_type === 'inquiry' || String(item.type || '').includes('inquiry')) return '#inquiries';
      if (item.object_type === 'quotation' || String(item.type || '').includes('quotation')) return '#quotations';
      if (item.object_type === 'invoice' || String(item.type || '').includes('invoice')) return '#invoices';
      if (item.object_type === 'project' || String(item.type || '').includes('project')) return '#projects';
      return '#dashboard';
    };
    openDrawer(`<h2>Notifications</h2><p>Tasks, approvals and team updates.</p><div class="vtp-list">${rows.length ? rows.map(item => `<button type="button" class="vtp-list-row vtp-notification-row" data-notification-target="${targetFor(item)}"><div><strong>${esc(item.title)}</strong><small>${esc(item.body || '')} · ${fmtDateTime(item.created_at)}</small></div>${chip(item.type)}</button>`).join('') : empty('You’re all caught up.')}</div>`);
  }

  function toggleUserMenu(force) {
    const menu = $('#vtp-user-dropdown');
    const button = $('#vtp-user-menu');
    const open = typeof force === 'boolean' ? force : menu.hidden;
    menu.hidden = !open;
    button.setAttribute('aria-expanded', String(open));
  }

  document.addEventListener('click', async event => {
    const userButton = event.target.closest('#vtp-user-menu');
    if (userButton) { toggleUserMenu(); return; }
    if (!event.target.closest('.vtp-user-wrap')) toggleUserMenu(false);

    const refreshAnnouncements = event.target.closest('[data-refresh-announcements]');
    if (refreshAnnouncements) { state.announcements = []; await routes.announcements(); return; }
    const annEdit = event.target.closest('[data-edit-announcement]');
    if (annEdit) { const item = state.announcements.find(row => Number(row.id) === Number(annEdit.dataset.editAnnouncement)); if (item) announcementDrawer(item); return; }
    const annArchive = event.target.closest('[data-archive-announcement]');
    if (annArchive) { if (!window.confirm('Archive this announcement? It will no longer appear to the team.')) return; await api(`announcements/${Number(annArchive.dataset.archiveAnnouncement)}/archive`, { method: 'POST' }); state.announcements = []; toast('Announcement archived.'); await routes.announcements(); return; }
    const annDelete = event.target.closest('[data-delete-announcement]');
    if (annDelete) { if (!window.confirm('Delete this announcement permanently?')) return; await api(`announcements/${Number(annDelete.dataset.deleteAnnouncement)}`, { method: 'DELETE' }); state.announcements = []; toast('Announcement deleted.'); await routes.announcements(); return; }
    const calendarShift = event.target.closest('[data-calendar-shift]');
    if (calendarShift) {
      const targetMonth = calendarMonth();
      targetMonth.setUTCMonth(targetMonth.getUTCMonth() + Number(calendarShift.dataset.calendarShift || 0));
      location.hash = calendarMonthHash(targetMonth);
      return;
    }
    if (event.target.closest('[data-calendar-today]')) { location.hash = '#calendar'; return; }
    const statTarget = event.target.closest('[data-stat-target]');
    if (statTarget) {
      if (pricingCategoryId() !== null && ['vtp-service-catalog','vtp-pricing-categories'].includes(statTarget.dataset.statTarget)) {
        await showPricingCategory();
        return;
      }
      const target = document.getElementById(statTarget.dataset.statTarget);
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }

    const open = event.target.closest('[data-open]');
    if (open) {
      const type = open.dataset.open;
      if (type === 'employee') employeeDrawer();
      if (type === 'inquiry') inquiryDrawer();
      if (type === 'quotation') await quotationDrawer();
      if (type === 'invoice') await invoiceDrawer();
      if (type === 'task') await taskDrawer();
      if (type === 'project') await projectDrawer();
      if (type === 'leave') leaveDrawer();
      if (type === 'holiday') holidayDrawer();
      if (type === 'announcement') announcementDrawer();
      if (type === 'category') categoryDrawer();
      if (type === 'service') serviceDrawer(null, open.dataset.categoryId || pricingCategoryId());
      if (type === 'package') packageDrawer();
      return;
    }
    if (event.target.closest('.vtp-drawer-close') || event.target === drawer) closeDrawer();

    const notification = event.target.closest('[data-notification-target]');
    if (notification) {
      closeDrawer();
      location.hash = notification.dataset.notificationTarget || '#dashboard';
      await route();
      return;
    }

    const addDriveLink = event.target.closest('[data-add-drive-link]');
    if (addDriveLink) {
      const links = $('#vtp-drive-links');
      if (links.children.length >= 5) { toast('You can add up to five Google Drive links.', true); return; }
      links.insertAdjacentHTML('beforeend', `<div class="vtp-drive-row"><input name="driveLabel" placeholder="Link label" aria-label="Link label"><input name="driveUrl" type="url" placeholder="Paste a Google Drive file or folder link" aria-label="Google Drive link"><button type="button" class="vtp-drive-remove" data-remove-drive-link aria-label="Remove Drive link">×</button></div>`);
      return;
    }
    const removeDriveLink = event.target.closest('[data-remove-drive-link]');
    if (removeDriveLink) { removeDriveLink.closest('.vtp-drive-row')?.remove(); return; }

    if (event.target.closest('[data-add-package-item]')) {
      $('#vtp-package-items').insertAdjacentHTML('beforeend', packageItemRow());
      return;
    }
    const removePackageItem = event.target.closest('[data-remove-package-item]');
    if (removePackageItem) { removePackageItem.closest('.vtp-package-item-row')?.remove(); return; }

    const saveService = event.target.closest('[data-save-service]');
    if (saveService) {
      const form = saveService.closest('form');
      if (!form || !form.reportValidity()) return;
      try {
        const data = formObject(form);
    if (formId === 'vtp-employee-edit-form' || formId === 'vtp-employee-form') {
      data.salaryAmount = data.hrSalaryAmount || '';
      data.salaryCurrency = data.hrSalaryCurrency || 'USD';
      data.employmentStatus = data.hrEmploymentStatus || 'probation';
      data.annualLeaveEntitlement = data.hrAnnualLeave || '15';
      delete data.hrSalaryAmount;
      delete data.hrSalaryCurrency;
      delete data.hrEmploymentStatus;
      delete data.hrAnnualLeave;
    }
        const payload = { ...data, categoryId: Number(data.categoryId), ...catalogPricePayload(form), sortOrder: Number(data.sortOrder || 0), custom: data.priceDisplay === 'request', startingPrice: data.priceDisplay === 'from', active: form.elements.active.checked };
        const path = data.id ? `catalog/services/${data.id}` : 'catalog/services';
        state.catalog = await api(path, { method: 'POST', body: JSON.stringify(payload) });
        closeDrawer(); toast('Service saved. The calculator will use the new price.'); await showPricingCategory(payload.categoryId);
      } catch (error) { toast(error.message, true); }
      return;
    }
    const editCategory = event.target.closest('[data-edit-category]');
    if (editCategory) { categoryDrawer(state.catalog.categories.find(item => Number(item.id) === Number(editCategory.dataset.editCategory))); return; }
    const editService = event.target.closest('[data-edit-service]');
    if (editService) { serviceDrawer(state.catalog.services.find(item => Number(item.id) === Number(editService.dataset.editService))); return; }
    const editPackage = event.target.closest('[data-edit-package]');
    if (editPackage) { packageDrawer(state.catalog.packages.find(item => Number(item.id) === Number(editPackage.dataset.editPackage))); return; }

    for (const type of ['category', 'service', 'package']) {
      const target = event.target.closest(`[data-delete-${type}]`);
      if (!target) continue;
      if (!confirm(`Delete this ${type}?`)) return;
      try {
        const plural = type === 'category' ? 'categories' : `${type}s`;
        state.catalog = await api(`catalog/${plural}/${target.dataset[`delete${type[0].toUpperCase()}${type.slice(1)}`]}`, { method: 'DELETE' });
        toast(`${type[0].toUpperCase()}${type.slice(1)} deleted.`);
        if (type === 'category') await showPricingCategory(); else await route();
      } catch (error) { toast(error.message, true); }
      return;
    }

    const deleteMap = {
      inquiry: { path: 'inquiries', state: ['inquiries', 'quotations'], message: 'Delete this inquiry? Any linked quotation will be kept but unlinked.' },
      quotation: { path: 'quotations', state: ['quotations', 'projects', 'invoices'], message: 'Delete this quotation? Linked projects, tasks and invoices will be kept but unlinked.' },
      project: { path: 'projects', state: ['projects', 'tasks', 'invoices'], message: 'Delete this project and its assigned tasks? Issued invoices will be kept but unlinked.' },
      task: { path: 'tasks', state: ['tasks', 'projects', 'invoices'], message: 'Delete this task permanently?' },
      invoice: { path: 'invoices', state: ['invoices', 'projects'], message: 'Delete this invoice permanently? Any payment recorded for it will also be removed from Finance.' },
    };
    for (const [type, config] of Object.entries(deleteMap)) {
      const target = event.target.closest(`[data-delete-${type}]`);
      if (!target) continue;
      if (!confirm(config.message)) return;
      try {
        const key = `delete${type[0].toUpperCase()}${type.slice(1)}`;
        const path = `${config.path}/${target.dataset[key]}`;
        try {
          await api(path, { method: 'DELETE' });
        } catch (error) {
          if (type !== 'quotation' || !/Google Drive/i.test(error.message)) throw error;
          if (!confirm('Google Drive could not remove this quotation’s PDF. Delete the quotation from the portal anyway? The PDF will stay in Drive for you to remove manually.')) return;
          await api(`${path}?force=1`, { method: 'DELETE' });
        }
        config.state.forEach(name => { state[name] = []; });
        toast(`${type[0].toUpperCase()}${type.slice(1)} deleted.`);
        await route();
      } catch (error) { toast(error.message, true); }
      return;
    }

    const quoteMode = event.target.closest('[data-quote-mode]');
    if (quoteMode) {
      // The mode-toggle buttons sit above <form id="vtp-quotation-form">
      // (not inside it), so look the form up by id rather than ancestry.
      const form = document.getElementById('vtp-quotation-form');
      if (!form) return;
      const mode = quoteMode.dataset.quoteMode;
      form.querySelectorAll('[data-quote-mode]').forEach(button => {
        const active = button === quoteMode;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', String(active));
      });
      const upload = form.querySelector('#vtp-quote-pdf-upload');
      if (upload) upload.hidden = mode !== 'pdf';
      if (form.elements.sourceType) form.elements.sourceType.value = mode === 'pdf' ? 'pdf_upload' : 'form';
      return;
    }

    const addQuoteItem = event.target.closest('[data-add-quote-item]');
    if (addQuoteItem) {
      $('#vtp-quote-items').insertAdjacentHTML('beforeend', `<div class="vtp-quote-item"><input type="hidden" name="itemTerm" value="once"><input type="hidden" name="itemDurationMonths" value="0"><input type="hidden" name="itemDurationDiscount" value="0"><input type="hidden" name="itemAnnualDiscount" value="0"><input type="hidden" name="itemPackageName" value=""><input name="itemDescription" placeholder="Service or deliverable" required><input name="itemQuantity" type="number" min="0.01" step="0.01" value="1" aria-label="Quantity"><input name="itemPrice" type="number" min="0" step="0.01" placeholder="Unit price" aria-label="Unit price" required><button type="button" class="vtp-drive-remove" data-remove-quote-item aria-label="Remove item">×</button></div>`);
      return;
    }
    const removeQuoteItem = event.target.closest('[data-remove-quote-item]');
    if (removeQuoteItem) { removeQuoteItem.closest('.vtp-quote-item')?.remove(); return; }

    const viewInquiry = event.target.closest('[data-view-inquiry]');
    if (viewInquiry) {
      const row = state.inquiries.find(item => Number(item.id) === Number(viewInquiry.dataset.viewInquiry));
      if (row) viewInquiryDrawer(row);
      return;
    }
    const quoteInquiry = event.target.closest('[data-quote-inquiry]');
    if (quoteInquiry) { await quotationDrawer(Number(quoteInquiry.dataset.quoteInquiry)); return; }
    const inquiryStatus = event.target.closest('[data-inquiry-status]');
    if (inquiryStatus) {
      try {
        await api(`inquiries/${inquiryStatus.dataset.inquiryStatus}/status`, { method: 'PATCH', body: JSON.stringify({ status: inquiryStatus.dataset.status }) });
        state.inquiries = []; toast('Inquiry updated.'); await route();
      } catch (error) { toast(error.message, true); }
      return;
    }
    const previewQuote = event.target.closest('[data-preview-quote]');
    if (previewQuote) {
      const quote = state.quotations.find(item => Number(item.id) === Number(previewQuote.dataset.previewQuote));
      if (quote) previewQuotation(quote);
      return;
    }
    const templateButton = event.target.closest('[data-preview-template]');
    if (templateButton) {
      previewQuotation(window.VTPQuoteReference.sample(templateButton.dataset.previewTemplate || 'once'));
      return;
    }
    if (event.target.closest('#vtp-print-quote')) { window.print(); return; }
    const sendQuote = event.target.closest('[data-send-quote]');
    if (sendQuote) {
      try {
        sendQuote.disabled = true;
        sendQuote.textContent = 'Sending…';
        const result = await api(`quotations/${sendQuote.dataset.sendQuote}/send`, { method: 'POST', body: '{}' });
        state.quotations = [];
        toast(result.message || 'Quotation sent to the client.');
        await route();
      } catch (error) { sendQuote.disabled = false; sendQuote.textContent = 'Send to client'; toast(error.message, true); }
      return;
    }
    const copyReview = event.target.closest('[data-copy-review]');
    if (copyReview) {
      const quote = state.quotations.find(item => Number(item.id) === Number(copyReview.dataset.copyReview));
      if (quote?.approval_token) {
        const url = `${location.origin}/quotation-review/?token=${quote.approval_token}`;
        try { await navigator.clipboard.writeText(url); toast('Secure client review link copied.'); }
        catch (error) { window.prompt('Copy the secure client review link:', url); }
      }
      return;
    }
    const quoteStatus = event.target.closest('[data-quote-status]');
    if (quoteStatus) {
      try {
        await api(`quotations/${quoteStatus.dataset.quoteStatus}/status`, { method: 'PATCH', body: JSON.stringify({ status: quoteStatus.dataset.status }) });
        state.quotations = []; toast('Quotation status updated.'); await route();
      } catch (error) { toast(error.message, true); }
      return;
    }
    const quoteProject = event.target.closest('[data-quote-project]');
    if (quoteProject) {
      const quote = state.quotations.find(item => Number(item.id) === Number(quoteProject.dataset.quoteProject));
      if (quote) await quoteProjectDrawer(quote);
      return;
    }
    const openProject = event.target.closest('[data-open-project]');
    if (openProject) {
      const project = state.projects.find(item => Number(item.id) === Number(openProject.dataset.openProject));
      if (project) await projectManageDrawer(project);
      return;
    }
    const projectReview = event.target.closest('[data-project-review]');
    if (projectReview) {
      const project = state.projects.find(item => Number(item.id) === Number(projectReview.dataset.projectReview));
      if (project) await projectManageDrawer(project, true);
      return;
    }
    const copyProjectReview = event.target.closest('[data-copy-project-review]');
    if (copyProjectReview) {
      const project = state.projects.find(item => Number(item.id) === Number(copyProjectReview.dataset.copyProjectReview));
      if (project?.client_review_token) {
        const url = `${location.origin}/project-review/?token=${project.client_review_token}`;
        try { await navigator.clipboard.writeText(url); toast('Secure project review link copied.'); }
        catch (error) { window.prompt('Copy the secure project review link:', url); }
      }
      return;
    }
    const invoiceStatus = event.target.closest('[data-invoice-status]');
    if (invoiceStatus) {
      try {
        const nextStatus = invoiceStatus.dataset.status;
        let payload = { status: nextStatus };
        if (nextStatus === 'paid') {
          const defaultDate = invoiceStatus.dataset.invoiceDate || new Date().toISOString().slice(0, 10);
          const paidAt = window.prompt('Payment date (YYYY-MM-DD)', defaultDate);
          if (paidAt === null) return;
          if (!/^\d{4}-\d{2}-\d{2}$/.test(paidAt)) { toast('Enter the payment date as YYYY-MM-DD.', true); return; }
          payload.paidAt = paidAt;
        }
        const result = await api(`invoices/${invoiceStatus.dataset.invoiceStatus}/status`, { method: 'PATCH', body: JSON.stringify(payload) });
        state.invoices = []; toast(result?.payment?.synced ? 'Invoice status updated. Payment synced to Finance Overview.' : 'Invoice status updated.'); await route();
      } catch (error) { toast(error.message, true); }
      return;
    }

    const submitTask = event.target.closest('[data-submit-task]');
    if (submitTask) {
      const row = state.tasks.find(item => Number(item.id) === Number(submitTask.dataset.submitTask));
      if (row) taskSubmissionDrawer(row);
      return;
    }

    const task = event.target.closest('[data-task]');
    if (task) {
      try {
        const result = await api(`tasks/${task.dataset.task}/status`, { method: 'PATCH', body: JSON.stringify({ status: task.dataset.status }) });
        state.tasks = [];
        state.projects = []; state.invoices = [];
        toast(result.projectReady ? 'All project tasks are submitted. The project is ready for client review.' : (task.dataset.status === 'done' ? 'Deliverable approved.' : 'Task started.'));
        await route();
      } catch (error) { toast(error.message, true); }
      return;
    }

    const leave = event.target.closest('[data-leave]');
    if (leave) {
      try {
        await api(`leave/${leave.dataset.leave}/review`, { method: 'PATCH', body: JSON.stringify({ status: leave.dataset.status }) });
        state.leave = [];
        toast(`Leave request ${leave.dataset.status}.`);
        await route();
      } catch (error) { toast(error.message, true); }
    }

    const viewEmployee = event.target.closest('[data-view-employee]');
    if (viewEmployee) {
      const row = state.employees.find(item => Number(item.id) === Number(viewEmployee.dataset.viewEmployee));
      if (row) employeeProfileDrawer(row);
      return;
    }
    const editEmployee = event.target.closest('[data-edit-employee]');
    if (editEmployee) {
      const row = state.employees.find(item => Number(item.id) === Number(editEmployee.dataset.editEmployee));
      if (row) employeeEditDrawer(row);
      return;
    }
    const toggleEmployee = event.target.closest('[data-toggle-employee]');
    if (toggleEmployee) {
      const row = state.employees.find(item => Number(item.id) === Number(toggleEmployee.dataset.toggleEmployee));
      if (!row) return;
      const restoring = toggleEmployee.dataset.currentActive === 'false';
      if (!confirm(restoring ? 'Restore this employee account?' : 'Deactivate this employee account? Their records will be preserved.')) return;
      try {
        await api(`employees/${row.id}`, { method: 'POST', body: JSON.stringify({ ...row, active: restoring, role: String(row.role || 'employee').toLowerCase() === 'manager' ? 'manager' : 'employee', availability: row.availability || 'available', skills: row.skills || '' }) });
        state.employees = [];
        toast(restoring ? 'Employee account restored.' : 'Employee account deactivated.');
        await route();
      } catch (error) { toast(error.message, true); }
      return;
    }

    const deleteHoliday = event.target.closest('[data-delete-holiday]');
    if (deleteHoliday && confirm('Delete this company holiday?')) {
      try {
        await api(`holidays/${deleteHoliday.dataset.deleteHoliday}`, { method: 'DELETE' });
        state.holidays = [];
        toast('Holiday deleted.');
        await route();
      } catch (error) { toast(error.message, true); }
    }

    const deleteFileClient = event.target.closest('[data-delete-file-client]');
    if (deleteFileClient) {
      if (!confirm('Remove this client folder link from the portal? The Google Drive folder itself will not be deleted.')) return;
      try {
        state.filesBrand = await api(`files-brand/client/${encodeURIComponent(deleteFileClient.dataset.deleteFileClient)}`, { method: 'DELETE' });
        toast('Client folder link removed.');
        await route();
      } catch (error) { toast(error.message, true); }
    }
  });

  document.addEventListener('change', async event => {
    const input = event.target.closest('[data-files-upload-key]');
    if (!input || !input.files?.[0]) return;
    try {
      const form = new FormData();
      form.append('key', input.dataset.filesUploadKey);
      form.append('file', input.files[0]);
      input.disabled = true;
      state.filesBrand = await api('files-brand/upload', { method: 'POST', body: form });
      toast('File uploaded and available to the team.');
      await route();
    } catch (error) { toast(error.message, true); input.disabled = false; }
  });

  document.addEventListener('change', async event => {
    const fileInput = event.target.closest('#vtp-quote-pdf-file');
    if (!fileInput || !fileInput.files?.[0]) return;
    const file = fileInput.files[0];
    const form = fileInput.closest('#vtp-quotation-form');
    const status = form?.querySelector('#vtp-quote-pdf-status');
    const setStatus = (message, isError = false) => { if (status) { status.textContent = message; status.classList.toggle('vtp-field-error', isError); } };
    if (!form) return;
    fileInput.disabled = true;
    setStatus('Reading the PDF…');
    try {
      const upload = new FormData();
      upload.append('file', file);
      const uploaded = await api('quotations/source-upload', { method: 'POST', body: upload }).catch(error => { toast(error.message, true); return null; });
      if (uploaded?.url && form.elements.sourceFileUrl) form.elements.sourceFileUrl.value = uploaded.url;

      const text = await extractPdfText(file).catch(() => '');
      const { items, total } = parseQuotationPdfText(text);
      const itemsContainer = form.querySelector('#vtp-quote-items');
      if (items.length && itemsContainer) {
        itemsContainer.innerHTML = items.map((item, index) => `<div class="vtp-quote-item"><input type="hidden" name="itemTerm" value="once"><input type="hidden" name="itemDurationMonths" value="0"><input type="hidden" name="itemDurationDiscount" value="0"><input type="hidden" name="itemAnnualDiscount" value="0"><input type="hidden" name="itemPackageName" value=""><input name="itemLabel" placeholder="Label" aria-label="Service label"><input name="itemDescription" value="${esc(item.description)}" placeholder="Service or deliverable" required><input name="itemQuantity" type="number" min="0.01" step="0.01" value="${Number(item.quantity || 1)}" aria-label="Quantity"><input name="itemPrice" type="number" min="0" step="0.01" value="${Number(item.unitPrice || 0)}" placeholder="Unit price" aria-label="Unit price" required>${index ? '<button type="button" class="vtp-drive-remove" data-remove-quote-item aria-label="Remove item">×</button>' : ''}</div>`).join('');
        setStatus(`Found ${items.length} service${items.length === 1 ? '' : 's'}${total ? ` and a total of ${money(total, form.elements.currency?.value || 'SAR')}` : ''} in the PDF. Review every row below — quantities, prices and wording may need fixing — before saving.`);
      } else {
        setStatus("Couldn't read service lines from this PDF automatically (this can happen with outlined/vector artwork). Add the services and prices below yourself — the PDF stays attached to this quotation for reference.", true);
      }
    } catch (error) {
      setStatus('Could not read this PDF. Add the services and prices below yourself — the PDF stays attached to this quotation for reference.', true);
    } finally {
      fileInput.disabled = false;
    }
  });

  document.addEventListener('change', event => {
    const announcementFilter = event.target.closest?.('[data-ann-filter]');
    if (announcementFilter) { state.announcementFilters[announcementFilter.dataset.annFilter] = announcementFilter.value; if (window.VTPAnnouncementRender) window.VTPAnnouncementRender(); return; }
    const field = event.target.closest?.('[name="contractType"], [name="contractMonths"], [name="contractStart"]');
    if (!field) return;
    const form = field.closest('#vtp-quotation-form');
    if (!form) return;
    const type = form.elements.contractType?.value || 'one_time';
    const months = Math.max(1, Number(form.elements.contractMonths?.value || 1));
    const start = form.elements.contractStart?.value || '';
    const end = form.elements.contractEnd;
    if (!end || type !== 'long_term' || months < 2 || !start) { if (end) end.value = ''; return; }
    const date = new Date(`${start}T00:00:00`);
    date.setMonth(date.getMonth() + months);
    date.setDate(date.getDate() - 1);
    end.value = date.toISOString().slice(0, 10);
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const statTarget = event.target.closest?.('[data-stat-target]');
    if (!statTarget) return;
    event.preventDefault();
    const target = document.getElementById(statTarget.dataset.statTarget);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    // A named id input shadows form.id on HTMLFormElement.
    const formId = form.getAttribute('id');
    const data = formObject(form);
    if (formId === 'vtp-employee-filter-form') {
      state.employeeFilters = { query: data.query || '', role: data.role || 'all', availability: data.availability || 'all', status: data.status || 'active' };
      await route();
      return;
    }
    try {
      if (formId === 'vtp-avatar-form') {
        const output = await api('profile/avatar', { method: 'POST', body: new FormData(form) });
        state.boot.user.avatar = output.avatar;
        $('#vtp-top-avatar').src = output.avatar;
        state.employees = [];
        toast('Profile photo updated.');
        await route();
        return;
      }
      if (formId === 'vtp-profile-details-form') {
        state.boot.user = await api('profile/details', { method: 'POST', body: JSON.stringify(data) });
        state.employees = [];
        toast('Profile details saved.');
        await route();
        return;
      }
      if (formId === 'vtp-files-link-form' || form.matches('[data-files-link-form]')) {
        state.filesBrand = await api('files-brand', { method: 'POST', body: JSON.stringify(data) });
        toast('Shared link saved.');
        await route();
        return;
      }
      if (form.matches('[data-quotation-template-form]')) {
        state.filesBrand = await api('quotation-templates', { method: 'POST', body: JSON.stringify(data) });
        toast('Quotation sample link saved.');
        await route();
        return;
      }
      if (formId === 'vtp-files-client-form' || form.matches('[data-files-client-form]')) {
        const logoInput = form.querySelector('[data-client-logo]');
        delete data.logoFile;
        let filesBrand = await api('files-brand', { method: 'POST', body: JSON.stringify(data) });
        const clientId = data.id || filesBrand.clients?.find(item => item.name === data.name)?.id;
        if (logoInput?.files?.[0] && clientId) {
          const upload = new FormData();
          upload.append('key', `client_logo_${clientId}`);
          upload.append('file', logoInput.files[0]);
          filesBrand = await api('files-brand/upload', { method: 'POST', body: upload });
        }
        state.filesBrand = filesBrand;
        toast(data.id ? 'Client profile updated.' : 'Client profile added.');
        await route();
        return;
      }
      if (formId === 'vtp-employee-edit-form') {
        data.active = Boolean(form.elements.active?.checked);
        await api(`employees/${Number(data.id)}`, { method: 'POST', body: JSON.stringify(data) });
        state.employees = [];
        closeDrawer();
        toast('Employee profile updated.');
        await route();
        return;
      }
      if (formId === 'vtp-employee-form') {
        const output = await api('employees', { method: 'POST', body: JSON.stringify(data) });
        state.employees = [];
        openDrawer(`<h2>Account created</h2><div class="vtp-alert">Copy this temporary password now. It is shown only once.</div><div class="vtp-card"><strong>${esc(output.employee.email)}</strong><p class="vtp-temp-password">${esc(output.temporaryPassword)}</p></div><button class="vtp-btn primary vtp-drawer-close">Done</button>`);
        toast('Employee account created.');
      }
      if (formId === 'vtp-category-form') {
        const payload = { ...data, sortOrder: Number(data.sortOrder || 0), active: form.elements.active.checked };
        const path = data.id ? `catalog/categories/${data.id}` : 'catalog/categories';
        state.catalog = await api(path, { method: 'POST', body: JSON.stringify(payload) });
        closeDrawer(); toast('Category saved.'); await showPricingCategory(Number(data.id) || [...state.catalog.categories].reverse().find(item => item.name === data.name.trim())?.id || null); return;
      }
      if (formId === 'vtp-service-form') {
        const payload = { ...data, categoryId: Number(data.categoryId), ...catalogPricePayload(form), sortOrder: Number(data.sortOrder || 0), custom: data.priceDisplay === 'request', startingPrice: data.priceDisplay === 'from', active: form.elements.active.checked };
        const path = data.id ? `catalog/services/${data.id}` : 'catalog/services';
        state.catalog = await api(path, { method: data.id ? 'POST' : 'POST', body: JSON.stringify(payload) });
        closeDrawer(); toast('Service saved. The calculator will use the new price.'); await showPricingCategory(payload.categoryId); return;
      }
      if (formId === 'vtp-package-form') {
        const items = [...form.querySelectorAll('.vtp-package-item-row')].map(row => ({ serviceId: Number(row.querySelector('[name="packageServiceId"]')?.value || 0), quantity: Number(row.querySelector('[name="packageQuantity"]')?.value || 1) })).filter(item => item.serviceId);
        if (!items.length) throw new Error('Add at least one service to the package.');
        const payload = { ...data, ...catalogPricePayload(form), sortOrder: Number(data.sortOrder || 0), active: form.elements.active.checked, items };
        const path = data.id ? `catalog/packages/${data.id}` : 'catalog/packages';
        state.catalog = await api(path, { method: 'POST', body: JSON.stringify(payload) });
        closeDrawer(); toast('Package saved.'); location.hash = '#pricing'; await route(); return;
      }
      if (formId === 'vtp-inquiry-form') {
        await api('inquiries', { method: 'POST', body: JSON.stringify(data) });
        state.inquiries = []; closeDrawer(); toast('Client inquiry created.'); location.hash = '#inquiries'; await route();
      }
      if (formId === 'vtp-quotation-form') {
        const items = [...form.querySelectorAll('.vtp-quote-item')].map(row => ({
          description: row.querySelector('[name="itemDescription"]')?.value.trim() || '',
          quantity: Number(row.querySelector('[name="itemQuantity"]')?.value || 1),
          unitPrice: Number(row.querySelector('[name="itemPrice"]')?.value || 0),
          term: row.querySelector('[name="itemTerm"]')?.value || 'once',
          durationMonths: Number(row.querySelector('[name="itemDurationMonths"]')?.value || 0),
          durationDiscount: Number(row.querySelector('[name="itemDurationDiscount"]')?.value || row.querySelector('[name="itemAnnualDiscount"]')?.value || 0),
          annualDiscount: Number(row.querySelector('[name="itemAnnualDiscount"]')?.value || row.querySelector('[name="itemDurationDiscount"]')?.value || 0),
          packageName: row.querySelector('[name="itemPackageName"]')?.value || ''
        })).filter(item => item.description);
        const quoteRows = [...form.querySelectorAll('.vtp-quote-item')];
        items.forEach((item, index) => { item.label = quoteRows[index]?.querySelector('[name="itemLabel"]')?.value.trim().toUpperCase() || ''; });
        const result = await api('quotations', { method: 'POST', body: JSON.stringify({ ...data, items }) });
        state.quotations = []; state.inquiries = []; closeDrawer();
        toast(Number(result.monthlyCount || 0) ? `Quotation saved with ${Number(result.monthlyCount)} monthly drafts.` : 'Quotation saved.');
        location.hash = '#quotations'; await route();
      }
      if (formId === 'vtp-quote-project-form') {
        const assignments = [...form.querySelectorAll('.vtp-assignment-row')].map(row => ({
          itemIndex: Number(row.dataset.itemIndex),
          assigneeId: Number(row.querySelector('[name="assignmentAssignee"]')?.value || 0),
          dueDate: row.querySelector('[name="assignmentDue"]')?.value || '',
          priority: row.querySelector('[name="assignmentPriority"]')?.value || 'medium'
        }));
        const result = await api(`quotations/${data.quotationId}/project`, { method: 'POST', body: JSON.stringify({ ...data, assignments }) });
        state.quotations = []; state.projects = []; state.tasks = []; closeDrawer();
        toast(`Project created with ${Number(result.taskCount || assignments.length)} separately assigned service tasks.`);
        location.hash = '#tasks'; await route();
      }
      if (formId === 'vtp-project-review-form') {
        const projectId = data.projectId;
        const result = data.publish === '1'
          ? await api(`projects/${projectId}/review`, { method: 'POST', body: JSON.stringify({ month: data.month || '', internalDriveUrl: data.internalDriveUrl || '', clientDriveUrl: data.clientDriveUrl || '' }) })
          : await api(`projects/${projectId}/drive`, { method: 'PATCH', body: JSON.stringify({ internalDriveUrl: data.internalDriveUrl || '', clientDriveUrl: data.clientDriveUrl || '' }) });
        state.projects = [];
        closeDrawer();
        toast(result.message || (data.publish === '1' ? 'Client review link sent.' : 'Project Drive links saved.'));
        location.hash = '#projects';
        await route();
        return;
      }
      if (formId === 'vtp-invoice-form') {
        await api('invoices', { method: 'POST', body: JSON.stringify(data) });
        state.invoices = []; closeDrawer(); toast('Invoice created.'); location.hash = '#invoices'; await route();
      }
      if (formId === 'vtp-task-form') {
        const driveLinks = [...form.querySelectorAll('.vtp-drive-row')].map(row => ({
          name: row.querySelector('[name="driveLabel"]')?.value.trim() || 'Google Drive',
          url: row.querySelector('[name="driveUrl"]')?.value.trim() || ''
        })).filter(link => link.url);
        await api('tasks', { method: 'POST', body: JSON.stringify({ ...data, driveLinks }) });
        state.tasks = [];
        closeDrawer();
        toast('Task added to the calendar and employee notified.');
        location.hash = '#tasks';
        await route();
      }
      if (formId === 'vtp-task-submission-form') {
        const driveLinks = [...form.querySelectorAll('.vtp-drive-row')].map(row => ({
          name: row.querySelector('[name="driveLabel"]')?.value.trim() || 'Completed work',
          url: row.querySelector('[name="driveUrl"]')?.value.trim() || ''
        })).filter(link => link.url);
        const result = await api(`tasks/${data.taskId}/submit`, { method: 'POST', body: JSON.stringify({ note: data.note, driveLinks }) });
        state.tasks = []; state.projects = []; state.invoices = [];
        closeDrawer();
        toast(result.projectReady ? 'Work submitted. All project tasks are in, so the project is ready for client review.' : 'Work submitted and the administrator was notified.');
        location.hash = '#tasks';
        await route();
      }
      if (formId === 'vtp-project-form') {
        await api('projects', { method: 'POST', body: JSON.stringify(data) });
        state.projects = [];
        closeDrawer();
        toast('Project created.');
        location.hash = '#projects';
        await route();
      }
      if (formId === 'vtp-leave-form') {
        await api('leave', { method: 'POST', body: JSON.stringify(data) });
        state.leave = [];
        closeDrawer();
        toast('Leave request submitted.');
        location.hash = '#leave';
        await route();
      }
      if (formId === 'vtp-holiday-form') {
        await api('holidays', { method: 'POST', body: JSON.stringify(data) });
        state.holidays = [];
        closeDrawer();
        toast('Holiday added to every calendar.');
        await route();
      }
      if (formId === 'vtp-announcement-form') {
        data.isPinned = Boolean(new FormData(form).get('isPinned'));
        const resourceUrl = String(data.resourceUrl || '').trim();
        delete data.resourceUrl;
        if (resourceUrl) {
          try { const parsed = new URL(resourceUrl); if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('Use a secure http or https resource link.'); } catch (error) { throw new Error('Enter a valid resource link.'); }
          data.content = String(data.content || '') + `\n\n<a href="${resourceUrl}">Open the shared resource</a>`;
        }
        const announcementId = form.dataset.announcementId;
        await api(announcementId ? `announcements/${announcementId}` : 'announcements', { method: announcementId ? 'PATCH' : 'POST', body: JSON.stringify(data) });
        state.announcements = [];
        closeDrawer();
        toast(announcementId ? 'Announcement updated.' : (data.publishAt ? 'Announcement scheduled.' : 'Announcement published.'));
        location.hash = '#announcements';
        await route();
      }
      if (formId === 'vtp-password-form') {
        if (data.password !== data.confirm) throw new Error('Passwords do not match.');
        await api('profile/password', { method: 'POST', body: JSON.stringify({ password: data.password, currentPassword: data.currentPassword }) });
        location.href = VTP.logout;
      }
    } catch (error) { toast(error.message, true); }
  });

  document.addEventListener('change', event => {
    if (event.target.id === 'vtp-avatar-input' && event.target.files?.[0]) $('#vtp-profile-preview').src = URL.createObjectURL(event.target.files[0]);
    if (event.target.matches('[data-invoice-quote]')) {
      const quote = state.quotations.find(item => Number(item.id) === Number(event.target.value));
      const form = event.target.closest('form');
      if (quote && form) {
        form.elements.clientName.value = quote.company || quote.client_name;
        form.elements.email.value = quote.email;
        form.elements.currency.value = quote.currency;
        form.elements.total.value = quote.total;
      }
    }
  });

  document.addEventListener('input', event => {
    syncCatalogPrice(event.target);
    const announcementFilter = event.target.closest?.('[data-ann-filter]');
    if (announcementFilter) { state.announcementFilters[announcementFilter.dataset.annFilter] = announcementFilter.value; if (window.VTPAnnouncementRender) window.VTPAnnouncementRender(); }
  });

  $('#vtp-notification-button').addEventListener('click', notifications);
  // Registered with VTPR.onRoute (the one place that decides what renders for the current
  // hash) rather than a raw hashchange listener. This is the fallback router: it always
  // claims the route, so it must register after the modules plugin's own onRoute call,
  // which checks its own narrower route set first. If VTPR isn't present for some reason
  // (modules plugin deactivated), fall back to a plain hashchange listener so routing still
  // works.
  if (window.VTPR) { window.VTPR.onRoute(() => { route(); return true; }); } else { window.addEventListener('hashchange', route); }
  // Returning to the portal after opening a file picker or another browser
  // window must not re-run the current route. The old focus/visibility
  // listeners rebuilt the content-management view as soon as the window
  // regained focus, which cleared unsaved file fields and looked like a
  // continual refresh. Route changes still happen through hashchange and
  // explicit actions, while users can use the existing refresh controls when
  // they need to fetch newer data.
  boot().catch(error => { view.innerHTML = empty(error.message); });
  [800, 1800, 3500].forEach(delay => window.setTimeout(installConnectLauncher, delay));
  document.addEventListener('click', event => {
    if (!event.target.closest('[data-add-quote-item]')) return;
    window.setTimeout(() => {
      document.querySelectorAll('#vtp-quote-items .vtp-quote-item').forEach(row => {
        if (row.querySelector('[name="itemLabel"]')) return;
        const label = document.createElement('input');
        label.name = 'itemLabel';
        label.placeholder = 'Label';
        label.setAttribute('aria-label', 'Service label');
        row.insertBefore(label, row.firstChild);
      });
    }, 0);
  });
})();


/* Vibes Finance payslip builder: generate a print-ready payslip from employee and leave records. */
(() => {
  'use strict';
  const RATE = 3.75;
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
  const today = () => new Date().toISOString().slice(0, 10);
  const currentPeriod = () => new Date().toISOString().slice(0, 7);
  const labelPeriod = value => {
    const match = String(value || '').match(/^(\d{4})-(\d{2})$/);
    if (!match) return String(value || '');
    return new Date(Number(match[1]), Number(match[2]) - 1, 1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
  };
  const money = value => `${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD`;
  const api = async path => {
    const root = window.VTP?.root || '/wp-json/vibes/v1/';
    const nonce = window.VTP?.nonce || '';
    const endpoint = `${root}${path}${root.includes('?') ? '&' : '?'}vtp_payslip_refresh=${Date.now()}`;
    const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': nonce } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'Unable to load payroll records.');
    return data;
  };
  const daysInMonth = period => {
    const [year, month] = String(period || currentPeriod()).split('-').map(Number);
    return new Date(year, month, 0).getDate();
  };
  const overlapDays = (start, end, period) => {
    const [year, month] = String(period || currentPeriod()).split('-').map(Number);
    const from = new Date(year, month - 1, 1);
    const to = new Date(year, month - 1, daysInMonth(period));
    const a = new Date(String(start || '').slice(0, 10) + 'T12:00:00');
    const b = new Date(String(end || start || '').slice(0, 10) + 'T12:00:00');
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return 0;
    const left = a > from ? a : from;
    const right = b < to ? b : to;
    return right < left ? 0 : Math.floor((right - left) / 86400000) + 1;
  };
  const salaryUsd = employee => {
    const amount = Number(employee?.salaryAmount || employee?.salary_amount || 0);
    return String(employee?.salaryCurrency || employee?.salary_currency || 'USD').toUpperCase() === 'SAR' ? amount / RATE : amount;
  };
  const payslipIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-3-2z"></path><path d="M8 8h8M8 12h8M8 16h5"></path></svg>';
  const injectStyle = () => {
    if (document.querySelector('#vtp-payslip-inline-style')) return;
    const style = document.createElement('style');
    style.id = 'vtp-payslip-inline-style';
    style.textContent = `.vtp-payslip-layout{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:20px;align-items:start}.vtp-payslip-controls{position:sticky;top:18px}.vtp-payslip-controls .vtp-form{gap:13px}.vtp-payslip-controls label{display:grid;gap:6px;color:#554b68;font-size:11px;font-weight:800}.vtp-payslip-controls input,.vtp-payslip-controls select{width:100%;min-width:0;border:1px solid #ded3eb;border-radius:9px;padding:10px 11px;background:#fff;color:#29232f;font:inherit;font-weight:500}.vtp-payslip-controls .vtp-btn{width:100%;justify-content:center}.vtp-payslip-note{display:block;color:#80758d;font-size:11px;line-height:1.45}.vtp-payslip-preview-wrap{min-width:0;padding:12px;border:1px solid #e7deef;border-radius:16px;background:#f7f4fa;overflow:auto}.vtp-payslip-sheet{position:relative;min-width:620px;min-height:800px;padding:34px 42px 24px;overflow:hidden;background:#fff;color:#29232f;box-shadow:0 12px 32px rgba(65,35,100,.11);font-family:Arial,Helvetica,sans-serif}.vtp-payslip-top{display:flex;align-items:flex-start;justify-content:space-between;gap:30px}.vtp-payslip-brand{display:flex;align-items:center;gap:10px;color:#17151a}.vtp-payslip-brand>div{display:flex;flex-direction:column;line-height:.8}.vtp-payslip-brand strong{font-size:43px;letter-spacing:-.06em}.vtp-payslip-brand small{margin-left:36px;color:#5b5560;font-size:17px;letter-spacing:.12em}.vtp-payslip-mark{display:grid;width:34px;height:55px;place-items:center;border-radius:16px 16px 18px 8px;background:linear-gradient(145deg,#d6a7d1 5%,#69409c 58%,#2e6a9f 100%);color:#fff;font-size:30px;font-weight:800;transform:rotate(15deg)}.vtp-payslip-title{min-width:235px}.vtp-payslip-title h2{display:flex;align-items:center;justify-content:flex-end;gap:8px;margin:0 0 20px;color:#222;font-size:31px;letter-spacing:.02em}.vtp-payslip-title h2 span{display:block;width:70px;height:7px;background:#633494;border-left:6px solid #ef765d}.vtp-payslip-meta{display:grid;grid-template-columns:auto auto;gap:8px 18px;padding-left:2px;font-size:12px}.vtp-payslip-meta b{color:#806895}.vtp-payslip-meta strong{text-align:right;font-size:14px}.vtp-payslip-identity{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:72px}.vtp-payslip-identity>div{display:flex;justify-content:space-between;gap:10px;padding:15px 18px;border-left:2px solid #633494;background:#faf7fc}.vtp-payslip-identity b{color:#633494;font-size:15px}.vtp-payslip-identity strong{overflow-wrap:anywhere;color:#6e6476;font-size:15px;text-align:right}.vtp-payslip-metrics{display:grid;grid-template-columns:repeat(3,1fr);margin-top:48px;border-bottom:1px solid #ef765d}.vtp-payslip-metrics>div{display:grid;gap:14px;justify-items:center;padding:12px 9px 17px;text-align:center}.vtp-payslip-metrics b{width:100%;padding:10px 8px;border-radius:16px;background:#633494;color:#fff;font-size:12px;line-height:1.25}.vtp-payslip-metrics strong{color:#633494;font-size:17px}.vtp-payslip-footer-row{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-top:44px}.vtp-payslip-payment{display:grid;gap:5px;color:#806895;font-size:13px}.vtp-payslip-payment b{color:#633494;font-size:12px}.vtp-payslip-payment span{display:flex;align-items:center;gap:7px}.vtp-payslip-payment i{width:6px;height:6px;background:#ef765d}.vtp-payslip-total{display:flex;align-items:center;gap:40px;padding:17px 24px;border:1px solid #e6d5f1;border-radius:19px;color:#633494}.vtp-payslip-total b,.vtp-payslip-total strong{font-size:20px}.vtp-payslip-watermark{position:absolute;right:40%;bottom:165px;color:#f7f3f9;font-size:170px;font-weight:800;line-height:1;transform:rotate(-20deg);pointer-events:none}.vtp-payslip-bottom{position:absolute;right:42px;bottom:22px;left:42px;display:flex;justify-content:space-between;padding-top:12px;border-top:5px solid transparent;border-image:linear-gradient(90deg,#633494 0 34%,#8f72b9 34% 68%,#ef765d 68%) 1;color:#2f2539;font-size:11px}.vtp-payslip-bottom span:last-child{color:#633494}@media(max-width:900px){.vtp-payslip-layout{grid-template-columns:1fr}.vtp-payslip-controls{position:static}.vtp-payslip-preview-wrap{order:2}}@media(max-width:720px){.vtp-payslip-sheet{min-width:0;padding:22px 18px 20px}.vtp-payslip-top{gap:12px}.vtp-payslip-brand strong{font-size:30px}.vtp-payslip-brand small{margin-left:24px;font-size:12px}.vtp-payslip-mark{width:25px;height:41px;font-size:22px}.vtp-payslip-title{min-width:0}.vtp-payslip-title h2{margin-bottom:10px;font-size:20px}.vtp-payslip-title h2 span{width:35px;height:5px}.vtp-payslip-meta{gap:5px 10px;font-size:9px}.vtp-payslip-meta strong{font-size:10px}.vtp-payslip-identity{gap:8px;margin-top:38px}.vtp-payslip-identity>div{display:grid;gap:5px;padding:10px}.vtp-payslip-identity b,.vtp-payslip-identity strong{font-size:11px;text-align:left}.vtp-payslip-metrics{margin-top:28px}.vtp-payslip-metrics>div{padding:7px 3px 11px}.vtp-payslip-metrics b{padding:7px 4px;border-radius:10px;font-size:8px}.vtp-payslip-metrics strong{font-size:12px}.vtp-payslip-footer-row{margin-top:26px}.vtp-payslip-total{gap:12px;padding:11px 12px}.vtp-payslip-total b,.vtp-payslip-total strong{font-size:13px}.vtp-payslip-bottom{right:18px;bottom:14px;left:18px;font-size:8px}.vtp-payslip-watermark{right:34%;bottom:115px;font-size:110px}}@media print{body *{visibility:hidden!important}.vtp-payslip-sheet,.vtp-payslip-sheet *{visibility:visible!important}.vtp-payslip-sheet{position:absolute;top:0;left:0;width:100%;min-height:0;box-shadow:none}.vtp-payslip-preview-wrap{border:0;padding:0;background:#fff}}`;
    style.textContent += '.vtp-payslip-brand img{display:block;width:356px;max-width:100%;height:auto;object-fit:contain;object-position:left center}.vtp-payslip-meta>*{padding:3px 0;border-bottom:1px solid #e2d5eb}.vtp-payslip-preview-wrap{padding:24px;display:flex;justify-content:center;align-items:flex-start}.vtp-payslip-sheet{box-sizing:border-box;width:794px;min-width:794px;height:1123px;min-height:1123px;padding:58px 58px 36px;box-shadow:none}.vtp-payslip-top{gap:26px}.vtp-payslip-title{min-width:250px;height:111px}.vtp-payslip-title h2{font-size:33px;line-height:1;margin-bottom:12px}.vtp-payslip-meta{gap:0 20px;font-size:13px;line-height:1.1}.vtp-payslip-meta strong{font-size:15px;line-height:1.1}.vtp-payslip-identity{margin-top:29px;gap:36px}.vtp-payslip-identity>div{padding:12px 18px}.vtp-payslip-metrics{margin-top:45px}.vtp-payslip-metrics>div{gap:10px;padding:0 8px 12px}.vtp-payslip-metrics b{padding:9px 8px;border-radius:16px;font-size:12px}.vtp-payslip-metrics strong{font-size:17px;line-height:1.2}.vtp-payslip-footer-row{margin-top:31px}.vtp-payslip-total{min-width:250px;justify-content:space-between;gap:22px;padding:17px 24px;border-radius:19px}.vtp-payslip-watermark{right:auto;left:50%;bottom:170px;width:290px;height:420px;color:transparent;font-size:0;opacity:.07;transform:translateX(-50%);background:url("/wp-content/plugins/vibes-team-portal/assets/images/vibes-logo-cropped.png") left center / 1160px auto no-repeat}.vtp-payslip-bottom{right:58px;bottom:28px;left:58px;padding-top:12px;font-size:11px}@media(max-width:900px){.vtp-payslip-preview-wrap{justify-content:flex-start}.vtp-payslip-sheet{width:794px;min-width:794px;height:1123px;min-height:1123px}}@media(max-width:720px){.vtp-payslip-preview-wrap{padding:10px}.vtp-payslip-sheet{width:794px;min-width:794px;height:1123px;min-height:1123px;padding:58px 58px 36px}.vtp-payslip-brand img{width:356px}.vtp-payslip-title{height:111px}.vtp-payslip-title h2{font-size:33px;line-height:1;margin-bottom:12px}.vtp-payslip-meta{gap:0 20px;font-size:13px;line-height:1.1}.vtp-payslip-meta strong{font-size:15px;line-height:1.1}.vtp-payslip-identity{gap:36px;margin-top:29px}.vtp-payslip-identity>div{display:flex;gap:10px;padding:12px 18px}.vtp-payslip-identity b,.vtp-payslip-identity strong{font-size:15px;text-align:initial}.vtp-payslip-metrics{margin-top:45px}.vtp-payslip-metrics>div{gap:10px;padding:0 8px 12px}.vtp-payslip-metrics b{padding:9px 8px;border-radius:16px;font-size:12px}.vtp-payslip-metrics strong{font-size:17px;line-height:1.2}.vtp-payslip-footer-row{margin-top:31px}.vtp-payslip-total{gap:22px;padding:17px 24px}.vtp-payslip-watermark{right:auto;left:50%;bottom:170px;width:290px;height:420px;font-size:0}.vtp-payslip-bottom{right:58px;bottom:28px;left:58px;font-size:11px}}@media print{.vtp-payslip-sheet{width:210mm;min-width:210mm;height:297mm;min-height:297mm;padding:14mm 15.4mm 9mm}.vtp-payslip-preview-wrap{display:block;padding:0}}';
    style.textContent += '.vtp-payslip-metrics{position:relative;overflow:hidden;border-bottom:1px solid #ef765d;border-radius:16px 16px 0 0;background:linear-gradient(to bottom,#633494 0 39px,#fff 39px 100%)}.vtp-payslip-metrics b{width:auto;padding:9px 8px;border-radius:0;background:transparent;font-size:14px;font-weight:700}.vtp-payslip-metrics strong{font-weight:500}.vtp-payslip-total{border-width:1.5px;min-width:198px;width:198px;gap:14px;padding:11px 17px;border-radius:18px}.vtp-payslip-total b,.vtp-payslip-total strong{font-size:17px}.vtp-payslip-payment{padding-top:12px}.vtp-payslip-identity b,.vtp-payslip-identity strong{font-weight:700}.vtp-payslip-brand-logo{display:block;width:224px;height:72px;background:url("/wp-content/plugins/vibes-team-portal/assets/images/vibes-logo-cropped.png") left center / contain no-repeat}.vtp-payslip-title{width:250px;min-width:250px;flex:0 0 250px}.vtp-payslip-title h2{font-size:24px;gap:6px;margin-bottom:12px;width:308px}.vtp-payslip-title h2 span{width:56px;height:16px;border-left-width:5px}.vtp-payslip-meta{width:154px;margin-left:auto;font-size:11px}.vtp-payslip-meta strong{font-size:13px}';
    document.head.appendChild(style);
  };
  const readDrafts = () => { try { return JSON.parse(localStorage.getItem('vtp_payslip_drafts_v1') || '[]') || []; } catch (_) { return []; } };
  const writeDraft = draft => { try { localStorage.setItem('vtp_payslip_drafts_v1', JSON.stringify([draft, ...readDrafts().filter(item => item.key !== draft.key)].slice(0, 12))); } catch (_) {} };
  let employees = [];
  let leave = [];
  let renderToken = 0;

  const employeeLeave = (employeeId, period) => {
    const rows = leave.filter(item => String(item.user_id || item.userId || '') === String(employeeId));
    const days = type => rows.reduce((sum, item) => {
      const kind = String(item.leave_type || item.leaveType || '').toLowerCase();
      const status = String(item.status || 'approved').toLowerCase();
      const counted = ['approved', 'completed', 'taken'].includes(status);
      return kind === type && counted ? sum + overlapDays(item.start_date || item.startDate, item.end_date || item.endDate, period) : sum;
    }, 0);
    return { sick: days('sick'), annual: days('vacation') };
  };

  const previewMarkup = (employee, values) => `<div class="vtp-payslip-sheet" id="vtp-payslip-sheet">
    <div class="vtp-payslip-top"><div class="vtp-payslip-brand"><span class="vtp-payslip-brand-logo" role="img" aria-label="Vibes Agency"></span></div><div class="vtp-payslip-title"><h2>PAYSLIP <span></span></h2><div class="vtp-payslip-meta"><b>PAY PERIOD</b><strong>${esc(labelPeriod(values.period))}</strong><b>ISSUED</b><strong>${esc(values.issuedDate)}</strong><b>CURRENCY</b><strong>USD</strong></div></div></div>
    <div class="vtp-payslip-identity"><div><b>Employee:</b><strong>${esc(employee?.name || 'Employee')}</strong></div><div><b>Email:</b><strong>${esc(employee?.email || '—')}</strong></div></div>
    <div class="vtp-payslip-metrics"><div><b>BASIC SALARY</b><strong>${money(values.salary)}</strong></div><div><b>SICK LEAVES TAKEN</b><strong>${esc(values.sickLeaves)}</strong></div><div><b>ANNUAL LEAVES TAKEN</b><strong>${esc(values.annualLeaves)}</strong></div></div>
    <div class="vtp-payslip-footer-row"><div class="vtp-payslip-payment"><b>Payment Method</b><span><i></i>${esc(values.paymentMethod || 'Bank Transfer')}</span></div><div class="vtp-payslip-total"><b>TOTAL</b><strong>${money(values.total)}</strong></div></div>
    <div class="vtp-payslip-watermark">v</div><div class="vtp-payslip-bottom"><span>We don’t follow trends, <b>WE SET VIBES.</b></span><span>vibesagency.co</span></div>
  </div>`;

  const renderPreview = (form, employee) => {
    const period = form.elements.period?.value || currentPeriod();
    const autoLeave = employeeLeave(employee?.id, period);
    const values = {
      key: `${employee?.id || 'employee'}:${period}`,
      period,
      issuedDate: form.elements.issuedDate?.value || today(),
      salary: Number(form.elements.salary?.value || salaryUsd(employee)),
      sickLeaves: Number(form.elements.sickLeaves?.value ?? autoLeave.sick),
      annualLeaves: Number(form.elements.annualLeaves?.value ?? autoLeave.annual),
      paymentMethod: form.elements.paymentMethod?.value || 'Bank Transfer'
    };
    values.total = values.salary;
    const target = document.querySelector('#vtp-payslip-preview');
    if (target) target.innerHTML = previewMarkup(employee, values);
    writeDraft({ ...values, employeeId: employee?.id, employeeName: employee?.name });
    return values;
  };

  const addNavLink = () => {
    const finance = document.querySelector('#vtp-nav [data-nav-group="finance"]');
    if (!finance || finance.querySelector('[data-route="payslips"]')) return;
    const link = document.createElement('a');
    link.href = '#payslips';
    link.dataset.route = 'payslips';
    link.innerHTML = `<i>${payslipIcon}</i><span>Payslips</span>`;
    finance.appendChild(link);
  };

  const page = async () => {
    const view = document.querySelector('#vtp-view');
    if (!view) return;
    const token = ++renderToken;
    try {
      [employees, leave] = await Promise.all([api('employees'), api('leave')]);
      if (token !== renderToken) return;
      const active = (employees || []).filter(item => item.active !== false && String(item.role || '').toLowerCase() !== 'admin');
      const first = active[0] || employees?.[0] || {};
      const options = active.map(item => `<option value="${esc(item.id)}">${esc(item.name)}${salaryUsd(item) ? ` · ${money(salaryUsd(item))}/mo` : ''}</option>`).join('');
      view.innerHTML = `<section class="vtp-page vtp-payslips-page"><header class="vtp-page-head"><div><h1>Payslips</h1><p>Generate a print-ready monthly payslip from employee compensation and approved leave records.</p></div><span class="vtp-chip">Finance · USD</span></header><div class="vtp-payslip-layout"><article class="vtp-card vtp-payslip-controls"><div class="vtp-card-head"><div><h2>Create payslip</h2><p>Client invoice currency does not affect payroll. Payroll is always shown in USD.</p></div></div><form id="vtp-payslip-form" class="vtp-form"><label>Employee<select name="employeeId" required>${options || '<option value="">No employees found</option>'}</select></label><div class="vtp-form-row"><label>Pay period<input name="period" type="month" value="${currentPeriod()}" required></label><label>Issued date<input name="issuedDate" type="date" value="${today()}" required></label></div><div class="vtp-form-row"><label>Basic salary (USD)<input name="salary" type="number" min="0" step="0.01" value="${salaryUsd(first).toFixed(2)}" required></label><label>Payment method<select name="paymentMethod"><option>Bank Transfer</option><option>Cash</option><option>Whish</option></select></label></div><div class="vtp-form-row"><label>Sick leaves taken<input name="sickLeaves" type="number" min="0" step="1" value="${employeeLeave(first.id, currentPeriod()).sick}"></label><label>Annual leaves taken<input name="annualLeaves" type="number" min="0" step="1" value="${employeeLeave(first.id, currentPeriod()).annual}"></label></div><button class="vtp-btn primary" type="submit">Generate payslip</button><button class="vtp-btn" type="button" data-print-payslip>Print / Save PDF</button><button class="vtp-btn" type="button" data-send-payslip>Send to employee</button><small class="vtp-payslip-note">Send opens a pre-addressed email draft. Save the PDF first if you want to attach the payslip.</small></form></article><article class="vtp-payslip-preview-wrap"><div id="vtp-payslip-preview"></div></article></div></section>`;
      const form = document.querySelector('#vtp-payslip-form');
      if (form) renderPreview(form, first);
    } catch (error) {
      view.innerHTML = `<section class="vtp-page"><div class="vtp-empty"><strong>${esc(error.message || 'Unable to load payslip records.')}</strong></div></section>`;
    }
  };

  document.addEventListener('submit', event => {
    const form = event.target.closest?.('#vtp-payslip-form');
    if (!form) return;
    event.preventDefault();
    const employee = employees.find(item => String(item.id) === String(form.elements.employeeId?.value)) || employees[0] || {};
    renderPreview(form, employee);
  });
  document.addEventListener('change', event => {
    const form = event.target.closest?.('#vtp-payslip-form');
    if (!form) return;
    if (event.target.name === 'employeeId') {
      const employee = employees.find(item => String(item.id) === String(event.target.value)) || {};
      form.elements.salary.value = salaryUsd(employee).toFixed(2);
      const counts = employeeLeave(employee.id, form.elements.period.value);
      form.elements.sickLeaves.value = counts.sick;
      form.elements.annualLeaves.value = counts.annual;
    }
    if (event.target.name === 'period') {
      const employee = employees.find(item => String(item.id) === String(form.elements.employeeId.value)) || {};
      const counts = employeeLeave(employee.id, event.target.value);
      form.elements.sickLeaves.value = counts.sick;
      form.elements.annualLeaves.value = counts.annual;
    }
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('[data-print-payslip]')) return;
    const sheet = document.querySelector('#vtp-payslip-sheet');
    if (!sheet) return;
    window.print();
  });
  document.addEventListener('click', event => {
    if (!event.target.closest('[data-send-payslip]')) return;
    const form = document.querySelector('#vtp-payslip-form');
    if (!form) return;
    const employee = employees.find(item => String(item.id) === String(form.elements.employeeId?.value)) || employees[0] || {};
    if (!employee.email) { window.alert('This employee does not have a work email address.'); return; }
    const values = renderPreview(form, employee);
    const subject = `Payslip - ${labelPeriod(values.period)} - Vibes Agency`;
    const body = `Hello ${employee.name || 'there'},\n\nYour payslip for ${labelPeriod(values.period)} is ready.\n\nTotal: ${money(values.total)}\nPayment method: ${values.paymentMethod}\n\nPlease attach the PDF generated from the Payslips page before sending.`;
    window.location.href = `mailto:${encodeURIComponent(employee.email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
  });
  const watch = () => {
    injectStyle();
    addNavLink();
    if (location.hash.split('?')[0] === '#payslips' && !document.querySelector('.vtp-payslips-page')) window.setTimeout(page, 80);
  };
  VTPR.onHashChange(watch);
  [300, 1000, 2000, 4000].forEach(delay => window.setTimeout(watch, delay));
  const nav = document.querySelector('#vtp-nav');
  if (nav) VTPR.watch(addNavLink);
  const view = document.querySelector('#vtp-view');
  if (view) VTPR.watch(() => { if (location.hash.split('?')[0] === '#payslips' && !view.querySelector('.vtp-payslips-page')) window.setTimeout(page, 60); });
})();


/* Vibes HR preview enhancement: probation, compensation and leave policy */
(() => {
  'use strict';
  const HR_KEY = 'vtp_hr_policy_preview_v1';
  const STYLE_ID = 'vtp-hr-preview-style';
  const jsonRead = (key, fallback) => { try { return JSON.parse(sessionStorage.getItem(key) || JSON.stringify(fallback)); } catch (_) { return fallback; } };
  const jsonWrite = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (_) {} };
  const localRead = () => { try { return JSON.parse(localStorage.getItem(HR_KEY) || '{}') || {}; } catch (_) { return {}; } };
  const localWrite = value => { try { localStorage.setItem(HR_KEY, JSON.stringify(value)); } catch (_) {} };
  const escHR = value => String(value ?? '').replace(/[&<>'\"]/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' }[ch]));
  const isoToday = () => new Date().toISOString().slice(0, 10);
  const addMonths = (value, months) => {
    if (!value) return '';
    const date = new Date(String(value) + 'T12:00:00');
    if (Number.isNaN(date.getTime())) return '';
    date.setMonth(date.getMonth() + Number(months || 0));
    return date.toISOString().slice(0, 10);
  };
  const daysBetween = (start, end) => {
    const a = new Date(String(start || '') + 'T12:00:00');
    const b = new Date(String(end || '') + 'T12:00:00');
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return 0;
    return Math.max(1, Math.floor((b - a) / 86400000) + 1);
  };
  const keyFor = item => String(item?.id || item?.email || item?.name || '').trim();
  const metaFor = item => {
    const all = localRead();
    const key = keyFor(item);
    const base = all[key] || {};
    const joining = item?.dateJoining || '';
    const probationEnd = base.probationEnd || addMonths(joining, 3);
    const identity = [item?.name || '', item?.email || ''].join(' ').toLowerCase();
    const manager = ['admin', 'administrator', 'manager'].includes(String(item?.role || '').toLowerCase()) || identity.includes('mohammad alty') || identity.includes('admin@vibesagency.co');
    const status = manager ? 'permanent' : (base.employmentStatus || item?.employmentStatus || (probationEnd && probationEnd <= isoToday() ? 'confirmed' : 'probation'));
    return { employmentStatus: status, probationEnd, salaryAmount: base.salaryAmount || '', salaryCurrency: base.salaryCurrency || 'USD', salaryCycle: 'monthly', annualLeaveEntitlement: Number(base.annualLeaveEntitlement || 15), ...base };
  };
  const fetchJson = async (path, cacheKey) => {
    const cached = jsonRead(cacheKey, null);
    if (cached && cached.time && Date.now() - cached.time < 60000) return cached.data;
    if (!window.VTP?.root || !window.VTP?.nonce) return [];
    try {
      const response = await fetch(window.VTP.root + path + (path.includes('?') ? '&' : '?') + 'vtp_hr_preview=1', { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': window.VTP.nonce } });
      const data = await response.json();
      jsonWrite(cacheKey, { time: Date.now(), data: Array.isArray(data) ? data : [] });
      return Array.isArray(data) ? data : [];
    } catch (_) { return []; }
  };
  const employeeData = () => fetchJson('employees', 'vtp_hr_employees_cache');
  const leaveData = () => fetchJson('leave', 'vtp_hr_leave_cache');
  const addStyles = () => {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = '.vtp-hr-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.vtp-hr-stat{border:1px solid rgba(96,51,141,.14);border-radius:14px;padding:16px;background:linear-gradient(145deg,#fff,#fbf8ff)}.vtp-hr-stat strong{display:block;font-size:25px;color:#60338d}.vtp-hr-stat span{display:block;margin-top:4px;color:#6f6680;font-size:12px}.vtp-hr-note{margin:14px 0 0;padding:12px 14px;border-radius:10px;background:#f5effb;color:#65587a;font-size:13px}.vtp-hr-policy{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.vtp-hr-policy-card{padding:14px;border:1px solid rgba(96,51,141,.14);border-radius:12px}.vtp-hr-policy-card strong{display:block;color:#2a2142;margin-bottom:5px}.vtp-hr-policy-card span{font-size:12px;color:#6f6680}.vtp-hr-row-meta{display:block;margin-top:4px;color:#6f6680;font-size:11px}.vtp-hr-form{margin:14px 0;padding:14px;border:1px solid rgba(96,51,141,.16);border-radius:12px;background:#fbf9ff}.vtp-hr-form h3{margin:0 0 8px;font-size:15px;color:#2a2142}.vtp-hr-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.vtp-hr-form small{display:block;margin-top:9px;color:#6f6680;font-size:12px}.vtp-hr-form label{display:grid;gap:5px;color:#3a2b50;font-size:12px;font-weight:600}.vtp-hr-form input,.vtp-hr-form select{min-height:38px}.vtp-hr-balance{font-weight:700;color:#60338d}@media(max-width:760px){.vtp-hr-grid,.vtp-hr-policy,.vtp-hr-form-grid{grid-template-columns:1fr}}';
    document.head.appendChild(style);
  };
  const insertEmployeeOverview = async () => {
    const page = document.querySelector('.vtp-employees-page');
    if (!page || page.querySelector('[data-hr-overview]') || page.dataset.hrOverviewPending === '1') return;
    page.dataset.hrOverviewPending = '1';
    const rows = await employeeData();
    const active = rows.filter(item => item.active !== false);
    const probation = active.filter(item => metaFor(item).employmentStatus === 'probation');
    const confirmed = active.filter(item => ['confirmed', 'permanent'].includes(metaFor(item).employmentStatus));
    const reviews = probation.filter(item => { const end = metaFor(item).probationEnd; return end && end <= addMonths(isoToday(), 1); });
    const card = document.createElement('article');
    card.className = 'vtp-card';
    card.dataset.hrOverview = '1';
    card.innerHTML = '<div class="vtp-card-head"><div><h2>Employment & compensation overview</h2><p>Track the three-month probation cycle and monthly employment settings.</p></div><span class="vtp-muted">Preview policy</span></div><div class="vtp-hr-grid"><div class="vtp-hr-stat"><strong>' + probation.length + '</strong><span>On probation</span></div><div class="vtp-hr-stat"><strong>' + confirmed.length + '</strong><span>Confirmed employees</span></div><div class="vtp-hr-stat"><strong>' + reviews.length + '</strong><span>Reviews due within 30 days</span></div></div><div class="vtp-hr-note">Probation lasts three months from the joining date. Salary is recorded as a monthly amount; annual leave becomes available after confirmation, while sick or emergency leave can still be requested during probation.</div>';
    page.dataset.hrOverviewPending = '0';
    const directory = [...page.querySelectorAll('.vtp-card')].find(item => item.querySelector('[data-employee-search]'));
    if (directory) directory.parentElement.insertBefore(card, directory); else page.appendChild(card);
    rows.forEach(item => {
      const button = page.querySelector('[data-view-employee="' + String(item.id) + '"]');
      const roleCell = button?.closest('tr')?.querySelector('[data-label="Role"]');
      if (!roleCell || roleCell.querySelector('[data-hr-row-meta]')) return;
      const meta = metaFor(item);
      const label = meta.employmentStatus === 'confirmed' ? 'Confirmed' : meta.employmentStatus === 'permanent' ? 'Permanent' : meta.employmentStatus === 'ended' ? 'Ended' : 'Probation' + (meta.probationEnd ? ' · until ' + meta.probationEnd : '');
      const small = document.createElement('small'); small.className = 'vtp-hr-row-meta'; small.dataset.hrRowMeta = '1'; small.textContent = label; roleCell.appendChild(small);
    });
  };
  const insertLeavePolicy = async () => {
    const page = document.querySelector('.vtp-page');
    if (!page || location.hash.split('?')[0] !== '#leave' || page.querySelector('[data-hr-policy]') || page.dataset.hrPolicyPending === '1') return;
    page.dataset.hrPolicyPending = '1';
    const [rows, leaves] = await Promise.all([employeeData(), leaveData()]);
    const active = rows.filter(item => item.active !== false);
    const cycleStartFor = joining => {
      if (!joining) return '';
      const start = new Date(String(joining) + 'T12:00:00');
      const today = new Date(isoToday() + 'T12:00:00');
      if (Number.isNaN(start.getTime()) || Number.isNaN(today.getTime())) return '';
      const cycle = new Date(start);
      cycle.setFullYear(today.getFullYear());
      if (cycle > today) cycle.setFullYear(today.getFullYear() - 1);
      return cycle.toISOString().slice(0, 10);
    };
    const monthsInCycle = (cycleStart, asOf) => {
      if (!cycleStart || !asOf) return 0;
      const start = new Date(String(cycleStart) + 'T12:00:00');
      const today = new Date(String(asOf) + 'T12:00:00');
      if (Number.isNaN(start.getTime()) || Number.isNaN(today.getTime()) || today < start) return 0;
      let months = (today.getFullYear() - start.getFullYear()) * 12 + today.getMonth() - start.getMonth();
      if (today.getDate() < start.getDate()) months -= 1;
      return Math.max(0, Math.min(12, months));
    };
    const roundDays = value => Math.round(Number(value || 0) * 100) / 100;
    const formatDays = value => roundDays(value).toFixed(2) + ' days';
    const balanceRows = active.filter(item => !['admin','manager'].includes(String(item.role || '').toLowerCase())).map(item => {
      const meta = metaFor(item);
      const entitlement = Number(meta.annualLeaveEntitlement || 15);
      const cycleStart = cycleStartFor(item.dateJoining);
      const today = isoToday();
      const accrued = roundDays(Math.min(entitlement, monthsInCycle(cycleStart, today) * entitlement / 12));
      const records = leaves.filter(leave => {
        const leaveStart = String(leave.start_date || leave.startDate || '');
        return String(leave.user_id || leave.userId || '') === String(item.id)
          && String(leave.leave_type || leave.leaveType || 'vacation').toLowerCase() === 'vacation'
          && (!cycleStart || leaveStart >= cycleStart);
      });
      const sumDays = list => list.reduce((sum, leave) => sum + daysBetween(leave.start_date || leave.startDate, leave.end_date || leave.endDate), 0);
      const pending = roundDays(sumDays(records.filter(leave => String(leave.status || '').toLowerCase() === 'pending')));
      const approved = roundDays(sumDays(records.filter(leave => {
        const status = String(leave.status || '').toLowerCase();
        const end = String(leave.end_date || leave.endDate || '');
        return status === 'approved' && end >= today;
      })));
      const used = roundDays(sumDays(records.filter(leave => {
        const status = String(leave.status || '').toLowerCase();
        const end = String(leave.end_date || leave.endDate || '');
        return status === 'approved' && end < today;
      })));
      const available = roundDays(Math.max(0, accrued - pending - approved - used));
      return '<tr><td><strong>' + escHR(item.name) + '</strong><small class="vtp-cell-sub">' + escHR(meta.employmentStatus === 'confirmed' ? 'Confirmed' : meta.employmentStatus === 'permanent' ? 'Permanent' : 'Probation') + '</small></td><td>' + escHR(meta.probationEnd || '—') + '</td><td class="vtp-hr-balance">' + formatDays(available) + '</td><td>' + formatDays(pending) + '</td><td>' + formatDays(approved) + '</td><td>' + formatDays(used) + '</td></tr>';
    }).join('');
    const card = document.createElement('article');
    card.className = 'vtp-card'; card.dataset.hrPolicy = '1';
    card.innerHTML = '<div class="vtp-card-head"><div><h2>Leave policy & balances</h2><p>Policy guidance is visible before approving a request.</p></div><span class="vtp-muted">Preview policy</span></div><div class="vtp-hr-policy"><div class="vtp-hr-policy-card"><strong>Probation</strong><span>Three months from the employee joining date.</span></div><div class="vtp-hr-policy-card"><strong>Monthly accrual</strong><span>Annual entitlement accrues at 15 ÷ 12 = 1.25 days per month.</span></div><div class="vtp-hr-policy-card"><strong>Rounding</strong><span>Balances are rounded to two decimal places (for example, 1.25 days). Leave requests themselves are counted as whole calendar days.</span></div><div class="vtp-hr-policy-card"><strong>Annual leave expiry</strong><span>Unused annual leave does not carry forward; it expires at the employment anniversary and the new cycle starts at 0.</span></div><div class="vtp-hr-policy-card"><strong>Sick / emergency</strong><span>Can still be requested during probation for admin review.</span></div><div class="vtp-hr-policy-card"><strong>Manager leave</strong><span>Managers can request time off; their absence remains visible on the team calendar.</span></div></div><div class="vtp-table-scroll" style="margin-top:14px"><table class="vtp-table"><thead><tr><th>Employee</th><th>Probation completion</th><th>Available</th><th>Pending</th><th>Approved</th><th>Used</th></tr></thead><tbody>' + (balanceRows || '<tr><td colspan="6">No employee balances available.</td></tr>') + '</tbody></table></div>';
    const firstCard = page.querySelector('.vtp-card');
    if (firstCard) firstCard.parentElement.insertBefore(card, firstCard); else page.appendChild(card);
    page.dataset.hrPolicyPending = '0';
  };
  const formMeta = form => {
    if (!form || form.dataset.hrFields) return;
    form.dataset.hrFields = '1';
    const email = form.elements.email?.value || '';
    const id = form.elements.id?.value || '';
    const all = localRead();
    const key = String(id || email || form.elements.name?.value || '').trim();
    const current = all[key] || {};
    const joining = form.elements.dateJoining?.value || '';
    const status = current.employmentStatus || (joining && addMonths(joining, 3) <= isoToday() ? 'confirmed' : 'probation');
    const wrap = document.createElement('section');
    wrap.className = 'vtp-hr-form';
    wrap.innerHTML = '<h3>Employment policy</h3><input type="hidden" name="salaryAmount" value=""><input type="hidden" name="salaryCurrency" value="USD"><div class="vtp-hr-form-grid"><label>Employment status<select name="hrEmploymentStatus"><option value="probation">Probation</option><option value="confirmed">Confirmed</option><option value="permanent">Permanent</option><option value="ended">Ended</option></select></label><label>Monthly salary<input name="hrSalaryAmount" type="number" min="0" step="0.01" placeholder="e.g. 2500"></label><label>Salary currency<select name="hrSalaryCurrency"><option value="USD">USD</option></select></label><label>Annual leave entitlement<input name="hrAnnualLeave" type="number" min="0" max="365" step="1" value="15"></label></div><small>Salary is monthly. Vacation leave is available after confirmation; sick or emergency leave can still be submitted during probation.</small>';
    wrap.querySelector('[name="hrEmploymentStatus"]').value = status;
    wrap.querySelector('[name="hrSalaryAmount"]').value = current.salaryAmount || '';
    wrap.querySelector('[name="hrSalaryCurrency"]').value = current.salaryCurrency || 'USD';
    wrap.querySelector('[name="hrAnnualLeave"]').value = Number(current.annualLeaveEntitlement || 15);
    const syncSalaryPayload = () => { wrap.querySelector('[name="salaryAmount"]').value = wrap.querySelector('[name="hrSalaryAmount"]').value || ''; wrap.querySelector('[name="salaryCurrency"]').value = 'USD'; };
    wrap.addEventListener('input', syncSalaryPayload);
    wrap.addEventListener('change', syncSalaryPayload);
    syncSalaryPayload();
    const submit = form.querySelector('button[type="submit"], button.vtp-btn.primary');
    (submit?.parentElement || form).insertBefore(wrap, submit || null);
    form.addEventListener('submit', () => {
      const next = localRead();
      next[key || email || form.elements.name?.value || 'preview'] = { employmentStatus: form.elements.hrEmploymentStatus?.value || 'probation', salaryAmount: form.elements.hrSalaryAmount?.value || '', salaryCurrency: form.elements.hrSalaryCurrency?.value || 'USD', salaryCycle: 'monthly', annualLeaveEntitlement: Number(form.elements.hrAnnualLeave?.value || 15), probationEnd: addMonths(joining, 3) };
      localWrite(next);
    }, { once: true });
  };
  const hideManagerEntitlement = () => {
    document.querySelectorAll('#vtp-employee-form,#vtp-employee-edit-form').forEach(form => {
      const roleValue = form.elements.role?.value || form.dataset.role || '';
      const identity = [form.elements.name?.value || '', form.elements.email?.value || ''].join(' ').toLowerCase();
      const managerLike = ['admin','administrator','manager'].includes(String(roleValue).toLowerCase()) || identity.includes('mohammad alty') || identity.includes('admin@vibesagency.co');
      const input = form.querySelector('[name="hrAnnualLeave"]');
      if (!input) return;
      const label = input.closest('label');
      if (managerLike && form.elements.role && [...form.elements.role.options].some(option => option.value === 'manager')) form.elements.role.value = 'manager';
      if (!managerLike) { if (label) label.style.display = ''; return; }
      if (label) label.style.display = 'none';
      input.value = '0';
      if (!form.querySelector('[data-hr-manager-leave-note]')) {
        const note = document.createElement('small');
        note.dataset.hrManagerLeaveNote = '1';
        note.className = 'vtp-hr-note';
        note.textContent = 'Manager leave is calendar-based; no vacation entitlement balance is tracked on this profile.';
        (input.closest('.vtp-hr-form') || form).appendChild(note);
      }
    });
  };
  const enhance = () => {
    addStyles();
    if (location.hash.split('?')[0] === '#employees') insertEmployeeOverview();
    if (location.hash.split('?')[0] === '#leave') insertLeavePolicy();
    document.querySelectorAll('#vtp-employee-form,#vtp-employee-edit-form').forEach(formMeta);
    hideManagerEntitlement();
    const leaveForm = document.querySelector('#vtp-leave-form');
    if (leaveForm && !leaveForm.querySelector('[data-hr-leave-note]')) { const note = document.createElement('div'); note.className = 'vtp-hr-note'; note.dataset.hrLeaveNote = '1'; note.textContent = 'During probation, vacation requests require admin or manager approval. Sick and emergency leave remains requestable.'; leaveForm.insertBefore(note, leaveForm.firstChild); }
  };
  let timer;
  const schedule = () => { clearTimeout(timer); timer = setTimeout(enhance, 160); };
  VTPR.watch(schedule);
  VTPR.onHashChange(schedule);
  setTimeout(enhance, 500);
})();


/* Vibes HR employment status enhancement */
(() => {
  'use strict';
  const managerLike = form => {
    const role = String(form?.elements?.role?.value || form?.dataset?.role || '').toLowerCase();
    const identity = [form?.elements?.name?.value || '', form?.elements?.email?.value || ''].join(' ').toLowerCase();
    return ['admin', 'administrator', 'manager'].includes(role) || identity.includes('mohammad alty') || identity.includes('admin@vibesagency.co');
  };
  const ensurePermanentOption = form => {
    const select = form?.elements?.hrEmploymentStatus;
    if (!select) return;
    if (!select.querySelector('option[value="permanent"]')) {
      const option = document.createElement('option');
      option.value = 'permanent';
      option.textContent = 'Permanent';
      select.appendChild(option);
    }
    if (managerLike(form)) select.value = 'permanent';
  };
  const applySalaryRule = form => {
    const select = form?.elements?.hrEmploymentStatus;
    const salary = form?.elements?.hrSalaryAmount;
    if (!select || !salary) return;
    const label = salary.closest('label');
    const manager = managerLike(form);
    if (manager) {
      if (label) label.style.display = 'none';
      salary.value = '';
      salary.required = false;
      salary.disabled = true;
      if (!form.querySelector('[data-hr-manager-salary-note]')) {
        const note = document.createElement('small');
        note.dataset.hrManagerSalaryNote = '1';
        note.className = 'vtp-hr-note';
        note.textContent = 'Manager role: monthly salary is not tracked in this profile.';
        (salary.closest('.vtp-hr-form') || form).appendChild(note);
      }
      return;
    }
    if (label) {
      label.style.display = '';
      if (!label.querySelector('.vtp-hr-required')) {
        const marker = document.createElement('span');
        marker.className = 'vtp-hr-required';
        marker.textContent = ' *';
        label.insertBefore(marker, label.firstChild);
      }
    }
    salary.disabled = false;
    salary.required = true;
  };
  const enhance = () => {
    document.querySelectorAll('#vtp-employee-form,#vtp-employee-edit-form').forEach(form => {
      ensurePermanentOption(form);
      applySalaryRule(form);
    });
  };
  let timer;
  const schedule = () => { clearTimeout(timer); timer = setTimeout(enhance, 120); };
  VTPR.watch(schedule);
  document.addEventListener('change', event => {
    if (event.target.matches('[name="hrEmploymentStatus"],[name="role"]')) schedule();
  });
  VTPR.onHashChange(schedule);
  setTimeout(enhance, 650);
})();


/* Vibes leave-management preview enhancement */
(() => {
  'use strict';
  const hrApi = async (path, options = {}) => {
    const cfg = window.VTP || {};
    const method = String(options.method || 'GET').toUpperCase();
    const headers = { 'X-WP-Nonce': cfg.nonce || '', ...(options.headers || {}) };
    if (!(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await fetch((cfg.root || '/wp-json/vibes/v1/') + path, { credentials: 'same-origin', cache: 'no-store', ...options, headers });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'Unable to update leave.');
    return data;
  };
  const isoToday = () => new Date().toISOString().slice(0, 10);
  const enhanceLeave = () => {
    if ((location.hash.split('?')[0] || '#') !== '#leave') return;
    const table = document.querySelector('#vtp-view .vtp-data-card table');
    if (!table) return;
    const admin = Boolean(table.querySelector('tbody [data-leave]') || table.querySelector('thead th:last-child')?.textContent.trim() === 'Action');
    if (!admin && !table.querySelector('thead th[data-hr-action-head]')) {
      const head = table.tHead?.rows[0];
      if (head) { const th = document.createElement('th'); th.dataset.hrActionHead = '1'; th.textContent = 'Action'; head.appendChild(th); }
    }
    table.querySelectorAll('tbody tr[data-leave-row]').forEach(row => {
      const id = row.dataset.leaveRow;
      const status = String(row.dataset.leaveStatus || '').toLowerCase();
      const started = Boolean(row.dataset.leaveStart && row.dataset.leaveStart <= isoToday());
      let cell = admin ? row.querySelector('td:last-child') : row.querySelector('td[data-hr-leave-action-cell]');
      if (!cell) { cell = document.createElement('td'); cell.dataset.hrLeaveActionCell = '1'; cell.dataset.label = 'Action'; row.appendChild(cell); }
      if (admin && (status === 'approved' || status === 'declined') && !cell.querySelector('[data-hr-leave-delete]')) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'vtp-btn small danger'; button.dataset.hrLeaveDelete = id; button.textContent = 'Delete & restore'; cell.appendChild(button);
      }
      if (!admin && status === 'pending' && !started && !cell.querySelector('[data-hr-leave-cancel]')) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'vtp-btn small danger'; button.dataset.hrLeaveCancel = id; button.textContent = 'Cancel request'; cell.appendChild(button);
      }
    });
  };
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-hr-leave-cancel],[data-hr-leave-delete]');
    if (!button) return;
    const id = button.dataset.hrLeaveCancel || button.dataset.hrLeaveDelete;
    const cancel = Boolean(button.dataset.hrLeaveCancel);
    if (!window.confirm(cancel ? 'Cancel this pending leave request?' : 'Delete this leave and restore the employee balance?')) return;
    button.disabled = true; button.textContent = 'Working…';
    try { await hrApi('leave/' + encodeURIComponent(id), { method: 'DELETE' }); window.alert(cancel ? 'Leave request cancelled.' : 'Leave removed; balance restored.'); window.location.reload(); }
    catch (error) { button.disabled = false; button.textContent = cancel ? 'Cancel request' : 'Delete & restore'; window.alert(error.message || 'Unable to update leave.'); }
  }, true);
  const root = document.querySelector('#vtp-view');
  if (root) VTPR.watch(() => setTimeout(enhanceLeave, 80));
  VTPR.onHashChange(() => setTimeout(enhanceLeave, 150));
  setTimeout(enhanceLeave, 500);
})();

/* 2026-11 redesign pass: navigation tooltips and the phone menu sheet. */
(() => {
  const ready = () => {
    const app = document.getElementById('vtp-app');
    const nav = document.getElementById('vtp-nav');
    if (!app || !nav) return;
    // Works with older server markup too: mark the phone tabs and add the Menu button if missing.
    ['dashboard', 'projects', 'tasks', 'calendar'].forEach(route => nav.querySelector(`a[data-route="${route}"]`)?.setAttribute('data-tab', ''));
    let more = document.querySelector('.vtp-nav-more');
    if (!more) {
      more = document.createElement('button');
      more.type = 'button'; more.className = 'vtp-nav-more'; more.setAttribute('aria-expanded', 'false'); more.setAttribute('aria-controls', 'vtp-nav');
      more.innerHTML = '<i><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></i><span>Menu</span>';
      nav.after(more);
    }
    const label = () => nav.querySelectorAll('a').forEach(link => {
      const text = (link.querySelector('span') || link).textContent.trim();
      if (text && !link.title) { link.title = text; link.setAttribute('aria-label', text); }
    });
    label();
    VTPR.watch(label);
    const close = () => { app.classList.remove('vtp-nav-open'); if (more) more.setAttribute('aria-expanded', 'false'); };
    if (more) more.addEventListener('click', () => {
      const open = !app.classList.contains('vtp-nav-open');
      app.classList.toggle('vtp-nav-open', open);
      more.setAttribute('aria-expanded', String(open));
    });
    nav.addEventListener('click', event => { if (event.target.closest('a')) close(); });
    VTPR.onHashChange(close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();

/* 2026-11: show the admin's PC path for mirrored Google Drive folders, with a copy button. */
(() => {
  const cache = new Map();
  const fill = async el => {
    const folder = el.dataset.folder;
    if (!folder || el.dataset.done) return;
    el.dataset.done = '1';
    const caps = (window.VTP && VTP.isAdmin) ? true : document.querySelector('#vtp-app[data-role="admin"]');
    if (!caps) { el.remove(); return; }
    try {
      if (!cache.has(folder)) {
        cache.set(folder, fetch(`${VTP.root}drive/mapped-path?folderUrl=${encodeURIComponent(folder)}`, { headers: { 'X-WP-Nonce': VTP.nonce }, credentials: 'same-origin', cache: 'no-store' }).then(r => r.ok ? r.json() : Promise.reject(r)));
      }
      const { mapped_path: path } = await cache.get(folder);
      if (!path) throw new Error('none');
      el.classList.add('vtp-pc-path');
      el.innerHTML = '';
      const code = document.createElement('code'); code.textContent = path;
      const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'vtp-btn small'; btn.textContent = 'Copy path';
      btn.addEventListener('click', async () => { try { await navigator.clipboard.writeText(path); btn.textContent = 'Copied ✓'; } catch (e) { window.prompt('Copy this path:', path); } setTimeout(() => { btn.textContent = 'Copy path'; }, 1800); });
      const label = document.createElement('span'); label.textContent = 'On your PC:';
      el.append(label, code, btn);
    } catch (error) {
      cache.delete(folder);
      el.textContent = 'PC path unavailable until Google Drive is connected.';
    }
  };
  const scan = () => document.querySelectorAll('[data-mapped-path-status][data-folder]:not([data-done])').forEach(fill);
  const start = () => { scan(); VTPR.watch(scan); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
