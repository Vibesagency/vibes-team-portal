window.__VPM_MODULES_PRESENT__ = true;
/* Vibes Portal Modules: same portal shell, same REST namespace, no separate app. */
/* similarity-async-fix-20261030 */
(() => {
  'use strict';
  const moduleRoutes = new Set(['connect', 'finance', 'crm', 'payslips']);
  const state = { boot: null, conversation: null, conversations: [], members: [], poll: null, after: 0, replyTo: null, connectFilter: 'all', connectSearch: '', overlayMode: false };
  const EMOJIS = Array.from('😀😃😄😁😆😅😂🤣😊😇🙂🙃😉😌😍🥰😘😗😙😚😋😛😝😜🤪🤨🧐🤓😎🤩🥳😏😒😞😔😟😕🙁☹️😣😖😫😩🥺😢😭😤😠😡🤬🤯😳🥵🥶😱😨😰😥😓🤗🤔🫣🤭🫢🤫🤥😶🫠😐😑😬🙄😯😦😧😮😲🥱😴🤤😪😵‍💫🤐🥴🤢🤮🤧😷🤒🤕🤑🤠😈👿👹👺🤡💩👻💀☠️👽👾🤖🎃😺😸😹😻😼😽🙀😿😾').slice(0, 100);
  const QUICK_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
  const $ = (selector, root = document) => root.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const root = () => document.getElementById('vpm-connect-overlay-root') || document.getElementById('vtp-view');
  const routeKey = () => (location.hash || '#dashboard').slice(1).split('?')[0];
  const api = async (path, options = {}) => {
    const headers = {'X-WP-Nonce': VPM.nonce, ...(options.headers || {})};
    if (options.body && typeof options.body !== 'string') { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
    const response = await fetch(VPM.root + path.replace(/^\//, ''), {...options, headers, credentials: 'same-origin', cache: 'no-store'});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'The request could not be completed.');
    return data;
  };
  const loading = (message = 'Loading module…') => { if (root()) root().innerHTML = `<div class="vpm-loading"><span></span><p>${esc(message)}</p></div>`; };
  const card = (label, value, note = '') => `<article class="vpm-stat"><small>${esc(label)}</small><strong>${esc(value)}</strong>${note ? `<span>${esc(note)}</span>` : ''}</article>`;
  const message = (text, kind = 'info') => `<div class="vpm-message ${kind}">${esc(text)}</div>`;
  const ensurePayslipStyles = () => { if (document.getElementById('vpm-payslip-styles')) return; const style = document.createElement('style'); style.id = 'vpm-payslip-styles'; style.textContent = ".vpm-payslips-page .vpm-page-head{align-items:flex-start}.vpm-payslip-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.vpm-payslip-sheet{border:1px solid rgba(96,51,141,.18);background:#fff;box-shadow:0 18px 40px rgba(44,26,75,.08)}.vpm-payslip-brand{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:18px;border-bottom:3px solid #60338d}.vpm-payslip-brand-mark{font-weight:800;letter-spacing:.08em;color:#60338d;font-size:1.05rem}.vpm-payslip-title{font-size:2rem;font-weight:800;color:#24183a;letter-spacing:.04em}.vpm-payslip-meta{display:grid;grid-template-columns:repeat(3,minmax(100px,1fr));gap:10px;margin:18px 0}.vpm-payslip-meta div{padding:12px 14px;border-radius:10px;background:#faf7ff;border:1px solid rgba(96,51,141,.12)}.vpm-payslip-meta span,.vpm-payslip-employee span,.vpm-payslip-table th,.vpm-payslip-note,.vpm-payslip-total span{display:block;color:#756b86;font-size:.82rem}.vpm-payslip-meta strong,.vpm-payslip-employee strong{display:block;margin-top:4px;color:#24183a}.vpm-payslip-employee{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:18px 0}.vpm-payslip-employee>div{padding:16px;border:1px solid rgba(96,51,141,.18);border-radius:12px;background:#fcfaff}.vpm-payslip-table{width:100%;border-collapse:collapse;margin-top:18px}.vpm-payslip-table th{padding:12px;text-align:left;background:#60338d;color:#fff;font-weight:700}.vpm-payslip-table td{padding:13px 12px;border-bottom:1px solid rgba(96,51,141,.14);color:#24183a}.vpm-payslip-table td:last-child{text-align:right;font-weight:700}.vpm-payslip-note{margin-top:18px;padding:14px 16px;background:#faf7ff;border-left:3px solid #ef7b67;line-height:1.45}.vpm-payslip-total{margin:18px 0 0 auto;max-width:280px;padding:16px;border:1px solid rgba(96,51,141,.18);border-radius:14px;background:#fcfaff}.vpm-payslip-total{display:flex;justify-content:space-between;gap:16px}.vpm-payslip-total strong{color:#60338d;font-size:1.15rem}.vpm-payslip-footer{margin-top:28px;padding-top:12px;border-top:3px solid #60338d;color:#756b86;font-size:.8rem}@media(max-width:720px){.vpm-payslip-meta{grid-template-columns:1fr}.vpm-payslip-employee{grid-template-columns:1fr}.vpm-payslip-title{font-size:1.5rem}}@media print{body *{visibility:hidden!important}.vpm-payslip-sheet,.vpm-payslip-sheet *{visibility:visible!important}.vpm-payslip-sheet{position:absolute;inset:0;box-shadow:none!important;border:0!important;padding:18px!important}}"; document.head.appendChild(style); };

  function installNav() {
    const nav = document.getElementById('vtp-nav'); if (!nav || !state.boot) return;    nav.querySelector('a[href$="#connect"]')?.remove();
    const links = [
      ['connect', 'Vibes Connect', '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.8 8.8 0 0 1-3.2-.6L4 20l1.2-3.7A7.2 7.2 0 0 1 4 11.5 7.5 7.5 0 0 1 12 4a7.5 7.5 0 0 1 8 7.5Z"></path><path d="M8 11h.01M12 11h.01M16 11h.01"></path></svg>', 'team', state.boot.capabilities.connect],
      ['finance', 'Finance Overview', '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 7v10M15 9.5c-.6-.6-1.6-1-3-1-1.7 0-2.8.8-2.8 2s1.1 2 2.8 2 2.8.8 2.8 2-1.1 2-2.8 2c-1.4 0-2.4-.4-3-1"></path></svg>', 'finance', state.boot.capabilities.finance],
      ['crm', 'CRM', '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.4-3 2.2-4.5 5.5-4.5s5.1 1.5 5.5 4.5"></path><path d="M15 6.5a3 3 0 0 1 0 5.8M16 14.8c2.6.4 4 1.8 4.5 4.2"></path></svg>', 'sales', state.boot.capabilities.crm],
    ];
    links.forEach(([route, label, icon, groupKey, allowed]) => {
      if (!allowed || nav.querySelector(`[data-vpm-route="${route}"]`)) return;
      const item = document.createElement('a'); item.href = `#${route}`; item.dataset.vpmRoute = route;
      // The core portal uses a delegated click router on the same navigation.
      // Stop it at the document capture phase so module routes are not reset
      // back to the dashboard while their REST view is loading.
      item.innerHTML = `<i aria-hidden="true">${icon}</i><span>${label}</span>`;
      const group = nav.querySelector(`[data-nav-group="${groupKey}"]`);
      (group || nav).appendChild(item);
    });
  }

  function setActiveNav() {
    const key = routeKey(); document.querySelectorAll('#vtp-nav a').forEach((link) => link.classList.toggle('active', (link.dataset.route || link.dataset.vpmRoute) === key));
  }

  function clearPoll() { if (state.poll) { clearTimeout(state.poll); state.poll = null; } }
  function schedulePoll() {
    clearPoll(); if ((!state.overlayMode && routeKey() !== 'connect') || !state.conversation) return;
    const delay = document.visibilityState === 'hidden' ? 30000 : 8000;
    state.poll = setTimeout(async () => { try { await loadMessages(true); } finally { schedulePoll(); } }, delay);
  }

  const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || '?';
  const avatar = (url, name, extra = '') => {
    // WordPress returns the grey “mystery person” Gravatar when a member has
    // no profile photo. Keep real photos, but use a branded initials avatar
    // instead of displaying that unrelated placeholder.
    const usableUrl = url && !String(url).includes('d=mm') ? url : '';
    return `<span class="vpm-avatar ${extra}">${usableUrl ? `<img src="${esc(usableUrl)}" alt="">` : `<b>${esc(initials(name))}</b>`}</span>`;
  };
  const prettyTime = (value) => { const date = value ? new Date(String(value).replace(' ', 'T')) : new Date(); if (Number.isNaN(date.getTime())) return esc(value || ''); return date.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'}); };
  const conversationTitle = (row) => row.title || (row.members || []).filter((member) => Number(member.id) !== Number(VPM.userId)).map((member) => member.name).join(' · ') || row.type;
  const vibesGroupAvatar = () => `<span class="vpm-avatar vpm-group-avatar" aria-label="Vibes Agency team"><img src="${esc(`${VPM.assetsUrl || ''}vibes%20logo.jpg`)}" alt="Vibes Agency logo"></span>`;
  const conversationAvatar = (row) => {
    if (row.type === 'general' || conversationTitle(row) === 'General Team') return vibesGroupAvatar();
    const other = (row.members || []).find((member) => Number(member.id) !== Number(VPM.userId));
    return other ? avatar(other.avatar_url, other.name) : avatar('', conversationTitle(row));
  };

  function filteredConversations() {
    const query = state.connectSearch.toLowerCase();
    return state.conversations.filter((row) => {
      const haystack = `${conversationTitle(row)} ${row.type} ${row.last_message_body || ''}`.toLowerCase();
      if (query && !haystack.includes(query)) return false;
      if (state.connectFilter === 'unread' && !Number(row.unread_count)) return false;
      if (state.connectFilter === 'groups' && !['group', 'project', 'task', 'general'].includes(row.type)) return false;
      if (state.connectFilter === 'pinned' && !JSON.parse(localStorage.getItem('vpm_connect_pins') || '[]').includes(Number(row.id))) return false;
      return true;
    });
  }

  function renderConversationList() {
    const list = $('.vpm-conversation-list-body'); if (!list) return;
    const rows = filteredConversations();
    list.innerHTML = rows.length ? rows.map((row) => `<button class="vpm-conversation ${row.id === state.conversation?.id ? 'active' : ''}" data-vpm-conversation="${row.id}">${conversationAvatar(row)}<span class="vpm-conversation-copy"><strong>${esc(conversationTitle(row))}</strong><small>${esc(row.last_message_body || `${row.type} conversation`)}</small></span><span class="vpm-conversation-meta">${row.last_message_at ? `<time>${prettyTime(row.last_message_at)}</time>` : ''}${Number(row.unread_count) ? `<b>${Number(row.unread_count)}</b>` : ''}</span></button>`).join('') : '<p class="vpm-empty">No conversations match this view.</p>';
    list.querySelectorAll('[data-vpm-conversation]').forEach((button) => button.addEventListener('click', () => selectConversation(Number(button.dataset.vpmConversation))));
  }

  function renderThreadHeader() {
    const head = $('.vpm-thread-head'); if (!head || !state.conversation) return;
    const row = state.conversation; const memberCount = (row.members || []).length;
    head.innerHTML = `<div class="vpm-thread-identity">${conversationAvatar(row)}<div><h2>${esc(conversationTitle(row))}</h2><small>${memberCount || 1} member${memberCount === 1 ? '' : 's'} · ${esc(row.type)} conversation</small></div></div><div class="vpm-thread-actions"><button type="button" class="vpm-icon-btn" data-vpm-pin aria-label="${JSON.parse(localStorage.getItem('vpm_connect_pins') || '[]').includes(Number(row.id)) ? 'Unpin' : 'Pin'} conversation">${JSON.parse(localStorage.getItem('vpm_connect_pins') || '[]').includes(Number(row.id)) ? '★' : '☆'}</button></div>`;
    $('[data-vpm-pin]', head)?.addEventListener('click', () => { const pins = JSON.parse(localStorage.getItem('vpm_connect_pins') || '[]'); const id = Number(row.id); const next = pins.includes(id) ? pins.filter((pin) => pin !== id) : [...pins, id]; localStorage.setItem('vpm_connect_pins', JSON.stringify(next)); renderThreadHeader(); renderConversationList(); });
  }

  function mountConnectShell() {
    if (window.VPMConnect) return;
    if (root()?.querySelector('.vpm-connect-page')) return;
    root().innerHTML = `<section class="vpm-page vpm-connect-page"><div class="vpm-page-head"><div><p class="vpm-eyebrow">VIBES CONNECT</p><h1>Work together, naturally.</h1><p>Private conversations for the people, projects and tasks you are authorized to see.</p></div><button type="button" class="vpm-connect-overlay-close" id="vpm-connect-close-overlay" aria-label="Close Vibes Connect">×</button></div><div class="vpm-connect"><aside class="vpm-conversation-list"><div class="vpm-connect-sidebar-head"><div><strong>Messages</strong><small id="vpm-connect-count">Loading conversations…</small></div><button type="button" class="vpm-create-group" id="vpm-new-group" aria-label="Create group" title="Create a new team group"><span class="vpm-create-group-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><circle cx="17" cy="9" r="2.5"></circle><path d="M3.5 18c.5-3 2.3-4.5 5.5-4.5s5 1.5 5.5 4.5M15 15.5c2.7-.2 4.5.7 5.2 2.5M19 4v6M16 7h6"></path></svg></span><span>Create group</span></button></div><label class="vpm-search"><span>⌕</span><input id="vpm-connect-search" placeholder="Search conversations" autocomplete="off"></label><div class="vpm-connect-filters"><button type="button" class="active" data-vpm-filter="all">All</button><button type="button" data-vpm-filter="unread">Unread</button><button type="button" data-vpm-filter="groups">Groups</button><button type="button" data-vpm-filter="pinned">Pinned</button></div><div class="vpm-conversation-list-body"><div class="vpm-loading"><span></span></div></div></aside><section class="vpm-thread"><div class="vpm-thread-head"></div><div class="vpm-thread-status"><span class="vpm-online-dot"></span><span>Live updates are on</span><span class="vpm-thread-status-spacer"></span><small id="vpm-last-seen"></small></div><div id="vpm-messages" class="vpm-messages"><div class="vpm-loading"><span></span></div></div><div id="vpm-reply-preview" class="vpm-reply-preview" hidden></div><form id="vpm-message-form" class="vpm-compose"><div class="vpm-compose-main"><textarea name="body" rows="1" placeholder="Write a message…" required></textarea><div class="vpm-compose-tools"><label class="vpm-attach-btn" title="Attach a file">＋<input name="file" type="file" accept="image/*,.pdf,.docx,.xlsx,.zip"></label><input name="attachmentUrl" type="url" placeholder="Paste a secure Drive link (optional)"><span class="vpm-file-name" id="vpm-file-name"></span></div></div><button type="submit" class="vpm-send-btn" aria-label="Send message">➤</button></form></section></div></section>`;
    $('#vpm-connect-close-overlay')?.addEventListener('click', () => { closeConnectOverlay(); });
    $('#vpm-new-group').addEventListener('click', () => openConversationModal(true));
    $('#vpm-connect-search').addEventListener('input', (event) => { state.connectSearch = event.target.value.trim(); renderConversationList(); });
    document.querySelectorAll('[data-vpm-filter]').forEach((button) => button.addEventListener('click', () => { state.connectFilter = button.dataset.vpmFilter; document.querySelectorAll('[data-vpm-filter]').forEach((item) => item.classList.toggle('active', item === button)); renderConversationList(); }));
    $('#vpm-message-form').addEventListener('submit', sendMessage); $('[name="file"]', $('#vpm-message-form')).addEventListener('change', (event) => { $('#vpm-file-name').textContent = event.target.files?.[0]?.name || ''; });
  }

  async function renderConnect() {
    if (window.VPMConnect) { window.VPMConnect.openFromRoute(); return; }
    mountConnectShell();
    try {
      const [conversations, members] = await Promise.all([api('connect/conversations'), api('connect/members')]);
      state.conversations = conversations || []; state.members = members || [];
      if (!state.conversations.length) { root().innerHTML = message('No authorized conversations are available yet.'); return; }
      state.conversation = state.conversation && state.conversations.find((row) => Number(row.id) === Number(state.conversation.id)) || state.conversations[0];
      const count = $('#vpm-connect-count'); if (count) count.textContent = `${state.conversations.length} conversation${state.conversations.length === 1 ? '' : 's'} · all authorized employees`;
      renderConversationList(); renderThreadHeader();
      await loadMessages(false); schedulePoll();
    } catch (error) { root().innerHTML = message(error.message, 'error'); }
  }

  function openConnectOverlay() {
    state.overlayMode = true;
    let overlay = document.getElementById('vpm-connect-overlay-root');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'vpm-connect-overlay-root';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-label', 'Vibes Connect');
      document.body.appendChild(overlay);
    }
    renderConnect();
  }

  function closeConnectOverlay() {
    state.overlayMode = false;
    clearPoll();
    document.getElementById('vpm-connect-overlay-root')?.remove();
    state.conversation = null;
    state.conversations = [];
    state.members = [];
    state.after = 0;
    state.replyTo = null;
  }

  window.VPMOpenConnectOverlay = openConnectOverlay;
  window.VPMCloseConnectOverlay = closeConnectOverlay;

  async function selectConversation(id) {
    const next = state.conversations.find((row) => Number(row.id) === Number(id)); if (!next) return;
    state.conversation = next; state.replyTo = null; state.after = 0; renderConversationList(); renderThreadHeader(); clearReply(); await loadMessages(false); schedulePoll();
  }

  const reactionLabel = (reaction) => {
    if (String(reaction).startsWith('emoji_')) {
      try { return String.fromCodePoint(...String(reaction).slice(6).split('-').map((part) => parseInt(part, 16)).filter(Boolean)); } catch (error) { return '😊'; }
    }
    return ({thumbs_up:'👍', heart:'❤️', eyes:'👀', celebrate:'🎉'}[reaction] || reaction);
  };
  const encodedEmoji = (emoji) => `emoji_${Array.from(emoji).map((char) => char.codePointAt(0).toString(16)).join('-')}`;
  const messageBody = (body) => esc(body).replace(/\[\[task:(\d+)\]\]/g, '<a class="vpm-task-link" href="#tasks" data-vpm-task-link="$1">Open task manager</a>').replace(/\n/g, '<br>');
  function messageHtml(row) {
    const mine = Number(row.user_id) === Number(VPM.userId); const reactions = (row.reactions || []).map((item) => `<button class="vpm-reaction ${item.active ? 'active' : ''}" data-vpm-reaction="${row.id}" data-vpm-reaction-name="${esc(item.reaction)}">${reactionLabel(item.reaction)} <b>${Number(item.total)}</b></button>`).join('');
    const parent = row.parent ? `<div class="vpm-quoted"><strong>${esc(row.parent.user_name || 'Reply')}</strong><span>${esc(row.parent.body || '')}</span></div>` : '';
    const quick = QUICK_EMOJIS.map((emoji) => `<button type="button" class="vpm-quick-emoji" data-vpm-emoji="${row.id}" data-emoji="${esc(emoji)}" aria-label="React with ${esc(emoji)}">${emoji}</button>`).join('');
    const actions = `<div class="vpm-message-menu" data-vpm-menu="${row.id}" hidden><div class="vpm-quick-reactions" aria-label="Quick reactions">${quick}</div><button type="button" class="vpm-emoji-toggle-control" data-vpm-open-emoji="${row.id}" aria-label="Open emoji picker" title="Open emoji picker">☺</button><div class="vpm-menu-actions"><button type="button" data-vpm-reply="${row.id}">↩ <span>Reply</span></button><button type="button" data-vpm-forward="${row.id}">↗ <span>Forward</span></button>${mine ? `<button type="button" data-vpm-edit="${row.id}">✎ <span>Edit</span></button><button type="button" data-vpm-delete="${row.id}">⌫ <span>Delete</span></button>` : ''}</div><div class="vpm-emoji-picker" data-vpm-emoji-picker="${row.id}" hidden><div class="vpm-emoji-tabs"><button type="button" class="active" aria-label="Recent reactions">◷</button><button type="button" aria-label="Smiles and people">☺</button><button type="button" aria-label="Objects">▧</button><button type="button" aria-label="Symbols">✦</button></div><input type="search" class="vpm-emoji-search" data-vpm-emoji-search="${row.id}" placeholder="Search reaction" autocomplete="off"><strong>Recent reactions</strong><div class="vpm-emoji-recent">${QUICK_EMOJIS.map((emoji) => `<button type="button" data-vpm-emoji="${row.id}" data-emoji="${esc(emoji)}" aria-label="React with ${esc(emoji)}">${emoji}</button>`).join('')}</div><strong>Smiles &amp; People</strong><div class="vpm-emoji-grid">${EMOJIS.map((emoji) => `<button type="button" data-vpm-emoji="${row.id}" data-emoji="${esc(emoji)}" aria-label="React with ${esc(emoji)}">${emoji}</button>`).join('')}</div></div></div>`;
    return `<article class="vpm-message-row ${mine ? 'mine' : ''}" data-vpm-message-id="${row.id}">${avatar(row.avatar_url, row.user_name, 'vpm-message-avatar')}<div class="vpm-message-bubble"><div class="vpm-message-meta"><strong>${esc(row.user_name)}</strong></div>${parent}${row.body ? `<p>${messageBody(row.body)}</p>` : ''}${(row.attachments || []).map((file) => `<a class="vpm-attachment" href="${esc(file.file_url)}" target="_blank" rel="noopener noreferrer"><span>↗</span><span><strong>${esc(file.filename)}</strong><small>${esc(file.mime_type || 'Attachment')}</small></span></a>`).join('')}<div class="vpm-message-foot"><span class="vpm-message-reactions">${reactions}</span><time class="vpm-message-time">${prettyTime(row.created_at)}${row.edited ? ' · edited' : ''}</time><button type="button" class="vpm-message-menu-trigger" data-vpm-message-menu="${row.id}" aria-label="Message actions" title="Message actions">＋</button></div>${actions}</div></article>`;
  }

  function closeMessageMenus() { document.querySelectorAll('[data-vpm-menu]').forEach((menu) => { menu.hidden = true; const picker = $('[data-vpm-emoji-picker]', menu); if (picker) picker.hidden = true; }); }
  function toggleMessageMenu(id) { const menu = document.querySelector(`[data-vpm-menu="${id}"]`); if (!menu) return; const wasHidden = menu.hidden; closeMessageMenus(); menu.hidden = !wasHidden; }
  function toggleEmojiPicker(id) { const picker = document.querySelector(`[data-vpm-emoji-picker="${id}"]`); if (picker) picker.hidden = !picker.hidden; }

  function openForwardModal(messageId) {
    const source = document.querySelector(`[data-vpm-message-id="${messageId}"]`); const body = source?.querySelector('.vpm-message-bubble p')?.textContent || 'Shared an attachment';
    const options = state.conversations.filter((conversation) => Number(conversation.id) !== Number(state.conversation?.id)).map((conversation) => `<option value="${conversation.id}">${esc(conversationTitle(conversation))}</option>`).join('');
    const panel = document.createElement('div'); panel.className = 'vpm-modal'; panel.innerHTML = `<div class="vpm-modal-card vpm-connect-modal"><button type="button" class="vpm-modal-close" aria-label="Close">×</button><p class="vpm-eyebrow">FORWARD MESSAGE</p><h2>Choose a conversation</h2><p class="vpm-muted">The message will be copied as a new message in the selected conversation.</p><form id="vpm-forward-form" class="vpm-form"><label class="vpm-field-label">Conversation<select name="conversationId" required>${options || '<option value="">No other conversations yet</option>'}</select></label><button type="submit" class="vpm-btn primary" ${options ? '' : 'disabled'}>Forward message</button></form></div>`;
    document.body.appendChild(panel); const close = () => panel.remove(); $('.vpm-modal-close', panel).addEventListener('click', close); $('#vpm-forward-form', panel).addEventListener('submit', async (event) => { event.preventDefault(); const target = Number(new FormData(event.currentTarget).get('conversationId')); if (!target) return; try { await api(`connect/conversations/${target}/messages`, {method:'POST', body:{body:`Forwarded message from ${source?.querySelector('.vpm-message-meta strong')?.textContent || 'Team member'}:\n${body}`}}); close(); alert('Message forwarded.'); } catch (error) { alert(error.message); } });
  }

  function bindMessageActions(container) {
    container.onclick = async (event) => {
      const menuTrigger = event.target.closest('[data-vpm-message-menu]');
      if (menuTrigger) { toggleMessageMenu(menuTrigger.dataset.vpmMessageMenu); return; }
      const openEmoji = event.target.closest('[data-vpm-open-emoji]');
      if (openEmoji) { const picker = openEmoji.closest('.vpm-message-menu')?.querySelector('.vpm-emoji-picker'); if (picker) picker.hidden = false; return; }
      const emoji = event.target.closest('[data-vpm-emoji]');
      if (emoji) { try { await api(`connect/messages/${emoji.dataset.vpmEmoji}/reactions`, {method:'POST', body:{reaction:encodedEmoji(emoji.dataset.emoji || '😊')}}); closeMessageMenus(); await loadMessages(false); } catch (error) { alert(error.message); } return; }
      const forward = event.target.closest('[data-vpm-forward]');
      if (forward) { closeMessageMenus(); openForwardModal(Number(forward.dataset.vpmForward)); return; }
      const reaction = event.target.closest('[data-vpm-reaction]');
      if (reaction) { try { await api(`connect/messages/${reaction.dataset.vpmReaction}/reactions`, {method:'POST', body:{reaction:reaction.dataset.vpmReactionName || 'thumbs_up'}}); await loadMessages(false); } catch (error) { alert(error.message); } return; }
      const reply = event.target.closest('[data-vpm-reply]');
      if (reply) { const row = [...container.querySelectorAll('[data-vpm-message-id]')].find((item) => item.dataset.vpmMessageId === reply.dataset.vpmReply); const body = row?.querySelector('p')?.textContent || ''; const user = row?.querySelector('.vpm-message-meta strong')?.textContent || 'Message'; state.replyTo = {id: Number(reply.dataset.vpmReply), body, user}; renderReply(); return; }
      const edit = event.target.closest('[data-vpm-edit]');
      if (edit) { const row = container.querySelector(`[data-vpm-message-id="${edit.dataset.vpmEdit}"]`); const current = row?.querySelector('p')?.textContent || ''; const next = window.prompt('Edit your message', current); if (next && next.trim() && next.trim() !== current) { try { await api(`connect/conversations/${state.conversation.id}/messages/${edit.dataset.vpmEdit}`, {method:'PATCH', body:{body:next.trim()}}); await loadMessages(false); } catch (error) { alert(error.message); } } return; }
      const del = event.target.closest('[data-vpm-delete]');
      if (del && window.confirm('Delete this message for everyone in the conversation?')) { try { await api(`connect/conversations/${state.conversation.id}/messages/${del.dataset.vpmDelete}`, {method:'DELETE'}); await loadMessages(false); } catch (error) { alert(error.message); } }
    };
    container.querySelectorAll('[data-vpm-open-emoji]').forEach((button) => {
      button.onclick = (event) => {
        event.stopPropagation();
        const picker = button.closest('.vpm-message-menu')?.querySelector('.vpm-emoji-picker');
        if (picker) picker.hidden = false;
      };
    });
    container.oninput = (event) => {
      const search = event.target.closest('[data-vpm-emoji-search]'); if (!search) return;
      const query = search.value.trim().toLowerCase(); const picker = search.closest('.vpm-emoji-picker');
      picker?.querySelectorAll('.vpm-emoji-grid [data-emoji]').forEach((button) => { button.hidden = Boolean(query && !button.dataset.emoji.includes(query)); });
    };
    const revealEmoji = (event) => {
      const openEmoji = event.target.closest('[data-vpm-open-emoji]');
      if (openEmoji) {
        const picker = openEmoji.closest('.vpm-message-menu')?.querySelector('.vpm-emoji-picker');
        if (picker) picker.hidden = false;
      }
    };
    container.onpointerdown = revealEmoji;
    container.onmousedown = revealEmoji;
  }

  async function loadMessages(incremental) {
    if (!state.conversation) return;
    const query = incremental && state.after ? `?after=${state.after}` : '?limit=100'; const rows = await api(`connect/conversations/${state.conversation.id}/messages${query}`); const container = $('#vpm-messages'); if (!container) return;
    if (!incremental) { state.after = 0; container.innerHTML = ''; }
    rows.forEach((row) => { state.after = Math.max(state.after, Number(row.id)); container.insertAdjacentHTML('beforeend', messageHtml(row)); });
    if (!rows.length && !incremental) container.innerHTML = '<div class="vpm-empty-state"><span>✦</span><strong>No messages yet</strong><p>Start with a friendly hello or share the next project update.</p></div>';
    if (rows.length) container.scrollTop = container.scrollHeight;
    bindMessageActions(container); if (state.after) api(`connect/conversations/${state.conversation.id}/read`, {method:'POST', body:{messageId:state.after}}).catch(() => {});
    const current = state.conversations.find((row) => Number(row.id) === Number(state.conversation.id)); if (current) { current.unread_count = 0; current.last_message_body = rows.length ? rows[rows.length - 1].body : current.last_message_body; renderConversationList(); }
    const seen = $('#vpm-last-seen'); if (seen) seen.textContent = rows.length ? `Last activity ${prettyTime(rows[rows.length - 1].created_at)}` : '';
  }

  function renderReply() { const box = $('#vpm-reply-preview'); if (!box) return; if (!state.replyTo) { box.hidden = true; box.innerHTML = ''; return; } box.hidden = false; box.innerHTML = `<div><strong>Replying to ${esc(state.replyTo.user)}</strong><span>${esc(state.replyTo.body)}</span></div><button type="button" id="vpm-clear-reply" aria-label="Cancel reply">×</button>`; $('#vpm-clear-reply').addEventListener('click', clearReply); $('[name="body"]', $('#vpm-message-form'))?.focus(); }
  function clearReply() { state.replyTo = null; renderReply(); }

  async function sendMessage(event) {
    event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const body = String(values.get('body') || '').trim(); const file = values.get('file'); const attachmentUrl = String(values.get('attachmentUrl') || '').trim(); if (!body && (!file || !file.size) && !attachmentUrl) return;
    const sendButton = $('.vpm-send-btn', form); sendButton.disabled = true;
    try {
      const payload = {body: body || 'Shared an attachment', parentId: state.replyTo?.id || 0}; if (attachmentUrl) payload.attachments = [{url: attachmentUrl, filename: 'Linked file'}];
      const sent = await api(`connect/conversations/${state.conversation.id}/messages`, {method:'POST', body:payload});
      if (file && file.size) { const upload = new FormData(); upload.append('messageId', sent.id); upload.append('file', file); const response = await fetch(`${VPM.root}connect/attachments`, {method:'POST', headers:{'X-WP-Nonce':VPM.nonce}, credentials:'same-origin', body:upload}); if (!response.ok) throw new Error('Your message was sent, but the file upload failed. Please retry the attachment.'); }
      form.reset(); $('#vpm-file-name').textContent = ''; clearReply(); await loadMessages(false);
    } catch (error) { alert(error.message); } finally { sendButton.disabled = false; }
  }

  function openConversationModal(groupOnly = false) {
    const panel = document.createElement('div'); panel.className = 'vpm-modal';
    const typeField = groupOnly ? '<input type="hidden" name="type" value="group"><p class="vpm-modal-context">A team group is visible to the authorized members you select.</p>' : '<label class="vpm-field-label">Conversation type<select name="type"><option value="direct">Direct message</option><option value="group">Team group</option><option value="project">Project discussion</option><option value="task">Task discussion</option></select></label>';
    const title = groupOnly ? 'Create a team group' : 'Start a focused conversation';
    const eyebrow = groupOnly ? 'NEW TEAM GROUP' : 'NEW CONVERSATION';
    panel.innerHTML = `<div class="vpm-modal-card vpm-connect-modal"><button type="button" class="vpm-modal-close" aria-label="Close">×</button><p class="vpm-eyebrow">${eyebrow}</p><h2>${title}</h2><p class="vpm-muted">Only selected authorized portal members will be able to read it.</p><form id="vpm-conversation-form" class="vpm-form">${typeField}<label class="vpm-field-label">Title<input name="title" placeholder="${groupOnly ? 'e.g. Design Team' : 'Optional title'}" ${groupOnly ? 'required' : ''}></label><div class="vpm-member-picker"><span class="vpm-field-label">Add members</span>${state.members.filter((member) => Number(member.id) !== Number(VPM.userId)).map((member) => `<label class="vpm-member-option"><input type="checkbox" name="memberIds" value="${member.id}">${avatar(member.avatar_url, member.name)}<span><strong>${esc(member.name)}</strong><small>${esc(member.email || 'Portal member')}</small></span></label>`).join('') || '<p class="vpm-empty">No other authorized members found.</p>'}</div>${groupOnly ? '' : '<label class="vpm-field-label vpm-optional-field">Project or task ID (optional)<input name="referenceId" type="number" min="1" placeholder="Use when this is linked to existing work"></label>'}<button type="submit" class="vpm-btn primary">${groupOnly ? 'Create group' : 'Create conversation'}</button></form></div>`;
    document.body.appendChild(panel); const closeModal = () => { if (panel.isConnected) panel.remove(); }; $('.vpm-modal-close', panel).addEventListener('click', closeModal); $('.vpm-modal-close', panel).addEventListener('pointerup', closeModal); $('#vpm-conversation-form', panel).addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const data = new FormData(form); const type = groupOnly ? 'group' : String(data.get('type')); const memberIds = data.getAll('memberIds').map(Number); if (type === 'direct' && memberIds.length !== 1) { alert('Choose one teammate for a direct message.'); return; } if (type !== 'direct' && !memberIds.length) { alert('Choose at least one teammate.'); return; } const referenceId = Number(data.get('referenceId') || 0); try { await api('connect/conversations', {method:'POST', body:{type, title:String(data.get('title') || ''), memberIds, projectId:type === 'project' ? referenceId : 0, taskId:type === 'task' ? referenceId : 0}}); closeModal(); state.connectFilter = 'all'; state.connectSearch = ''; await renderConnect(); } catch (error) { alert(error.message); } });
  }

  async function renderAnalytics() {
    loading('Calculating work metrics…');
    try {
      const scope = state.boot.capabilities.teamAnalytics ? 'team' : 'own'; const [summary, complexity] = await Promise.all([api(`analytics/summary?scope=${scope}`), api('analytics/complexity')]);
      root().innerHTML = `<section class="vpm-page"><div class="vpm-page-head"><div><p class="vpm-eyebrow">VIBES ANALYTICS</p><h1>${scope === 'team' ? 'Team performance' : 'My performance'}</h1><p>Measured from completed portal work, approvals and deadlines — never timers, screenshots or keystrokes.</p></div><span class="vpm-chip">${scope === 'team' ? 'Team scope' : 'Private scope'}</span></div><div class="vpm-stats">${card('Assigned tasks', summary.assigned)}${card('Completed tasks', summary.completed)}${card('Complexity points', summary.complexityPoints)}${card('On-time delivery', `${summary.onTime}%`)}${card('Open overdue', summary.overdue)}${card('Revision events', summary.revisions)}</div><div class="vpm-grid two"><article class="vpm-panel"><h2>Monthly trend</h2><div class="vpm-table-wrap"><table><thead><tr><th>Month</th><th>Completed</th><th>Points</th></tr></thead><tbody>${(summary.monthly || []).map((row) => `<tr><td>${esc(row.month)}</td><td>${esc(row.tasks)}</td><td>${esc(row.points)}</td></tr>`).join('') || `<tr><td colspan="3">${message('No completed work in the selected period.')}</td></tr>`}</tbody></table></div></article><article class="vpm-panel"><h2>Complexity configuration</h2><p class="vpm-muted">Historical points are stored when overridden; revisions do not count the task twice.</p><div class="vpm-table-wrap"><table><thead><tr><th>Service</th><th>Points</th><th>Effective</th></tr></thead><tbody>${complexity.map((row) => `<tr><td>${esc(row.label)}</td><td>${esc(row.points)}</td><td>${esc(row.effective_from)}</td></tr>`).join('')}</tbody></table></div></article></div></section>`;
    } catch (error) { root().innerHTML = message(error.message, 'error'); }
  }

  async function renderFinance() {
    loading('Opening confidential finance…');
    try {
      const [dashboard, expenses, payments] = await Promise.all([api('finance/dashboard'), api('finance/expenses?perPage=8'), api('finance/payments?perPage=100')]);
      const SAR_PER_USD = 3.75;
      const toUsdAmount = (amount, currency = 'SAR') => {
        const value = Number(amount || 0);
        const code = String(currency || 'USD').toUpperCase();
        if (code === 'SAR') return value / SAR_PER_USD;
        if (code === 'USD') return value;
        return null;
      };
      const formatUsd = (amount) => `USD ${Number(amount || 0).toFixed(2)}`;
      const dashboardCurrency = String(dashboard && dashboard.currency || 'SAR').toUpperCase();
      const dashboardUsd = (field) => {
        const byCurrency = dashboard && dashboard.byCurrency && typeof dashboard.byCurrency === 'object' ? dashboard.byCurrency : null;
        if (byCurrency && Object.keys(byCurrency).length) {
          return Object.entries(byCurrency).reduce((sum, [currency, values]) => {
            const converted = toUsdAmount(values && values[field], currency);
            return converted == null ? sum : sum + converted;
          }, 0);
        }
        const converted = toUsdAmount(dashboard && dashboard[field], dashboardCurrency);
        return converted == null ? 0 : converted;
      };
      const dashboardMetric = (field) => {
        const raw = dashboard && dashboard[field];
        if (raw === null || raw === undefined || raw === '') return '—';
        if (!Number.isFinite(Number(raw))) return String(raw);
        // The server already returns USD totals (book currency); older responses are converted here.
        return formatUsd(dashboardCurrency === 'USD' ? Number(raw) : dashboardUsd(field));
      };
      const categoryLabel = (value) => ({ ai_tools: 'AI tools' }[value] || String(value || 'general').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()));
      root().innerHTML = `<section class="vpm-page"><div class="vpm-page-head"><div><p class="vpm-eyebrow">VIBES FINANCE</p><h1>Confidential finance</h1><p>Owner and explicitly authorized finance users only. Employee performance never changes salary automatically.</p></div><span class="vpm-chip danger">Restricted</span></div><div class="vpm-stats">${card('Invoiced revenue', dashboardMetric('invoiced'))}${card('Payments collected', dashboardMetric('collected'))}${card('Outstanding', dashboardMetric('outstanding'))}${card('Expenses', dashboardMetric('expenses'))}${card('Payroll', dashboardMetric('payroll'))}${card('Estimated profit', dashboardMetric('estimatedProfit'))}</div><div class="vpm-grid two"><article class="vpm-panel"><h2>Record payment</h2><form id="vpm-payment-form" class="vpm-form"><input name="invoiceId" type="number" min="1" placeholder="Existing invoice ID" required><input name="amount" type="number" min="0.01" step="0.01" placeholder="Amount" required><input name="paidAt" type="date" required><input name="reference" placeholder="Reference"><button class="vpm-btn primary">Record partial payment</button></form></article><article class="vpm-panel"><h2>Record expense</h2><form id="vpm-expense-form" class="vpm-form"><select name="category" aria-label="Expense category"><option value="software">Software</option><option value="ai_tools">AI tools</option><option value="hosting">Hosting</option><option value="advertising">Advertising</option><option value="freelancers">Freelancers</option><option value="salaries">Salaries</option><option value="equipment">Equipment</option><option value="general">General</option></select><input name="amount" type="number" min="0.01" step="0.01" placeholder="Amount" required><select name="currency" aria-label="Expense currency"><option value="USD" selected>USD</option><option value="SAR">SAR</option></select><input name="expenseDate" type="date" required><input name="vendor" placeholder="Vendor"><textarea name="description" placeholder="Description"></textarea><button class="vpm-btn primary">Save expense</button></form></article></div><article class="vpm-panel"><h2>Recent expenses</h2><div class="vpm-table-wrap"><table><thead><tr><th>Date</th><th>Category</th><th>Vendor</th><th>Amount</th><th>Currency</th><th><span class="vpm-sr">Actions</span></th></tr></thead><tbody>${(expenses.rows || []).map((row) => row.status === 'voided' ? `<tr class="vpm-voided"><td>${esc(row.expense_date)}</td><td>${esc(categoryLabel(row.category))}</td><td>${esc(row.vendor)}</td><td><s>${esc(row.amount)}</s></td><td>${esc(row.currency)}</td><td><span class="vpm-muted">Voided</span> <button type="button" class="vpm-btn danger" data-delete-expense="${esc(row.id)}">Delete</button></td></tr>` : `<tr><td>${esc(row.expense_date)}</td><td>${esc(categoryLabel(row.category))}</td><td>${esc(row.vendor)}</td><td>${esc(row.amount)}</td><td>${esc(row.currency)}</td><td><button type="button" class="vpm-btn" data-void-expense="${esc(row.id)}">Void</button> <button type="button" class="vpm-btn danger" data-delete-expense="${esc(row.id)}">Delete</button></td></tr>`).join('') || `<tr><td colspan="6">${message('No expenses recorded.')}</td></tr>`}</tbody></table></div></article></section>`;
  const financeRows = Array.isArray(payments.rows) ? payments.rows.map((row) => {
    const sourceCurrency = String(row.currency || 'USD').toUpperCase();
    const sourceAmount = Number(row.amount || 0);
    return {...row, amount:sourceAmount, currency:sourceCurrency, source_amount:sourceAmount, source_currency:sourceCurrency, amount_usd:toUsdAmount(sourceAmount, sourceCurrency), paid_at:String(row.paid_at || row.created_at || '').slice(0, 10), client_name:String(row.client_name || row.company || 'Unassigned client')};
  }) : [];
  const formatFinanceAmount = (row) => row && row.amount_usd != null ? formatUsd(row.amount_usd) : 'USD unavailable';
  const paidClients = [...new Set(financeRows.map((row) => row.client_name).filter(Boolean))].sort((a, b) => a.localeCompare(b));
  const paidYears = [...new Set(financeRows.map((row) => String(row.paid_at || '').slice(0, 4)).filter((year) => /^\d{4}$/.test(year)))].sort((a, b) => Number(b) - Number(a));
  const financePanel = document.createElement('article');
  financePanel.className = 'vpm-panel vpm-finance-insights-panel';
  financePanel.innerHTML = `<div class="vpm-panel-head"><div><p class="vpm-eyebrow">COLLECTIONS</p><h2>Payments collected</h2><p class="vpm-muted">Amounts are shown in USD. SAR is converted at 3.75 SAR/USD; original source-currency records remain unchanged.</p></div><span class="vpm-chip success">${financeRows.length} recorded</span></div><div class="vpm-grid four vpm-finance-filter-grid"><label class="vpm-field"><span>Client</span><select class="vpm-select" aria-label="Client" data-finance-filter="client"><option value="">All clients</option>${paidClients.map((client) => `<option value="${esc(client)}">${esc(client)}</option>`).join('')}</select></label><label class="vpm-field"><span>Month</span><select class="vpm-select" aria-label="Month" data-finance-filter="month"><option value="">All months</option>${Array.from({length: 12}, (_, index) => `<option value="${index + 1}">${new Date(2000, index, 1).toLocaleString([], {month:'long'})}</option>`).join('')}</select></label><label class="vpm-field"><span>Year</span><select class="vpm-select" aria-label="Year" data-finance-filter="year"><option value="">All years</option>${paidYears.map((year) => `<option value="${year}">${year}</option>`).join('')}</select></label><label class="vpm-field"><span>Sort by</span><select class="vpm-select" aria-label="Sort by" data-finance-filter="sort"><option value="recent">Recent first</option><option value="month">Month</option><option value="year">Year</option><option value="client">Client</option></select></label></div><div class="vpm-grid three" data-finance-metrics></div><div class="vpm-card-head"><div><h3>Collection analytics</h3><p class="vpm-muted">Switch between monthly and yearly totals. SAR payments are converted at 3.75 SAR/USD; original records remain unchanged.</p></div><label class="vpm-field vpm-finance-analytics-field"><span>View</span><select class="vpm-select" aria-label="View" data-finance-analytics><option value="month">Monthly</option><option value="year">Yearly</option></select></label></div><div class="vpm-table-wrap"><table><thead><tr><th>Period</th><th>Payments</th><th>Clients</th><th>Collected</th></tr></thead><tbody data-finance-analytics-rows></tbody></table></div><div class="vpm-card-head"><div><h3>Payment records</h3><p class="vpm-muted" data-finance-filter-status></p></div></div><div class="vpm-table-wrap"><table><thead><tr><th>Client</th><th>Invoice</th><th>Paid date</th><th>Amount</th><th>Reference</th></tr></thead><tbody data-finance-payment-rows></tbody></table></div>`;
  const financeSection = root().querySelector('section.vpm-page');
  if (financeSection) financeSection.appendChild(financePanel);
  const filter = (name) => financePanel.querySelector(`[data-finance-filter="${name}"]`);
  const analyticsMode = financePanel.querySelector('[data-finance-analytics]');
  const paymentRowsNode = financePanel.querySelector('[data-finance-payment-rows]');
  const analyticsRowsNode = financePanel.querySelector('[data-finance-analytics-rows]');
  const metricsNode = financePanel.querySelector('[data-finance-metrics]');
  const statusNode = financePanel.querySelector('[data-finance-filter-status]');
  const asDate = (value) => { const stamp = Date.parse(String(value || '').replace(' ', 'T')); return Number.isNaN(stamp) ? new Date(0) : new Date(stamp); };
  const amountByUsd = (rows) => formatUsd(rows.reduce((total, row) => total + (Number(row.amount_usd) || 0), 0));
  const filteredRows = () => {
    const clientValue = filter('client')?.value || '';
    const monthValue = Number(filter('month')?.value || 0);
    const yearValue = filter('year')?.value || '';
    const rows = financeRows.filter((row) => { const date = asDate(row.paid_at); return (!clientValue || row.client_name === clientValue) && (!monthValue || date.getMonth() + 1 === monthValue) && (!yearValue || String(date.getFullYear()) === yearValue); });
    const sortValue = filter('sort')?.value || 'recent';
    return rows.sort((left, right) => { if (sortValue === 'client') return left.client_name.localeCompare(right.client_name) || asDate(right.paid_at) - asDate(left.paid_at); if (sortValue === 'month') return (asDate(right.paid_at).getMonth() - asDate(left.paid_at).getMonth()) || asDate(right.paid_at) - asDate(left.paid_at); if (sortValue === 'year') return (asDate(right.paid_at).getFullYear() - asDate(left.paid_at).getFullYear()) || asDate(right.paid_at) - asDate(left.paid_at); return asDate(right.paid_at) - asDate(left.paid_at); });
  };
  const renderFinanceInsights = () => {
    const rows = filteredRows();
    const mode = analyticsMode?.value || 'month';
    const groups = {};
    rows.forEach((row) => { const date = asDate(row.paid_at); const key = mode === 'year' ? String(date.getFullYear()) : `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`; if (!groups[key]) groups[key] = {period:key, payments:0, clients:new Set(), rows:[]}; groups[key].payments += 1; groups[key].clients.add(row.client_name); groups[key].rows.push(row); });
    const groupRows = Object.values(groups).sort((a, b) => b.period.localeCompare(a.period));
    if (metricsNode) metricsNode.innerHTML = [card('Payments shown', rows.length), card('Clients who paid', new Set(rows.map((row) => row.client_name)).size), card('Collected in view', amountByUsd(rows))].join('');
    if (statusNode) statusNode.textContent = `${rows.length} payment record${rows.length === 1 ? '' : 's'} shown`;
    if (analyticsRowsNode) analyticsRowsNode.innerHTML = groupRows.length ? groupRows.map((group) => `<tr><td>${esc(mode === 'month' ? `${group.period.slice(0, 4)}-${group.period.slice(5)} (${new Date(Number(group.period.slice(0, 4)), Number(group.period.slice(5)) - 1, 1).toLocaleString([], {month:'short'})})` : group.period)}</td><td>${group.payments}</td><td>${group.clients.size}</td><td>${esc(amountByUsd(group.rows))}</td></tr>`).join('') : `<tr><td colspan="4">${message('No payment analytics match these filters.')}</td></tr>`;
    if (paymentRowsNode) paymentRowsNode.innerHTML = rows.length ? rows.map((row) => `<tr><td>${esc(row.client_name)}</td><td>${esc(row.invoice_number || `Invoice #${row.invoice_id || '—'}`)}</td><td>${esc(row.paid_at || '—')}</td><td>${esc(formatFinanceAmount(row))}</td><td>${esc(row.reference || '—')}</td></tr>`).join('') : `<tr><td colspan="5">${message('No recorded payments match these filters.')}</td></tr>`;
  };
  financePanel.querySelectorAll('[data-finance-filter], [data-finance-analytics]').forEach((control) => control.addEventListener('change', renderFinanceInsights));
  renderFinanceInsights();
      $('#vpm-payment-form').addEventListener('submit', async (event) => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); try { await api('finance/payments', {method:'POST', body:data}); await renderFinance(); } catch (error) { alert(error.message); } });
      $('#vpm-expense-form').addEventListener('submit', async (event) => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); try { await api('finance/expenses', {method:'POST', body:data}); await renderFinance(); } catch (error) { alert(error.message); } });
      root().querySelectorAll('[data-delete-expense]').forEach((button) => button.addEventListener('click', async () => { if (!confirm('Delete this expense permanently? This cannot be undone.')) return; try { await api(`finance/expenses/${button.dataset.deleteExpense}?permanent=1`, {method:'DELETE'}); await renderFinance(); } catch (error) { alert(error.message); } }));
      root().querySelectorAll('[data-void-expense]').forEach((button) => button.addEventListener('click', async () => { if (!confirm('Void this expense? It stays in the history but no longer counts in totals.')) return; try { await api(`finance/expenses/${button.dataset.voidExpense}`, {method:'DELETE'}); await renderFinance(); } catch (error) { alert(error.message); } }));
    } catch (error) { root().innerHTML = message(error.message, 'error'); }
  }

  async function renderPayslips() {
    ensurePayslipStyles();
    loading('Opening payslips…');
    try {
      const [employees, leaveRows, salaryHistory] = await Promise.all([api('employees'), api('leave'), api('finance/salaries')]);
      const staff = (Array.isArray(employees) ? employees : []).filter((row) => row && row.active !== false);
      const periods = Array.from({length: 12}, (_, index) => { const date = new Date(); date.setDate(1); date.setMonth(date.getMonth() - index); return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0'); });
      const defaultEmployee = staff.find((row) => String(row.role || '').toLowerCase() !== 'admin') || staff[0] || {};
      const defaultPeriod = periods[0];
      const monthLabel = (period) => { const [year, month] = String(period).split('-').map(Number); return year && month ? new Date(Date.UTC(year, month - 1, 1)).toLocaleString([], {month:'long', year:'numeric', timeZone:'UTC'}) : period; };
      const overlapDays = (startValue, endValue, period) => { const [year, month] = String(period).split('-').map(Number); if (!year || !month) return 0; const monthStart = Date.UTC(year, month - 1, 1); const monthEnd = Date.UTC(year, month, 0); const start = Date.parse(String(startValue).slice(0, 10) + 'T00:00:00Z'); const end = Date.parse(String(endValue).slice(0, 10) + 'T00:00:00Z'); if (!Number.isFinite(start) || !Number.isFinite(end)) return 0; const clippedStart = Math.max(start, monthStart); const clippedEnd = Math.min(end, monthEnd); return clippedEnd < clippedStart ? 0 : Math.floor((clippedEnd - clippedStart) / 86400000) + 1; };
      const leaveSummary = (userId, period) => { const summary = {annual:0, sick:0}; (Array.isArray(leaveRows) ? leaveRows : []).forEach((row) => { if (Number(row.user_id) !== Number(userId) || String(row.status).toLowerCase() !== 'approved') return; const days = overlapDays(row.start_date, row.end_date, period); if (!days) return; const type = String(row.leave_type || '').toLowerCase(); if (type === 'sick' || type === 'sick_leave' || type === 'sick-leave') summary.sick += days; else summary.annual += days; }); return summary; };
      const salaryFor = (employee, period) => { const profileAmount = Number(employee.salaryAmount || 0); if (profileAmount > 0) return {amount:profileAmount, source:'Employee profile'}; const [year, month] = String(period).split('-').map(Number); const monthStart = year && month ? year + '-' + String(month).padStart(2, '0') + '-01' : period + '-01'; const rows = (Array.isArray(salaryHistory) ? salaryHistory : []).filter((row) => Number(row.user_id) === Number(employee.id) && String(row.effective_from || '') <= monthStart && (!row.effective_to || String(row.effective_to) >= monthStart)).sort((a,b) => String(b.effective_from).localeCompare(String(a.effective_from))); const row = rows[0]; return row ? {amount:Number(row.amount || 0), source:'Salary history'} : {amount:0, source:'Not configured'}; };
      const money = (amount) => 'USD ' + Number(amount || 0).toFixed(2);
      root().innerHTML = '<section class="vpm-page vpm-payslips-page"><div class="vpm-page-head"><div><p class="vpm-eyebrow">FINANCE</p><h1>Payslips</h1><p>Prepare a monthly payslip from the employee profile and approved leave records.</p></div><span class="vpm-chip danger">Confidential</span></div><div class="vpm-grid two"><article class="vpm-panel"><div class="vpm-panel-head"><div><h2>Build a payslip</h2><p class="vpm-muted">Preview, download, print, or send the selected month.</p></div></div><form id="vpm-payslip-form" class="vpm-form"><label class="vpm-field"><span>Employee</span><select name="employee" class="vpm-select">' + staff.map((row) => '<option value="' + esc(row.id) + '" ' + (Number(row.id) === Number(defaultEmployee.id) ? 'selected' : '') + '>' + esc(row.name || row.display_name || row.email) + '</option>').join('') + '</select></label><label class="vpm-field"><span>Pay period</span><select name="period" class="vpm-select">' + periods.map((period) => '<option value="' + period + '" ' + (period === defaultPeriod ? 'selected' : '') + '>' + esc(monthLabel(period)) + '</option>').join('') + '</select></label><div class="vpm-payslip-actions"><button class="vpm-btn primary" type="submit">Preview payslip</button><button class="vpm-btn" type="button" data-payslip-download>Download PDF</button><button class="vpm-btn" type="button" data-payslip-print>Print payslip</button><button class="vpm-btn" type="button" data-payslip-send>Send to employee</button></div><p class="vpm-muted" data-payslip-status>PDF downloads are generated from the selected employee and month.</p></form></article><article class="vpm-panel"><div class="vpm-panel-head"><div><h2>Monthly checks</h2><p class="vpm-muted">Approved leave is counted only inside the selected pay period.</p></div></div><div class="vpm-stats" data-payslip-checks></div></article></div><article class="vpm-panel vpm-payslip-sheet" data-payslip-sheet aria-live="polite"></article></section>';
      const form = document.querySelector('#vpm-payslip-form'); const sheet = document.querySelector('[data-payslip-sheet]'); const checks = document.querySelector('[data-payslip-checks]'); const status = document.querySelector('[data-payslip-status]'); const printButton = document.querySelector('[data-payslip-print]'); const downloadButton = document.querySelector('[data-payslip-download]'); const sendButton = document.querySelector('[data-payslip-send]');
      const current = () => { const employee = staff.find((row) => Number(row.id) === Number(form.employee.value)) || defaultEmployee; const period = form.period.value || defaultPeriod; return {employee, period, salary:salaryFor(employee, period), leave:leaveSummary(employee.id, period)}; };
      const draw = () => { const {employee, period, salary, leave} = current(); const issued = new Date().toLocaleDateString([], {day:'2-digit', month:'short', year:'numeric'}); checks.innerHTML = [card('Basic salary', money(salary.amount)), card('Annual leave taken', leave.annual + ' day' + (leave.annual === 1 ? '' : 's')), card('Sick leave taken', leave.sick + ' day' + (leave.sick === 1 ? '' : 's'))].join(''); sheet.innerHTML = '<div class="vpm-payslip-brand"><div><div class="vpm-payslip-brand-mark">VIBES AGENCY</div><div class="vpm-payslip-title">PAYSLIP</div></div><div class="vpm-payslip-meta"><div><span>Pay period</span><strong>' + esc(monthLabel(period)) + '</strong></div><div><span>Issued</span><strong>' + esc(issued) + '</strong></div><div><span>Currency</span><strong>USD</strong></div></div></div><div class="vpm-payslip-employee"><div><span>Employee</span><strong>' + esc(employee.name || employee.email || 'Employee') + '</strong><small>' + esc(employee.email || '') + '</small></div><div><span>Payment method</span><strong>Monthly salary</strong><small>Source: ' + esc(salary.source) + '</small></div></div><table class="vpm-payslip-table"><thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody><tr><td>Basic salary</td><td>' + esc(money(salary.amount)) + '</td></tr><tr><td>Annual leaves taken</td><td>' + leave.annual + ' day' + (leave.annual === 1 ? '' : 's') + '</td></tr><tr><td>Sick leaves taken</td><td>' + leave.sick + ' day' + (leave.sick === 1 ? '' : 's') + '</td></tr></tbody></table><div class="vpm-payslip-note">Leave figures include approved leave overlapping ' + esc(monthLabel(period)) + ' only. This payslip is confidential and intended for the named employee.</div><div class="vpm-payslip-total"><span>Net salary</span><strong>' + esc(money(salary.amount)) + '</strong></div><div class="vpm-payslip-footer">Vibes Agency · Confidential employee document</div>'; };
      const pdfFetch = async (send = false) => { const {employee, period} = current(); const response = await fetch(VPM.root + (send ? 'payslips/send' : 'payslips/pdf'), {method:'POST', headers:{'X-WP-Nonce':VPM.nonce, 'Content-Type':'application/json'}, credentials:'same-origin', body:JSON.stringify({employeeId:employee.id, period})}); if (!response.ok) { const data = await response.json().catch(() => ({})); throw new Error(data.message || 'The payslip request could not be completed.'); } return response; };
      form.addEventListener('submit', (event) => { event.preventDefault(); draw(); status.textContent = 'Preview updated for the selected employee and month.'; });
      printButton.addEventListener('click', () => { draw(); window.print(); });
      downloadButton.addEventListener('click', async () => { try { status.textContent = 'Preparing PDF…'; const response = await pdfFetch(false); const blob = await response.blob(); const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'Payslip_' + String(current().employee.name || 'Employee').replace(/[^a-z0-9]+/gi, '_') + '_' + current().period.replace('-', '') + '.pdf'; document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url); status.textContent = 'PDF downloaded successfully.'; } catch (error) { status.textContent = error.message; } });
      sendButton.addEventListener('click', async () => { const {employee} = current(); if (!employee.email || !window.confirm('Send this payslip to ' + employee.email + '?')) return; try { status.textContent = 'Sending payslip…'; await pdfFetch(true); status.textContent = 'Payslip sent to ' + employee.email + '.'; } catch (error) { status.textContent = error.message; } });
      draw();
    } catch (error) { root().innerHTML = message(error.message, 'error'); }
  }
  async function renderLeadDiscoverySection(currentRows = []) {
    const previous = document.getElementById('vpm-lead-discovery'); if (previous) previous.remove();
    const section = document.createElement('article'); section.id = 'vpm-lead-discovery'; section.className = 'vpm-panel vpm-lead-discovery';
    const serviceOptions = ['AI Video Production','Video Editing','Motion Graphics','2D/3D Animation','CGI','Branding','Graphic Design','Social Media Management','Presentations','Website Design & Development','Digital Marketing'];
    const serviceMarkup = serviceOptions.map((item) => '<option value="' + esc(item) + '">' + esc(item) + '</option>').join('');
    section.innerHTML = [
      '<div class="vpm-panel-head"><div><p class="vpm-eyebrow">VIBES CRM</p><h2>Lead Discovery</h2><p class="vpm-muted">Find GCC businesses, review recommendations, and move qualified leads into the existing pipeline.</p></div><span class="vpm-chip" id="vpm-discovery-provider-badge">Provider status</span></div>',
      '<div class="vpm-grid two vpm-discovery-config-grid">',
        '<div class="vpm-form"><label>Keywords <small>English or Arabic, comma separated</small><input id="vpm-discovery-keywords" placeholder="Hotels, luxury hotels, فنادق"></label><div id="vpm-discovery-suggestions" class="vpm-chip-row"></div><div class="vpm-form-row"><button type="button" class="vpm-link" id="vpm-expand-keywords">✨ Expand keywords</button><button type="button" class="vpm-link" id="vpm-save-search">Save search</button></div></div>',
        '<div class="vpm-form"><label>Countries <small>Choose one or more</small><select id="vpm-discovery-countries" multiple size="4"><option>Saudi Arabia</option><option>United Arab Emirates</option><option>Qatar</option><option>Kuwait</option><option>Bahrain</option><option>Oman</option></select></label><label>Cities <input id="vpm-discovery-cities" placeholder="Riyadh, Jeddah, Dubai"></label></div>',
        '<div class="vpm-form"><label>Industry / category <input id="vpm-discovery-industry" placeholder="Hospitality, real estate, healthcare"></label><label>Vibes service opportunity <select id="vpm-discovery-services" multiple size="5">' + serviceMarkup + '</select></label></div>',
        '<div class="vpm-form"><label>Automatic scan <span class="vpm-switch"><input id="vpm-discovery-auto" type="checkbox"><i></i></span></label><label>Frequency <select id="vpm-discovery-frequency"><option value="manual">Manual</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label><div class="vpm-form-row"><label>Max results <input id="vpm-discovery-max" type="number" min="1" max="100" value="50"></label><label>Spend cap (USD) <input id="vpm-discovery-spend" type="number" min="0" step="0.01" value="0"></label></div></div>',
      '</div>',
      '<div class="vpm-form-row vpm-discovery-actions"><button type="button" class="vpm-btn primary" id="vpm-discovery-search">Manual search</button><button type="button" class="vpm-btn" id="vpm-discovery-scan">Run configured scan</button><select id="vpm-discovery-saved"><option value="">Saved searches</option></select><span class="vpm-muted" id="vpm-discovery-status">Ready.</span></div>',
      '<div id="vpm-discovery-similarity-banner" class="vpm-discovery-similarity-banner" hidden></div>',
      '<div class="vpm-grid four vpm-discovery-stats"><div id="vpm-discovery-stat-found"></div><div id="vpm-discovery-stat-new"></div><div id="vpm-discovery-stat-review"></div><div id="vpm-discovery-stat-usage"></div></div>',
      '<div class="vpm-discovery-table-wrap"><table class="vpm-table"><thead><tr><th>Company</th><th>Location</th><th>Industry</th><th>Website / contact</th><th>Suggested services</th><th>Score</th><th>Similarity</th><th>Status</th><th>Actions</th></tr></thead><tbody id="vpm-discovery-results"><tr><td colspan="9" class="vpm-empty">Enter keywords and run a search.</td></tr></tbody></table></div>',
      '<div class="vpm-grid two"><div class="vpm-panel vpm-discovery-note"><h3>Provider and privacy controls</h3><p class="vpm-muted">Google Places is designed as a server-side provider. API keys are never placed in this page. Until a server-side provider credential is configured, results come from permitted CRM records and the existing public RSS / official JSON scanner.</p><div id="vpm-google-config" class="vpm-form-row" hidden><input id="vpm-google-api-key" type="password" autocomplete="new-password" placeholder="Restricted Google Places API key"><button type="button" class="vpm-btn" id="vpm-google-configure">Save server credential</button></div><p class="vpm-muted">Admin setup stores the credential server-side and never renders it in the portal.</p></p><p class="vpm-muted">Discovered businesses remain in review until an authorized user approves them. No unsolicited email, WhatsApp, or SMS is sent.</p></div><div class="vpm-panel"><h3>Search history</h3><div id="vpm-discovery-history" class="vpm-history-list"><p class="vpm-empty">No searches yet.</p></div></div></div>'
    ].join('');
    const anchor = $('.vpm-grid.two', root()); if (anchor) anchor.before(section); else root().appendChild(section);
    const $d = (selector) => $(selector, section);
    const savedKey = 'vpm_lead_discovery_searches_v1'; const historyKey = 'vpm_lead_discovery_history_v1'; const laterKey = 'vpm_lead_discovery_saved_v1';
    const readStore = (key) => { try { const value = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(value) ? value : []; } catch (error) { return []; } };
    const writeStore = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); } catch (error) {} };
    const words = { hotels:['luxury hotels','resorts','serviced apartments','boutique hotels','hospitality companies','فنادق فاخرة','منتجعات','شقق فندقية','فنادق بوتيك','شركات ضيافة'], 'real estate':['real estate developers','property developers','mixed-use developers','commercial real estate','مطورو العقارات','شركات التطوير العقاري'], it:['software companies','technology companies','managed IT services','cloud providers','شركات تقنية','خدمات تقنية'], healthcare:['medical clinics','specialty clinics','hospitals','healthcare providers','عيادات طبية','مراكز صحية'], construction:['construction companies','general contractors','engineering firms','مقاولات','شركات إنشاءات'], ecommerce:['e-commerce businesses','online retailers','marketplaces','متاجر إلكترونية','تجارة إلكترونية'] };
    const relatedCategories = { hotel:['hotel','hotels','resort','resorts','hospitality','serviced apartment','فنادق','منتجع','ضيافة'], 'real estate':['real estate','property','developer','developers','عقار','عقارات','تطوير عقاري'], it:['it','technology','software','cloud','tech','تقنية','تكنولوجيا','برمجيات'], healthcare:['health','healthcare','clinic','clinics','hospital','medical','صحة','رعاية صحية','عيادة','مستشفى'], construction:['construction','contractor','engineering','infrastructure','مقاولات','إنشاءات','هندسة'], ecommerce:['ecommerce','e-commerce','retail','marketplace','online','تجارة إلكترونية','متجر إلكتروني'] };
    let dataRows = Array.isArray(currentRows) ? currentRows.slice() : []; let similarityContext = null;
    const readConfig = () => ({keywords:$d('#vpm-discovery-keywords').value.trim(), countries:Array.from($d('#vpm-discovery-countries').selectedOptions).map((option) => option.value), cities:$d('#vpm-discovery-cities').value.trim(), industry:$d('#vpm-discovery-industry').value.trim(), services:Array.from($d('#vpm-discovery-services').selectedOptions).map((option) => option.value), automatic:$d('#vpm-discovery-auto').checked, frequency:$d('#vpm-discovery-frequency').value, maxResults:Number($d('#vpm-discovery-max').value || 50), spendCap:Number($d('#vpm-discovery-spend').value || 0)});
    const fillConfig = (config) => { $d('#vpm-discovery-keywords').value = config.keywords || ''; $d('#vpm-discovery-cities').value = config.cities || ''; $d('#vpm-discovery-industry').value = config.industry || ''; $d('#vpm-discovery-auto').checked = !!config.automatic; $d('#vpm-discovery-frequency').value = config.frequency || 'manual'; $d('#vpm-discovery-max').value = config.maxResults || 50; $d('#vpm-discovery-spend').value = config.spendCap || 0; Array.from($d('#vpm-discovery-countries').options).forEach((option) => { option.selected = (config.countries || []).includes(option.value); }); Array.from($d('#vpm-discovery-services').options).forEach((option) => { option.selected = (config.services || []).includes(option.value); }); };
    const setStatus = (text, kind) => { const node = $d('#vpm-discovery-status'); if (!node) return; node.textContent = text; node.className = 'vpm-muted' + (kind ? ' ' + kind : ''); };
    const setStats = (found, fresh, review, usage) => { const stats = [['#vpm-discovery-stat-found','Businesses found',found],['#vpm-discovery-stat-new','New leads',fresh],['#vpm-discovery-stat-review','Needs review',review],['#vpm-discovery-stat-usage','API usage',usage]]; stats.forEach(([selector,label,value]) => { const node = $d(selector); if (node) node.innerHTML = card(label, value); }); };
    const normalized = () => dataRows.map((lead) => ({id:lead.id, company:lead.company || 'Unnamed business', country:lead.country || '', city:lead.city || '', industry:lead.industry || 'Not specified', website:lead.website || '', phone:lead.contact_phone || lead.contactPhone || '', services:Array.isArray(lead.services) ? lead.services : (Array.isArray(lead.services_json) ? lead.services_json : []), score:Number(lead.lead_score || 0), source:lead.lead_source || 'CRM', discovered:lead.created_at || '', status:lead.review_status || 'pending', stage:lead.stage_key || '', evidence:lead.evidence_url || ''}));
    const lower = (value) => String(value || '').toLowerCase();
    const tokens = (value) => lower(value).split(/[^a-z0-9\u0600-\u06ff]+/).map((item) => item.trim()).filter((item) => item.length > 1);
    const overlap = (left, right) => { const a = new Set(tokens(left)); const b = new Set(tokens(right)); if (!a.size || !b.size) return 0; let matches = 0; a.forEach((item) => { if (b.has(item)) matches += 1; }); return Math.min(1, matches / Math.max(1, Math.min(a.size, b.size))); };
    const categoryKey = (industry) => Object.keys(relatedCategories).find((key) => relatedCategories[key].some((term) => lower(industry).includes(term))) || '';
    const categoryTerms = (industry) => { const key = categoryKey(industry); return key ? relatedCategories[key] : tokens(industry); };
    const setSimilarityBanner = () => { const banner = $d('#vpm-discovery-similarity-banner'); if (!banner) return; if (!similarityContext) { banner.hidden = true; banner.innerHTML = ''; return; } banner.hidden = false; banner.innerHTML = '<strong>Similarity search</strong><span>Based on ' + esc(similarityContext.company) + ' · ' + esc(similarityContext.geoLabel) + ' · ' + esc(similarityContext.industryMode === 'related' ? 'same or related industry' : 'same industry') + '</span><button type="button" class="vpm-link" data-discovery-clear-similarity>Clear</button>'; };
    const computeSimilarity = (row, context) => { const industryText = row.industry + ' ' + row.company; const sourceTerms = categoryTerms(context.industry); const exactIndustry = lower(row.industry).includes(lower(context.industry)) || (context.industry && lower(context.industry).includes(lower(row.industry)));
      const relatedIndustry = sourceTerms.some((term) => lower(industryText).includes(lower(term))); const industryScore = exactIndustry ? 1 : (relatedIndustry ? 0.65 : 0);
      const sameCountry = !context.country || !row.country || lower(row.country).includes(lower(context.country)) || lower(context.country).includes(lower(row.country)); const sameCity = !context.city || !row.city || lower(row.city).includes(lower(context.city)) || lower(context.city).includes(lower(row.city));
      const geoScore = sameCity ? 1 : (sameCountry ? 0.7 : 0.35); const activityScore = Math.max(overlap(context.keywords, row.industry + ' ' + row.company + ' ' + row.services.join(' ')), relatedIndustry ? 0.65 : 0); const serviceScore = context.services.length ? overlap(context.services.join(' '), row.services.join(' ')) : (row.services.length ? 0.5 : 0);
      const score = Math.round(industryScore * 40 + geoScore * 25 + activityScore * 20 + serviceScore * 15); const reasons = ['Industry match: ' + (exactIndustry ? 'verified same category' : relatedIndustry ? 'related category' : 'limited match'), 'Geography: ' + (sameCity ? 'same city' : sameCountry ? 'same country' : 'other GCC location'), 'Business activity: ' + Math.round(activityScore * 100) + '% overlap', 'Vibes service fit: ' + (context.services.length ? Math.round(serviceScore * 100) + '% overlap' : 'recommendation pending')]; return {score, reasons, sameCountry, sameCity}; };
    const isCandidate = (row, context) => { if (String(row.id) === String(context.sourceId)) return false; const sameCountry = !context.country || !row.country || lower(row.country).includes(lower(context.country)) || lower(context.country).includes(lower(row.country)); const sameCity = !context.city || !row.city || lower(row.city).includes(lower(context.city)) || lower(context.city).includes(lower(row.city)); if (context.geoMode === 'city' && !sameCity) return false; if ((context.geoMode === 'nearby' || context.geoMode === 'country') && !sameCountry) return false; if (context.websiteRequired && !row.website) return false; if (context.contactRequired && !row.phone && !row.website) return false; if (row.score < context.minScore) return false; const terms = categoryTerms(context.industry); const sameIndustry = lower(row.industry).includes(lower(context.industry)) || lower(context.industry).includes(lower(row.industry)); const related = terms.some((term) => lower(row.industry + ' ' + row.company).includes(lower(term))); if (context.industryMode === 'same' && !sameIndustry) return false; if (context.industryMode === 'related' && !sameIndustry && !related) return false; return true; };
    const renderResults = (rows) => { const body = $d('#vpm-discovery-results'); if (!body) return; if (!rows.length) { body.innerHTML = '<tr><td colspan="9" class="vpm-empty">No matching records. Configure a permitted provider to discover new businesses.</td></tr>'; return; } body.innerHTML = rows.map((row) => { const location = [row.city,row.country].filter(Boolean).join(', ') || '—'; const contact = [row.website ? '<a class="vpm-link" href="' + esc(row.website) + '" target="_blank" rel="noopener">Website ↗</a>' : '', row.phone ? '<span>' + esc(row.phone) + '</span>' : ''].filter(Boolean).join('<br>') || '—'; const similarity = row.similarity != null ? '<strong>' + esc(row.similarity) + '%</strong><small>' + esc((row.similarityReasons || []).slice(0,2).join(' · ')) + '</small>' : '—'; const actions = ['<button type="button" class="vpm-link" data-discovery-details="' + esc(row.id) + '">View details</button>','<button type="button" class="vpm-link" data-discovery-analyze="' + esc(row.id) + '">Analyze</button>','<button type="button" class="vpm-link" data-discovery-similar="' + esc(row.id) + '">Find similar</button>','<button type="button" class="vpm-link" data-discovery-save-later="' + esc(row.id) + '">Save for later</button>', row.status === 'approved' && row.stage !== 'won' ? '<button type="button" class="vpm-link" data-discovery-convert="' + esc(row.id) + '">Create opportunity</button>' : '<button type="button" class="vpm-link" data-discovery-review="' + esc(row.id) + '" data-action="save">Add to review</button>', row.status === 'approved' ? '<button type="button" class="vpm-link" data-discovery-owner="' + esc(row.id) + '">Assign me</button>' : '', '<button type="button" class="vpm-link danger" data-discovery-review="' + esc(row.id) + '" data-action="reject">Dismiss</button>'].join(''); return '<tr><td><strong>' + esc(row.company) + '</strong><small>' + esc(row.source) + '</small></td><td>' + esc(location) + '</td><td>' + esc(row.industry) + '</td><td>' + contact + '</td><td>' + esc(row.services.join(' · ') || 'Recommendation pending') + '</td><td><strong>' + esc(row.score) + '</strong><small>' + (row.score >= 70 ? 'High priority' : row.score >= 40 ? 'Medium priority' : 'Low priority') + '</small></td><td>' + similarity + '</td><td><span class="vpm-chip">' + esc(row.status) + '</span></td><td><div class="vpm-lead-actions">' + actions + '</div></td></tr>'; }).join(''); };
    const applyFilters = () => { const config = readConfig(); const tokensToMatch = config.keywords.toLowerCase().split(',').map((value) => value.trim()).filter(Boolean); const cityTokens = config.cities.toLowerCase().split(',').map((value) => value.trim()).filter(Boolean); let filtered = normalized().filter((row) => { const haystack = [row.company,row.country,row.city,row.industry,row.services.join(' ')].join(' ').toLowerCase(); const keywordMatch = !tokensToMatch.length || tokensToMatch.some((token) => haystack.includes(token)); const countryMatch = !config.countries.length || config.countries.some((country) => row.country.toLowerCase().includes(country.toLowerCase())); const cityMatch = !cityTokens.length || cityTokens.some((city) => row.city.toLowerCase().includes(city)); const industryMatch = !config.industry || row.industry.toLowerCase().includes(config.industry.toLowerCase()) || (similarityContext && similarityContext.industryMode === 'related' && categoryTerms(similarityContext.industry).some((term) => row.industry.toLowerCase().includes(term))); const serviceMatch = !config.services.length || config.services.some((service) => row.services.join(' ').toLowerCase().includes(service.toLowerCase())); return keywordMatch && countryMatch && cityMatch && industryMatch && serviceMatch; });
      if (similarityContext) { filtered = normalized().filter((row) => isCandidate(row, similarityContext)).map((row) => { const result = computeSimilarity(row, similarityContext); return Object.assign({}, row, {similarity:result.score, similarityReasons:result.reasons}); }).sort((a,b) => b.similarity - a.similarity); }
      filtered = filtered.slice(0, Math.max(1, config.maxResults || 50)); renderResults(filtered); const fresh = filtered.filter((row) => row.status === 'pending').length; const review = filtered.filter((row) => row.status === 'pending' || row.status === 'needs_review').length; setStats(filtered.length, fresh, review, similarityContext ? '0 calls · confirm before provider search' : '0 calls'); const history = readStore(historyKey); history.unshift({at:new Date().toISOString(), keywords:similarityContext ? 'Similar to ' + similarityContext.company : (config.keywords || 'All records'), count:filtered.length, mode:similarityContext ? 'similarity' : 'finder'}); writeStore(historyKey, history.slice(0,10)); renderHistory(); return filtered; };
    const renderSaved = () => { const select = $d('#vpm-discovery-saved'); if (!select) return; const saved = readStore(savedKey); select.innerHTML = '<option value="">Saved searches</option>' + saved.map((item) => '<option value="' + esc(item.id) + '">' + esc(item.name || item.keywords || 'Saved search') + '</option>').join(''); };
    const renderHistory = () => { const history = readStore(historyKey); const target = $d('#vpm-discovery-history'); if (!target) return; target.innerHTML = history.length ? history.map((item) => '<div class="vpm-history-item"><strong>' + esc(item.keywords) + '</strong><small>' + esc(new Date(item.at).toLocaleString()) + ' · ' + esc(item.count) + ' result(s)' + (item.mode === 'similarity' ? ' · similarity' : '') + '</small></div>').join('') : '<p class="vpm-empty">No searches yet.</p>'; };
    const expandKeywords = () => { const base = ($d('#vpm-discovery-keywords').value.split(',')[0] || '').trim().toLowerCase(); const suggestions = words[base] || words[Object.keys(words).find((key) => base.includes(key))] || []; $d('#vpm-discovery-suggestions').innerHTML = suggestions.length ? suggestions.map((item) => '<button type="button" class="vpm-chip" data-discovery-suggestion="' + esc(item) + '">' + esc(item) + '</button>').join('') : '<span class="vpm-muted">Try hotels, real estate, IT, healthcare, construction, or ecommerce.</span>'; $d('#vpm-discovery-suggestions').querySelectorAll('[data-discovery-suggestion]').forEach((button) => button.addEventListener('click', () => { const current = $d('#vpm-discovery-keywords').value.split(',').map((item) => item.trim()).filter(Boolean); if (!current.includes(button.dataset.discoverySuggestion)) current.push(button.dataset.discoverySuggestion); $d('#vpm-discovery-keywords').value = current.join(', '); })); };
    const closeModal = () => document.getElementById('vpm-discovery-modal')?.remove();
    const showModal = (title, body) => { closeModal(); const modal = document.createElement('div'); modal.id = 'vpm-discovery-modal'; modal.className = 'vpm-discovery-modal'; modal.innerHTML = '<div class="vpm-discovery-modal-card"><div class="vpm-panel-head"><div><p class="vpm-eyebrow">VIBES CRM</p><h3>' + esc(title) + '</h3></div><button type="button" class="vpm-link" data-discovery-close>Close</button></div><div class="vpm-discovery-modal-body">' + body + '</div></div>'; root().appendChild(modal); modal.addEventListener('click', (event) => { if (event.target === modal || event.target.closest('[data-discovery-close]')) closeModal(); }); return modal; };
    const sourceFor = (id) => normalized().find((row) => String(row.id) === String(id));
    const openSimilarity = (source) => { if (!source) return; const related = categoryTerms(source.industry).concat(words[categoryKey(source.industry)] || []).filter(Boolean).slice(0,10); const modal = showModal('Find similar companies', '<p class="vpm-muted">Start from <strong>' + esc(source.company) + '</strong>. Verified source data: ' + esc(source.industry) + (source.city || source.country ? ' · ' + esc([source.city,source.country].filter(Boolean).join(', ')) : '') + '.</p><div class="vpm-grid two"><label>Geographic scope<select id="vpm-sim-geo"><option value="city">Same city</option><option value="nearby">Nearby cities in the same country</option><option value="country">Same country</option><option value="gcc">All GCC</option></select></label><label>Industry scope<select id="vpm-sim-industry"><option value="same">Same industry</option><option value="related">Same or related industries</option></select></label><label>Relevant Vibes services<select id="vpm-sim-services" multiple size="5">' + serviceMarkup + '</select></label><div class="vpm-form"><label><input type="checkbox" id="vpm-sim-website"> Website required</label><label><input type="checkbox" id="vpm-sim-contact"> Public contact required</label><label>Minimum lead score<input type="number" id="vpm-sim-score" min="0" max="100" value="0"></label></div></div><p class="vpm-muted">Suggested keywords: ' + esc(related.join(', ')) + '</p><div class="vpm-discovery-confirm"><span>Estimated provider calls before confirmation: <strong>0</strong></span><button type="button" class="vpm-btn" data-discovery-close>Cancel</button><button type="button" class="vpm-btn primary" data-discovery-confirm-similar>Confirm similar search</button></div>');
      Array.from(modal.querySelector('#vpm-sim-services').options).forEach((option) => { option.selected = source.services.includes(option.value); }); modal.querySelector('[data-discovery-confirm-similar]').addEventListener('click', () => { const geoMode = modal.querySelector('#vpm-sim-geo').value; const industryMode = modal.querySelector('#vpm-sim-industry').value; const services = Array.from(modal.querySelector('#vpm-sim-services').selectedOptions).map((option) => option.value); const country = source.country; const city = source.city; const geoLabel = modal.querySelector('#vpm-sim-geo').selectedOptions[0].textContent; similarityContext = {sourceId:source.id, company:source.company, industry:source.industry, country, city, services, keywords:related.join(', '), geoMode, geoLabel, industryMode, websiteRequired:modal.querySelector('#vpm-sim-website').checked, contactRequired:modal.querySelector('#vpm-sim-contact').checked, minScore:Number(modal.querySelector('#vpm-sim-score').value || 0)}; fillConfig({keywords:related.join(', '), countries:geoMode === 'gcc' ? ['Saudi Arabia','United Arab Emirates','Qatar','Kuwait','Bahrain','Oman'] : (country ? [country] : []), cities:geoMode === 'city' ? city : '', industry:industryMode === 'same' ? source.industry : '', services, automatic:false, frequency:'manual', maxResults:50, spendCap:0}); closeModal(); setSimilarityBanner(); const rows = applyFilters(); setStatus(rows.length + ' similar record(s) loaded. No provider credits were used; confirm any provider search separately.'); }); };
    const openDetails = (row, analyze) => { if (!row) return; const body = '<div class="vpm-detail-grid"><div><small>Company</small><strong>' + esc(row.company) + '</strong></div><div><small>Industry</small><strong>' + esc(row.industry) + '</strong></div><div><small>Location</small><strong>' + esc([row.city,row.country].filter(Boolean).join(', ') || 'Not available') + '</strong></div><div><small>Website / phone</small><strong>' + esc([row.website,row.phone].filter(Boolean).join(' · ') || 'Not available') + '</strong></div><div><small>Discovery source</small><strong>' + esc(row.source) + '</strong></div><div><small>Lead score</small><strong>' + esc(row.score) + '/100</strong></div></div><p class="vpm-muted">' + (analyze ? 'Analysis uses only verified CRM fields. Recommended services are suggestions, not verified facts.' : 'Only permitted, stored CRM data is shown here.') + '</p><p><strong>Suggested services:</strong> ' + esc(row.services.join(' · ') || 'Recommendation pending') + '</p><div class="vpm-discovery-confirm"><button type="button" class="vpm-btn" data-discovery-close>Close</button><button type="button" class="vpm-btn primary" data-discovery-modal-similar>Find similar companies</button></div>'; const modal = showModal(analyze ? 'Analyze business' : 'Company details', body); modal.querySelector('[data-discovery-modal-similar]').addEventListener('click', () => { closeModal(); openSimilarity(row); }); };
    $d('#vpm-expand-keywords')?.addEventListener('click', expandKeywords);
    $d('#vpm-discovery-search')?.addEventListener('click', async () => {
      similarityContext = null; setSimilarityBanner(); const config = readConfig();
      const button = $d('#vpm-discovery-search'); if (button) button.disabled = true;
      if (window.__vpmGoogleConfigured) {
        setStatus('Searching Google Places securely…');
        try {
          const result = await api('crm/lead-hunter/google-search', {method:'POST', body:config});
          dataRows = Array.isArray(result.rows) ? result.rows : [];
          const filtered = normalized().slice(0, Math.max(1, config.maxResults || 50));
          renderResults(filtered);
          const review = dataRows.filter((row) => !row.duplicate && (row.review_status === 'pending' || row.review_status === 'needs_review')).length;
          setStats(Number(result.found || filtered.length), Number(result.new || 0), review, String(result.apiUsage?.requests || 1) + ' call');
          setStatus(String(result.found || filtered.length) + ' public business result(s) loaded. New leads remain in review.');
        } catch (error) { setStatus(error.message, 'error'); }
        finally { if (button) button.disabled = false; }
        return;
      }
      setStatus('Searching CRM records and permitted sources…'); const rows = applyFilters();
      setStatus(rows.length ? rows.length + ' matching record(s) loaded.' : 'No matching records. Configure Google Places for new discovery results.');
      if (button) button.disabled = false;
    });
    $d('#vpm-save-search')?.addEventListener('click', () => { const config = readConfig(); if (!config.keywords && !similarityContext) { setStatus('Add at least one keyword before saving.', 'error'); return; } const saved = readStore(savedKey); saved.unshift({id:String(Date.now()), name:similarityContext ? 'Similar to ' + similarityContext.company : config.keywords.split(',')[0].trim(), ...config, similarity:similarityContext}); writeStore(savedKey, saved.slice(0,20)); renderSaved(); setStatus('Search saved locally for this portal user.'); });
    $d('#vpm-discovery-saved')?.addEventListener('change', (event) => { const item = readStore(savedKey).find((entry) => entry.id === event.target.value); if (item) { similarityContext = item.similarity || null; fillConfig(item); setSimilarityBanner(); applyFilters(); setStatus('Loaded saved search.'); } });
    $d('#vpm-discovery-scan')?.addEventListener('click', async (event) => { const button = event.currentTarget; button.disabled = true; setStatus('Running the configured public-source scan…'); try { const result = await api('crm/lead-hunter/scan', {method:'POST', body:{}}); const latest = await api('crm/leads?perPage=100'); dataRows = latest.rows || []; applyFilters(); setStatus('Scan complete. ' + (result.accepted || result.accepted_count || 0) + ' new lead(s) accepted.'); } catch (error) { setStatus(error.message, 'error'); } finally { button.disabled = false; } });
    section.addEventListener('click', async (event) => { const target = event.target; if (target.closest('[data-discovery-clear-similarity]')) { similarityContext = null; setSimilarityBanner(); applyFilters(); setStatus('Similarity filters cleared.'); return; } const similar = target.closest('[data-discovery-similar]'); const details = target.closest('[data-discovery-details]'); const analyze = target.closest('[data-discovery-analyze]'); const later = target.closest('[data-discovery-save-later]'); const review = target.closest('[data-discovery-review]'); const convert = target.closest('[data-discovery-convert]'); const owner = target.closest('[data-discovery-owner]'); if (similar) { openSimilarity(sourceFor(similar.dataset.discoverySimilar)); return; } if (details) { openDetails(sourceFor(details.dataset.discoveryDetails), false); return; } if (analyze) { openDetails(sourceFor(analyze.dataset.discoveryAnalyze), true); return; } if (later) { const row = sourceFor(later.dataset.discoverySaveLater); if (row) { const savedLater = readStore(laterKey).filter((item) => String(item.id) !== String(row.id)); savedLater.unshift(Object.assign({}, row, {savedAt:new Date().toISOString()})); writeStore(laterKey, savedLater.slice(0,50)); setStatus('Saved for later.'); } return; } if (review) { try { await api('crm/lead-hunter/review', {method:'POST', body:{leadId:review.dataset.discoveryReview, action:review.dataset.action}}); setStatus(review.dataset.action === 'reject' ? 'Lead dismissed.' : 'Lead added to review queue.'); const latest = await api('crm/leads?perPage=100'); dataRows = latest.rows || []; applyFilters(); } catch (error) { setStatus(error.message, 'error'); } } else if (convert) { try { await api('crm/leads/' + convert.dataset.discoveryConvert + '/convert', {method:'POST', body:{}}); setStatus('Opportunity created in the existing CRM pipeline.'); const latest = await api('crm/leads?perPage=100'); dataRows = latest.rows || []; applyFilters(); } catch (error) { setStatus(error.message, 'error'); } } else if (owner) { try { await api('crm/leads/' + owner.dataset.discoveryOwner, {method:'PATCH', body:{assignedUserId:Number(VPM.userId)}}); setStatus('Lead assigned to you.'); } catch (error) { setStatus(error.message, 'error'); } } });
    renderSaved(); renderHistory(); setSimilarityBanner(); setStats(0,0,0,'0 calls');
    try {
      const status = await api('crm/lead-hunter/google-status');
      window.__vpmGoogleConfigured = !!status.configured;
      const badge = $d('#vpm-discovery-provider-badge');
      if (badge) { badge.textContent = status.configured ? 'Google Places connected' : 'Google Places needs admin setup'; badge.className = 'vpm-chip ' + (status.configured ? 'success' : 'warning'); }
      const setup = $d('#vpm-google-config'); if (setup) setup.hidden = !!status.configured;
      $d('#vpm-google-configure')?.addEventListener('click', async () => {
        const input = $d('#vpm-google-api-key'); const apiKey = input ? input.value.trim() : '';
        if (!apiKey) { setStatus('Enter the restricted server credential first.', 'error'); return; }
        const button = $d('#vpm-google-configure'); if (button) button.disabled = true;
        try {
          await api('crm/lead-hunter/google-configure', {method:'POST', body:{apiKey}});
          window.__vpmGoogleConfigured = true; if (input) input.value = ''; if (setup) setup.hidden = true;
          if (badge) { badge.textContent = 'Google Places connected'; badge.className = 'vpm-chip success'; }
          setStatus('Google Places is connected. Searches will use the approved server-side provider.');
        } catch (error) { setStatus(error.message, 'error'); }
        finally { if (button) button.disabled = false; }
      });
    } catch (error) { const badge = $d('#vpm-discovery-provider-badge'); if (badge) badge.textContent = 'Provider status unavailable'; }
  }
async function renderCRM() {
    // 2026-11: Prospects CRM — research-agent leads, public requests, and search settings.
    const S = window.__vpcState || (window.__vpcState = { tab: 'prospects', sector: 'All', pri: 'Any priority', q: '', onlyEmail: false, onlyNew: false, rType: 'All', rPlat: 'All platforms', rOpen: false, open: new Set() });
    try { const saved = localStorage.getItem('vpcTab'); if (saved && !S.loaded) S.tab = saved; } catch (e) {}
    S.loaded = true;
    loading('Opening CRM…');
    let P = [], R = [], settings = null;
    try {
      [P, R, settings] = await Promise.all([api('crm/prospects?kind=prospect'), api('crm/prospects?kind=request'), api('crm/search-settings')]);
    } catch (error) { root().innerHTML = message(error.message, 'error'); return; }
    const validEmail = (e) => /^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/.test(e || '');
    const safeUrl = (u) => { try { const x = new URL(u); return /^https?:$/.test(x.protocol) ? x.href : ''; } catch (e) { return ''; } };
    const shortUrl = (u) => u.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
    const igHandle = (u) => { const m = u.match(/instagram\.com\/([^/?#]+)/i); return m ? '@' + m[1] : 'Instagram'; };
    const days = (d) => d ? (Date.now() - new Date(d + 'T00:00:00').getTime()) / 864e5 : 99;
    const order = { High: 0, Medium: 1, Low: 2 };
    const splitBody = (b) => { const parts = String(b || '').split(/\n?— — —\n?/); return parts.length > 1 ? { ar: parts[0].trim(), en: parts.slice(1).join('\n').trim() } : { ar: '', en: String(b || '').trim() }; };
    const toastMsg = (t) => { let el = document.getElementById('vpc-toast'); if (!el) { el = document.createElement('div'); el.id = 'vpc-toast'; el.className = 'vpc-toast'; el.setAttribute('role', 'status'); document.body.appendChild(el); } el.textContent = t; el.classList.add('on'); clearTimeout(toastMsg.t); toastMsg.t = setTimeout(() => el.classList.remove('on'), 1700); };
    const copy = async (t, label) => { try { await navigator.clipboard.writeText(t); toastMsg(label + ' copied'); } catch (e) { window.prompt('Copy:', t); } };
    const chipRow = (items, current, attr) => items.map((v) => `<button type="button" class="vpc-chip" data-${attr}="${esc(v)}" aria-pressed="${v === current}">${esc(v)}</button>`).join('');
    const extras = (x) => { const e = x.extra && typeof x.extra === 'object' ? Object.entries(x.extra).filter(([, v]) => String(v || '').trim()) : []; return e.length ? `<details class="vpc-more"><summary>More details</summary><dl>${e.map(([k, v]) => { const u = /^https?:\/\//.test(String(v)) ? safeUrl(String(v)) : ''; return `<dt>${esc(k.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/^./, (c) => c.toUpperCase()))}</dt><dd dir="auto">${u ? `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(shortUrl(u))}</a>` : esc(v)}</dd>`; }).join('')}</dl></details>` : ''; };
    const syncLine = () => { const L = settings && settings.lastSync; if (!L || !L.at) return '<div class="vpc-sync warn">The daily research agent hasn\'t sent results yet. New prospects appear here automatically once it runs.</div>'; const t = L.ts ? new Date(L.ts * 1000) : new Date(String(L.at).replace(' ', 'T')); const hours = (Date.now() - t.getTime()) / 36e5; const when = t.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); const today = L.day === new Date().toISOString().slice(0, 10); return `<div class="vpc-sync ${hours > 30 ? 'warn' : 'ok'}"><span class="dot" aria-hidden="true"></span>Research agent last update: <b>${esc(when)}</b>${today || hours <= 30 ? ` · ${esc(L.created || 0)} new, ${esc(L.updated || 0)} updated` : ''}${hours > 30 ? ' · No update in over a day. Check the "Vibes CRM sync" scheduled task.' : ''}</div>`; };
    const stat = (n, label, tone) => `<div class="vpc-stat ${tone || ''}"><b>${esc(n)}</b><span>${esc(label)}</span></div>`;
    const icon = { mail: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>', phone: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>', web: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>', ig: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/></svg>' };

    const prospectsView = () => {
      const sectors = ['All', ...[...new Set(P.map((x) => x.sector).filter(Boolean))].sort()];
      const q = S.q.toLowerCase();
      const rows = P.filter((x) => (S.sector === 'All' || x.sector === S.sector) && (S.pri === 'Any priority' || x.priority === S.pri) && (!S.onlyEmail || validEmail(x.email)) && (!S.onlyNew || (x.status || 'New') === 'New') && (!q || [x.name, x.city, x.why, x.pitch, x.sector].join(' ').toLowerCase().includes(q)))
        .sort((a, b) => (order[a.priority] ?? 3) - (order[b.priority] ?? 3) || (validEmail(b.email) - validEmail(a.email)) || String(b.added || '').localeCompare(String(a.added || '')));
      const count = (k) => P.filter((x) => (x.status || 'New') === k).length;
      const card = (x) => {
        const st = x.status || 'New'; const src = safeUrl(x.source || ''); const w = safeUrl(x.website || ''); const ig = safeUrl(x.instagram || '');
        const { ar, en } = splitBody(x.emailBody); const mailto = validEmail(x.email) ? `mailto:${encodeURIComponent(x.email)}?subject=${encodeURIComponent(x.emailSubject || '')}&body=${encodeURIComponent(x.emailBody || '')}` : '';
        const contact = [
          validEmail(x.email) ? `<div class="vpc-line">${icon.mail}<span class="vpc-email">${esc(x.email)}</span><button type="button" class="vpc-mini" data-copy="email" data-id="${x.id}">Copy</button></div>` : '',
          x.phone ? `<div class="vpc-line">${icon.phone}<a href="tel:${esc(String(x.phone).replace(/[^\d+]/g, ''))}">${esc(x.phone)}</a></div>` : '',
          w ? `<div class="vpc-line">${icon.web}<a href="${esc(w)}" target="_blank" rel="noopener">${esc(shortUrl(w))}</a></div>` : '',
          ig ? `<div class="vpc-line">${icon.ig}<a href="${esc(ig)}" target="_blank" rel="noopener">${esc(igHandle(ig))}</a></div>` : '',
          !validEmail(x.email) ? `<div class="vpc-noemail">${esc(x.contactNote || 'No public email found')}</div>` : (x.contactNote ? `<small class="vpc-muted">${esc(x.contactNote)}</small>` : ''),
        ].join('');
        const draft = x.emailBody ? `<details class="vpc-draft" data-id="${x.id}"${S.open.has(x.id) ? ' open' : ''}><summary><span class="vpc-chev" aria-hidden="true">›</span>Email draft<span class="vpc-subj">${esc(x.emailSubject || '')}</span></summary><div class="vpc-draft-body"><div class="vpc-actions"><button type="button" class="vpc-btn primary" data-copy="all" data-id="${x.id}">Copy full email</button><button type="button" class="vpc-btn" data-copy="subject" data-id="${x.id}">Copy subject</button>${ar ? `<button type="button" class="vpc-btn" data-copy="ar" data-id="${x.id}">Copy Arabic</button><button type="button" class="vpc-btn" data-copy="en" data-id="${x.id}">Copy English</button>` : ''}${mailto ? `<a class="vpc-btn" href="${esc(mailto)}">Open in mail app</a>` : ''}${st === 'New' ? `<button type="button" class="vpc-btn" data-mark="${x.id}">Mark as contacted</button>` : ''}</div><div class="vpc-subject"><small>Subject</small><div dir="auto">${esc(x.emailSubject || '')}</div></div><div class="vpc-letters">${ar ? `<pre class="vpc-letter" lang="ar" dir="rtl"><small dir="ltr">Arabic</small>${esc(ar)}</pre>` : ''}<pre class="vpc-letter" lang="en"><small>English</small>${esc(en)}</pre></div></div></details>` : '';
        return `<article class="vpc-card ${st === 'Won' || st === 'Not a fit' ? 'done' : ''}"><div class="vpc-main"><div><h3 class="vpc-name">${esc(x.name)}</h3><div class="vpc-muted">${esc([x.sector, x.city].filter(Boolean).join(' · '))}</div><div class="vpc-badges">${x.priority ? `<span class="vpc-pill pri-${esc(x.priority)}">${esc(x.priority)} priority</span>` : ''}${days(x.added) < 7 ? '<span class="vpc-pill new">New</span>' : ''}</div><label class="vpc-k" for="vpc-st-${x.id}">Status</label><select id="vpc-st-${x.id}" data-status="${x.id}">${['New', 'Contacted', 'Replied', 'Won', 'Not a fit'].map((o) => `<option${o === st ? ' selected' : ''}>${o}</option>`).join('')}</select></div><div class="vpc-col"><div><div class="vpc-k">Why now</div><p>${esc(x.why || '')}</p>${src ? `<a class="vpc-src" href="${esc(src)}" target="_blank" rel="noopener">Source ↗</a>` : ''}</div>${x.pitch ? `<div class="vpc-pitch"><div class="vpc-k">What to pitch</div><p>${esc(x.pitch)}</p></div>` : ''}</div><div class="vpc-contact"><div class="vpc-k">Contact</div>${contact}</div></div>${extras(x)}${draft}</article>`;
      };
      return `<div class="vpc-stats">${stat(P.length, 'Prospects')}${stat(P.filter((x) => x.priority === 'High').length, 'High priority', 'hi')}${stat(P.filter((x) => validEmail(x.email)).length, 'With email', 'blue')}${stat(P.filter((x) => days(x.added) < 7).length, 'Added this week', 'pink')}${stat(count('Contacted'), 'Contacted', 'md')}${stat(count('Replied'), 'Replied', 'coral')}${stat(count('Won'), 'Won', 'ok')}</div>
        <div class="vpc-bar"><div class="vpc-row">${chipRow(sectors, S.sector, 'sector')}</div><div class="vpc-row">${chipRow(['Any priority', 'High', 'Medium', 'Low'], S.pri, 'pri')}<span class="vpc-sep"></span><button type="button" class="vpc-chip tog" data-toggle="onlyEmail" aria-pressed="${S.onlyEmail}">Has email</button><button type="button" class="vpc-chip tog" data-toggle="onlyNew" aria-pressed="${S.onlyNew}">Not contacted yet</button><input type="search" class="vpc-search" placeholder="Search brand, city or idea" aria-label="Search prospects" value="${esc(S.q)}"><span class="vpc-count">${rows.length} of ${P.length}</span></div></div>
        <div class="vpc-list">${P.length ? (rows.map(card).join('') || '<div class="vpc-empty">No prospects match these filters.</div>') : '<div class="vpc-empty">No prospects yet. The research agent adds new Saudi brands here every morning.</div>'}</div>`;
    };

    const requestsView = () => {
      const plats = ['All platforms', ...[...new Set(R.map((x) => x.platform).filter(Boolean))].sort()];
      const rows = R.filter((x) => (S.rType === 'All' || x.type === S.rType) && (S.rPlat === 'All platforms' || x.platform === S.rPlat) && (!S.rOpen || (x.status || 'New') === 'New'))
        .sort((a, b) => String(b.added || '').localeCompare(String(a.added || '')) || String(a.deadline || '9').localeCompare(String(b.deadline || '9')));
      const count = (k) => R.filter((x) => (x.status || 'New') === k).length;
      const card = (x) => {
        const st = x.status || 'New'; const u = safeUrl(x.url || ''); const tender = x.type === 'Open tender';
        const fact = (k, v) => v ? `<div class="vpc-fact"><span>${k}</span><span dir="auto">${esc(v)}</span></div>` : '';
        return `<article class="vpc-card ${st === 'Won' || st === 'Not a fit' ? 'done' : ''}"><div class="vpc-req"><div class="vpc-col"><div><h3 class="vpc-req-title" dir="auto">${esc(x.title)}</h3><div class="vpc-badges"><span class="vpc-pill ${tender ? 'tender' : 'plat'}">${esc(tender ? 'Open tender' : (x.platform || 'Request'))}</span>${x.countryVerified === false ? '<span class="vpc-pill warn">Country not verified</span>' : ''}${days(x.added) < 1.5 ? '<span class="vpc-pill new">New today</span>' : ''}</div></div>${x.need ? `<div><div class="vpc-k">What they need</div><p>${esc(x.need)}</p></div>` : ''}${x.fit ? `<div class="vpc-pitch"><div class="vpc-k">How Vibes fits</div><p>${esc(x.fit)}</p></div>` : ''}<div class="vpc-actions">${u ? `<a class="vpc-btn primary" href="${esc(u)}" target="_blank" rel="noopener">Open post ↗</a><button type="button" class="vpc-btn" data-copy="url" data-id="${x.id}">Copy link</button>` : ''}</div></div><div class="vpc-facts">${fact('Posted by', x.poster)}${fact('Location', x.location)}${fact('Budget', x.budget)}${fact('Posted', x.postedAt)}${fact('Deadline', x.deadline)}${x.howToRespond ? `<small class="vpc-muted">${esc(x.howToRespond)}</small>` : ''}<label class="vpc-k" for="vpc-rs-${x.id}">Status</label><select id="vpc-rs-${x.id}" data-status="${x.id}">${['New', 'Applied', 'In talks', 'Won', 'Not a fit'].map((o) => `<option${o === st ? ' selected' : ''}>${o}</option>`).join('')}</select></div></div>${extras(x)}</article>`;
      };
      return `<div class="vpc-stats">${stat(R.length, 'Requests')}${stat(R.filter((x) => x.type !== 'Open tender' && days(x.added) < 1.5).length, 'Found today', 'ok')}${stat(R.filter((x) => x.type === 'Open tender').length, 'Open tenders', 'coral')}${stat(count('Applied'), 'Applied', 'md')}${stat(count('In talks'), 'In talks', 'blue')}${stat(count('Won'), 'Won', 'ok')}</div>
        <div class="vpc-bar"><div class="vpc-row">${chipRow(['All', 'Request', 'Open tender'], S.rType, 'rtype')}<span class="vpc-sep"></span>${chipRow(plats, S.rPlat, 'rplat')}<span class="vpc-sep"></span><button type="button" class="vpc-chip tog" data-toggle="rOpen" aria-pressed="${S.rOpen}">Not handled yet</button><span class="vpc-count">${rows.length} of ${R.length}</span></div></div>
        <div class="vpc-list">${R.length ? (rows.map(card).join('') || '<div class="vpc-empty">No requests match these filters.</div>') : '<div class="vpc-empty">No requests yet. The research agent adds public requests from the last 24 hours every morning.</div>'}</div>`;
    };

    const settingsView = () => {
      const allSectors = ['Beauty', 'Fashion', 'E-commerce', 'Coffee', 'Restaurants', 'Real estate', 'Clinics & wellness', 'Fitness', 'Hospitality', 'Startups'];
      const allPlatforms = ['Mostaql', 'Khamsat', 'LinkedIn', 'X', 'Etimad tenders'];
      const checks = (name, all, chosen) => all.map((v) => `<label class="vpc-check"><input type="checkbox" name="${name}" value="${esc(v)}"${chosen.includes(v) ? ' checked' : ''}><span>${esc(v)}</span></label>`).join('');
      return `<form id="vpc-settings" class="vpc-settings"><p class="vpc-muted">The research agent reads these settings every morning before it searches. Changes apply from the next morning's run.</p>
        <fieldset><legend>Sectors to search</legend><div class="vpc-checks">${checks('sectors', allSectors, settings.sectors || [])}</div></fieldset>
        <div class="vpc-two"><label class="vpc-field"><span>Include keywords</span><small>One per line, Arabic or English. The agent looks for news and posts using these.</small><textarea name="includeKeywords" rows="7" dir="auto">${esc((settings.includeKeywords || []).join('\n'))}</textarea></label>
        <label class="vpc-field"><span>Exclude keywords</span><small>One per line. Results mentioning these are skipped.</small><textarea name="excludeKeywords" rows="7" dir="auto">${esc((settings.excludeKeywords || []).join('\n'))}</textarea></label></div>
        <label class="vpc-field"><span>Cities</span><small>One per line. Leave empty for all of Saudi Arabia.</small><textarea name="cities" rows="3">${esc((settings.cities || []).join('\n'))}</textarea></label>
        <fieldset><legend>Where to look for requests</legend><div class="vpc-checks">${checks('platforms', allPlatforms, settings.platforms || [])}</div></fieldset>
        <div class="vpc-three"><label class="vpc-field"><span>New prospects per day</span><input type="number" name="prospectsPerDay" min="1" max="15" value="${esc(settings.prospectsPerDay)}"></label><label class="vpc-field"><span>Requests per day</span><input type="number" name="requestsPerDay" min="0" max="15" value="${esc(settings.requestsPerDay)}"></label><label class="vpc-field"><span>Minimum request budget (USD)</span><input type="number" name="minBudgetUsd" min="0" step="50" value="${esc(settings.minBudgetUsd)}"></label></div>
        <label class="vpc-check big"><input type="checkbox" name="smallBrandsOnly"${settings.smallBrandsOnly ? ' checked' : ''}><span>Prefer small and mid-sized brands (skip big groups that already have agencies)</span></label>
        <label class="vpc-field"><span>Extra notes for the agent</span><small>Anything else to focus on, e.g. "Ramadan campaigns", "Lebanese brands entering KSA".</small><textarea name="notes" rows="3" dir="auto">${esc(settings.notes || '')}</textarea></label>
        <div class="vpc-actions"><button class="vpc-btn primary" type="submit">Save search settings</button></div>
        ${document.querySelector('#vtp-app[data-role="admin"]') ? `<fieldset class="vpc-agent"><legend>Daily research agent connection</legend><p class="vpc-muted">The research agent uses a private key to add prospects to this CRM. Copy it and paste it into the scheduled task where it says <b>PASTE_AGENT_KEY_HERE</b>. Keep it private.</p><div class="vpc-actions"><button type="button" class="vpc-btn" data-agent-key>Copy agent key</button></div></fieldset>` : ''}</form>`;
    };

    const draw = () => {
      const newReq = R.filter((x) => (x.status || 'New') === 'New').length;
      const tab = (key, label, n) => `<button type="button" class="vpc-tab" role="tab" data-tab="${key}" aria-selected="${S.tab === key}">${label}${n != null ? ` <span class="n">${n}</span>` : ''}</button>`;
      root().innerHTML = `<section class="vpm-page vpc"><div class="vpm-page-head"><div><p class="vpm-eyebrow">VIBES CRM</p><h1>Prospects &amp; requests</h1><p>Saudi brands with a fresh reason to buy, with public contacts and a ready bilingual email, plus live public requests for agency work.</p></div></div>
        <div class="vpc-tabs" role="tablist">${tab('prospects', 'Prospects', P.length)}${tab('requests', 'Requests', newReq)}${tab('settings', 'Search settings')}</div>${syncLine()}
        <div class="vpc-view">${S.tab === 'requests' ? requestsView() : S.tab === 'settings' ? settingsView() : prospectsView()}</div></section>`;
      wire();
    };

    const setStatus = async (id, status, list) => {
      try { const fresh = await api(`crm/prospects/${id}`, { method: 'PATCH', body: { status } }); const i = list.findIndex((x) => x.id === Number(id)); if (i >= 0) list[i] = fresh; toastMsg('Status saved'); draw(); }
      catch (error) { toastMsg(error.message); }
    };

    const wire = () => {
      const el = root();
      el.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { S.tab = b.dataset.tab; try { localStorage.setItem('vpcTab', S.tab); } catch (e) {} draw(); }));
      el.querySelectorAll('[data-sector]').forEach((b) => b.addEventListener('click', () => { S.sector = b.dataset.sector; draw(); }));
      el.querySelectorAll('[data-pri]').forEach((b) => b.addEventListener('click', () => { S.pri = b.dataset.pri; draw(); }));
      el.querySelectorAll('[data-rtype]').forEach((b) => b.addEventListener('click', () => { S.rType = b.dataset.rtype; draw(); }));
      el.querySelectorAll('[data-rplat]').forEach((b) => b.addEventListener('click', () => { S.rPlat = b.dataset.rplat; draw(); }));
      el.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', () => { S[b.dataset.toggle] = !S[b.dataset.toggle]; draw(); }));
      const search = el.querySelector('.vpc-search');
      if (search) search.addEventListener('input', () => { S.q = search.value; clearTimeout(wire.t); wire.t = setTimeout(() => { const pos = search.selectionStart; draw(); const s2 = root().querySelector('.vpc-search'); if (s2) { s2.focus(); s2.setSelectionRange(pos, pos); } }, 250); });
      el.querySelectorAll('select[data-status]').forEach((s) => s.addEventListener('change', () => setStatus(s.dataset.status, s.value, S.tab === 'requests' ? R : P)));
      el.querySelectorAll('[data-mark]').forEach((b) => b.addEventListener('click', () => setStatus(b.dataset.mark, 'Contacted', P)));
      el.querySelectorAll('details.vpc-draft').forEach((d) => d.addEventListener('toggle', () => { const id = Number(d.dataset.id); d.open ? S.open.add(id) : S.open.delete(id); }));
      el.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', () => {
        const x = [...P, ...R].find((row) => row.id === Number(b.dataset.id)); if (!x) return; const { ar, en } = splitBody(x.emailBody);
        ({ email: () => copy(x.email, 'Email address'), all: () => copy(x.emailBody || '', 'Email'), subject: () => copy(x.emailSubject || '', 'Subject'), ar: () => copy(ar, 'Arabic email'), en: () => copy(en, 'English email'), url: () => copy(x.url || '', 'Link') }[b.dataset.copy] || (() => {}))();
      }));
      const keyBtn = el.querySelector('[data-agent-key]');
      if (keyBtn) keyBtn.addEventListener('click', async () => { try { const k = await api('crm/agent-key'); await navigator.clipboard.writeText(k.key); toastMsg('Agent key copied'); } catch (error) { toastMsg('Could not copy the key: ' + error.message); } });
      const form = el.querySelector('#vpc-settings');
      if (form) form.addEventListener('submit', async (event) => {
        event.preventDefault(); const fd = new FormData(form); const lines = (k) => String(fd.get(k) || '').split(/\n+/).map((v) => v.trim()).filter(Boolean);
        const body = { sectors: fd.getAll('sectors'), platforms: fd.getAll('platforms'), includeKeywords: lines('includeKeywords'), excludeKeywords: lines('excludeKeywords'), cities: lines('cities'), prospectsPerDay: Number(fd.get('prospectsPerDay')), requestsPerDay: Number(fd.get('requestsPerDay')), minBudgetUsd: Number(fd.get('minBudgetUsd')), smallBrandsOnly: fd.get('smallBrandsOnly') === 'on', notes: String(fd.get('notes') || '') };
        try { const last = settings && settings.lastSync; settings = await api('crm/search-settings', { method: 'POST', body }); settings.lastSync = last; toastMsg('Search settings saved'); } catch (error) { toastMsg(error.message); }
      });
    };
    draw();
  }
  function openLeadForm() {
    const panel = document.createElement('div'); panel.className = 'vpm-modal'; panel.innerHTML = `<div class="vpm-modal-card"><button class="vpm-modal-close" aria-label="Close">×</button><h2>Create manual lead</h2><form id="vpm-lead-form" class="vpm-form"><input name="company" placeholder="Company name" required><input name="country" value="Saudi Arabia" placeholder="Country"><input name="website" type="url" placeholder="Public website"><input name="contactName" placeholder="Public contact name"><input name="contactEmail" type="email" placeholder="Public business email"><input name="services" placeholder="Services (comma separated)"><textarea name="description" placeholder="Opportunity description"></textarea><button class="vpm-btn primary">Save lead</button></form></div>`; document.body.appendChild(panel); $('.vpm-modal-close', panel).addEventListener('click', () => panel.remove()); $('#vpm-lead-form', panel).addEventListener('submit', async (event) => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); data.services = data.services.split(',').map((item) => item.trim()).filter(Boolean); try { await api('crm/leads', {method:'POST', body:data}); panel.remove(); await renderCRM(); } catch (error) { alert(error.message); } });
  }

  async function renderModule() {
    setActiveNav(); clearPoll(); const key = routeKey(); if (!moduleRoutes.has(key)) return;
    if (key === 'connect') await renderConnect(); else if (key === 'analytics') await renderAnalytics(); else if (key === 'finance') await renderFinance(); else if (key === 'payslips') await renderPayslips(); else await renderCRM();
  }

  function claimInitialModuleRoute() {
    const target = routeKey(); let pending = false; let stableTicks = 0; let attempts = 0;
    const timer = setInterval(() => {
      if (routeKey() !== target || !moduleRoutes.has(routeKey()) || attempts++ > 40) { clearInterval(timer); return; }
      const view = root(); const child = view && view.firstElementChild;
      const routeOwned = routeKey(); const owned = child && (child.classList.contains('vpm-message') || (routeOwned === 'crm' && (child.classList.contains('vpc') || child.querySelector('#vpm-lead-discovery'))) || (routeOwned === 'analytics' && child.querySelector('.vpm-stats')) || (routeOwned === 'finance' && child.querySelector('#vpm-payment-form')) || (routeOwned === 'payslips' && child.querySelector('#vpm-payslip-form')) || (routeOwned === 'connect' && child.classList.contains('vpm-connect-page')));
      // Keep watching while this module route is active. The core portal can
      // finish a delayed route render several seconds later; if it replaces
      // our view with Dashboard, reclaim the route without changing the URL.
      if (owned) { stableTicks += 1; return; }
      stableTicks = 0;
      if (pending) return;
      pending = true;
      renderModule().finally(() => { pending = false; });
    }, 500);
  }

  async function bootstrapWithRetry() {
    let lastError;
    for (let attempt = 0; attempt < 3; attempt += 1) {
      try { return await api('modules/bootstrap'); } catch (error) {
        lastError = error;
        if (attempt < 2) await new Promise((resolve) => window.setTimeout(resolve, 500 * (attempt + 1)));
      }
    }
    throw lastError || new Error('The workspace could not finish loading.');
  }

  async function init() {
    const initialRoute = routeKey();
    // Mount Connect immediately so the workspace is usable while the
    // capability check and conversation data finish loading in parallel.
    if (initialRoute === 'connect') {
      const mountWhenReady = () => {
        if (routeKey() !== 'connect') return;
        if (root()) { mountConnectShell(); renderConnect(); return; }
        window.setTimeout(mountWhenReady, 50);
      };
      mountWhenReady();
    }
    try { state.boot = await bootstrapWithRetry(); } catch (error) {
      const view = root();
      if (view && initialRoute !== 'connect') view.innerHTML = message('The workspace could not finish loading. Please refresh and try again.', 'error');
      return;
    }
    installNav();
    window.addEventListener('hashchange', (event) => { if (moduleRoutes.has(routeKey())) { event.stopImmediatePropagation(); renderModule(); } else clearPoll(); }, true);
    document.addEventListener('visibilitychange', schedulePoll);
    if (moduleRoutes.has(routeKey())) claimInitialModuleRoute();
  }
  // Install before the existing portal script registers its delegated router.
  // This keeps the module pages inside the existing shell without replacing it.
  const blockCoreRoute = (event) => {
    const link = event.target && event.target.closest ? event.target.closest('a[data-vpm-route]') : null;
    if (!link) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const target = `#${link.dataset.vpmRoute}`;
    if (target === '#connect' && typeof window.VPMOpenConnectOverlay === 'function') { window.VPMOpenConnectOverlay(); return; }
    if (target === '#connect') sessionStorage.setItem('vtp-connect-return-hash', routeKey() === 'connect' ? '#dashboard' : `#${routeKey()}`);
    if (location.hash !== target) history.pushState({}, '', target);
    renderModule();
  };
  window.addEventListener('click', blockCoreRoute, true);
  document.addEventListener('click', blockCoreRoute, true);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();



/* Connect reliability layer: employee shortcuts, resili

/* Connect reliability layer: employee shortcuts, resilient group creation, and direct-chat entry points. */
(() => {
  const endpoint = (path, options = {}) => {
    const headers = {'X-WP-Nonce': VPM.nonce, ...(options.headers || {})};
    if (options.body && typeof options.body !== 'string') { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
  return fetch(VPM.root + path.replace(/^\//, ''), {...options, headers, credentials:'same-origin', cache:'no-store'}).then(async (response) => { const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.message || 'The request could not be completed.'); return data; });
  };
  const esc2 = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const initials2 = (name) => String(name || '?').trim().split(/\\s+/).slice(0,2).map((part) => part[0]).join('').toUpperCase() || '?';
  const memberAvatar = (member) => { const url = member.avatar_url && !String(member.avatar_url).includes('d=mm') ? member.avatar_url : ''; return '<span class="vpm-avatar vpm-employee-avatar">' + (url ? '<img src="' + esc2(url) + '" alt="">' : '<b>' + esc2(initials2(member.name)) + '</b>') + '</span>'; };
  let membersCache = null;
  async function members() { if (!membersCache) membersCache = await endpoint('connect/members'); return membersCache || []; }
  async function renderEmployeeShortcuts() {
    const sidebar = document.querySelector('.vpm-connect-page .vpm-conversation-list'); if (!sidebar) return;
    let panel = sidebar.querySelector('#vpm-employee-directory');
    if (!panel) { panel = document.createElement('div'); panel.id = 'vpm-employee-directory'; panel.className = 'vpm-employee-directory'; const body = sidebar.querySelector('.vpm-conversation-list-body'); if (body) body.after(panel); }
    if (panel.dataset.ready === '1') return;
    panel.innerHTML = '<div class="vpm-employee-directory-head"><strong>Team members</strong><small>Start a direct chat</small></div><div class="vpm-employee-directory-list"><div class="vpm-loading"><span></span></div></div>';
    try {
      const people = (await members()).filter((member) => Number(member.id) !== Number(VPM.userId));
      const list = panel.querySelector('.vpm-employee-directory-list');
      list.innerHTML = people.length ? people.map((member) => '<button type="button" class="vpm-employee" data-vpm-direct="' + member.id + '">' + memberAvatar(member) + '<span><strong>' + esc2(member.name) + '</strong><small>' + esc2(member.email || 'Portal member') + '</small></span></button>').join('') : '<p class="vpm-empty">No other authorized employees found.</p>';
      list.querySelectorAll('[data-vpm-direct]').forEach((button) => button.addEventListener('click', async () => {
        button.disabled = true;
        try { await endpoint('connect/conversations', {method:'POST', body:{type:'direct', title:'', memberIds:[Number(button.dataset.vpmDirect)]}}); location.hash = '#connect'; location.reload(); } catch (error) { button.disabled = false; alert(error.message); }
      }));
      panel.dataset.ready = '1';
    } catch (error) { panel.innerHTML = '<div class="vpm-employee-directory-head"><strong>Team members</strong></div><p class="vpm-empty">' + esc2(error.message) + '</p>'; }
  }
  async function openReliableGroupDialog() {
    if (document.querySelector('#vpm-reliable-group-modal')) return;
    let people; try { people = (await members()).filter((member) => Number(member.id) !== Number(VPM.userId)); } catch (error) { alert(error.message); return; }
    const panel = document.createElement('div'); panel.id = 'vpm-reliable-group-modal'; panel.className = 'vpm-modal';
    panel.innerHTML = '<div class="vpm-modal-card vpm-connect-modal"><button type="button" class="vpm-modal-close" aria-label="Close">×</button><p class="vpm-eyebrow">NEW TEAM GROUP</p><h2>Create a team group</h2><p class="vpm-muted">Choose the authorized employees who should have access.</p><form class="vpm-form"><label class="vpm-field-label">Title<input name="title" placeholder="e.g. Design Team" required></label><div class="vpm-member-picker"><span class="vpm-field-label">Add members</span>' + (people.length ? people.map((member) => '<label class="vpm-member-option"><input type="checkbox" name="memberIds" value="' + member.id + '">' + memberAvatar(member) + '<span><strong>' + esc2(member.name) + '</strong><small>' + esc2(member.email || 'Portal member') + '</small></span></label>').join('') : '<p class="vpm-empty">No other authorized employees found.</p>') + '</div><button type="submit" class="vpm-btn primary">Create group</button></form></div>';
    document.body.appendChild(panel);
    const close = () => panel.remove(); panel.querySelector('.vpm-modal-close').addEventListener('click', close);
    panel.querySelector('form').addEventListener('submit', async (event) => { event.preventDefault(); const data = new FormData(event.currentTarget); const memberIds = data.getAll('memberIds').map(Number); if (!memberIds.length) { alert('Choose at least one employee.'); return; } const submit = event.currentTarget.querySelector('button[type="submit"]'); submit.disabled = true; try { await endpoint('connect/conversations', {method:'POST', body:{type:'group', title:String(data.get('title') || ''), memberIds}}); close(); location.hash = '#connect'; location.reload(); } catch (error) { submit.disabled = false; alert(error.message); } });
  }
  document.addEventListener('click', (event) => { const button = event.target.closest && event.target.closest('#vpm-new-group'); if (!button) return; event.preventDefault(); event.stopImmediatePropagation(); openReliableGroupDialog(); }, true);
  const observe = () => { if ((location.hash || '').split('?')[0] === '#connect') renderEmployeeShortcuts(); };
  // Connect rendering is event-driven; avoid a document-wide observer loop.
  window.addEventListener('hashchange', () => { membersCache = null; setTimeout(observe, 80); });
  observe();
})();


/* Vibes Service Demand Discovery: public buying-intent signals, no restricted scraping. */
(function(){
  'use strict';
  const SERVICES = [
    ['AI Video Production','AI video production','إنتاج فيديو بالذكاء الاصطناعي'],
    ['Video Editing','video editing','مونتاج فيديو'],
    ['Motion Graphics','motion graphics','موشن جرافيك'],
    ['2D/3D Animation','2D 3D animation','رسوم متحركة ثنائية وثلاثية الأبعاد'],
    ['CGI','CGI 3D rendering','تصميم CGI'],
    ['Branding','branding brand identity','تصميم هوية بصرية'],
    ['Graphic Design','graphic design','تصميم جرافيك'],
    ['Social Media Management','social media management','إدارة حسابات التواصل الاجتماعي'],
    ['Presentations','presentation design','تصميم عروض تقديمية'],
    ['Website Design & Development','website design development','تصميم وتطوير مواقع'],
    ['Digital Marketing','digital marketing','تسويق رقمي']
  ];
  const COUNTRIES = ['Saudi Arabia','United Arab Emirates','Qatar','Kuwait','Bahrain','Oman'];
  const SIGNALS = {
    request: ['looking for a','seeking a','need a','مطلوب','نبحث عن','نحتاج إلى'],
    rfp: ['RFP','request for proposal','tender','quotation','مناقصة','طلب عرض سعر'],
    hiring: ['hiring','freelance','contractor','agency wanted','توظيف','مستقل','شركة مطلوبة'],
    launch: ['launching','new branch','rebranding','opening soon','إطلاق','فرع جديد','إعادة branding']
  };
  const root = () => (window.VPM && VPM.root) || '/wp-json/vibes/v1/';
  const nonce = () => (window.VPM && VPM.nonce) || '';
  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const api = async (path, options) => {
    const opts = options || {};
    const headers = Object.assign({'X-WP-Nonce': nonce(), 'Content-Type':'application/json'}, opts.headers || {});
    const response = await fetch(root() + path.replace(/^\//,''), Object.assign({}, opts, {headers, credentials:'same-origin', body: opts.body && typeof opts.body !== 'string' ? JSON.stringify(opts.body) : opts.body}));
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'The portal could not complete that request.');
    return data;
  };
  const findService = (value) => SERVICES.find((item) => item[0] === value) || SERVICES[0];
  const buildQuery = (form) => {
    const service = findService(form.service);
    const city = String(form.city || '').trim();
    const country = String(form.country || '').trim();
    const language = form.language === 'ar';
    const terms = SIGNALS[form.signal] || SIGNALS.request;
    const serviceTerms = language ? service[2] : service[1];
    const signalTerms = language ? terms.slice(3).join(' OR ') : terms.slice(0,3).join(' OR ');
    const location = [city, country].filter(Boolean).join(' ');
    return '(' + serviceTerms + ') (' + signalTerms + ')' + (location ? ' ' + location : '');
  };
  const feedUrl = (query, language, country) => {
    const gl = country === 'Saudi Arabia' ? 'SA' : country === 'United Arab Emirates' ? 'AE' : country === 'Qatar' ? 'QA' : country === 'Kuwait' ? 'KW' : country === 'Bahrain' ? 'BH' : country === 'Oman' ? 'OM' : 'US';
    const ceid = language === 'ar' ? gl + ':ar' : gl + ':en';
    return 'https://news.google.com/rss/search?q=' + encodeURIComponent(query) + '&hl=' + (language === 'ar' ? 'ar' : 'en-US') + '&gl=' + gl + '&ceid=' + ceid;
  };
  function initServiceDemand(){
    const discovery = document.querySelector('#vpm-lead-discovery');
    if (!discovery || document.querySelector('#vpm-service-demand')) return;
    const card = document.createElement('section');
    card.id = 'vpm-service-demand';
    card.className = 'vpm-card';
    card.style.cssText = 'margin-top:18px;border:1px solid #e3d8f4;border-radius:18px;padding:22px;background:linear-gradient(135deg,#fff 0%,#faf7ff 100%);box-shadow:0 8px 24px rgba(96,51,141,.08)';
    card.innerHTML = '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap"><div><span class="vpm-eyebrow" style="color:#60338d">SERVICE DEMAND</span><h2 style="margin:4px 0 6px">Find public buying signals</h2><p class="vpm-muted" style="max-width:760px;margin:0">Search public RFPs, tenders, hiring requests, and launch signals for Vibes services. This does not reveal private Google searches or scrape restricted platforms.</p></div><span class="vpm-chip" style="background:#efe5fb;color:#60338d">Intent-first discovery</span></div><div class="vpm-form-grid" style="margin-top:18px"><label>Vibes service<select id="vpm-demand-service">' + SERVICES.map((item) => '<option value="' + esc(item[0]) + '">' + esc(item[0]) + '</option>').join('') + '</select></label><label>Public signal<select id="vpm-demand-signal"><option value="request">Looking for a provider</option><option value="rfp">RFP / tender / quotation</option><option value="hiring">Hiring or freelancer request</option><option value="launch">Launch, expansion, or rebrand</option></select></label><label>Country<select id="vpm-demand-country"><option value="">Any GCC country</option>' + COUNTRIES.map((c) => '<option>' + esc(c) + '</option>').join('') + '</select></label><label>City<input id="vpm-demand-city" placeholder="Riyadh, Dubai, Doha…"></label><label>Language<select id="vpm-demand-language"><option value="en">English</option><option value="ar">Arabic</option></select></label></div><label style="display:block;margin-top:14px">Generated public search query<textarea id="vpm-demand-query" rows="2" readonly></textarea></label><div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px"><button type="button" class="vpm-button" id="vpm-demand-search">Search public sources</button><button type="button" class="vpm-button secondary" id="vpm-demand-feed">Add Google News RSS scan</button><button type="button" class="vpm-button secondary" id="vpm-demand-preview">Refresh query</button></div><div id="vpm-demand-status" class="vpm-message" style="margin-top:12px" hidden></div><details style="margin-top:16px"><summary>Capture a verified public request into CRM</summary><div class="vpm-form-grid" style="margin-top:12px"><label>Company name<input id="vpm-demand-company" placeholder="Company named in the request"></label><label>Evidence URL<input id="vpm-demand-evidence" type="url" placeholder="https://public-source.example/request"></label><label style="grid-column:1/-1">Evidence summary<textarea id="vpm-demand-description" rows="3" placeholder="What did the company publicly request?"></textarea></label></div><button type="button" class="vpm-button" id="vpm-demand-capture" style="margin-top:12px">Add to review queue</button></details>'; 
    discovery.insertAdjacentElement('afterend', card);
    const q = card.querySelector('#vpm-demand-query');
    const formValues = () => ({service:card.querySelector('#vpm-demand-service').value, signal:card.querySelector('#vpm-demand-signal').value, country:card.querySelector('#vpm-demand-country').value, city:card.querySelector('#vpm-demand-city').value, language:card.querySelector('#vpm-demand-language').value});
    const refresh = () => { q.value = buildQuery(formValues()); };
    const status = (text, kind) => { const el = card.querySelector('#vpm-demand-status'); el.hidden = !text; el.className = 'vpm-message ' + (kind || 'info'); el.textContent = text || ''; };
    card.querySelectorAll('select,input').forEach((el) => el.addEventListener('input', refresh));
    card.querySelector('#vpm-demand-preview').addEventListener('click', refresh);
    card.querySelector('#vpm-demand-search').addEventListener('click', () => { refresh(); const query = q.value; window.open('https://www.google.com/search?q=' + encodeURIComponent(query), '_blank', 'noopener'); window.open('https://news.google.com/search?q=' + encodeURIComponent(query), '_blank', 'noopener'); status('Public search pages opened. Review the evidence manually before capturing a lead.', 'info'); });
    card.querySelector('#vpm-demand-feed').addEventListener('click', async () => { refresh(); const values=formValues(); const url=feedUrl(q.value, values.language, values.country); try { await api('crm/lead-hunter/sources',{method:'POST',body:{name:'Service Demand — ' + values.service + (values.city ? ' — ' + values.city : ''),sourceType:'rss',endpointUrl:url,config:{mode:'service_demand',service:values.service,signal:values.signal,country:values.country,city:values.city,language:values.language,query:q.value},active:true}}); status('Google News RSS source saved. Run the configured scan from Lead Hunter when you are ready.', 'success'); } catch (error) { status(error.message, 'error'); } });
    card.querySelector('#vpm-demand-capture').addEventListener('click', async () => { const values=formValues(); const company=card.querySelector('#vpm-demand-company').value.trim(); const evidence=card.querySelector('#vpm-demand-evidence').value.trim(); const description=card.querySelector('#vpm-demand-description').value.trim(); if (!company || !evidence || !description) { status('Company name, public evidence URL, and evidence summary are required.', 'error'); return; } try { const result=await api('crm/leads',{method:'POST',body:{leadType:'verified_demand',company,industry:'Service demand',country:values.country,city:values.city,evidenceUrl:evidence,services:[values.service],description,leadSource:'Public service request',leadScore:90,scoreReasons:['Explicit public service-demand evidence','Vibes service match','Manually reviewed evidence']}}); status('Verified public request added to the CRM review queue (lead #' + (result.id || '') + ').', 'success'); card.querySelector('#vpm-demand-company').value=''; card.querySelector('#vpm-demand-evidence').value=''; card.querySelector('#vpm-demand-description').value=''; } catch (error) { status(error.message, 'error'); } });
    refresh();
  }
  initServiceDemand();
  setInterval(initServiceDemand, 2500);
})();

/* Portal repair pass 2026-11-03: bounded UI fixes */
(() => {
  const addRepairs = () => {
    if (!document.getElementById('vpm-layout-repairs')) {
      const style = document.createElement('style');
      style.id = 'vpm-layout-repairs';
      style.textContent = '.vpm-payslip-sheet{min-width:0;overflow:hidden}.vpm-payslip-sheet *{max-width:100%;box-sizing:border-box}.vpm-payslip-employee>div{min-width:0;overflow-wrap:anywhere}.vpm-payslip-employee strong{overflow-wrap:anywhere}@media(max-width:980px){.vpm-payslips-page .vpm-grid.two{grid-template-columns:1fr}}';
      document.head.appendChild(style);
    }
  };
  const currentRoute = () => (location.hash || '#dashboard').slice(1).split('?')[0];
  const fetchEmployees = async () => {
    const response = await fetch(VPM.root + 'employees', {headers: {'X-WP-Nonce': VPM.nonce}, credentials: 'same-origin', cache: 'no-store'});
    const data = await response.json().catch(() => []);
    if (!response.ok) throw new Error(data.message || 'Unable to load employees.');
    return data;
  };
  const syncDashboardEmployees = async () => {
    if (currentRoute() !== 'dashboard' || document.querySelector('.vtp-d')) return; // the redesigned dashboard already shows correct counts
    const target = document.querySelector('a[href="#employees"] strong');
    if (!target || target.dataset.vpmEmployeeCountSynced === '1') return;
    target.dataset.vpmEmployeeCountSynced = '1';
    try {
      const rows = await fetchEmployees();
      const list = Array.isArray(rows) ? rows : (rows && Array.isArray(rows.items) ? rows.items : (rows && Array.isArray(rows.data) ? rows.data : []));
      const count = list.filter((row) => row && row.active !== false).length;
      target.textContent = String(count);
    } catch (error) { target.dataset.vpmEmployeeCountSynced = '0'; }
  };
  const triggerModuleRoute = () => { const route = currentRoute(); if (!['finance', 'crm', 'payslips'].includes(route)) return; const link = document.querySelector('#vtp-nav a[href="#' + route + '"]'); if (link && !link.classList.contains('active')) link.click(); };
  const run = () => { addRepairs(); syncDashboardEmployees(); triggerModuleRoute(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run, {once:true}); else run();
  window.addEventListener('hashchange', () => window.setTimeout(run, 250));
  window.setTimeout(triggerModuleRoute, 650);
  window.setTimeout(triggerModuleRoute, 2200);
  window.setTimeout(triggerModuleRoute, 4200);
  const view = document.getElementById('vtp-view');
  [view, document.getElementById('vtp-nav')].filter(Boolean).forEach((target) => new MutationObserver(() => { if (currentRoute() === 'dashboard') syncDashboardEmployees(); else triggerModuleRoute(); }).observe(target, {childList:true, subtree:true}));
})();


/* Finance source-currency controls: client documents stay in SAR or USD; reporting converts separately. */
(() => {
  'use strict';
  const applyFinanceCurrencyControls = () => {
    const inquiry = document.querySelector('#vtp-inquiry-form');
    if (inquiry) {
      const budget = inquiry.elements?.budget;
      const row = budget?.closest('.vtp-form-row');
      if (row && !inquiry.elements.currency) {
        const field = document.createElement('div');
        field.className = 'vtp-field';
        field.innerHTML = '<label>Currency</label><select name="currency"><option value="SAR" selected>SAR · Saudi client</option><option value="USD">USD · Lebanon / international</option></select><small>Keep the client document in its source currency.</small>';
        row.appendChild(field);
      }
      if (!inquiry.dataset.vtpFinanceCurrencyBound) {
        inquiry.dataset.vtpFinanceCurrencyBound = '1';
        inquiry.addEventListener('submit', () => {
          const currency = inquiry.elements.currency?.value === 'USD' ? 'USD' : 'SAR';
          let details = inquiry.elements.serviceDetails;
          if (!details) { details = document.createElement('input'); details.type = 'hidden'; details.name = 'serviceDetails'; inquiry.appendChild(details); }
          const services = String(inquiry.elements.services?.value || '').split(/[\r\n,]+/).map(value => value.trim()).filter(Boolean);
          details.value = JSON.stringify((services.length ? services : ['Custom request']).map(name => ({ name, quantity: 1, unitPrice: 0, currency, custom: true })));
        }, true);
      }
    }
    const invoice = document.querySelector('#vtp-invoice-form');
    const select = invoice?.elements?.currency;
    if (select && !select.dataset.vtpFinanceCurrencyReady) {
      select.dataset.vtpFinanceCurrencyReady = '1';
      if (![...select.options].some(option => option.value === 'SAR')) {
        const option = document.createElement('option'); option.value = 'SAR'; option.textContent = 'SAR · Saudi client'; select.insertBefore(option, select.firstChild);
      }
      if (![...select.options].some(option => option.value === 'USD')) {
        const option = document.createElement('option'); option.value = 'USD'; option.textContent = 'USD · Lebanon / international'; select.appendChild(option);
      }
      const quotation = invoice.elements?.quotationId;
      if (select.value !== 'USD' && select.value !== 'SAR') select.value = 'SAR';
      if (quotation && !quotation.value) select.value = 'SAR';
      const field = select.closest('.vtp-field');
      if (field && !field.querySelector('[data-vtp-finance-currency-note]')) {
        const note = document.createElement('small'); note.dataset.vtpFinanceCurrencyNote = '1'; note.textContent = 'Invoice currency follows the client quotation; KSA clients normally use SAR.'; field.appendChild(note);
      }
    }
  };
  const roots = [document.querySelector('#vtp-view'), document.querySelector('#vtp-drawer')].filter(Boolean);
  roots.forEach(root => new MutationObserver(applyFinanceCurrencyControls).observe(root, { childList: true, subtree: true }));
  window.addEventListener('hashchange', applyFinanceCurrencyControls);
  window.setTimeout(applyFinanceCurrencyControls, 500);
})();

/* Vibes Connect v2 — team chat. Replaces the earlier overlay; talks to the same vibes/v1/connect REST routes. */
(() => {
  'use strict';
  if (window.__VCX_LOADED__) return; window.__VCX_LOADED__ = true;

  const S = {
    open: false, convs: [], active: null, msgs: [], sig: '', hasMore: false, reads: {}, typing: [], members: [],
    me: Number(window.VPM && VPM.userId) || 0, filter: 'all', q: '', reply: null, edit: null, files: [], pending: [],
    newFromId: 0, unreadBelow: 0, listTimer: 0, threadTimer: 0, lastTypingPing: 0, lastReadSent: 0, searchTimer: 0, searchRes: null, loadingThread: false, view: 'list'
  };
  const QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
  const EMOJI = Array.from('😀😃😄😁😆😅😂🤣😊😇🙂😉😌😍🥰😘😋😛😜🤪😎🤩🥳😏😒😞😔😟😕🙁😣😫😩🥺😢😭😤😠😡🤯😳😱😨😰😓🤗🤔🤭🤫😶😐😑😬🙄😯😮😲🥱😴🤤😷🤒🤕👍👎👏🙌🙏💪👌✌️🤞👋🤝❤️🧡💛💚💙💜🖤💔🔥✨🎉🎊💯✅❌⭐⚡☕🍕🎯📌📎💡');
  const MAX_FILES = 5, MAX_BYTES = 20 * 1024 * 1024;
  const coarse = () => window.matchMedia && matchMedia('(pointer: coarse)').matches;
  const mobile = () => window.matchMedia && matchMedia('(max-width: 760px)').matches;

  const $ = (sel, el = document) => el.querySelector(sel);
  const $$ = (sel, el = document) => Array.from(el.querySelectorAll(sel));
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[c]));
  const icons = {
    send: '<svg viewBox="0 0 24 24"><path d="M4 12 20 4l-4.5 16-3.5-6.5L4 12Z"/><path d="m12 13.5 3.5-5"/></svg>',
    clip: '<svg viewBox="0 0 24 24"><path d="m20 11-8.2 8.2a5 5 0 0 1-7-7L13.5 3.5a3.3 3.3 0 0 1 4.7 4.7L9.6 16.8a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8"/></svg>',
    smile: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.2a4.2 4.2 0 0 0 7 0M9 9.5h.01M15 9.5h.01"/></svg>',
    back: '<svg viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></svg>',
    close: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
    info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
    pin: '<svg viewBox="0 0 24 24"><path d="M9 4h6l-1 6 3 3H7l3-3-1-6ZM12 13v7"/></svg>',
    more: '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/></svg>',
    reply: '<svg viewBox="0 0 24 24"><path d="M9 7 4 12l5 5"/><path d="M4 12h9a7 7 0 0 1 7 7"/></svg>',
    file: '<svg viewBox="0 0 24 24"><path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12.500 4.500 4.500L19 7.500"/></svg>',
    chat: '<svg viewBox="0 0 24 24"><path d="M20 11.500a7.500 7.500 0 0 1-8 7.500 8.800 8.800 0 0 1-3.200-.600L4 20l1.200-3.700A7.200 7.200 0 0 1 4 11.500 7.500 7.500 0 0 1 12 4a7.500 7.500 0 0 1 8 7.500Z"/></svg>',
    users: '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.500 19c.4-3 2.200-4.500 5.500-4.500s5.100 1.500 5.500 4.500M16 6a3 3 0 0 1 0 5.500M17 14.500c2 .5 3.200 1.800 3.600 4.500"/></svg>'
  };

  // ---------- helpers ----------
  const api = async (path, options = {}) => {
    const headers = {'X-WP-Nonce': VPM.nonce, ...(options.headers || {})};
    if (options.body && !(options.body instanceof FormData) && typeof options.body !== 'string') { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
    const res = await fetch(VPM.root + path.replace(/^\//, ''), {...options, headers, credentials: 'same-origin', cache: 'no-store'});
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || 'The request could not be completed.');
    return data;
  };
  const initials = (n) => String(n || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
  const hue = (n) => { let h = 0; for (const c of String(n)) h = (h * 31 + c.codePointAt(0)) % 360; return h; };
  const avatar = (name, url, size = '') => {
    const real = url && !String(url).includes('d=mm') ? url : '';
    return `<span class="vcx-av ${size}" style="--h:${hue(name)}">${real ? `<img src="${esc(real)}" alt="" loading="lazy">` : `<b>${esc(initials(name))}</b>`}</span>`;
  };
  const groupAv = (size = '') => `<span class="vcx-av vcx-av-group ${size}"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.500 19c.4-3 2.200-4.500 5.500-4.500s5.100 1.500 5.500 4.500M16 6a3 3 0 0 1 0 5.500M17 14.500c2 .5 3.200 1.800 3.600 4.500"/></svg></span>`;
  const other = (c) => (c.members || []).find((m) => Number(m.id) !== S.me);
  const isDirect = (c) => c.type === 'direct';
  const convTitle = (c) => isDirect(c) ? (other(c)?.name || c.title) : (c.title || 'Conversation');
  const convAv = (c, size) => isDirect(c) ? avatar(other(c)?.name || c.title, other(c)?.avatar_url, size) : groupAv(size);
  const tsOf = (m) => (m.ts ? m.ts * 1000 : new Date(String(m.created_at).replace(' ', 'T')).getTime());
  const fmtTime = (ms) => new Date(ms).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
  const dayKey = (ms) => { const d = new Date(ms); return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`; };
  const dayLabel = (ms) => {
    const d = new Date(ms), now = new Date(), yest = new Date(Date.now() - 864e5);
    if (dayKey(ms) === dayKey(now.getTime())) return 'Today';
    if (dayKey(ms) === dayKey(yest.getTime())) return 'Yesterday';
    return d.toLocaleDateString([], {weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric'});
  };
  const listTime = (ms) => {
    if (!ms) return ''; const now = Date.now();
    if (dayKey(ms) === dayKey(now)) return fmtTime(ms);
    if (dayKey(ms) === dayKey(now - 864e5)) return 'Yesterday';
    if (now - ms < 6 * 864e5) return new Date(ms).toLocaleDateString([], {weekday: 'short'});
    return new Date(ms).toLocaleDateString([], {day: 'numeric', month: 'short'});
  };
  const sizeLabel = (b) => !b ? '' : b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  const emojiName = (e) => 'u' + Array.from(e).map((c) => c.codePointAt(0).toString(16)).join('-');
  const emojiFrom = (name) => {
    const legacy = {thumbs_up: '👍', heart: '❤️', laugh: '😂', smile: '😊', wow: '😮', sad: '😢', pray: '🙏', fire: '🔥', celebrate: '🎉'};
    if (legacy[name]) return legacy[name];
    if (/^u[0-9a-f]+(-[0-9a-f]+)*$/.test(name)) { try { return String.fromCodePoint(...name.slice(1).split('-').map((h) => parseInt(h, 16))); } catch (e) { /* fall through */ } }
    return name;
  };
  const previewText = (c) => {
    if (!c.message_count) return 'No messages yet';
    const who = Number(c.last_message_user_id) === S.me ? 'You: ' : (!isDirect(c) && c.last_message_user ? c.last_message_user.split(' ')[0] + ': ' : '');
    return who + (c.last_message_body ? c.last_message_body.replace(/\s+/g, ' ') : '📎 Attachment');
  };
  const memberNamesRe = () => {
    const names = (S.active?.members || []).map((m) => m.name).filter(Boolean);
    const firsts = names.map((n) => n.split(' ')[0]).filter((n) => n.length > 1);
    const all = Array.from(new Set([...names, ...firsts])).sort((a, b) => b.length - a.length).map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    return all.length ? new RegExp('@(' + all.join('|') + ')(?![\\p{L}\\p{N}_])', 'giu') : null;
  };
  const richText = (text) => {
    let html = esc(text);
    html = html.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>');
    const re = memberNamesRe(); if (re) html = html.replace(re, '<span class="vcx-mention">$&</span>');
    return html.replace(/\n/g, '<br>');
  };
  const toast = (text, kind = 'info') => {
    const host = $('#vcx-toasts'); if (!host) return;
    const t = document.createElement('div'); t.className = 'vcx-toast ' + kind; t.textContent = text; host.appendChild(t);
    setTimeout(() => t.classList.add('out'), 3600); setTimeout(() => t.remove(), 4000);
  };
  const emitUnread = () => {
    const total = S.convs.reduce((n, c) => n + Number(c.unread_count || 0), 0);
    window.dispatchEvent(new CustomEvent('vpm-connect-unread', {detail: {count: total}}));
  };

  // ---------- shell ----------
  function shell() {
    return `<div class="vcx-app" data-view="list">
      <aside class="vcx-side" aria-label="Conversations">
        <header class="vcx-side-head">
          <div class="vcx-brand"><span class="vcx-logo">${icons.chat}</span><div><strong>Vibes Connect</strong><small>Team chat</small></div></div>
          <div class="vcx-head-actions"><button type="button" class="vcx-icon-btn primary" data-act="new" aria-label="Start a new chat" title="New chat">${icons.plus}</button><button type="button" class="vcx-icon-btn" data-act="close" aria-label="Close Vibes Connect" title="Close">${icons.close}</button></div>
        </header>
        <div class="vcx-search"><span>${icons.search}</span><input id="vcx-search" type="search" placeholder="Search chats, people and messages" autocomplete="off" aria-label="Search"></div>
        <div class="vcx-filters" role="tablist">${[['all', 'All'], ['unread', 'Unread'], ['groups', 'Groups'], ['pinned', 'Pinned']].map(([k, l]) => `<button type="button" role="tab" data-filter="${k}" class="${S.filter === k ? 'on' : ''}">${l}<i data-count="${k}"></i></button>`).join('')}</div>
        <div class="vcx-list" id="vcx-list" role="list"></div>
      </aside>
      <section class="vcx-main" aria-label="Chat">
        <div class="vcx-empty-main" id="vcx-empty"><div><span>${icons.chat}</span><h2>Select a conversation</h2><p>Pick a chat on the left or start a new one.</p></div></div>
        <div class="vcx-chat" id="vcx-chat" hidden>
          <header class="vcx-chat-head" id="vcx-chat-head"></header>
          <div class="vcx-scroll" id="vcx-scroll" tabindex="0"></div>
          <button type="button" class="vcx-jump" id="vcx-jump" hidden></button>
          <div class="vcx-typing" id="vcx-typing" hidden></div>
          <div class="vcx-bars" id="vcx-bars"></div>
          <form class="vcx-composer" id="vcx-composer" autocomplete="off">
            <button type="button" class="vcx-icon-btn" data-act="attach" aria-label="Attach files" title="Attach files">${icons.clip}</button>
            <input type="file" id="vcx-file" multiple hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.csv">
            <div class="vcx-input-wrap"><textarea id="vcx-input" rows="1" placeholder="Write a message" aria-label="Message"></textarea><button type="button" class="vcx-icon-btn" data-act="emoji" aria-label="Insert emoji" title="Emoji">${icons.smile}</button></div>
            <button type="submit" class="vcx-send" id="vcx-send" aria-label="Send message" title="Send">${icons.send}</button>
          </form>
        </div>
      </section>
      <div class="vcx-drop" id="vcx-drop" hidden>Drop files to attach</div>
    </div>
    <div id="vcx-layer"></div><div id="vcx-toasts" aria-live="polite"></div>`;
  }

  function openOverlay() {
    if (S.open) return;
    S.open = true;
    let root = document.getElementById('vcx-root');
    if (!root) { root = document.createElement('div'); root.id = 'vcx-root'; root.className = 'vcx-backdrop'; root.setAttribute('role', 'dialog'); root.setAttribute('aria-modal', 'true'); root.setAttribute('aria-label', 'Vibes Connect'); document.body.appendChild(root); }
    root.innerHTML = shell();
    document.body.classList.add('vcx-open');
    bindShell(root);
    sizeToViewport();
    loadAll(true);
  }
  function closeOverlay() {
    S.open = false; clearTimeout(S.listTimer); clearTimeout(S.threadTimer);
    S.active = null; S.msgs = []; S.sig = ''; S.reply = null; S.edit = null; S.files.forEach(releaseFile); S.files = []; S.pending = []; S.view = 'list';
    document.getElementById('vcx-root')?.remove(); document.body.classList.remove('vcx-open');
    document.documentElement.style.removeProperty('--vcx-h');
  }
  function openFromRoute() {
    if ((location.hash || '').slice(1).split('?')[0] === 'connect') {
      let back = '#dashboard'; try { back = sessionStorage.getItem('vtp-connect-return-hash') || '#dashboard'; sessionStorage.removeItem('vtp-connect-return-hash'); } catch (e) { /* ignore */ }
      if (back === '#connect') back = '#dashboard';
      history.replaceState(history.state, '', back);
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    }
    openOverlay();
  }
  function sizeToViewport() {
    const vv = window.visualViewport; if (!S.open) return;
    document.documentElement.style.setProperty('--vcx-h', (vv ? vv.height : window.innerHeight) + 'px');
  }

  function setView(view) {
    S.view = view; const app = $('.vcx-app'); if (app) app.dataset.view = view;
  }

  // ---------- data ----------
  async function loadAll(first) {
    try {
      const [convs, members] = await Promise.all([api('connect/conversations'), api('connect/members')]);
      S.convs = convs || []; S.members = members || [];
      renderList(); emitUnread();
      if (first && !mobile() && S.convs.length) await openConversation(S.convs.find((c) => c.type === 'general' || c.pinned)?.id || S.convs[0].id, {keepView: true});
    } catch (e) { const l = $('#vcx-list'); if (l) l.innerHTML = `<div class="vcx-note error">${esc(e.message)}</div>`; }
    schedule();
  }
  function schedule() {
    clearTimeout(S.listTimer); clearTimeout(S.threadTimer); if (!S.open) return;
    const hidden = document.visibilityState === 'hidden';
    S.listTimer = setTimeout(async () => { try { S.convs = await api('connect/conversations'); renderList(false); emitUnread(); renderHeadMeta(); } catch (e) { /* retry next tick */ } schedule(); }, hidden ? 45000 : 15000);
    if (S.active) S.threadTimer = setTimeout(async () => { try { await loadThread(); } catch (e) { /* retry */ } }, hidden ? 20000 : 4000);
  }
  async function refreshNow() { if (!S.open) return; try { S.convs = await api('connect/conversations'); renderList(false); emitUnread(); if (S.active) await loadThread(); } catch (e) { /* ignore */ } schedule(); }

  async function openConversation(id, opts = {}) {
    const conv = S.convs.find((c) => Number(c.id) === Number(id)); if (!conv) return;
    S.active = conv; S.msgs = []; S.sig = ''; S.hasMore = false; S.reply = null; S.edit = null; S.pending = []; S.unreadBelow = 0; S.lastReadSent = 0; S.newFromId = 0; S.typing = [];
    S.files.forEach(releaseFile); S.files = [];
    S.reads = {};
    $('#vcx-empty').hidden = true; $('#vcx-chat').hidden = false;
    $('#vcx-scroll').innerHTML = '<div class="vcx-loading"><span></span></div>';
    renderChatHead(); renderBars(); renderList(false);
    if (!opts.keepView || mobile()) setView('chat');
    const input = $('#vcx-input'); if (input) { input.value = ''; autosize(); }
    try { await loadThread(true, opts.jumpTo); } catch (e) { $('#vcx-scroll').innerHTML = `<div class="vcx-note error">${esc(e.message)}</div>`; }
    schedule();
    if (!mobile() && !coarse()) input?.focus();
  }

  async function loadThread(initial = false, jumpTo = 0) {
    if (!S.active || S.loadingThread) return; S.loadingThread = true;
    const id = S.active.id;
    try {
      const data = await api(`connect/conversations/${id}/thread?limit=60${S.sig && !initial ? '&sig=' + S.sig : ''}`);
      if (!S.active || S.active.id !== id) return;
      S.reads = data.reads || {}; S.typing = data.typing || [];
      if (!data.unchanged) {
        const firstLoad = initial || !S.msgs.length;
        const prevIds = new Set(S.msgs.map((m) => m.id));
        const myRead = Number(S.reads[String(S.me)] || 0);
        if (firstLoad) S.newFromId = myRead;
        const incoming = (data.messages || []).filter((m) => !prevIds.has(m.id) && m.user_id !== S.me);
        // Keep older pages the user already loaded; replace the newest window.
        const oldest = data.messages[0]?.id || 0;
        const keepOlder = firstLoad ? [] : S.msgs.filter((m) => m.id < oldest);
        S.msgs = [...keepOlder, ...(data.messages || [])];
        if (firstLoad) S.hasMore = !!data.hasMore; else if (keepOlder.length === 0) S.hasMore = !!data.hasMore;
        S.sig = data.sig;
        S.pending = S.pending.filter((p) => p.status === 'failed');
        renderMessages({firstLoad, incomingCount: firstLoad ? 0 : incoming.length, jumpTo});
      }
      renderTyping(); renderSeen();
      markRead();
    } finally { S.loadingThread = false; }
    if (S.open && S.active && S.active.id === id) { clearTimeout(S.threadTimer); S.threadTimer = setTimeout(() => loadThread().catch(() => schedule()), document.visibilityState === 'hidden' ? 20000 : 4000); }
  }

  async function loadOlder() {
    if (!S.active || !S.hasMore || !S.msgs.length) return;
    const btn = $('#vcx-older'); if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }
    const sc = $('#vcx-scroll'); const before = sc.scrollHeight;
    try {
      const data = await api(`connect/conversations/${S.active.id}/thread?limit=60&before=${S.msgs[0].id}`);
      S.msgs = [...(data.messages || []), ...S.msgs]; S.hasMore = !!data.hasMore;
      renderMessages({keepScroll: true}); sc.scrollTop = sc.scrollHeight - before;
    } catch (e) { toast(e.message, 'error'); }
  }

  function markRead() {
    if (!S.active || document.visibilityState === 'hidden') return;
    const last = [...S.msgs].reverse().find((m) => !m.deleted);
    if (!last || last.id <= S.lastReadSent) return;
    const mineRead = Number(S.reads[String(S.me)] || 0);
    const cur = S.convs.find((x) => x.id === S.active.id);
    if (last.id <= mineRead && !Number(cur?.unread_count || 0)) { S.lastReadSent = last.id; return; }
    S.lastReadSent = last.id;
    api(`connect/conversations/${S.active.id}/read`, {method: 'POST', body: {messageId: last.id}}).then(() => {
      S.reads[String(S.me)] = last.id; const c = S.convs.find((x) => x.id === S.active.id); if (c) c.unread_count = 0; renderList(false); emitUnread();
    }).catch(() => { S.lastReadSent = 0; });
  }

  // ---------- list ----------
  function filteredConvs() {
    const q = S.q.toLowerCase();
    let rows = S.convs.filter((c) => {
      if (S.filter === 'unread' && !Number(c.unread_count)) return false;
      if (S.filter === 'groups' && isDirect(c)) return false;
      if (S.filter === 'pinned' && !c.pinned) return false;
      if (!q) return true;
      return convTitle(c).toLowerCase().includes(q) || (c.members || []).some((m) => (m.name || '').toLowerCase().includes(q));
    });
    rows.sort((a, b) => (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || String(b.sort_at || '').localeCompare(String(a.sort_at || '')));
    return rows;
  }
  function renderList(resetScroll = true) {
    const list = $('#vcx-list'); if (!list) return;
    const keep = list.scrollTop;
    $$('.vcx-filters [data-count]').forEach((el) => {
      const k = el.dataset.count; const n = k === 'unread' ? S.convs.filter((c) => Number(c.unread_count)).length : 0; el.textContent = n ? n : ''; el.style.display = n ? '' : 'none';
    });
    const rows = filteredConvs();
    let html = rows.map((c) => {
      const unread = Number(c.unread_count) || 0, active = S.active && S.active.id === c.id;
      return `<button type="button" role="listitem" class="vcx-row ${active ? 'active' : ''} ${unread ? 'unread' : ''}" data-open="${c.id}">
        ${convAv(c)}
        <span class="vcx-row-body"><span class="vcx-row-top"><strong>${esc(convTitle(c))}</strong><time>${esc(listTime(Number(c.last_ts) * 1000))}</time></span>
        <span class="vcx-row-bottom"><span class="vcx-preview">${esc(previewText(c))}</span>${c.pinned ? `<i class="vcx-pinned" title="Pinned">${icons.pin}</i>` : ''}${unread ? `<b class="vcx-badge">${unread > 99 ? '99+' : unread}</b>` : ''}</span></span></button>`;
    }).join('');
    if (S.q.length >= 2 && S.searchRes) {
      html += `<div class="vcx-section">Messages</div>` + (S.searchRes.length ? S.searchRes.map((r) => {
        const c = S.convs.find((x) => x.id === r.conversation_id); if (!c) return '';
        return `<button type="button" role="listitem" class="vcx-row result" data-open="${c.id}" data-jump="${r.id}">${convAv(c)}<span class="vcx-row-body"><span class="vcx-row-top"><strong>${esc(convTitle(c))}</strong><time>${esc(listTime(Number(r.ts) * 1000))}</time></span><span class="vcx-row-bottom"><span class="vcx-preview"><b>${esc((r.user_name || '').split(' ')[0])}:</b> ${esc(r.body)}</span></span></span></button>`;
      }).join('') : '<div class="vcx-note">No matching messages</div>');
    }
    if (!html) html = S.convs.length ? `<div class="vcx-note">${S.filter === 'unread' ? 'You are all caught up 🎉' : S.filter === 'pinned' ? 'Pin a chat to keep it at the top.' : 'Nothing matches your search.'}</div>` : `<div class="vcx-note">No conversations yet. Tap <b>+</b> to start one.</div>`;
    list.innerHTML = html;
    list.scrollTop = resetScroll ? 0 : keep;
  }

  // ---------- chat header ----------
  function subtitle(c) {
    if (S.typing.length) return `<span class="vcx-typing-text">${esc(S.typing.join(', '))} ${S.typing.length > 1 ? 'are' : 'is'} typing…</span>`;
    if (isDirect(c)) return 'Direct message';
    const n = (c.members || []).length; const names = (c.members || []).filter((m) => Number(m.id) !== S.me).slice(0, 3).map((m) => m.name.split(' ')[0]).join(', ');
    return `${n} members${names ? ' · You, ' + esc(names) + (n > 4 ? '…' : '') : ''}`;
  }
  function renderChatHead() {
    const c = S.active, el = $('#vcx-chat-head'); if (!c || !el) return;
    el.innerHTML = `<button type="button" class="vcx-icon-btn vcx-back" data-act="back" aria-label="Back to conversations">${icons.back}</button>
      <button type="button" class="vcx-head-main" data-act="info" aria-label="Conversation details">${convAv(c, 'md')}<span><strong>${esc(convTitle(c))}</strong><small id="vcx-sub">${subtitle(c)}</small></span></button>
      <div class="vcx-head-actions"><button type="button" class="vcx-icon-btn ${c.pinned ? 'on' : ''}" data-act="pin" aria-label="${c.pinned ? 'Unpin' : 'Pin'} conversation" title="${c.pinned ? 'Unpin' : 'Pin'}">${icons.pin}</button><button type="button" class="vcx-icon-btn" data-act="info" aria-label="Details" title="Details">${icons.info}</button><button type="button" class="vcx-icon-btn vcx-close-chat" data-act="close" aria-label="Close" title="Close">${icons.close}</button></div>`;
  }
  function renderHeadMeta() { const c = S.convs.find((x) => S.active && x.id === S.active.id); if (c) { S.active = c; const sub = $('#vcx-sub'); if (sub) sub.innerHTML = subtitle(c); } }
  function renderTyping() {
    const el = $('#vcx-typing'); if (!el) return; const sub = $('#vcx-sub'); if (sub && S.active) sub.innerHTML = subtitle(S.active);
    el.hidden = !S.typing.length; el.innerHTML = S.typing.length ? `<span class="vcx-dots"><i></i><i></i><i></i></span> ${esc(S.typing.join(', '))} ${S.typing.length > 1 ? 'are' : 'is'} typing` : '';
  }
  function renderSeen() {
    $$('.vcx-seen').forEach((n) => n.remove());
    const mine = [...S.msgs].reverse().find((m) => m.user_id === S.me && !m.deleted); if (!mine || !S.active) return;
    const others = (S.active.members || []).filter((m) => Number(m.id) !== S.me);
    const seen = others.filter((m) => Number(S.reads[String(m.id)] || 0) >= mine.id);
    if (!seen.length) return;
    const node = $(`[data-msg="${mine.id}"] .vcx-meta`); if (!node) return;
    const label = isDirect(S.active) ? 'Seen' : (seen.length === others.length ? 'Seen by everyone' : `Seen by ${seen.length}`);
    node.insertAdjacentHTML('beforeend', `<span class="vcx-seen" title="${esc(seen.map((m) => m.name).join(', '))}">${icons.check}${label}</span>`);
  }

  // ---------- messages ----------
  function attachmentHtml(a) {
    const url = a.url + (a.url.includes('?') ? '&' : '?') + '_wpnonce=' + encodeURIComponent(VPM.nonce);
    if (a.image) return `<button type="button" class="vcx-img" data-lightbox="${esc(url)}" data-name="${esc(a.filename)}"><img src="${esc(url)}" alt="${esc(a.filename)}" loading="lazy"></button>`;
    return `<a class="vcx-file" href="${esc(url)}" download="${esc(a.filename)}"><span class="vcx-file-ic">${icons.file}</span><span><strong>${esc(a.filename)}</strong><small>${esc(sizeLabel(a.size) || (a.mime || '').split('/').pop().toUpperCase())}</small></span></a>`;
  }
  function bubble(m, grouped, first) {
    const mine = m.user_id === S.me; const ts = tsOf(m);
    if (m.deleted) return `<div class="vcx-msg ${mine ? 'mine' : ''} ${grouped ? 'grouped' : ''}" data-msg="${m.id}">${mine || grouped ? '<span class="vcx-av-spacer"></span>' : avatar(m.user_name, m.avatar_url, 'sm')}<div class="vcx-col"><div class="vcx-bubble deleted">🚫 This message was deleted</div></div></div>`;
    const quote = m.parent ? `<button type="button" class="vcx-quote" data-goto="${m.parent.id}"><b>${esc(m.parent.user_name || 'Message')}</b><span>${m.parent.deleted ? 'Deleted message' : esc(m.parent.body || '📎 Attachment')}</span></button>` : '';
    const atts = (m.attachments || []).length ? `<div class="vcx-atts ${(m.attachments || []).every((a) => a.image) ? 'imgs' : ''}">${m.attachments.map(attachmentHtml).join('')}</div>` : '';
    const body = m.body ? `<div class="vcx-text">${richText(m.body)}</div>` : '';
    const reacts = (m.reactions || []).length ? `<div class="vcx-reacts">${m.reactions.map((r) => `<button type="button" class="vcx-react ${r.active ? 'on' : ''}" data-react="${m.id}" data-name="${esc(r.reaction)}" title="${esc((r.users || []).join(', '))}"><span>${esc(emojiFrom(r.reaction))}</span><b>${r.total}</b></button>`).join('')}</div>` : '';
    return `<div class="vcx-msg ${mine ? 'mine' : ''} ${grouped ? 'grouped' : ''}" data-msg="${m.id}">
      ${mine ? '' : (grouped ? '<span class="vcx-av-spacer"></span>' : avatar(m.user_name, m.avatar_url, 'sm'))}
      <div class="vcx-col">${!mine && first && !isDirect(S.active) ? `<span class="vcx-name" style="--h:${hue(m.user_name)}">${esc(m.user_name)}</span>` : ''}
        <div class="vcx-bubble-row"><div class="vcx-bubble ${m.attachments?.length && !m.body && !m.parent ? 'media' : ''}" data-menu="${m.id}">${quote}${atts}${body}<span class="vcx-meta"><time>${esc(fmtTime(ts))}</time>${m.edited ? '<em>edited</em>' : ''}</span></div>
        <div class="vcx-tools"><button type="button" data-quick="${m.id}" aria-label="React" title="React">${icons.smile}</button><button type="button" data-replyto="${m.id}" aria-label="Reply" title="Reply">${icons.reply}</button><button type="button" data-menu-btn="${m.id}" aria-label="More actions" title="More">${icons.more}</button></div></div>
        ${reacts}</div></div>`;
  }
  function pendingBubble(p) {
    return `<div class="vcx-msg mine pending ${p.status}" data-pending="${p.id}"><div class="vcx-col"><div class="vcx-bubble-row"><div class="vcx-bubble">${p.files.length ? `<div class="vcx-atts">${p.files.map((f) => f.type.startsWith('image/') ? `<span class="vcx-img"><img src="${esc(f.preview)}" alt=""></span>` : `<span class="vcx-file"><span class="vcx-file-ic">${icons.file}</span><span><strong>${esc(f.file.name)}</strong></span></span>`).join('')}</div>` : ''}${p.body ? `<div class="vcx-text">${richText(p.body)}</div>` : ''}<span class="vcx-meta">${p.status === 'failed' ? '<em class="err">Not sent</em>' : '<em>Sending…</em>'}</span></div></div>${p.status === 'failed' ? `<div class="vcx-failed"><span>${esc(p.error || 'Could not send.')}</span><button type="button" data-retry="${p.id}">Retry</button><button type="button" data-discard="${p.id}">Discard</button></div>` : ''}</div></div>`;
  }
  function renderMessages(opts = {}) {
    const sc = $('#vcx-scroll'); if (!sc) return;
    const nearBottom = sc.scrollHeight - sc.scrollTop - sc.clientHeight < 140;
    const prevTop = sc.scrollTop;
    let html = S.hasMore ? '<div class="vcx-older-wrap"><button type="button" id="vcx-older" class="vcx-older">Load earlier messages</button></div>' : '';
    if (!S.msgs.length && !S.pending.length) {
      html = `<div class="vcx-hello"><span>${icons.chat}</span><h3>${isDirect(S.active) ? 'Say hi to ' + esc(convTitle(S.active).split(' ')[0]) : 'Start the conversation'}</h3><p>Messages here are only visible to ${isDirect(S.active) ? 'you two' : 'the members of this chat'}.</p></div>`;
    } else {
      let lastDay = '', lastUser = 0, lastTs = 0, newShown = false;
      S.msgs.forEach((m) => {
        const ts = tsOf(m), dk = dayKey(ts);
        if (dk !== lastDay) { html += `<div class="vcx-day"><span>${esc(dayLabel(ts))}</span></div>`; lastDay = dk; lastUser = 0; }
        if (!newShown && S.newFromId && m.id > S.newFromId && m.user_id !== S.me) { html += '<div class="vcx-new"><span>New messages</span></div>'; newShown = true; lastUser = 0; }
        const grouped = lastUser === m.user_id && ts - lastTs < 5 * 60000;
        html += bubble(m, grouped, !grouped); lastUser = m.user_id; lastTs = ts;
      });
      S.pending.forEach((p) => { html += pendingBubble(p); });
    }
    sc.innerHTML = html;
    renderSeen();
    if (opts.keepScroll) return;
    if (opts.jumpTo) { requestAnimationFrame(() => gotoMessage(opts.jumpTo)); return; }
    if (opts.firstLoad) {
      const marker = $('.vcx-new', sc);
      requestAnimationFrame(() => { if (marker && S.newFromId && sc.scrollHeight - marker.offsetTop < sc.clientHeight * 3) sc.scrollTop = Math.max(0, marker.offsetTop - 80); else sc.scrollTop = sc.scrollHeight; updateJump(); });
    } else if (nearBottom || S.pending.length) { sc.scrollTop = sc.scrollHeight; S.unreadBelow = 0; updateJump(); }
    else { sc.scrollTop = prevTop; S.unreadBelow += opts.incomingCount || 0; updateJump(); }
  }
  function updateJump() {
    const sc = $('#vcx-scroll'), j = $('#vcx-jump'); if (!sc || !j) return;
    const away = sc.scrollHeight - sc.scrollTop - sc.clientHeight > 240;
    if (!away) S.unreadBelow = 0;
    j.hidden = !away; j.innerHTML = S.unreadBelow ? `↓ ${S.unreadBelow} new message${S.unreadBelow > 1 ? 's' : ''}` : '↓';
    j.classList.toggle('badge', !!S.unreadBelow);
  }
  async function gotoMessage(id) {
    let el = $(`[data-msg="${id}"]`);
    let tries = 0;
    while (!el && S.hasMore && tries++ < 6) { await loadOlder(); el = $(`[data-msg="${id}"]`); }
    if (!el) { toast('That message is no longer available.'); return; }
    el.scrollIntoView({block: 'center', behavior: 'smooth'}); el.classList.add('flash'); setTimeout(() => el.classList.remove('flash'), 1800);
  }

  // ---------- composer ----------
  function autosize() { const i = $('#vcx-input'); if (!i) return; i.style.height = 'auto'; i.style.height = Math.min(i.scrollHeight, 150) + 'px'; updateSend(); }
  function updateSend() { const i = $('#vcx-input'), b = $('#vcx-send'); if (!i || !b) return; b.classList.toggle('ready', !!(i.value.trim() || S.files.length)); }
  const releaseFile = (f) => { if (f.preview) URL.revokeObjectURL(f.preview); };
  function addFiles(list) {
    for (const file of Array.from(list || [])) {
      if (S.files.length >= MAX_FILES) { toast(`You can attach up to ${MAX_FILES} files.`, 'error'); break; }
      if (file.size > MAX_BYTES) { toast(`${file.name} is larger than 20 MB.`, 'error'); continue; }
      S.files.push({file, type: file.type || '', preview: (file.type || '').startsWith('image/') ? URL.createObjectURL(file) : ''});
    }
    renderBars(); updateSend();
  }
  function renderBars() {
    const el = $('#vcx-bars'); if (!el) return; let html = '';
    if (S.edit) html += `<div class="vcx-bar"><div><b>Editing message</b><span>${esc(S.edit.body)}</span></div><button type="button" data-act="cancel-bar" aria-label="Cancel editing">${icons.close}</button></div>`;
    else if (S.reply) html += `<div class="vcx-bar"><div><b>Replying to ${esc(S.reply.user_name)}</b><span>${esc(S.reply.body || '📎 Attachment')}</span></div><button type="button" data-act="cancel-bar" aria-label="Cancel reply">${icons.close}</button></div>`;
    if (S.files.length) html += `<div class="vcx-chips">${S.files.map((f, i) => `<span class="vcx-chip">${f.preview ? `<img src="${esc(f.preview)}" alt="">` : `<i>${icons.file}</i>`}<span>${esc(f.file.name)}</span><button type="button" data-rmfile="${i}" aria-label="Remove ${esc(f.file.name)}">${icons.close}</button></span>`).join('')}</div>`;
    el.innerHTML = html;
  }
  async function submitComposer() {
    if (!S.active) return; const input = $('#vcx-input'); const body = input.value.trim();
    if (S.edit) {
      if (!body) { toast('A message cannot be empty.', 'error'); return; }
      const edit = S.edit; S.edit = null; input.value = ''; autosize(); renderBars();
      try { await api(`connect/conversations/${S.active.id}/messages/${edit.id}`, {method: 'PATCH', body: {body}}); S.sig = ''; await loadThread(); } catch (e) { toast(e.message, 'error'); }
      return;
    }
    if (!body && !S.files.length) return;
    const p = {id: 'p' + Date.now(), body, files: S.files.slice(), parentId: S.reply ? S.reply.id : 0, status: 'sending', conv: S.active.id};
    S.pending.push(p); S.files = []; S.reply = null; input.value = ''; autosize(); renderBars(); renderMessages({}); send(p);
  }
  async function send(p) {
    p.status = 'sending';
    const fd = new FormData(); fd.append('body', p.body); if (p.parentId) fd.append('parentId', p.parentId); p.files.forEach((f, i) => fd.append('file' + i, f.file, f.file.name));
    try {
      await api(`connect/conversations/${p.conv}/send`, {method: 'POST', body: fd});
      p.files.forEach(releaseFile); S.pending = S.pending.filter((x) => x !== p); S.sig = '';
      if (S.active && S.active.id === p.conv) { await loadThread(); const sc = $('#vcx-scroll'); if (sc) sc.scrollTop = sc.scrollHeight; }
      refreshListSoon();
    } catch (e) { p.status = 'failed'; p.error = e.message; if (S.active && S.active.id === p.conv) renderMessages({}); }
  }
  let listSoon = 0; function refreshListSoon() { clearTimeout(listSoon); listSoon = setTimeout(async () => { try { S.convs = await api('connect/conversations'); renderList(false); } catch (e) { /* ignore */ } }, 400); }
  function pingTyping() {
    if (!S.active || Date.now() - S.lastTypingPing < 3000) return; S.lastTypingPing = Date.now();
    api(`connect/conversations/${S.active.id}/typing`, {method: 'POST', body: {}}).catch(() => {});
  }

  // mentions
  function mentionState() {
    const i = $('#vcx-input'); if (!i) return null; const pos = i.selectionStart; const before = i.value.slice(0, pos);
    const m = before.match(/(^|\s)@([\p{L}\p{N}_ .-]{0,30})$/u); if (!m || isDirect(S.active)) return null;
    return {start: pos - m[2].length - 1, query: m[2].toLowerCase()};
  }
  function updateMentions() {
    const layer = $('#vcx-layer'); layer.querySelector('.vcx-mentions')?.remove();
    const st = mentionState(); if (!st) return;
    const people = (S.active.members || []).filter((m) => Number(m.id) !== S.me && m.name.toLowerCase().includes(st.query.trim())).slice(0, 6); if (!people.length) return;
    const box = document.createElement('div'); box.className = 'vcx-mentions';
    box.innerHTML = people.map((m, i) => `<button type="button" data-mention="${esc(m.name)}" class="${i === 0 ? 'on' : ''}">${avatar(m.name, m.avatar_url, 'sm')}<span>${esc(m.name)}</span></button>`).join('');
    const bars = $('#vcx-composer'); const r = bars.getBoundingClientRect(); const host = layer.getBoundingClientRect();
    box.style.left = (r.left - host.left + 56) + 'px'; box.style.bottom = (host.bottom - r.top + 6) + 'px'; layer.appendChild(box);
  }
  function insertMention(name) {
    const i = $('#vcx-input'), st = mentionState(); if (!st) return;
    i.value = i.value.slice(0, st.start) + '@' + name + ' ' + i.value.slice(i.selectionStart); const pos = st.start + name.length + 2; i.setSelectionRange(pos, pos); $('#vcx-layer .vcx-mentions')?.remove(); i.focus(); autosize();
  }

  // ---------- menus, sheets ----------
  function closeLayer() { const l = $('#vcx-layer'); if (l) { l.innerHTML = ''; l.onclick = null; l.onsubmit = null; } }
  function sheet(html, cls = '') {
    const layer = $('#vcx-layer'); layer.onclick = null; layer.onsubmit = null; layer.innerHTML = `<div class="vcx-scrim" data-act="dismiss"></div><div class="vcx-sheet ${cls}" role="dialog" aria-modal="true">${html}</div>`;
    const first = layer.querySelector('input,textarea,button.vcx-primary'); if (first && !coarse()) first.focus();
  }
  function msgById(id) { return S.msgs.find((m) => Number(m.id) === Number(id)); }
  function openMenu(id, anchor) {
    const m = msgById(id); if (!m || m.deleted) return; const mine = m.user_id === S.me; const admin = !!window.VPMIsAdmin || !!$('#vtp-app[data-role="admin"]');
    const quick = QUICK.map((e) => `<button type="button" class="vcx-q" data-reactq="${m.id}" data-e="${esc(e)}">${e}</button>`).join('') + `<button type="button" class="vcx-q more" data-moreemoji="${m.id}" aria-label="More emoji">${icons.plus}</button>`;
    const items = [['reply', 'Reply', icons.reply], ['copy', 'Copy text', icons.file], ['forward', 'Forward', icons.send]];
    if (mine && m.body) items.push(['edit', 'Edit', icons.file]); if (mine || admin) items.push(['delete', 'Delete', icons.close, 'danger']);
    const inner = `<div class="vcx-quickrow">${quick}</div><div class="vcx-menu">${items.filter((i) => i[0] !== 'copy' || m.body).map(([k, l, ic, c]) => `<button type="button" class="${c || ''}" data-mact="${k}" data-id="${m.id}"><i>${ic}</i>${l}</button>`).join('')}</div>`;
    if (mobile() || !anchor) { sheet(inner, 'bottom compact'); return; }
    const layer = $('#vcx-layer'); layer.onclick = null; layer.onsubmit = null; layer.innerHTML = `<div class="vcx-scrim clear" data-act="dismiss"></div><div class="vcx-pop" role="menu">${inner}</div>`;
    const pop = $('.vcx-pop', layer), r = anchor.getBoundingClientRect(), host = layer.getBoundingClientRect(); let top = r.bottom - host.top + 6; const h = pop.offsetHeight;
    if (top + h > host.height - 8) top = Math.max(8, r.top - host.top - h - 6);
    let left = mine ? r.right - host.left - pop.offsetWidth : r.left - host.left; left = Math.max(8, Math.min(left, host.width - pop.offsetWidth - 8));
    pop.style.top = top + 'px'; pop.style.left = left + 'px';
  }
  function emojiPicker(onPick, anchor) {
    const grid = `<div class="vcx-emoji-grid">${EMOJI.map((e) => `<button type="button" data-pick="${esc(e)}">${e}</button>`).join('')}</div>`;
    const layer = $('#vcx-layer');
    if (mobile()) { sheet(grid, 'bottom emoji'); } else {
      layer.onclick = null; layer.onsubmit = null; layer.innerHTML = `<div class="vcx-scrim clear" data-act="dismiss"></div><div class="vcx-pop emoji">${grid}</div>`;
      const pop = $('.vcx-pop', layer), r = anchor.getBoundingClientRect(), host = layer.getBoundingClientRect();
      pop.style.left = Math.max(8, Math.min(r.right - host.left - pop.offsetWidth, host.width - pop.offsetWidth - 8)) + 'px'; pop.style.top = Math.max(8, r.top - host.top - pop.offsetHeight - 8) + 'px';
    }
    layer.onclick = (ev) => { const b = ev.target.closest('[data-pick]'); if (b) { onPick(b.dataset.pick); closeLayer(); layer.onclick = null; } };
  }
  async function react(id, emoji) {
    try { await api(`connect/messages/${id}/reactions`, {method: 'POST', body: {reaction: emojiName(emoji)}}); S.sig = ''; await loadThread(); } catch (e) { toast(e.message, 'error'); }
  }
  function confirmBox(title, text, okLabel, onOk) {
    sheet(`<h3>${esc(title)}</h3><p class="vcx-muted">${esc(text)}</p><div class="vcx-actions"><button type="button" class="vcx-btn" data-act="dismiss">Cancel</button><button type="button" class="vcx-btn danger vcx-primary" id="vcx-ok">${esc(okLabel)}</button></div>`, 'dialog');
    $('#vcx-ok').addEventListener('click', () => { closeLayer(); onOk(); });
  }
  function forwardSheet(id) {
    const m = msgById(id); if (!m) return;
    sheet(`<h3>Forward to…</h3><div class="vcx-fwd">${S.convs.map((c) => `<button type="button" data-fwd="${c.id}">${convAv(c, 'sm')}<span>${esc(convTitle(c))}</span></button>`).join('')}</div>`, 'dialog scroll');
    $('#vcx-layer').onclick = async (ev) => {
      const b = ev.target.closest('[data-fwd]'); if (!b) return; const target = Number(b.dataset.fwd); closeLayer(); $('#vcx-layer').onclick = null;
      const fd = new FormData(); fd.append('body', `↪ Forwarded from ${m.user_name}:\n${m.body || '(attachment)'}`);
      try { await api(`connect/conversations/${target}/send`, {method: 'POST', body: fd}); toast('Message forwarded'); refreshListSoon(); } catch (e) { toast(e.message, 'error'); }
    };
  }
  function lightbox(url, name) {
    const layer = $('#vcx-layer'); layer.onclick = null; layer.onsubmit = null; layer.innerHTML = `<div class="vcx-lightbox" data-act="dismiss"><button type="button" class="vcx-icon-btn light" data-act="dismiss" aria-label="Close">${icons.close}</button><img src="${esc(url)}" alt="${esc(name)}"><a class="vcx-btn light" href="${esc(url)}" download="${esc(name)}">Download</a></div>`;
  }
  function newChatSheet() {
    const people = S.members.filter((m) => Number(m.id) !== S.me);
    sheet(`<div class="vcx-seg"><button type="button" class="on" data-tab="direct">Direct message</button><button type="button" data-tab="group">New group</button></div>
      <div id="vcx-newbody"></div>`, 'dialog scroll');
    const body = $('#vcx-newbody');
    const drawDirect = () => { body.innerHTML = `<div class="vcx-search sm"><span>${icons.search}</span><input id="vcx-psearch" placeholder="Find a teammate" autocomplete="off"></div><div class="vcx-people" id="vcx-people"></div>`;
      const draw = (q = '') => { $('#vcx-people').innerHTML = people.filter((m) => m.name.toLowerCase().includes(q)).map((m) => `<button type="button" data-dm="${m.id}">${avatar(m.name, m.avatar_url)}<span><strong>${esc(m.name)}</strong><small>${esc(m.email || '')}</small></span></button>`).join('') || '<div class="vcx-note">No teammates found</div>'; };
      draw(); $('#vcx-psearch').addEventListener('input', (e) => draw(e.target.value.toLowerCase())); };
    const drawGroup = () => { body.innerHTML = `<form id="vcx-groupform"><label class="vcx-field">Group name<input name="title" placeholder="e.g. Design team" maxlength="80" required></label><div class="vcx-field">Members</div><div class="vcx-people select">${people.map((m) => `<label><input type="checkbox" name="m" value="${m.id}">${avatar(m.name, m.avatar_url)}<span><strong>${esc(m.name)}</strong><small>${esc(m.email || '')}</small></span><i class="tick">${icons.check}</i></label>`).join('')}</div><div class="vcx-actions"><button type="button" class="vcx-btn" data-act="dismiss">Cancel</button><button type="submit" class="vcx-btn primary">Create group</button></div></form>`; };
    drawDirect();
    $('#vcx-layer').onclick = async (ev) => {
      const tab = ev.target.closest('[data-tab]'); if (tab) { $$('.vcx-seg button').forEach((b) => b.classList.toggle('on', b === tab)); tab.dataset.tab === 'direct' ? drawDirect() : drawGroup(); return; }
      const dm = ev.target.closest('[data-dm]'); if (dm) { try { const r = await api('connect/conversations', {method: 'POST', body: {type: 'direct', title: '', memberIds: [Number(dm.dataset.dm)]}}); closeLayer(); await reloadAndOpen(r.id); } catch (e) { toast(e.message, 'error'); } }
    };
    $('#vcx-layer').onsubmit = async (ev) => {
      ev.preventDefault(); const form = ev.target; const ids = $$('input[name=m]:checked', form).map((i) => Number(i.value));
      if (!ids.length) { toast('Choose at least one teammate.', 'error'); return; }
      const btn = $('button[type=submit]', form); btn.disabled = true;
      try { const r = await api('connect/conversations', {method: 'POST', body: {type: 'group', title: form.title.value.trim(), memberIds: ids}}); closeLayer(); await reloadAndOpen(r.id); } catch (e) { btn.disabled = false; toast(e.message, 'error'); }
    };
  }
  async function reloadAndOpen(id) { S.convs = await api('connect/conversations'); renderList(); await openConversation(id); }

  function infoSheet() {
    const c = S.active; if (!c) return; const direct = isDirect(c), manage = !!c.can_manage;
    const inGroup = new Set((c.members || []).map((m) => Number(m.id)));
    const addable = S.members.filter((m) => !inGroup.has(Number(m.id)));
    sheet(`<div class="vcx-info-head">${convAv(c, 'lg')}<h3>${esc(convTitle(c))}</h3><p class="vcx-muted">${direct ? esc(other(c)?.email || 'Direct message') : `${(c.members || []).length} members`}</p></div>
      ${manage ? `<form id="vcx-rename" class="vcx-rename"><input name="title" value="${esc(c.title)}" maxlength="80" aria-label="Group name"><button class="vcx-btn small primary">Rename</button></form>` : ''}
      <div class="vcx-field">Members</div><div class="vcx-people">${(c.members || []).map((m) => `<div class="vcx-person">${avatar(m.name, m.avatar_url)}<span><strong>${esc(m.name)}${Number(m.id) === S.me ? ' (you)' : ''}</strong><small>${esc(m.email || '')}</small></span>${manage && Number(m.id) !== S.me && c.type !== 'general' ? `<button type="button" class="vcx-link danger" data-rmmember="${m.id}">Remove</button>` : ''}</div>`).join('')}</div>
      ${manage && addable.length ? `<div class="vcx-field">Add people</div><div class="vcx-people">${addable.map((m) => `<button type="button" data-addmember="${m.id}">${avatar(m.name, m.avatar_url)}<span><strong>${esc(m.name)}</strong><small>${esc(m.email || '')}</small></span><i class="vcx-plus">${icons.plus}</i></button>`).join('')}</div>` : ''}
      <div class="vcx-actions col"><button type="button" class="vcx-btn" data-act="pin-sheet">${c.pinned ? 'Unpin conversation' : 'Pin conversation'}</button>${!direct && c.type !== 'general' ? '<button type="button" class="vcx-btn danger" data-act="leave">Leave group</button>' : ''}<button type="button" class="vcx-btn" data-act="dismiss">Done</button></div>`, 'dialog scroll');
    const layer = $('#vcx-layer');
    $('#vcx-rename')?.addEventListener('submit', async (ev) => { ev.preventDefault(); try { await api(`connect/conversations/${c.id}`, {method: 'PATCH', body: {title: ev.target.title.value}}); toast('Group renamed'); await afterGroupChange(c.id, true); } catch (e) { toast(e.message, 'error'); } });
    layer.onclick = async (ev) => {
      const add = ev.target.closest('[data-addmember]'), rm = ev.target.closest('[data-rmmember]');
      try {
        if (add) { await api(`connect/conversations/${c.id}/members`, {method: 'POST', body: {memberIds: [Number(add.dataset.addmember)]}}); await afterGroupChange(c.id, true); }
        else if (rm) { await api(`connect/conversations/${c.id}/members/${rm.dataset.rmmember}`, {method: 'DELETE'}); await afterGroupChange(c.id, true); }
      } catch (e) { toast(e.message, 'error'); }
    };
  }
  async function afterGroupChange(id, reopen) { S.convs = await api('connect/conversations'); const c = S.convs.find((x) => x.id === id); if (c) S.active = c; renderList(false); renderChatHead(); if (reopen) infoSheet(); }
  async function togglePin() {
    if (!S.active) return; try { const r = await api(`connect/conversations/${S.active.id}/pin`, {method: 'POST', body: {}}); const c = S.convs.find((x) => x.id === S.active.id); if (c) c.pinned = r.pinned; S.active.pinned = r.pinned; renderList(false); renderChatHead(); toast(r.pinned ? 'Conversation pinned' : 'Conversation unpinned'); } catch (e) { toast(e.message, 'error'); }
  }

  // ---------- events ----------
  function bindShell(root) {
    root.addEventListener('click', onClick);
    root.addEventListener('contextmenu', (ev) => { const b = ev.target.closest('[data-menu]'); if (b && !ev.target.closest('a,button.vcx-quote,.vcx-react,.vcx-img')) { ev.preventDefault(); openMenu(b.dataset.menu, b); } });
    const input = $('#vcx-input', root);
    input.addEventListener('input', () => { autosize(); if (input.value.trim()) pingTyping(); updateMentions(); });
    input.addEventListener('keyup', (ev) => { if (!['ArrowUp', 'ArrowDown', 'Enter', 'Tab', 'Escape'].includes(ev.key)) updateMentions(); });
    input.addEventListener('keydown', (ev) => {
      const pop = $('.vcx-mentions');
      if (pop && (ev.key === 'Enter' || ev.key === 'Tab')) { const on = $('button.on', pop) || $('button', pop); if (on) { ev.preventDefault(); insertMention(on.dataset.mention); return; } }
      if (pop && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) { ev.preventDefault(); const btns = $$('button', pop); const i = btns.findIndex((b) => b.classList.contains('on')); btns[i]?.classList.remove('on'); btns[(i + (ev.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length].classList.add('on'); return; }
      if (ev.key === 'Escape') { if (pop) { pop.remove(); return; } if (S.edit || S.reply) { S.edit = null; S.reply = null; input.value = ''; autosize(); renderBars(); } return; }
      if (ev.key === 'Enter' && !ev.shiftKey && !coarse() && !ev.isComposing) { ev.preventDefault(); submitComposer(); }
      if (ev.key === 'ArrowUp' && !input.value && !S.edit) { const last = [...S.msgs].reverse().find((m) => m.user_id === S.me && !m.deleted && m.body); if (last) { ev.preventDefault(); startEdit(last); } }
    });
    input.addEventListener('paste', (ev) => { const files = Array.from(ev.clipboardData?.files || []); if (files.length) { ev.preventDefault(); addFiles(files); } });
    $('#vcx-composer', root).addEventListener('submit', (ev) => { ev.preventDefault(); submitComposer(); });
    $('#vcx-file', root).addEventListener('change', (ev) => { addFiles(ev.target.files); ev.target.value = ''; });
    const search = $('#vcx-search', root);
    search.addEventListener('input', () => {
      S.q = search.value.trim(); S.searchRes = null; renderList(); clearTimeout(S.searchTimer);
      if (S.q.length >= 2) S.searchTimer = setTimeout(async () => { const q = S.q; try { const r = await api('connect/search?q=' + encodeURIComponent(q)); if (S.q === q) { S.searchRes = r; renderList(); } } catch (e) { /* ignore */ } }, 300);
    });
    const sc = $('#vcx-scroll', root); sc.addEventListener('scroll', updateJump, {passive: true});
    $('#vcx-jump', root).addEventListener('click', () => { sc.scrollTo({top: sc.scrollHeight, behavior: 'smooth'}); });
    // long-press for touch
    let lp = 0, lpMoved = false;
    sc.addEventListener('touchstart', (ev) => { const b = ev.target.closest('[data-menu]'); if (!b || ev.target.closest('a,.vcx-img,.vcx-react,.vcx-quote')) return; lpMoved = false; lp = setTimeout(() => { if (!lpMoved) { navigator.vibrate?.(10); openMenu(b.dataset.menu, b); } }, 450); }, {passive: true});
    sc.addEventListener('touchmove', () => { lpMoved = true; clearTimeout(lp); }, {passive: true});
    sc.addEventListener('touchend', () => clearTimeout(lp)); sc.addEventListener('touchcancel', () => clearTimeout(lp));
    // drag & drop
    const app = $('.vcx-app', root), drop = $('#vcx-drop', root); let depth = 0;
    app.addEventListener('dragenter', (ev) => { if (ev.dataTransfer?.types?.includes('Files') && S.active) { depth++; drop.hidden = false; } });
    app.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) drop.hidden = true; });
    app.addEventListener('dragover', (ev) => { if (ev.dataTransfer?.types?.includes('Files')) ev.preventDefault(); });
    app.addEventListener('drop', (ev) => { ev.preventDefault(); depth = 0; drop.hidden = true; if (S.active) addFiles(ev.dataTransfer.files); });
  }
  function startEdit(m) { S.edit = m; S.reply = null; const i = $('#vcx-input'); i.value = m.body; renderBars(); autosize(); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }

  async function onClick(ev) {
    const t = ev.target;
    const act = t.closest('[data-act]');
    if (act) {
      const a = act.dataset.act;
      if (a === 'dismiss') { if (act.classList.contains('vcx-lightbox') && t.closest('img,a')) return; closeLayer(); return; }
      if (a === 'close') { closeOverlay(); return; }
      if (a === 'new') { newChatSheet(); return; }
      if (a === 'back') { setView('list'); S.active = null; S.sig = ''; clearTimeout(S.threadTimer); renderList(false); $('#vcx-chat').hidden = true; $('#vcx-empty').hidden = false; return; }
      if (a === 'info') { infoSheet(); return; }
      if (a === 'pin') { togglePin(); return; }
      if (a === 'pin-sheet') { closeLayer(); togglePin(); return; }
      if (a === 'leave') { const c = S.active; confirmBox('Leave this group?', 'You will stop receiving messages from it. Someone will need to add you back.', 'Leave', async () => { try { await api(`connect/conversations/${c.id}/members/${S.me}`, {method: 'DELETE'}); S.active = null; S.convs = await api('connect/conversations'); renderList(); $('#vcx-chat').hidden = true; $('#vcx-empty').hidden = false; setView('list'); toast('You left the group'); } catch (e) { toast(e.message, 'error'); } }); return; }
      if (a === 'attach') { $('#vcx-file').click(); return; }
      if (a === 'emoji') { emojiPicker((e) => { const i = $('#vcx-input'); const p = i.selectionStart ?? i.value.length; i.value = i.value.slice(0, p) + e + i.value.slice(i.selectionEnd ?? p); i.focus(); const np = p + e.length; i.setSelectionRange(np, np); autosize(); }, act); return; }
      if (a === 'cancel-bar') { S.edit = null; S.reply = null; $('#vcx-input').value = ''; autosize(); renderBars(); return; }
    }
    const openBtn = t.closest('[data-open]'); if (openBtn) { await openConversation(Number(openBtn.dataset.open), {jumpTo: Number(openBtn.dataset.jump) || 0}); return; }
    const filt = t.closest('[data-filter]'); if (filt) { S.filter = filt.dataset.filter; $$('.vcx-filters button').forEach((b) => b.classList.toggle('on', b === filt)); renderList(); return; }
    const more = t.closest('#vcx-older'); if (more) { loadOlder(); return; }
    const lb = t.closest('[data-lightbox]'); if (lb) { lightbox(lb.dataset.lightbox, lb.dataset.name); return; }
    const go = t.closest('[data-goto]'); if (go) { gotoMessage(Number(go.dataset.goto)); return; }
    const rc = t.closest('[data-react]'); if (rc) { react(rc.dataset.react, emojiFrom(rc.dataset.name)); return; }
    const quick = t.closest('[data-quick]'); if (quick) { openMenu(quick.dataset.quick, quick); return; }
    const menuBtn = t.closest('[data-menu-btn]'); if (menuBtn) { openMenu(menuBtn.dataset.menuBtn, menuBtn); return; }
    const rep = t.closest('[data-replyto]'); if (rep) { const m = msgById(rep.dataset.replyto); if (m) { S.reply = m; S.edit = null; renderBars(); $('#vcx-input').focus(); } return; }
    const rq = t.closest('[data-reactq]'); if (rq) { closeLayer(); react(rq.dataset.reactq, rq.dataset.e); return; }
    const me = t.closest('[data-moreemoji]'); if (me) { const id = me.dataset.moreemoji; emojiPicker((e) => react(id, e), me); return; }
    const mact = t.closest('[data-mact]'); if (mact) {
      const m = msgById(mact.dataset.id); const k = mact.dataset.mact; closeLayer(); if (!m) return;
      if (k === 'reply') { S.reply = m; S.edit = null; renderBars(); $('#vcx-input').focus(); }
      else if (k === 'copy') { try { await navigator.clipboard.writeText(m.body); toast('Copied'); } catch (e) { toast('Could not copy', 'error'); } }
      else if (k === 'forward') forwardSheet(m.id);
      else if (k === 'edit') startEdit(m);
      else if (k === 'delete') confirmBox('Delete this message?', 'It will be removed for everyone in the conversation, including any attachments.', 'Delete', async () => { try { await api(`connect/conversations/${S.active.id}/messages/${m.id}`, {method: 'DELETE'}); S.sig = ''; await loadThread(); refreshListSoon(); } catch (e) { toast(e.message, 'error'); } });
      return;
    }
    const rm = t.closest('[data-rmfile]'); if (rm) { const [f] = S.files.splice(Number(rm.dataset.rmfile), 1); if (f) releaseFile(f); renderBars(); updateSend(); return; }
    const mn = t.closest('[data-mention]'); if (mn) { insertMention(mn.dataset.mention); return; }
    const retry = t.closest('[data-retry]'); if (retry) { const p = S.pending.find((x) => x.id === retry.dataset.retry); if (p) { p.status = 'sending'; renderMessages({}); send(p); } return; }
    const disc = t.closest('[data-discard]'); if (disc) { S.pending = S.pending.filter((x) => x.id !== disc.dataset.discard); renderMessages({keepScroll: true}); return; }
  }

  document.addEventListener('keydown', (ev) => { if (!S.open || ev.key !== 'Escape') return; const layer = $('#vcx-layer'); if (layer && layer.children.length) { layer.innerHTML = ''; layer.onclick = null; layer.onsubmit = null; return; } if (!$('#vcx-input')?.value && !S.edit && !S.reply) closeOverlay(); });
  document.addEventListener('visibilitychange', () => { if (S.open && document.visibilityState === 'visible') refreshNow(); });
  window.addEventListener('focus', () => { if (S.open) refreshNow(); });
  window.addEventListener('resize', sizeToViewport);
  if (window.visualViewport) { visualViewport.addEventListener('resize', sizeToViewport); visualViewport.addEventListener('scroll', sizeToViewport); }

  // take over the entry points used by the launcher, nav link and bell
  const api2 = {open: openOverlay, close: closeOverlay, openFromRoute};
  window.VPMConnect = api2;
  ['VPMOpenConnectOverlay', 'VPMCloseConnectOverlay'].forEach((name, i) => Object.defineProperty(window, name, {configurable: true, get: () => (i ? closeOverlay : openOverlay), set: () => {}}));
  if ((location.hash || '').slice(1).split('?')[0] === 'connect') { const go = () => (window.VPM && document.getElementById('vtp-view') ? openFromRoute() : setTimeout(go, 80)); go(); }
})();

/* Client feedback panel: approvals and admin-only comments for the client review page */
(() => {
  if (window.__vcfInstalled) return; window.__vcfInstalled = true;
  const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const when = (s) => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? '' : d.toLocaleString('en-GB', {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'}); };
  const inject = () => document.querySelectorAll('[data-copy-project-review]').forEach((b) => {
    if (b.parentElement.querySelector('[data-vcf]')) return;
    const n = document.createElement('button'); n.className = 'vtp-btn small'; n.dataset.vcf = b.dataset.copyProjectReview; n.textContent = 'Client feedback'; b.after(n);
  });
  new MutationObserver(inject).observe(document.body, {childList: true, subtree: true}); inject();
  const close = () => document.getElementById('vcf-modal')?.remove();
  async function open(id) {
    close();
    const m = document.createElement('div'); m.id = 'vcf-modal'; m.innerHTML = '<div class="vcf-card" role="dialog" aria-label="Client feedback"><div class="vcf-load">Loading…</div></div>';
    m.addEventListener('click', (e) => { if (e.target === m || e.target.closest('[data-vcf-close]')) close(); }); document.body.appendChild(m);
    const card = m.firstChild;
    try {
      const r = await fetch(VTP.root + `projects/${id}/client-feedback`, {headers: {'X-WP-Nonce': VTP.nonce}, credentials: 'same-origin', cache: 'no-store'});
      const d = await r.json(); if (!r.ok) throw new Error(d.message || 'Could not load feedback.');
      const label = {pending: 'Waiting', commented: 'Comment sent', approved: 'Approved'};
      const status = d.status === 'complete' ? 'All approved · download link sent' : d.status === 'finalizing' ? 'Preparing download link…' : 'In review';
      card.innerHTML = `<header><div><h3>Client feedback</h3><p>${esc(status)}</p></div><button class="vcf-x" data-vcf-close aria-label="Close">×</button></header>
        <div class="vcf-sec"><b>Items</b>${d.items.length ? d.items.map((i) => `<div class="vcf-row"><span>${esc(i.title)}<small>${esc(i.number || '')}</small></span><em class="vcf-${esc(i.state)}">${label[i.state] || i.state}${i.approved_by ? ' · ' + esc(i.approved_by) : ''}${i.approved_at ? ' · ' + esc(when(i.approved_at)) : ''}</em></div>`).join('') : '<p class="vcf-mut">No approved deliverables are visible to the client yet.</p>'}${d.waiting ? `<p class="vcf-mut">${d.waiting} item(s) not yet approved by you, so hidden from the client.</p>` : ''}</div>
        <div class="vcf-sec"><b>Comments (only you can see these)</b>${d.comments.length ? d.comments.map((c) => `<div class="vcf-cm"><small>${esc(c.title)} · ${esc(c.author)} · ${esc(when(c.created_at))}</small><p>${esc(c.message)}</p></div>`).join('') : '<p class="vcf-mut">No comments yet.</p>'}</div>
        <footer>${d.page ? `<a class="vtp-btn small" href="${esc(d.page)}" target="_blank" rel="noopener">Open client page</a>` : ''}${d.download_url ? `<a class="vtp-btn small primary" href="${esc(d.download_url)}" target="_blank" rel="noopener">Client download folder</a>` : ''}</footer>`;
    } catch (err) { card.innerHTML = `<header><h3>Client feedback</h3><button class="vcf-x" data-vcf-close>×</button></header><p class="vcf-mut">${esc(err.message)}</p>`; }
  }
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-vcf]'); if (b) { e.preventDefault(); open(b.dataset.vcf); } });
})();


/* Client Reviews: admin page listing every project's client review, approvals and comments */
(() => {
  if (window.__vclInstalled) return; window.__vclInstalled = true;
  const ROUTE = 'client-reviews';
  const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const route = () => (location.hash || '#dashboard').slice(1).split('?')[0];
  const ago = (s) => { if (!s) return '—'; const d = typeof s === 'number' ? new Date(s) : new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return '—'; const m = Math.round((Date.now() - d) / 60000); if (m < 1) return 'just now'; if (m < 60) return m + ' min ago'; if (m < 1440) return Math.round(m / 60) + ' h ago'; if (m < 43200) return Math.round(m / 1440) + ' d ago'; return d.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}); };
  const LABEL = {not_sent: 'Not sent yet', in_review: 'In review', finalizing: 'Preparing download', complete: 'Approved · delivered'};
  let filter = 'all', data = [], token = 0;
  const icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v10H9l-4 4Z"></path><path d="m9 10 2 2 4-4"></path></svg>';

  function installNav() {
    const nav = document.getElementById('vtp-nav'); if (!nav || nav.querySelector('[data-route="' + ROUTE + '"]')) return;
    const group = nav.querySelector('[data-nav-group="sales"]'); if (!group || !group.querySelector('a')) return;
    const a = document.createElement('a'); a.href = '#' + ROUTE; a.dataset.route = ROUTE;
    a.innerHTML = '<i aria-hidden="true">' + icon + '</i><span>Client Reviews</span><b class="vcl-dot" id="vcl-dot" hidden></b>'; group.appendChild(a);
    if (route() === ROUTE) a.classList.add('active');
  }

  function view() {
    const list = data.filter((p) => filter === 'all' || (filter === 'in_review' ? ['in_review', 'finalizing'].includes(p.status) : p.status === filter));
    const n = (s) => data.filter((p) => s.includes(p.status)).length;
    const comments = data.reduce((t, p) => t + p.comments, 0);
    const chip = (k, l, c) => `<button class="vcl-chip${filter === k ? ' on' : ''}" data-vcl-filter="${k}">${l}<em>${c}</em></button>`;
    const card = (p) => {
      const pct = p.ready ? Math.round(p.approved / p.ready * 100) : 0;
      const sub = p.status === 'not_sent' ? (p.ready ? `${p.ready} of ${p.total} deliverables ready. Send the review link from the project card.` : `${p.total ? p.total + ' tasks in progress' : 'No tasks yet'}. Deliverables appear here once the admin approves them.`) : `${p.approved} of ${p.ready} approved${p.total > p.ready ? ' · ' + (p.total - p.ready) + ' still in progress' : ''}`;
      return `<article class="vcl-card vcl-${esc(p.status)}"><div class="vcl-main"><div class="vcl-title"><h3>${esc(p.name)}</h3><span class="vcl-badge">${esc(LABEL[p.status] || p.status)}</span></div>
        <p class="vcl-client">${esc(p.client || 'No client')}${p.email ? ' · ' + esc(p.email) : ''}</p>
        ${p.status === 'not_sent' ? '' : `<div class="vcl-bar"><i style="width:${pct}%"></i></div>`}<p class="vcl-sub">${esc(sub)}</p></div>
        <div class="vcl-meta"><span><b>${p.comments}</b>comment${p.comments === 1 ? '' : 's'}</span><span><b>${esc(ago(p.last_ts ? p.last_ts * 1000 : p.last))}</b>last activity</span></div>
        <div class="vcl-act">${p.status === 'not_sent' ? '<a class="vtp-btn small" href="#projects" data-route="projects">Open projects</a>' : `<button class="vtp-btn small primary" data-vcf="${p.id}">View feedback</button><a class="vtp-btn small" href="${esc(p.page)}" target="_blank" rel="noopener">Client page</a>${p.download_url ? `<a class="vtp-btn small" href="${esc(p.download_url)}" target="_blank" rel="noopener">Download folder</a>` : ''}`}</div></article>`;
    };
    const empty = `<div class="vcl-empty"><h3>No client reviews yet</h3><p>This is where you follow what clients think of their work.</p><ol><li>Employees finish tasks and the admin approves each delivery.</li><li>On the project card, click <b>Send client review</b>. The client gets a private link with no password.</li><li>The client sees all work on the page, comments (only you see them) and approves each item.</li><li>When every item is approved, the Drive download link is emailed to the client automatically.</li></ol></div>`;
    return `<section class="vtp-page vcl-page"><header class="vcl-head"><div><p class="vcl-eye">SALES &amp; CLIENTS</p><h1>Client Reviews</h1><p>Every project's client review: what is waiting, what is approved, and what the client wrote.</p></div></header>
      <div class="vcl-stats"><div><small>In review</small><strong>${n(['in_review', 'finalizing'])}</strong></div><div><small>Delivered</small><strong>${n(['complete'])}</strong></div><div><small>Not sent yet</small><strong>${n(['not_sent'])}</strong></div><div><small>Client comments</small><strong>${comments}</strong></div></div>
      <div class="vcl-chips">${chip('all', 'All', data.length)}${chip('in_review', 'In review', n(['in_review', 'finalizing']))}${chip('complete', 'Delivered', n(['complete']))}${chip('not_sent', 'Not sent', n(['not_sent']))}</div>
      <div class="vcl-list">${!data.length ? empty : list.length ? list.map(card).join('') : '<div class="vcl-empty"><p>Nothing in this filter.</p></div>'}</div></section>`;
  }

  async function render() {
    const el = document.getElementById('vtp-view'); if (!el || route() !== ROUTE) return;
    const t = ++token;
    if (!el.querySelector('.vcl-page')) el.innerHTML = '<section class="vtp-page vcl-page"><div class="vcl-load">Loading client reviews…</div></section>';
    document.querySelectorAll('#vtp-nav a').forEach((l) => l.classList.toggle('active', l.dataset.route === ROUTE));
    try {
      const r = await fetch(VTP.root + 'client-reviews', {headers: {'X-WP-Nonce': VTP.nonce}, credentials: 'same-origin', cache: 'no-store'});
      const d = await r.json(); if (!r.ok) throw new Error(d.message || 'Could not load client reviews.');
      if (t !== token || route() !== ROUTE) return; data = d.projects || []; el.innerHTML = view();
    } catch (e) { if (t === token) el.innerHTML = `<section class="vtp-page vcl-page"><div class="vcl-empty"><p>${esc(e.message)}</p></div></section>`; }
  }

  document.addEventListener('click', (e) => { const c = e.target.closest('[data-vcl-filter]'); if (!c) return; filter = c.dataset.vclFilter; const el = document.getElementById('vtp-view'); if (el) el.innerHTML = view(); });
  window.__vclRender = render;
  const kick = () => { installNav(); if (route() === ROUTE) { const el = document.getElementById('vtp-view'); if (el && !el.querySelector('.vcl-page')) render(); } };
  window.addEventListener('hashchange', () => { installNav(); if (route() === ROUTE) render(); });
  new MutationObserver(() => installNav()).observe(document.body, {childList: true, subtree: true});
  [0, 500, 1400, 3000].forEach((ms) => setTimeout(kick, ms)); kick();
})();
/* Client Reviews: route watcher (router uses pushState, no hashchange) */
(() => { let last = ''; setInterval(() => { const r = (location.hash || '').slice(1).split('?')[0]; const el = document.getElementById('vtp-view'); if (r === 'client-reviews' && el && !el.querySelector('.vcl-page') && window.__vclRender) window.__vclRender(); last = r; }, 400); })();

/* Print monthly expenses */
(() => {
  if (window.__vpeInstalled) return; window.__vpeInstalled = true;
  const esc = (v) => String(v ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const label = (v) => ({ai_tools: 'AI tools'}[v] || String(v || 'general').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()));
  const inject = () => document.querySelectorAll('.vpm-panel h2').forEach((h) => {
    if (h.textContent.trim() !== 'Recent expenses' || h.parentElement.querySelector('.vpe-bar')) return;
    const bar = document.createElement('div'); bar.className = 'vpe-bar';
    const now = new Date(); const ym = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    bar.innerHTML = `<label>Month <input type="month" class="vpe-month" value="${ym}"></label><button type="button" class="vpm-btn vpe-print">Print monthly expenses</button><span class="vpe-msg"></span>`;
    h.after(bar);
  });
  new MutationObserver(inject).observe(document.body, {childList: true, subtree: true}); inject();
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('.vpe-print'); if (!b) return;
    const bar = b.closest('.vpe-bar'), month = bar.querySelector('.vpe-month').value, msg = bar.querySelector('.vpe-msg');
    if (!month) { msg.textContent = 'Choose a month first.'; return; }
    b.disabled = true; msg.textContent = 'Preparing…';
    try {
      let rows = [];
      for (let page = 1; page < 50; page++) {
        const r = await fetch(VPM.root + `finance/expenses?perPage=100&page=${page}`, {headers: {'X-WP-Nonce': VPM.nonce}, credentials: 'same-origin', cache: 'no-store'});
        const d = await r.json(); if (!r.ok) throw new Error(d.message || 'Could not load expenses.');
        rows = rows.concat(d.rows || []); if ((d.rows || []).length < 100) break;
      }
      rows = rows.filter((x) => String(x.expense_date).startsWith(month) && x.status !== 'voided').sort((a, b) => String(a.expense_date).localeCompare(String(b.expense_date)));
      const totals = {}; rows.forEach((x) => { const c = String(x.currency || '').toUpperCase(); totals[c] = (totals[c] || 0) + Number(x.amount); });
      const title = new Date(month + '-01T00:00:00').toLocaleDateString('en-GB', {month: 'long', year: 'numeric'});
      const body = rows.length ? rows.map((x) => `<tr><td>${esc(x.expense_date)}</td><td>${esc(label(x.category))}</td><td>${esc(x.vendor)}</td><td>${esc(x.description || '')}</td><td class="n">${Number(x.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${esc(String(x.currency).toUpperCase())}</td></tr>`).join('') : '<tr><td colspan="5" style="text-align:center;padding:24px;color:#777">No expenses recorded for this month.</td></tr>';
      const tot = Object.entries(totals).map(([c, v]) => `<div><span>Total ${esc(c)}</span><b>${v.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} ${esc(c)}</b></div>`).join('');
      const html = `<!doctype html><html><head><meta charset="utf-8"><title>Expenses ${esc(title)}</title><style>body{font:13px/1.45 Arial,sans-serif;color:#231a30;margin:32px}h1{margin:0;font-size:22px;color:#60338d}p{margin:2px 0 18px;color:#6f6482}table{width:100%;border-collapse:collapse}th{text-align:left;background:#f4effa;font-size:11px;text-transform:uppercase;letter-spacing:.06em;padding:8px;border-bottom:2px solid #60338d}td{padding:8px;border-bottom:1px solid #e8e0f2;vertical-align:top}.n{text-align:right;white-space:nowrap}.tot{margin-top:18px;display:flex;gap:28px;justify-content:flex-end}.tot div{text-align:right}.tot span{display:block;font-size:11px;color:#6f6482;text-transform:uppercase}.tot b{font-size:16px}@page{margin:14mm}</style></head><body><h1>Vibes Agency · Monthly expenses</h1><p>${esc(title)} · ${rows.length} expense${rows.length === 1 ? '' : 's'} · Printed ${new Date().toLocaleDateString('en-GB')}</p><table><thead><tr><th>Date</th><th>Category</th><th>Vendor</th><th>Description</th><th class="n">Amount</th></tr></thead><tbody>${body}</tbody></table><div class="tot">${tot}</div></body></html>`;
      document.getElementById('vpe-frame')?.remove();
      const f = document.createElement('iframe'); f.id = 'vpe-frame'; f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'; f.srcdoc = html;
      f.onload = () => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (_) {} };
      document.body.appendChild(f); msg.textContent = rows.length ? '' : 'No expenses for that month.';
    } catch (err) { msg.textContent = err.message; }
    b.disabled = false;
  });
})();
