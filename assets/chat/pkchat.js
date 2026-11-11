/*!
 * pkchat.js — Chat estilo Teams para o pikachuPM (web + PWA).
 * Canais: sprints, protótipos e leads. Backend: api/chat.php
 *
 * Uso:
 *   PKChat.mount(elemento, {
 *     api:      'api/chat.php',          // URL do endpoint
 *     token:    null,                    // Bearer token (PWA). Sem token → cookies de sessão
 *     mode:     'full' | 'embedded',     // full = lista de canais + conversa; embedded = um só canal
 *     channel:  { type: 'sprint', id: 3 },// canal inicial (obrigatório em embedded)
 *     theme:    'light' | 'dark',
 *     linkBase: 'index.php',             // para ligar à página da sprint/protótipo/lead (web)
 *     notify:   true,                    // notificações do browser para novas mensagens
 *     onUnread: (total, mentions) => {}, // badge externo
 *     onClose:  () => {},                // botão ✕ (PWA)
 *   });
 */
(function () {
  'use strict';

  const TYPE_META = {
    sprint:    { label: 'Sprints',    one: 'Sprint',    icon: '🏃' },
    prototype: { label: 'Protótipos', one: 'Protótipo', icon: '🧪' },
    lead:      { label: 'Leads',      one: 'Lead',      icon: '🎯' },
    project:   { label: 'Projetos',   one: 'Projeto',   icon: '📁' },
    lab:       { label: 'Lab',        one: 'Lab Chat',  icon: '🔬' },
  };
  const QUICK  = ['👍', '❤️', '😆', '😮', '😢', '🎉'];
  const EMOJIS = ('😀 😃 😄 😁 😆 😅 😂 🙂 😉 😊 😍 😘 😎 🤔 😐 🙄 😴 😮 😢 😭 😡 🤯 🥳 🤝 ' +
                  '👍 👎 👏 🙌 🙏 💪 👀 ✅ ❌ ⚠️ 🔥 🎉 🚀 💡 📌 📎 ❤️ 💯 ⭐ ☕ 🐛 ✨ 📅 📈').split(' ');
  const POLL_ACTIVE = 3000, POLL_HIDDEN = 15000, CHANNELS_EVERY = 20000;
  // Polling adaptativo: com conversa activa (mensagem ou "a escrever" no último minuto) o canal aberto actualiza a cada 1 s
  const POLL_FAST = 1000, HOT_WINDOW = 60000;

  // ── utilitários ─────────────────────────────────────────────
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const parseTs = s => s ? new Date(String(s).replace(' ', 'T').replace(/(\.\d{3})\d+$/, '$1')) : null;
  const fmtTime = d => d.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });
  function dayKey(d) { return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
  function dayLabel(d) {
    const t = new Date(); t.setHours(0, 0, 0, 0);
    const x = new Date(d); x.setHours(0, 0, 0, 0);
    const diff = Math.round((t - x) / 86400000);
    if (diff === 0) return 'Hoje';
    if (diff === 1) return 'Ontem';
    const o = { weekday: 'long', day: 'numeric', month: 'long' };
    if (d.getFullYear() !== t.getFullYear()) o.year = 'numeric';
    return d.toLocaleDateString('pt-PT', o);
  }
  function shortWhen(s) {
    const d = parseTs(s); if (!d) return '';
    const now = new Date();
    if (dayKey(d) === dayKey(now)) return fmtTime(d);
    const diff = (now - d) / 86400000;
    if (diff < 6) return d.toLocaleDateString('pt-PT', { weekday: 'short' });
    return d.toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' });
  }
  function fmtSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }
  function initials(name) {
    const p = String(name || '?').replace(/[._-]+/g, ' ').trim().split(/\s+/);
    return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : (p[0][1] || ''))).toUpperCase();
  }
  const avColor = uid => `hsl(${(uid * 67) % 360} 52% 46%)`;
  function fileIcon(name, mime) {
    const ext = String(name).split('.').pop().toLowerCase();
    if ((mime || '').startsWith('image/')) return '🖼️';
    if (ext === 'pdf') return '📕';
    if (['doc', 'docx', 'odt', 'rtf'].includes(ext)) return '📘';
    if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return '📗';
    if (['ppt', 'pptx', 'odp'].includes(ext)) return '📙';
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return '🗜️';
    if (['mp4', 'mov', 'avi', 'mkv', 'webm'].includes(ext)) return '🎬';
    if (['mp3', 'wav', 'ogg', 'm4a'].includes(ext)) return '🎵';
    if (['stl', 'step', 'stp', 'dxf', 'dwg'].includes(ext)) return '📐';
    return '📄';
  }

  /** Markdown leve: ```bloco```, `código`, **negrito**, _itálico_, ~~riscado~~, links e @menções. */
  function fmtBody(text, memberNames, myName) {
    const keep = [];
    const hold = h => '\u0000' + (keep.push(h) - 1) + '\u0000';
    let s = String(text || '');
    s = s.replace(/```([\s\S]*?)```/g, (_, c) => hold('<pre class="pkc-pre">' + esc(c.replace(/^\n/, '')) + '</pre>'));
    s = s.replace(/`([^`\n]+)`/g, (_, c) => hold('<code class="pkc-code">' + esc(c) + '</code>'));
    s = esc(s);
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[\s(])_([^_\n]+)_(?=$|[\s).,!?:;])/g, '$1<em>$2</em>');
    s = s.replace(/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/g, '$1<em>$2</em>');
    s = s.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');
    s = s.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, u => hold(`<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`));
    s = s.replace(/(^|[\s(])@([\wÀ-ÿ.\-]+)/g, (m, pre, name) => {
      const clean = name.replace(/[.\-]+$/, '');
      const tail  = name.slice(clean.length);
      const lc = clean.toLowerCase();
      if (!memberNames.has(lc) && lc !== 'todos' && lc !== 'all') return m;
      const me = lc === String(myName).toLowerCase() || lc === 'todos' || lc === 'all';
      return `${pre}<span class="pkc-mention${me ? ' pkc-mention-me' : ''}">@${clean}</span>${tail}`;
    });
    s = s.replace(/\n/g, '<br>');
    return s.replace(/\u0000(\d+)\u0000/g, (_, i) => keep[+i]);
  }

  // ── API ─────────────────────────────────────────────────────
  function makeApi(opts) {
    const base  = opts.api;
    const token = opts.token || null;
    const headers = () => token ? { Authorization: 'Bearer ' + token } : {};
    const cred    = token ? 'omit' : 'same-origin';
    const url = (action, params = {}) => {
      const q = new URLSearchParams({ action, ...params });
      return base + (base.includes('?') ? '&' : '?') + q.toString();
    };
    async function parse(resp) {
      let data = null;
      try { data = await resp.json(); } catch (e) { /* não-JSON */ }
      if (!resp.ok) {
        const err = new Error((data && data.error) || ('HTTP ' + resp.status));
        err.status = resp.status;
        throw err;
      }
      if (!data || typeof data !== 'object') throw new Error('Resposta inválida do servidor (api/chat.php)');
      return data;
    }
    return {
      token,
      url,
      async get(action, params) {
        return parse(await fetch(url(action, params), { headers: headers(), credentials: cred, cache: 'no-store' }));
      },
      async post(action, data) {
        const body = data instanceof FormData ? data : JSON.stringify(data);
        const h = headers();
        if (!(data instanceof FormData)) h['Content-Type'] = 'application/json';
        return parse(await fetch(url(action), { method: 'POST', headers: h, credentials: cred, body }));
      },
      /** POST multipart com progresso (XHR). */
      upload(action, form, onProgress) {
        return new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest();
          xhr.open('POST', url(action));
          if (token) xhr.setRequestHeader('Authorization', 'Bearer ' + token);
          xhr.withCredentials = !token;
          xhr.upload.onprogress = e => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
          xhr.onload = () => {
            let d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status >= 200 && xhr.status < 300 && d) resolve(d);
            else reject(new Error((d && d.error) || ('HTTP ' + xhr.status)));
          };
          xhr.onerror = () => reject(new Error('Falha de rede'));
          xhr.send(form);
        });
      },
      fileUrl(id, inline) { return url('file', inline ? { file_id: id, inline: 1 } : { file_id: id }); },
      async blob(action, params) {
        const r = await fetch(url(action, params), { headers: headers(), credentials: cred });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.blob();
      },
    };
  }

  // ── som ────────────────────────────────────────────────────
  // Gerado com Web Audio (sem ficheiros). Os browsers só deixam tocar depois de
  // um clique/toque na página, por isso o contexto é "desbloqueado" no 1.º gesto.
  const SOUND_KEY = 'pkchat_sound';
  let audioCtx = null, lastDing = 0;
  function soundEnabled() { try { return localStorage.getItem(SOUND_KEY) !== '0'; } catch (e) { return true; } }
  function setSound(on) { try { localStorage.setItem(SOUND_KEY, on ? '1' : '0'); } catch (e) {} }
  function unlockAudio() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
    } catch (e) {}
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(ev => document.addEventListener(ev, unlockAudio, { passive: true }));
  /** kind: 'msg' (2 notas) | 'mention' (3 notas, mais alto). */
  function ding(kind = 'msg', force = false) {
    if (!force && !soundEnabled()) return;
    const now = Date.now();
    if (now - lastDing < 1200) return;
    lastDing = now;
    try {
      unlockAudio();
      if (!audioCtx || audioCtx.state !== 'running') return;
      const t = audioCtx.currentTime;
      const notes = kind === 'mention' ? [[784, 0], [1047, 0.11], [1319, 0.22]] : [[880, 0], [1319, 0.13]];
      const vol = kind === 'mention' ? 0.3 : 0.22;
      notes.forEach(([f, d]) => {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine'; o.frequency.value = f;
        o.connect(g); g.connect(audioCtx.destination);
        g.gain.setValueAtTime(0.0001, t + d);
        g.gain.exponentialRampToValueAtTime(vol, t + d + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.38);
        o.start(t + d); o.stop(t + d + 0.42);
      });
    } catch (e) {}
  }

  // ── notificações do sistema ────────────────────────────────
  async function sysNotify(title, body, tag) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const reg = navigator.serviceWorker && await navigator.serviceWorker.getRegistration();
      if (reg && reg.showNotification) {
        await reg.showNotification(title, { body, tag, renotify: true, icon: 'img/icon-192x192.png', data: { url: location.href } });
        return;
      }
    } catch (e) { /* fallback */ }
    try { new Notification(title, { body, tag }); } catch (e) {}
  }

  // ════════════════════════════════════════════════════════════
  function mount(root, userOpts) {
    const opts = Object.assign({ api: 'api/chat.php', mode: 'full', theme: 'light', notify: true, linkBase: '' }, userOpts || {});
    const api  = makeApi(opts);
    const full = opts.mode !== 'embedded';
    const LS_KEY = 'pkchat_last_channel';

    const S = {
      me: null, channels: [], filter: '', showClosed: false, collapsed: {},
      cur: null,            // {type,id}
      chan: null,           // metadados do canal actual
      members: [], memberNames: new Set(),
      msgs: new Map(), order: [], lastId: 0, since: '', hasMore: false, loadingOlder: false,
      firstUnreadId: 0, readSent: 0, reads: [], typing: [],
      view: 'chat', replyTo: null, editingId: null, pending: [], sending: false,
      topicFilter: 0, topics: opts.topics || [], requireTopic: !!opts.require_topic,
      pollTimer: null, chanTimer: null, lastActivity: 0, inPoll: false, pollKick: false, lastChannelsAt: 0, lastTypingSent: 0,
      blobUrls: new Map(), prevLast: null, newWhileUp: 0, destroyed: false, gen: 0,
    };

    // ── DOM ────────────────────────────────────────────────────
    root.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'pkc ' + (full ? 'pkc-full' : 'pkc-embedded') + (opts.theme === 'dark' ? ' pkc-dark' : '');
    if (opts.height) wrap.style.height = opts.height;
    wrap.innerHTML = `
      ${full ? `
      <aside class="pkc-side">
        <div class="pkc-side-head">
          <span class="pkc-side-title">💬 Chat</span>
          <button class="pkc-ibtn pkc-sound-btn" data-act="sound" title="Som das mensagens">${soundEnabled() ? '🔊' : '🔇'}</button>
          <button class="pkc-ibtn" data-act="notif" title="Activar notificações">🔔</button>
          ${opts.onClose ? '<button class="pkc-ibtn" data-act="close" title="Fechar">✕</button>' : ''}
        </div>
        <div class="pkc-side-search"><input type="search" class="pkc-chan-filter" placeholder="Procurar canal…" autocomplete="off"></div>
        <div class="pkc-chan-list"><div class="pkc-muted pkc-pad">A carregar canais…</div></div>
        <label class="pkc-side-foot"><input type="checkbox" class="pkc-show-closed"> Mostrar arquivados</label>
      </aside>` : ''}
      <section class="pkc-main">
        <div class="pkc-placeholder">${full ? '<div class="pkc-ph-ico">💬</div><div>Escolhe uma sprint, protótipo ou lead para conversar.</div>' : '<div class="pkc-muted">A carregar conversa…</div>'}</div>
        <div class="pkc-conv" hidden>
          <header class="pkc-head">
            ${full ? '<button class="pkc-ibtn pkc-back" data-act="back" title="Voltar">←</button>' : ''}
            <div class="pkc-head-ico"></div>
            <div class="pkc-head-txt"><div class="pkc-head-title"></div><div class="pkc-head-sub"></div></div>
            <button class="pkc-head-members" data-act="members" title="Membros"></button>
            <div class="pkc-head-actions">
              <button class="pkc-ibtn" data-act="search" title="Pesquisar">🔍</button>
              <button class="pkc-ibtn" data-act="export" title="Exportar histórico">⤓</button>
              <a class="pkc-ibtn pkc-open-item" target="_self" title="Abrir" hidden>↗</a>
            </div>
            <div class="pkc-pop pkc-members-pop" hidden></div>
          </header>
          <nav class="pkc-tabs">
            <button data-view="chat" class="active">Conversa</button>
            <button data-view="files">Ficheiros</button>
            <button data-view="pinned">Afixadas</button>
          </nav>
          <div class="pkc-searchbar" hidden>
            <input type="search" placeholder="Pesquisar mensagens e ficheiros…" autocomplete="off">
            <button class="pkc-ibtn" data-act="search-close">✕</button>
          </div>
          <div class="pkc-banner" hidden></div>
          <div class="pkc-body">
            <div class="pkc-msgs" tabindex="0"></div>
            <div class="pkc-panel" hidden></div>
            <button class="pkc-jump" data-act="jump" hidden>↓ Novas mensagens</button>
            <div class="pkc-drop" hidden><div>📎 Larga os ficheiros para anexar</div></div>
          </div>
          <div class="pkc-typing"></div>
          <footer class="pkc-composer">
            <div class="pkc-reply-bar" hidden></div>
            <div class="pkc-pending"></div>
            <div class="pkc-progress" hidden><div></div></div>
            <div class="pkc-topic-bar" hidden>
              <label style="font-size:.8em;font-weight:600;margin-right:4px">📌 Assunto:</label>
              <select class="pkc-topic-select" style="flex:1;font-size:.85em;padding:2px 6px;border-radius:4px;border:1px solid #ccc;"></select>
              <button class="pkc-ibtn pkc-topic-filter-btn" title="Filtrar mensagens por assunto" style="font-size:.8em;padding:2px 6px;white-space:nowrap">🔍 Filtrar</button>
              <button class="pkc-ibtn pkc-topic-clear-btn" title="Limpar filtro" hidden style="font-size:.8em;padding:2px 6px">✕ Todos</button>
            </div>
            <div class="pkc-input-wrap">
              <textarea rows="1" placeholder="Escrever uma mensagem…" maxlength="10000"></textarea>
              <div class="pkc-tools">
                <button class="pkc-ibtn" data-act="attach" title="Anexar ficheiros">📎</button>
                <button class="pkc-ibtn" data-act="emoji" title="Emoji">😊</button>
                <span class="pkc-hint">Enter envia · Shift+Enter nova linha · @ para mencionar</span>
                <button class="pkc-send" data-act="send" title="Enviar">➤</button>
              </div>
            </div>
            <input type="file" class="pkc-file" multiple hidden>
            <div class="pkc-pop pkc-mention-pop" hidden></div>
            <div class="pkc-pop pkc-emoji-pop" hidden></div>
          </footer>
        </div>
      </section>
      <div class="pkc-pop pkc-menu" hidden></div>
      <div class="pkc-lightbox" hidden><img alt=""><button class="pkc-ibtn" data-act="lb-close">✕</button></div>
      <div class="pkc-toast" hidden></div>`;
    root.appendChild(wrap);

    const $  = sel => wrap.querySelector(sel);
    const D = {
      side: $('.pkc-side'), chanList: $('.pkc-chan-list'), chanFilter: $('.pkc-chan-filter'), showClosed: $('.pkc-show-closed'),
      placeholder: $('.pkc-placeholder'), conv: $('.pkc-conv'),
      headIco: $('.pkc-head-ico'), headTitle: $('.pkc-head-title'), headSub: $('.pkc-head-sub'),
      headMembers: $('.pkc-head-members'), membersPop: $('.pkc-members-pop'), openItem: $('.pkc-open-item'),
      tabs: $('.pkc-tabs'), searchbar: $('.pkc-searchbar'), searchInput: $('.pkc-searchbar input'),
      banner: $('.pkc-banner'), body: $('.pkc-body'), msgs: $('.pkc-msgs'), panel: $('.pkc-panel'),
      jump: $('.pkc-jump'), drop: $('.pkc-drop'), typing: $('.pkc-typing'),
      composer: $('.pkc-composer'), replyBar: $('.pkc-reply-bar'), pending: $('.pkc-pending'), progress: $('.pkc-progress'),
      topicBar: $('.pkc-topic-bar'), topicSelect: $('.pkc-topic-select'), topicFilterBtn: $('.pkc-topic-filter-btn'), topicClearBtn: $('.pkc-topic-clear-btn'),
      input: $('.pkc-composer textarea'), file: $('.pkc-file'), mentionPop: $('.pkc-mention-pop'), emojiPop: $('.pkc-emoji-pop'),
      menu: $('.pkc-menu'), lightbox: $('.pkc-lightbox'), toast: $('.pkc-toast'),
    };

    // modo compacto (telemóvel)
    const checkNarrow = () => wrap.classList.toggle('pkc-narrow', wrap.clientWidth > 0 && wrap.clientWidth < 720);
    checkNarrow();
    const ro = new ResizeObserver(checkNarrow);
    ro.observe(wrap);

    let toastT;
    function toast(msg, isErr) {
      D.toast.textContent = msg;
      D.toast.className = 'pkc-toast' + (isErr ? ' pkc-toast-err' : '');
      D.toast.hidden = false;
      clearTimeout(toastT);
      toastT = setTimeout(() => { D.toast.hidden = true; }, 3200);
    }

    // ── imagens / downloads (token → blob) ─────────────────────
    async function imgSrc(att) {
      if (!api.token) return api.fileUrl(att.id, true);
      if (S.blobUrls.has(att.id)) return S.blobUrls.get(att.id);
      const b = await api.blob('file', { file_id: att.id, inline: 1 });
      const u = URL.createObjectURL(b);
      S.blobUrls.set(att.id, u);
      return u;
    }
    function hydrateImages(container) {
      container.querySelectorAll('img[data-att]').forEach(img => {
        if (img.dataset.loaded) return;
        img.dataset.loaded = '1';
        imgSrc({ id: +img.dataset.att }).then(u => { img.src = u; }).catch(() => { img.alt = 'Imagem indisponível'; });
      });
    }
    async function download(id, name) {
      if (!api.token) { window.open(api.fileUrl(id, false), '_blank'); return; }
      try {
        const b = await api.blob('file', { file_id: id });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b); a.download = name || 'ficheiro';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      } catch (e) { toast('Erro ao descarregar: ' + e.message, true); }
    }

    // ══ LISTA DE CANAIS ════════════════════════════════════════
    async function loadChannels(silent) {
      try {
        const d = await api.get('channels');
        S.me = d.me;
        const prev = S.prevLast;
        S.prevLast = {};
        d.channels.forEach(c => { S.prevLast[c.type + ':' + c.id] = c.last ? c.last.id : 0; });
        // notificações: novas mensagens noutros canais (ou com a janela escondida)
        if (prev) {
          let sound = null;
          d.channels.forEach(c => {
            const k = c.type + ':' + c.id;
            if (!c.last || !c.unread || c.last.id <= (prev[k] || 0)) return;
            if (c.last.username === S.me.username) return;
            const isCur = S.cur && S.cur.type === c.type && S.cur.id === c.id;
            if (isCur) return; // o canal aberto já toca pelo polling
            sound = c.mentions ? 'mention' : (sound || 'msg');
            if (opts.notify && document.hidden) sysNotify(`${TYPE_META[c.type].icon} ${c.title}`, `${c.last.username}: ${c.last.body}`, 'pkchat-' + k);
          });
          if (sound) ding(sound);
        }
        // o canal aberto conta como lido
        d.channels.forEach(c => {
          if (S.cur && S.cur.type === c.type && S.cur.id === c.id && !document.hidden) { c.unread = 0; c.mentions = 0; }
        });
        S.channels = d.channels;
        S.lastChannelsAt = Date.now();
        renderChannels();
        emitUnread();
      } catch (e) {
        if (!silent && D.chanList) D.chanList.innerHTML = `<div class="pkc-err pkc-pad">Erro: ${esc(e.message)}</div>`;
      }
    }

    function emitUnread() {
      if (!opts.onUnread) return;
      const t = S.channels.reduce((a, c) => a + (c.unread || 0), 0);
      const m = S.channels.reduce((a, c) => a + (c.mentions || 0), 0);
      opts.onUnread(t, m);
    }

    function renderChannels() {
      if (!full) return;
      const f = S.filter.trim().toLowerCase();
      const groups = { sprint: [], prototype: [], lead: [], project: [], lab: [] };
      // Abertos primeiro; dentro disso, mensagem mais recente primeiro; sem mensagens no fim (por nome)
      S.channels.sort((a, b) => {
        if (a.closed !== b.closed) return a.closed ? 1 : -1;
        const ta = a.last ? a.last.created_at : '', tb = b.last ? b.last.created_at : '';
        if (ta !== tb) return ta < tb ? 1 : -1;
        return a.title.localeCompare(b.title, 'pt');
      });
      S.channels.forEach(c => {
        if (c.closed && !S.showClosed && !(S.cur && S.cur.type === c.type && S.cur.id === c.id)) return;
        if (f && !c.title.toLowerCase().includes(f)) return;
        groups[c.type].push(c);
      });
      let html = '';
      for (const type of ['sprint', 'prototype', 'lead', 'project', 'lab']) {
        const list = groups[type];
        const unread = list.reduce((a, c) => a + c.unread, 0);
        const col = S.collapsed[type];
        html += `<div class="pkc-group">
          <button class="pkc-group-head" data-act="group" data-type="${type}">
            <span class="pkc-caret">${col ? '▸' : '▾'}</span> ${TYPE_META[type].label}
            <span class="pkc-group-count">${list.length}</span>
            ${unread ? `<span class="pkc-badge">${unread}</span>` : ''}
          </button>`;
        if (!col) {
          if (!list.length) html += `<div class="pkc-muted pkc-group-empty">Sem ${TYPE_META[type].label.toLowerCase()}${f ? ' com esse nome' : ''}.</div>`;
          list.forEach(c => {
            const active = S.cur && S.cur.type === c.type && S.cur.id === c.id;
            const last = c.last ? `${c.last.username === (S.me && S.me.username) ? 'Tu' : esc(c.last.username)}: ${esc(c.last.body)}` : '<i>Sem mensagens</i>';
            html += `<button class="pkc-chan${active ? ' active' : ''}${c.unread ? ' unread' : ''}${c.closed ? ' closed' : ''}" data-act="open" data-type="${c.type}" data-id="${c.id}">
              <span class="pkc-chan-ico">${TYPE_META[c.type].icon}</span>
              <span class="pkc-chan-main">
                <span class="pkc-chan-top"><span class="pkc-chan-name">${esc(c.title)}${c.closed ? ' <span class="pkc-tag">arquivado</span>' : ''}</span>
                  <span class="pkc-chan-time">${c.last ? shortWhen(c.last.created_at) : ''}</span></span>
                <span class="pkc-chan-last">${last}</span>
              </span>
              ${c.mentions ? '<span class="pkc-badge pkc-badge-at">@</span>' : ''}
              ${c.unread ? `<span class="pkc-badge">${c.unread > 99 ? '99+' : c.unread}</span>` : ''}
            </button>`;
          });
        }
        html += '</div>';
      }
      if (!S.channels.length) {
        html = '<div class="pkc-muted pkc-pad">Ainda não pertences a nenhum canal. Quando fores adicionado como membro ou responsável de uma sprint, protótipo, lead ou projeto, o canal aparece aqui.</div>';
      }
      D.chanList.innerHTML = html;
    }

    // ══ ABRIR CANAL ════════════════════════════════════════════
    async function openChannel(type, id) {
      id = +id;
      stopPoll();
      const gen = ++S.gen;
      S.cur = { type, id };
      S.msgs.clear(); S.order = []; S.lastId = 0; S.since = ''; S.hasMore = false;
      S.replyTo = null; S.editingId = null; S.pending = []; S.readSent = 0; S.newWhileUp = 0;
      S.typing = []; S.reads = []; S.view = 'chat'; S.topicFilter = 0;
      try { localStorage.setItem(LS_KEY, type + ':' + id); } catch (e) {}
      wrap.classList.add('pkc-has-conv');
      D.placeholder.hidden = true;
      D.conv.hidden = false;
      D.msgs.innerHTML = '<div class="pkc-muted pkc-pad">A carregar…</div>';
      renderReply(); renderPending(); setView('chat');
      D.searchbar.hidden = true;
      if (full) renderChannels();

      try {
        const d = await api.get('messages', { type, id, limit: 50 });
        if (gen !== S.gen) return;
        S.chan = d.channel;
        S.me = d.me;
        S.members = d.members || [];
        S.memberNames = new Set(S.members.map(m => String(m.username).toLowerCase()));
        d.messages.forEach(upsert);
        S.hasMore = d.has_more;
        S.since = d.server_time;
        S.firstUnreadId = 0;
        const fu = d.messages.find(m => m.id > d.last_read_id && m.user_id !== S.me.user_id);
        if (fu && d.last_read_id > 0) S.firstUnreadId = fu.id;
        renderHead();
        renderMessages({ toBottom: true, toUnread: !!S.firstUnreadId });
        markRead();
        // Tópicos para canal lab
        S.requireTopic = type === 'lab' || !!opts.require_topic;
        if (S.requireTopic) loadTopics();
        else if (D.topicBar) D.topicBar.hidden = true;
        const c = S.channels.find(x => x.type === type && x.id === id);
        if (c) { c.unread = 0; c.mentions = 0; renderChannels(); emitUnread(); }
        if (!wrap.classList.contains('pkc-narrow')) D.input.focus({ preventScroll: true });
      } catch (e) {
        if (gen !== S.gen) return;
        D.conv.hidden = true;
        D.placeholder.hidden = false;
        D.placeholder.innerHTML = e.status === 403
          ? `<div class="pkc-ph-ico">🔒</div><div>A conversa de ${esc(TYPE_META[type].one.toLowerCase())} só está disponível para o responsável e os membros.</div>`
          : `<div class="pkc-err">Erro ao abrir conversa: ${esc(e.message)}</div>`;
        return;
      }
      // Conversa com mensagem no último minuto → começa logo em modo rápido
      const lastM = S.msgs.get(S.lastId);
      S.lastActivity = lastM && (Date.now() - parseTs(lastM.created_at)) < HOT_WINDOW ? Date.now() : 0;
      schedulePoll(nextPollDelay());
    }

    function upsert(m) {
      if (!S.msgs.has(m.id)) {
        S.order.push(m.id);
        if (S.order.length > 1 && m.id < S.order[S.order.length - 2]) S.order.sort((a, b) => a - b);
      }
      S.msgs.set(m.id, m);
      if (m.id > S.lastId) S.lastId = m.id;
    }

    function renderHead() {
      const c = S.chan;
      D.headIco.textContent = TYPE_META[c.type].icon;
      D.headTitle.textContent = c.title;
      D.headSub.innerHTML = `${TYPE_META[c.type].one} · ${esc(c.estado || '')}${c.closed ? ' · <span class="pkc-tag">arquivado</span>' : ''}`;
      const ms = S.members.slice(0, 4).map(m => `<span class="pkc-av pkc-av-sm" style="background:${avColor(m.user_id)}" title="${esc(m.username)}">${esc(initials(m.username))}</span>`).join('');
      D.headMembers.innerHTML = `<span class="pkc-av-stack">${ms}</span><span class="pkc-members-n">${S.members.length}</span>`;
      if (opts.linkBase !== null && opts.linkBase !== undefined && c.url && !api.token && full) {
        D.openItem.hidden = false;
        D.openItem.href = (opts.linkBase || '') + c.url;
        D.openItem.title = 'Abrir ' + TYPE_META[c.type].one.toLowerCase();
      } else D.openItem.hidden = true;
      if (c.closed) {
        D.banner.hidden = false;
        D.banner.innerHTML = `🔒 ${TYPE_META[c.type].one} fechad${c.type === 'lead' ? 'a' : (c.type === 'sprint' ? 'a' : 'o')} — o canal está arquivado e o histórico mantém-se disponível só para leitura.`;
      } else D.banner.hidden = true;
      D.composer.classList.toggle('pkc-disabled', !c.can_write);
      D.input.disabled = !c.can_write;
      D.input.placeholder = c.can_write ? `Escrever para ${c.title}…` : 'Canal arquivado (só leitura)';
    }

    function renderMembersPop() {
      D.membersPop.innerHTML = `<div class="pkc-pop-title">Membros (${S.members.length})</div>` + S.members.map(m => `
        <div class="pkc-member"><span class="pkc-av pkc-av-sm" style="background:${avColor(m.user_id)}">${esc(initials(m.username))}</span>
        <span>${esc(m.username)}</span><span class="pkc-muted pkc-small">${esc(m.role)}</span></div>`).join('') +
        `<div class="pkc-muted pkc-small pkc-pad-t">Os membros são geridos na página da ${esc(TYPE_META[S.chan.type].one.toLowerCase())}.</div>`;
    }

    // ══ MENSAGENS ══════════════════════════════════════════════
    function nearBottom() { return D.msgs.scrollHeight - D.msgs.scrollTop - D.msgs.clientHeight < 80; }

    function msgHtml(m, prev) {
      const d = parseTs(m.created_at);
      const mine = S.me && m.user_id === S.me.user_id;
      const cont = prev && !prev.system && !m.system && prev.user_id === m.user_id && !m.reply_to &&
                   (d - parseTs(prev.created_at)) < 5 * 60000 && dayKey(d) === dayKey(parseTs(prev.created_at));
      let h = '';
      if (m.id === S.firstUnreadId) h += '<div class="pkc-sep pkc-sep-new"><span>Novas mensagens</span></div>';
      if (m.deleted) {
        return h + `<div class="pkc-msg pkc-deleted${mine ? ' pkc-mine' : ''}${cont ? ' pkc-cont' : ''}" data-id="${m.id}">
          <div class="pkc-av" style="background:${avColor(m.user_id)}">${esc(initials(m.username))}</div>
          <div class="pkc-bubble"><div class="pkc-meta"><b>${esc(m.username)}</b><span>${fmtTime(d)}</span></div>
          <div class="pkc-text pkc-muted"><i>Esta mensagem foi eliminada.</i></div></div></div>`;
      }
      const editing = S.editingId === m.id;
      const atts = m.attachments.map(a => a.is_image
        ? `<button class="pkc-att-img" data-act="lightbox" data-att="${a.id}" title="${esc(a.name)}"><img data-att="${a.id}" alt="${esc(a.name)}" loading="lazy"></button>`
        : `<button class="pkc-att-file" data-act="download" data-att="${a.id}" data-name="${esc(a.name)}">
             <span class="pkc-att-ico">${fileIcon(a.name, a.mime)}</span>
             <span class="pkc-att-txt"><span class="pkc-att-name">${esc(a.name)}</span><span class="pkc-muted pkc-small">${fmtSize(a.size)}</span></span>
             <span class="pkc-att-dl">⤓</span></button>`).join('');
      const reacts = m.reactions.map(r => `<button class="pkc-react${r.mine ? ' mine' : ''}" data-act="react" data-emoji="${esc(r.emoji)}" title="${esc(r.users.join(', '))}">${esc(r.emoji)} <span>${r.count}</span></button>`).join('');
      const canWrite = S.chan && S.chan.can_write;
      const actions = canWrite ? `<div class="pkc-actions">
          ${QUICK.map(e => `<button data-act="react" data-emoji="${e}" title="Reagir">${e}</button>`).join('')}
          <button data-act="reply" title="Responder">↩</button>
          <button data-act="menu" title="Mais opções">⋯</button>
        </div>` : `<div class="pkc-actions"><button data-act="menu" title="Mais opções">⋯</button></div>`;
      return h + `<div class="pkc-msg${mine ? ' pkc-mine' : ''}${cont ? ' pkc-cont' : ''}${m.pinned ? ' pkc-pinned' : ''}${mentionsMe(m) ? ' pkc-mentioned' : ''}" data-id="${m.id}">
        <div class="pkc-av" style="background:${avColor(m.user_id)}" title="${esc(m.username)}">${esc(initials(m.username))}</div>
        <div class="pkc-bubble">
          <div class="pkc-meta"><b>${esc(m.username)}</b><span title="${d.toLocaleString('pt-PT')}">${fmtTime(d)}</span>${m.edited ? '<span class="pkc-muted">Editada</span>' : ''}${m.pinned ? '<span title="Afixada">📌</span>' : ''}</div>
          ${m.reply_to ? `<button class="pkc-quote" data-act="goto" data-target="${m.reply_to.id}"><b>${esc(m.reply_to.username)}</b><span>${esc(m.reply_to.body)}</span></button>` : ''}
          ${editing
            ? `<div class="pkc-edit"><textarea>${esc(m.body)}</textarea><div class="pkc-edit-btns"><button data-act="edit-cancel">Cancelar</button><button class="pkc-primary" data-act="edit-save">Guardar</button></div></div>`
            : (m.body ? `<div class="pkc-text">${fmtBody(m.body, S.memberNames, S.me ? S.me.username : '')}</div>` : '')}
          ${atts ? `<div class="pkc-atts">${atts}</div>` : ''}
          ${reacts ? `<div class="pkc-reacts">${reacts}</div>` : ''}
          ${editing ? '' : actions}
        </div>
      </div>`;
    }

    function mentionsMe(m) {
      if (!S.me || m.user_id === S.me.user_id || !m.body) return false;
      const re = new RegExp('(^|[\\s(])@(' + S.me.username.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '|todos|all)\\b', 'i');
      return re.test(m.body);
    }

    function seenHtml() {
      if (!S.me) return '';
      // última mensagem minha → quem já a leu
      let lastMine = null;
      for (let i = S.order.length - 1; i >= 0; i--) {
        const m = S.msgs.get(S.order[i]);
        if (m.user_id === S.me.user_id && !m.deleted) { lastMine = m; break; }
        if (!m.deleted) break; // há mensagens de outros depois → irrelevante
      }
      if (!lastMine) return '';
      const who = S.reads.filter(r => r.user_id !== S.me.user_id && r.last_read_id >= lastMine.id).map(r => r.username);
      if (!who.length) return '<div class="pkc-seen">✓ Enviada</div>';
      return `<div class="pkc-seen" title="${esc(who.join(', '))}">✓✓ Vista por ${who.length === 1 ? esc(who[0]) : who.length + ' pessoas'}</div>`;
    }

    function renderMessages({ toBottom = false, keepFromTop = null, toUnread = false } = {}) {
      const stick = toBottom || nearBottom();
      const oldH = D.msgs.scrollHeight, oldTop = D.msgs.scrollTop;
      let html = '';
      if (S.hasMore) html += '<div class="pkc-older"><button data-act="older">Carregar mensagens anteriores</button></div>';
      else if (S.order.length) html += `<div class="pkc-start">Início da conversa de ${esc(S.chan ? S.chan.title : '')}</div>`;
      if (!S.order.length) {
        html += `<div class="pkc-empty-conv"><div class="pkc-ph-ico">👋</div><div>Ainda não há mensagens.</div>
          <div class="pkc-muted pkc-small">${S.chan && S.chan.can_write ? 'Envia a primeira mensagem para toda a equipa.' : ''}</div></div>`;
      }
      let prev = null, lastDay = '';
      for (const id of S.order) {
        const m = S.msgs.get(id);
        const d = parseTs(m.created_at);
        const k = dayKey(d);
        if (k !== lastDay) { html += `<div class="pkc-sep"><span>${esc(dayLabel(d))}</span></div>`; lastDay = k; prev = null; }
        html += msgHtml(m, prev);
        prev = m;
      }
      html += seenHtml();
      D.msgs.innerHTML = html;
      hydrateImages(D.msgs);
      D.msgs.querySelectorAll('.pkc-att-img img').forEach(img => img.addEventListener('load', () => { if (stick) D.msgs.scrollTop = D.msgs.scrollHeight; }, { once: true }));
      if (toUnread) {
        const sep = D.msgs.querySelector('.pkc-sep-new');
        if (sep) { D.msgs.scrollTop = sep.offsetTop - 40; return; }
      }
      if (keepFromTop !== null) D.msgs.scrollTop = D.msgs.scrollHeight - oldH + oldTop;
      else if (stick) D.msgs.scrollTop = D.msgs.scrollHeight;
      else D.msgs.scrollTop = oldTop;
      const editTa = D.msgs.querySelector('.pkc-edit textarea');
      if (editTa) { editTa.focus(); editTa.setSelectionRange(editTa.value.length, editTa.value.length); autosize(editTa); }
    }

    async function loadOlder() {
      if (S.loadingOlder || !S.hasMore || !S.order.length) return;
      S.loadingOlder = true;
      const gen = S.gen;
      try {
        const d = await api.get('messages', { type: S.cur.type, id: S.cur.id, before_id: S.order[0], limit: 50 });
        if (gen !== S.gen) return;
        d.messages.forEach(upsert);
        S.order.sort((a, b) => a - b);
        S.hasMore = d.has_more;
        renderMessages({ keepFromTop: true });
      } catch (e) { toast('Erro: ' + e.message, true); }
      finally { S.loadingOlder = false; }
    }

    function renderTyping() {
      const t = S.typing;
      D.typing.innerHTML = t.length
        ? `<span class="pkc-dots"><i></i><i></i><i></i></span> ${esc(t.length === 1 ? t[0] + ' está a escrever…' : t.slice(0, 2).join(', ') + (t.length > 2 ? ' e outros' : '') + ' estão a escrever…')}`
        : '';
    }

    // ── polling ────────────────────────────────────────────────
    function stopPoll() { clearTimeout(S.pollTimer); S.pollTimer = null; }
    function nextPollDelay() {
      if (document.hidden) return POLL_HIDDEN;
      return Date.now() - S.lastActivity < HOT_WINDOW ? POLL_FAST : POLL_ACTIVE;
    }
    function touchActivity() { S.lastActivity = Date.now(); }
    // Pede novidades já (ex.: depois de enviar); se houver um poll a decorrer, repete logo a seguir
    function kickPoll() {
      if (!S.cur || S.destroyed) return;
      if (S.inPoll) { S.pollKick = true; return; }
      stopPoll(); poll();
    }
    function schedulePoll(ms) {
      stopPoll();
      if (S.destroyed) return;
      S.pollTimer = setTimeout(poll, ms);
    }
    async function poll() {
      if (!S.cur || S.destroyed) return;
      const gen = S.gen;
      S.inPoll = true; S.pollKick = false;
      try {
        const d = await api.get('poll', { type: S.cur.type, id: S.cur.id, after_id: S.lastId, since: S.since });
        if (gen !== S.gen) { S.inPoll = false; return; }
        const wasBottom = nearBottom();
        let newFromOthers = 0, changed = false, mentioned = false;
        d.messages.forEach(m => {
          const old = S.msgs.get(m.id);
          if (!old && S.me && m.user_id !== S.me.user_id && !m.deleted) {
            newFromOthers++;
            if (mentionsMe(m)) mentioned = true;
          }
          if (!old || JSON.stringify(old) !== JSON.stringify(m)) changed = true;
          upsert(m);
        });
        S.since = d.server_time;
        const newest = d.messages.filter(m => !m.deleted).pop();
        const cc = S.channels.find(x => x.type === S.cur.type && x.id === S.cur.id);
        if (cc && newest && (!cc.last || newest.id > cc.last.id)) {
          cc.last = { id: newest.id, username: newest.username, body: newest.body || '📎 Anexo', created_at: newest.created_at };
          renderChannels();
        }
        const readsChanged = JSON.stringify(S.reads) !== JSON.stringify(d.reads);
        S.reads = d.reads;
        if (S.chan && (S.chan.can_write !== d.can_write || S.chan.closed !== d.closed || (d.estado && S.chan.estado !== d.estado))) {
          S.chan.can_write = d.can_write; S.chan.closed = d.closed;
          if (d.estado) S.chan.estado = d.estado;
          const lc = S.channels.find(x => x.type === S.cur.type && x.id === S.cur.id);
          if (lc) lc.closed = d.closed;
          renderHead(); renderChannels(); changed = true;
        }
        if ((changed || readsChanged) && !S.editingId) renderMessages();
        if (newFromOthers && !wasBottom) { S.newWhileUp += newFromOthers; D.jump.hidden = false; D.jump.textContent = `↓ ${S.newWhileUp} nova${S.newWhileUp > 1 ? 's' : ''} mensage${S.newWhileUp > 1 ? 'ns' : 'm'}`; }
        if (newFromOthers || (d.typing && d.typing.length)) touchActivity();
        if (JSON.stringify(S.typing) !== JSON.stringify(d.typing)) { S.typing = d.typing; renderTyping(); }
        if (newFromOthers) ding(mentioned ? 'mention' : 'msg');
        if (newFromOthers && document.hidden && opts.notify && !full) {
          const last = d.messages[d.messages.length - 1];
          sysNotify(`${TYPE_META[S.cur.type].icon} ${S.chan.title}`, `${last.username}: ${last.body || '📎 Anexo'}`, 'pkchat-' + S.cur.type + ':' + S.cur.id);
        }
        markRead();
      } catch (e) { /* rede instável: tenta de novo */ }
      if (full && Date.now() - S.lastChannelsAt > CHANNELS_EVERY) loadChannels(true);
      S.inPoll = false;
      if (gen === S.gen) schedulePoll(S.pollKick ? 0 : nextPollDelay());
    }

    function markRead() {
      if (!S.cur || document.hidden || !S.lastId || S.lastId <= S.readSent) return;
      if (S.view === 'chat' && !nearBottom() && S.msgs.size > 0) return;
      S.readSent = S.lastId;
      api.post('read', { type: S.cur.type, id: S.cur.id, last_id: S.lastId }).catch(() => { S.readSent = 0; });
      const c = S.channels.find(x => x.type === S.cur.type && x.id === S.cur.id);
      if (c && (c.unread || c.mentions)) { c.unread = 0; c.mentions = 0; renderChannels(); emitUnread(); }
    }

    // ══ COMPOSER ═══════════════════════════════════════════════
    function autosize(ta) { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 220) + 'px'; }

    function renderReply() {
      if (!S.replyTo) { D.replyBar.hidden = true; return; }
      const m = S.replyTo;
      D.replyBar.hidden = false;
      D.replyBar.innerHTML = `<div class="pkc-quote"><b>↩ ${esc(m.username)}</b><span>${esc((m.body || '📎 Anexo').slice(0, 160))}</span></div>
        <button class="pkc-ibtn" data-act="reply-cancel" title="Cancelar">✕</button>`;
    }

    function addFiles(list) {
      if (!S.chan || !S.chan.can_write) return;
      for (const f of list) {
        if (f.size > 20 * 1024 * 1024) { toast(`"${f.name}" excede 20 MB`, true); continue; }
        const total = S.pending.reduce((a, p) => a + p.file.size, 0) + f.size;
        if (total > 20 * 1024 * 1024) { toast('Máximo de 20 MB por mensagem — envia os restantes noutra mensagem', true); continue; }
        if (S.pending.length >= 10) { toast('Máximo de 10 ficheiros por mensagem', true); break; }
        const p = { file: f, id: Math.random().toString(36).slice(2), url: null };
        if (f.type.startsWith('image/')) p.url = URL.createObjectURL(f);
        S.pending.push(p);
      }
      renderPending();
      D.input.focus();
    }
    function renderPending() {
      D.pending.innerHTML = S.pending.map(p => `<div class="pkc-chip">
        ${p.url ? `<img src="${p.url}" alt="">` : `<span class="pkc-att-ico">${fileIcon(p.file.name, p.file.type)}</span>`}
        <span class="pkc-chip-txt"><span class="pkc-att-name">${esc(p.file.name)}</span><span class="pkc-muted pkc-small">${fmtSize(p.file.size)}</span></span>
        <button class="pkc-ibtn" data-act="unpend" data-pid="${p.id}" title="Remover">✕</button></div>`).join('');
    }

    async function send() {
      if (S.sending || !S.chan || !S.chan.can_write) return;
      const body = D.input.value.trim();
      if (!body && !S.pending.length) return;
      // Canal lab exige tópico
      const topicId = D.topicSelect ? (parseInt(D.topicSelect.value, 10) || 0) : 0;
      if (S.requireTopic && !topicId) {
        toast('Escolhe um assunto antes de enviar', true);
        D.topicSelect && D.topicSelect.focus();
        return;
      }
      S.sending = true;
      D.composer.classList.add('pkc-busy');
      const fd = new FormData();
      fd.append('type', S.cur.type);
      fd.append('id', S.cur.id);
      fd.append('body', body);
      if (S.replyTo) fd.append('reply_to', S.replyTo.id);
      if (topicId)   fd.append('topic_id', topicId);
      S.pending.forEach(p => fd.append('files[]', p.file, p.file.name));
      const hasFiles = S.pending.length > 0;
      if (hasFiles) { D.progress.hidden = false; D.progress.firstElementChild.style.width = '0%'; }
      const gen = S.gen;
      try {
        const d = await api.upload('send', fd, f => { D.progress.firstElementChild.style.width = Math.round(f * 100) + '%'; });
        if (gen === S.gen) {
          upsert(d.message);
          S.readSent = Math.max(S.readSent, d.message.id);
          S.firstUnreadId = 0;
          renderMessages({ toBottom: true });
        }
        D.input.value = ''; autosize(D.input);
        S.pending.forEach(p => p.url && URL.revokeObjectURL(p.url));
        S.pending = []; renderPending();
        S.replyTo = null; renderReply();
        const c = S.channels.find(x => x.type === S.cur.type && x.id === S.cur.id);
        if (c) { c.last = { id: d.message.id, username: S.me.username, body: body || '📎 Anexo', created_at: d.message.created_at }; renderChannels(); }
        touchActivity(); kickPoll();
      } catch (e) {
        toast('Não foi possível enviar: ' + e.message, true);
      } finally {
        S.sending = false;
        D.composer.classList.remove('pkc-busy');
        D.progress.hidden = true;
      }
    }

    // ── Tópicos (canal lab) ────────────────────────────────────
    function renderTopicBar() {
      if (!D.topicBar) return;
      const show = S.requireTopic && S.topics.length > 0;
      D.topicBar.hidden = !show;
      if (!show) return;
      const sel = D.topicSelect;
      const prev = sel.value;
      sel.innerHTML = '<option value="">— Escolher assunto —</option>' +
        S.topics.map(t => `<option value="${t.id}"${String(t.id) === prev ? ' selected' : ''}>${esc(t.titulo)}</option>`).join('');
      if (prev) sel.value = prev;
      if (D.topicClearBtn) D.topicClearBtn.hidden = !S.topicFilter;
    }

    async function loadTopics() {
      if (!S.requireTopic || !S.cur) return;
      try {
        const d = await api.get('topics', { type: S.cur.type, id: S.cur.id });
        S.topics = d.topics || [];
        renderTopicBar();
        // Pré-seleccionar e filtrar automaticamente se vier default_topic
        const defTopic = opts.default_topic || 0;
        if (defTopic && S.topics.some(t => t.id == defTopic)) {
          if (D.topicSelect) D.topicSelect.value = String(defTopic);
          applyTopicFilter(defTopic);
        }
      } catch { /* silencioso */ }
    }

    function applyTopicFilter(tid) {
      tid = parseInt(tid, 10) || 0;
      if (!tid || !S.cur) return;
      S.topicFilter = tid;
      if (D.topicClearBtn) D.topicClearBtn.hidden = false;
      S.msgs.clear(); S.order = []; S.lastId = 0; S.since = ''; S.hasMore = false;
      D.msgs.innerHTML = '<div class="pkc-muted pkc-pad">A carregar…</div>';
      api.get('messages', { type: S.cur.type, id: S.cur.id, topic_id: tid, limit: 50 }).then(d => {
        d.messages.forEach(upsert);
        S.hasMore = d.has_more;
        renderMessages({ toBottom: true });
        const topicTitle = S.topics.find(t => t.id == tid)?.titulo || '';
        if (D.banner) { D.banner.hidden = false; D.banner.innerHTML = `🔍 A mostrar mensagens do assunto: <strong>${esc(topicTitle)}</strong>`; }
      }).catch(e => toast('Erro ao filtrar: ' + e.message, true));
    }

    function sendTyping() {
      if (!S.cur || !S.chan || !S.chan.can_write) return;
      const now = Date.now();
      if (now - S.lastTypingSent < 3000) return;
      S.lastTypingSent = now;
      touchActivity();
      api.post('typing', { type: S.cur.type, id: S.cur.id }).catch(() => {});
    }

    // ── @menções ───────────────────────────────────────────────
    let mention = null; // {start, items, idx}
    function updateMention() {
      const ta = D.input;
      const upto = ta.value.slice(0, ta.selectionStart);
      const m = upto.match(/(^|[\s(])@([\wÀ-ÿ.\-]*)$/);
      if (!m) { closeMention(); return; }
      const q = m[2].toLowerCase();
      const items = [...S.members.filter(x => S.me && x.user_id !== S.me.user_id && x.username.toLowerCase().includes(q)),
                     ...('todos'.startsWith(q) ? [{ username: 'todos', role: 'notificar todos os membros', user_id: 0 }] : [])].slice(0, 8);
      if (!items.length) { closeMention(); return; }
      mention = { start: upto.length - m[2].length - 1, items, idx: 0 };
      renderMention();
    }
    function renderMention() {
      D.mentionPop.hidden = false;
      D.mentionPop.innerHTML = mention.items.map((x, i) => `<button class="pkc-member${i === mention.idx ? ' active' : ''}" data-act="mention" data-i="${i}">
        <span class="pkc-av pkc-av-sm" style="background:${x.user_id ? avColor(x.user_id) : '#888'}">${x.user_id ? esc(initials(x.username)) : '@'}</span>
        <span>${esc(x.username)}</span><span class="pkc-muted pkc-small">${esc(x.role)}</span></button>`).join('');
    }
    function closeMention() { mention = null; D.mentionPop.hidden = true; }
    function pickMention(i) {
      const x = mention.items[i];
      const ta = D.input;
      const before = ta.value.slice(0, mention.start);
      const after  = ta.value.slice(ta.selectionStart);
      ta.value = before + '@' + x.username + ' ' + after;
      const pos = before.length + x.username.length + 2;
      ta.setSelectionRange(pos, pos);
      closeMention();
      ta.focus();
    }

    function insertAtCursor(ta, text) {
      const s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? ta.value.length;
      ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
      ta.setSelectionRange(s + text.length, s + text.length);
      ta.focus(); autosize(ta);
    }

    // ══ VISTAS: ficheiros / afixadas / pesquisa ════════════════
    function setView(v) {
      S.view = v;
      D.tabs.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.view === v));
      const chat = v === 'chat';
      D.msgs.hidden = !chat;
      D.panel.hidden = chat;
      D.composer.hidden = !chat;
      D.typing.hidden = !chat;
      D.jump.hidden = true;
      if (v === 'files') loadFiles();
      if (v === 'pinned') loadPinned();
    }

    function miniMsgHtml(m) {
      const d = parseTs(m.created_at);
      return `<button class="pkc-mini" data-act="goto" data-target="${m.id}">
        <span class="pkc-av pkc-av-sm" style="background:${avColor(m.user_id)}">${esc(initials(m.username))}</span>
        <span class="pkc-mini-main"><span class="pkc-meta"><b>${esc(m.username)}</b><span>${d.toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })}</span></span>
        <span class="pkc-mini-body">${m.body ? fmtBody(m.body, S.memberNames, S.me.username) : ''}${m.attachments.length ? ' <span class="pkc-muted">📎 ' + m.attachments.map(a => esc(a.name)).join(', ') + '</span>' : ''}</span></span></button>`;
    }

    async function loadFiles() {
      D.panel.innerHTML = '<div class="pkc-muted pkc-pad">A carregar ficheiros…</div>';
      try {
        const d = await api.get('files', { type: S.cur.type, id: S.cur.id });
        if (S.view !== 'files') return;
        if (!d.files.length) { D.panel.innerHTML = '<div class="pkc-muted pkc-pad">Nenhum ficheiro partilhado neste canal.</div>'; return; }
        const imgs = d.files.filter(f => f.is_image), docs = d.files.filter(f => !f.is_image);
        D.panel.innerHTML =
          (imgs.length ? `<div class="pkc-panel-title">Imagens (${imgs.length})</div><div class="pkc-gallery">${imgs.map(f =>
            `<button class="pkc-att-img" data-act="lightbox" data-att="${f.id}" title="${esc(f.name)} — ${esc(f.username)}"><img data-att="${f.id}" alt="${esc(f.name)}" loading="lazy"></button>`).join('')}</div>` : '') +
          (docs.length ? `<div class="pkc-panel-title">Ficheiros (${docs.length})</div><table class="pkc-table"><thead><tr><th>Nome</th><th>Partilhado por</th><th>Data</th><th>Tamanho</th></tr></thead><tbody>${docs.map(f => `
            <tr data-act="download" data-att="${f.id}" data-name="${esc(f.name)}"><td>${fileIcon(f.name, f.mime)} ${esc(f.name)}</td><td>${esc(f.username)}</td>
            <td>${parseTs(f.created_at).toLocaleDateString('pt-PT')}</td><td>${fmtSize(f.size)}</td></tr>`).join('')}</tbody></table>` : '');
        hydrateImages(D.panel);
      } catch (e) { D.panel.innerHTML = `<div class="pkc-err pkc-pad">${esc(e.message)}</div>`; }
    }

    async function loadPinned() {
      D.panel.innerHTML = '<div class="pkc-muted pkc-pad">A carregar…</div>';
      try {
        const d = await api.get('pinned', { type: S.cur.type, id: S.cur.id });
        if (S.view !== 'pinned') return;
        D.panel.innerHTML = d.messages.length
          ? `<div class="pkc-panel-title">📌 Mensagens afixadas</div>` + d.messages.map(miniMsgHtml).join('')
          : '<div class="pkc-muted pkc-pad">Sem mensagens afixadas. Usa o menu ⋯ de uma mensagem para a afixar.</div>';
      } catch (e) { D.panel.innerHTML = `<div class="pkc-err pkc-pad">${esc(e.message)}</div>`; }
    }

    let searchT;
    function doSearch() {
      clearTimeout(searchT);
      const q = D.searchInput.value.trim();
      if (q.length < 2) { if (S.view === 'search') setView('chat'); return; }
      searchT = setTimeout(async () => {
        S.view = 'search';
        D.tabs.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        D.msgs.hidden = true; D.panel.hidden = false; D.composer.hidden = true; D.typing.hidden = true;
        D.panel.innerHTML = '<div class="pkc-muted pkc-pad">A pesquisar…</div>';
        try {
          const d = await api.get('search', { type: S.cur.type, id: S.cur.id, q });
          if (S.view !== 'search') return;
          D.panel.innerHTML = `<div class="pkc-panel-title">${d.messages.length} resultado${d.messages.length === 1 ? '' : 's'} para “${esc(q)}”</div>` +
            (d.messages.map(miniMsgHtml).join('') || '');
        } catch (e) { D.panel.innerHTML = `<div class="pkc-err pkc-pad">${esc(e.message)}</div>`; }
      }, 300);
    }

    /** Salta para uma mensagem (carrega histórico anterior se preciso). */
    async function gotoMsg(id) {
      id = +id;
      if (S.view !== 'chat') setView('chat');
      let guard = 0;
      while (!S.msgs.has(id) && S.hasMore && guard++ < 40) {
        const gen = S.gen;
        const d = await api.get('messages', { type: S.cur.type, id: S.cur.id, before_id: S.order[0], limit: 100 });
        if (gen !== S.gen) return;
        d.messages.forEach(upsert);
        S.order.sort((a, b) => a - b);
        S.hasMore = d.has_more;
      }
      renderMessages();
      const elm = D.msgs.querySelector(`.pkc-msg[data-id="${id}"]`);
      if (!elm) { toast('Mensagem não encontrada'); return; }
      elm.scrollIntoView({ block: 'center', behavior: 'smooth' });
      elm.classList.add('pkc-flash');
      setTimeout(() => elm.classList.remove('pkc-flash'), 1800);
    }

    // ── menu ⋯ ─────────────────────────────────────────────────
    function openMenu(m, anchor) {
      const mine = S.me && m.user_id === S.me.user_id;
      const canWrite = S.chan.can_write;
      const isAdmin = S.me && S.me.is_admin;
      const items = [];
      if (canWrite) items.push(['reply', '↩ Responder']);
      if (m.body) items.push(['copy', '📋 Copiar texto']);
      if (canWrite) items.push(['pin', m.pinned ? '📌 Desafixar' : '📌 Afixar']);
      if (canWrite) items.push(['emoji-react', '😊 Outra reacção…']);
      if (mine && canWrite && m.body !== undefined) items.push(['edit', '✏️ Editar']);
      if ((mine && canWrite) || isAdmin) items.push(['delete', '🗑 Eliminar']);
      D.menu.innerHTML = items.map(([a, l]) => `<button data-act="m-${a}" data-mid="${m.id}"${a === 'delete' ? ' class="pkc-danger"' : ''}>${l}</button>`).join('');
      D.menu.hidden = false;
      const r = anchor.getBoundingClientRect(), w = wrap.getBoundingClientRect();
      const mh = D.menu.offsetHeight, mw = D.menu.offsetWidth;
      let top = r.bottom - w.top + 4;
      if (top + mh > w.height) top = Math.max(4, r.top - w.top - mh - 4);
      D.menu.style.top = top + 'px';
      D.menu.style.left = Math.max(4, Math.min(r.right - w.left - mw, w.width - mw - 4)) + 'px';
    }
    function closePops() {
      D.menu.hidden = true;
      D.emojiPop.hidden = true;
      D.membersPop.hidden = true;
      closeMention();
      wrap.querySelectorAll('.pkc-msg.pkc-touch').forEach(e => e.classList.remove('pkc-touch'));
    }

    let emojiTarget = null; // null → composer; número → reacção a mensagem
    function openEmoji(target, anchor) {
      emojiTarget = target;
      D.emojiPop.innerHTML = EMOJIS.map(e => `<button data-act="emoji-pick" data-emoji="${e}">${e}</button>`).join('');
      D.emojiPop.hidden = false;
      if (target !== null && anchor) {
        // posiciona junto à mensagem
        const r = anchor.getBoundingClientRect(), w = wrap.getBoundingClientRect();
        wrap.appendChild(D.emojiPop);
        D.emojiPop.classList.add('pkc-emoji-float');
        D.emojiPop.style.top = Math.max(4, Math.min(r.top - w.top, w.height - D.emojiPop.offsetHeight - 4)) + 'px';
        D.emojiPop.style.left = Math.max(4, Math.min(r.left - w.left, w.width - D.emojiPop.offsetWidth - 4)) + 'px';
      } else {
        D.composer.appendChild(D.emojiPop);
        D.emojiPop.classList.remove('pkc-emoji-float');
        D.emojiPop.style.top = ''; D.emojiPop.style.left = '';
      }
    }

    async function act(action, data) {
      try {
        await api.post(action, Object.assign({ type: S.cur.type, id: S.cur.id }, data));
        stopPoll(); poll();
      } catch (e) { toast(e.message, true); }
    }

    // ══ EVENTOS ════════════════════════════════════════════════
    wrap.addEventListener('click', async e => {
      const t = e.target.closest('[data-act]');
      const msgEl = e.target.closest('.pkc-msg');
      // toque numa mensagem em ecrãs táteis → mostra barra de acções
      if (!t && msgEl && wrap.classList.contains('pkc-narrow')) {
        const was = msgEl.classList.contains('pkc-touch');
        closePops();
        if (!was) msgEl.classList.add('pkc-touch');
        return;
      }
      if (!t) {
        if (!e.target.closest('.pkc-pop')) closePops();
        return;
      }
      const a = t.dataset.act;
      const mid = msgEl ? +msgEl.dataset.id : (t.dataset.mid ? +t.dataset.mid : 0);
      const m = mid ? S.msgs.get(mid) : null;
      if (!['emoji', 'emoji-pick', 'members', 'mention', 'menu'].includes(a)) closePops();

      switch (a) {
        case 'open': openChannel(t.dataset.type, t.dataset.id); break;
        case 'group': S.collapsed[t.dataset.type] = !S.collapsed[t.dataset.type]; renderChannels(); break;
        case 'back': wrap.classList.remove('pkc-has-conv'); stopPoll(); S.cur = null; S.gen++; renderChannels(); loadChannels(true); break;
        case 'close': opts.onClose && opts.onClose(); break;
        case 'sound': {
          const on = !soundEnabled();
          setSound(on);
          t.textContent = on ? '🔊' : '🔇';
          toast(on ? '🔊 Som activado' : '🔇 Som desligado');
          if (on) ding('msg', true);
          break;
        }
        case 'notif':
          if (opts.onNotifications) { opts.onNotifications(); break; }
          if (!('Notification' in window)) { toast('Este browser não suporta notificações', true); break; }
          Notification.requestPermission().then(p => toast(p === 'granted' ? '🔔 Notificações activadas' : 'Notificações bloqueadas no browser', p !== 'granted'));
          break;
        case 'members': {
          const open = D.membersPop.hidden;
          closePops();
          if (open) { renderMembersPop(); D.membersPop.hidden = false; }
          break;
        }
        case 'search':
          D.searchbar.hidden = !D.searchbar.hidden;
          if (!D.searchbar.hidden) D.searchInput.focus(); else { D.searchInput.value = ''; setView('chat'); }
          break;
        case 'search-close': D.searchbar.hidden = true; D.searchInput.value = ''; setView('chat'); break;
        case 'export':
          if (!api.token) { window.open(api.url('export', { type: S.cur.type, id: S.cur.id }), '_blank'); break; }
          try {
            const b = await api.blob('export', { type: S.cur.type, id: S.cur.id });
            const l = document.createElement('a');
            l.href = URL.createObjectURL(b); l.download = `chat_${S.cur.type}_${S.cur.id}.html`;
            document.body.appendChild(l); l.click(); l.remove();
          } catch (err) { toast('Erro ao exportar: ' + err.message, true); }
          break;
        case 'older': loadOlder(); break;
        case 'jump': D.msgs.scrollTop = D.msgs.scrollHeight; D.jump.hidden = true; S.newWhileUp = 0; markRead(); break;
        case 'attach': D.file.click(); break;
        case 'emoji': if (D.emojiPop.hidden) openEmoji(null); else D.emojiPop.hidden = true; break;
        case 'emoji-pick':
          if (emojiTarget === null) insertAtCursor(D.input, t.dataset.emoji);
          else act('react', { message_id: emojiTarget, emoji: t.dataset.emoji });
          D.emojiPop.hidden = true;
          break;
        case 'send': send(); break;
        case 'unpend': {
          const i = S.pending.findIndex(p => p.id === t.dataset.pid);
          if (i >= 0) { const [p] = S.pending.splice(i, 1); if (p.url) URL.revokeObjectURL(p.url); renderPending(); }
          break;
        }
        case 'reply-cancel': S.replyTo = null; renderReply(); break;
        case 'mention': pickMention(+t.dataset.i); break;
        case 'react': if (m) act('react', { message_id: m.id, emoji: t.dataset.emoji }); break;
        case 'reply': if (m) { S.replyTo = m; renderReply(); D.input.focus(); } break;
        case 'menu': if (m) { const open = D.menu.hidden || D.menu.dataset.mid != m.id; closePops(); if (open) { openMenu(m, t); D.menu.dataset.mid = m.id; } } break;
        case 'm-reply': S.replyTo = S.msgs.get(mid); renderReply(); D.input.focus(); break;
        case 'm-copy': {
          const mm = S.msgs.get(mid);
          try { await navigator.clipboard.writeText(mm.body); toast('Texto copiado'); } catch (err) { toast('Não foi possível copiar', true); }
          break;
        }
        case 'm-pin': act('pin', { message_id: mid }); break;
        case 'm-emoji-react': {
          const anchor = D.msgs.querySelector(`.pkc-msg[data-id="${mid}"] .pkc-bubble`);
          openEmoji(mid, anchor);
          break;
        }
        case 'm-edit': S.editingId = mid; renderMessages(); break;
        case 'm-delete':
          if (confirm('Eliminar esta mensagem? Os anexos também serão removidos.')) act('delete', { message_id: mid });
          break;
        case 'edit-cancel': S.editingId = null; renderMessages(); break;
        case 'edit-save': saveEdit(); break;
        case 'goto': gotoMsg(t.dataset.target); break;
        case 'lightbox': {
          const img = D.lightbox.querySelector('img');
          img.removeAttribute('src');
          D.lightbox.hidden = false;
          imgSrc({ id: +t.dataset.att }).then(u => { img.src = u; });
          D.lightbox.dataset.att = t.dataset.att;
          break;
        }
        case 'lb-close': D.lightbox.hidden = true; break;
        case 'download': download(+t.dataset.att, t.dataset.name); break;
      }
    });

    D.lightbox.addEventListener('click', e => { if (e.target === D.lightbox) D.lightbox.hidden = true; });
    D.lightbox.querySelector('img').addEventListener('dblclick', () => download(+D.lightbox.dataset.att, ''));

    async function saveEdit() {
      const ta = D.msgs.querySelector('.pkc-edit textarea');
      if (!ta) return;
      const body = ta.value.trim();
      const id = S.editingId;
      if (!body) { toast('A mensagem não pode ficar vazia', true); return; }
      S.editingId = null;
      const m = S.msgs.get(id);
      if (m) { m.body = body; m.edited = true; }
      renderMessages();
      act('edit', { message_id: id, body });
    }

    D.msgs.addEventListener('keydown', e => {
      const ta = e.target.closest('.pkc-edit textarea');
      if (!ta) return;
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveEdit(); }
      if (e.key === 'Escape') { S.editingId = null; renderMessages(); }
    });
    D.msgs.addEventListener('input', e => { if (e.target.matches('.pkc-edit textarea')) autosize(e.target); });

    D.msgs.addEventListener('scroll', () => {
      if (D.msgs.scrollTop < 60 && S.hasMore) loadOlder();
      if (nearBottom()) { D.jump.hidden = true; S.newWhileUp = 0; markRead(); }
    }, { passive: true });

    D.input.addEventListener('input', () => { autosize(D.input); updateMention(); if (D.input.value) sendTyping(); });
    D.input.addEventListener('click', updateMention);
    D.input.addEventListener('keydown', e => {
      if (mention) {
        if (e.key === 'ArrowDown') { e.preventDefault(); mention.idx = (mention.idx + 1) % mention.items.length; renderMention(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); mention.idx = (mention.idx - 1 + mention.items.length) % mention.items.length; renderMention(); return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pickMention(mention.idx); return; }
        if (e.key === 'Escape') { closeMention(); return; }
      }
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); return; }
      if (e.key === 'Escape' && S.replyTo) { S.replyTo = null; renderReply(); return; }
      if (e.key === 'ArrowUp' && !D.input.value && S.me) {
        // ↑ com o campo vazio edita a última mensagem própria (como no Teams)
        for (let i = S.order.length - 1; i >= 0; i--) {
          const m = S.msgs.get(S.order[i]);
          if (m.user_id === S.me.user_id && !m.deleted && m.body) { e.preventDefault(); S.editingId = m.id; renderMessages(); break; }
        }
      }
    });
    D.input.addEventListener('paste', e => {
      const files = [...(e.clipboardData?.files || [])];
      if (files.length) { e.preventDefault(); addFiles(files.map((f, i) => f.name && f.name !== 'image.png' ? f
        : new File([f], `imagem_${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}${i ? '_' + i : ''}.${(f.type.split('/')[1] || 'png')}`, { type: f.type }))); }
    });
    D.file.addEventListener('change', () => { addFiles([...D.file.files]); D.file.value = ''; });

    // Filtro por tópico (canal lab)
    if (D.topicFilterBtn) {
      D.topicFilterBtn.addEventListener('click', () => {
        const tid = D.topicSelect ? (parseInt(D.topicSelect.value, 10) || 0) : 0;
        if (!tid) { toast('Escolhe um assunto para filtrar', true); return; }
        applyTopicFilter(tid);
      });
    }
    if (D.topicClearBtn) {
      D.topicClearBtn.addEventListener('click', () => {
        S.topicFilter = 0;
        D.topicClearBtn.hidden = true;
        if (D.banner) D.banner.hidden = true;
        if (D.topicSelect) D.topicSelect.value = '';
        if (S.cur) openChannel(S.cur.type, S.cur.id);
      });
    }

    // drag & drop
    let dragDepth = 0;
    D.conv.addEventListener('dragenter', e => { if (![...e.dataTransfer.types].includes('Files') || !S.chan?.can_write) return; e.preventDefault(); dragDepth++; D.drop.hidden = false; });
    D.conv.addEventListener('dragover', e => { if ([...e.dataTransfer.types].includes('Files')) e.preventDefault(); });
    D.conv.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; D.drop.hidden = true; } });
    D.conv.addEventListener('drop', e => {
      e.preventDefault(); dragDepth = 0; D.drop.hidden = true;
      if (e.dataTransfer.files.length) addFiles([...e.dataTransfer.files]);
    });

    D.tabs.addEventListener('click', e => {
      const b = e.target.closest('button[data-view]');
      if (b) { D.searchbar.hidden = true; D.searchInput.value = ''; setView(b.dataset.view); if (b.dataset.view === 'chat') renderMessages({ toBottom: true }); }
    });
    D.searchInput.addEventListener('input', doSearch);
    D.searchInput.addEventListener('keydown', e => { if (e.key === 'Escape') { D.searchbar.hidden = true; D.searchInput.value = ''; setView('chat'); } });

    if (full) {
      D.chanFilter.addEventListener('input', () => { S.filter = D.chanFilter.value; renderChannels(); });
      D.showClosed.addEventListener('change', () => { S.showClosed = D.showClosed.checked; renderChannels(); });
    }

    const onVis = () => {
      if (S.destroyed) return;
      if (!document.hidden) { markRead(); if (S.cur) { stopPoll(); poll(); } else if (full) loadChannels(true); }
    };
    document.addEventListener('visibilitychange', onVis);
    const onKey = e => { if (e.key === 'Escape') { if (!D.lightbox.hidden) D.lightbox.hidden = true; closePops(); } };
    document.addEventListener('keydown', onKey);

    // canais sem conversa aberta: actualizar a lista periodicamente
    if (full) S.chanTimer = setInterval(() => { if (!S.cur && !document.hidden) loadChannels(true); }, CHANNELS_EVERY);

    // ══ ARRANQUE ═══════════════════════════════════════════════
    (async () => {
      if (full) {
        await loadChannels();
        let start = opts.channel;
        if (!start) {
          try { const v = (localStorage.getItem(LS_KEY) || '').split(':'); if (v.length === 2) start = { type: v[0], id: +v[1] }; } catch (e) {}
          if (start && !S.channels.some(c => c.type === start.type && c.id === start.id)) start = null;
          if (start && wrap.classList.contains('pkc-narrow')) start = null; // em telemóvel começa pela lista
        }
        if (start) openChannel(start.type, start.id);
      } else if (opts.channel) {
        openChannel(opts.channel.type, opts.channel.id);
      }
    })();

    return {
      open: (type, id) => openChannel(type, id),
      refresh: () => { loadChannels(true); if (S.cur) { stopPoll(); poll(); } },
      destroy() {
        S.destroyed = true;
        stopPoll(); clearInterval(S.chanTimer);
        ro.disconnect();
        document.removeEventListener('visibilitychange', onVis);
        document.removeEventListener('keydown', onKey);
        S.blobUrls.forEach(u => URL.revokeObjectURL(u));
        root.innerHTML = '';
      },
    };
  }

  /** Só o contador de não lidas (badge no menu/cabeçalho), sem montar a UI. */
  function watchUnread(opts, cb, every = 30000) {
    const api = makeApi(opts);
    let prev = null, timer;
    async function tick() {
      try {
        const d = await api.get('unread');
        cb(d.unread_total, d.mentions_total, d.channels);
        if (prev) {
          let sound = null;
          d.channels.forEach(c => {
            const k = c.type + ':' + c.id;
            if (!c.last || c.last.id <= (prev[k] || 0)) return;
            sound = c.mentions ? 'mention' : (sound || 'msg');
            if (opts.notify && document.hidden) sysNotify(`${TYPE_META[c.type].icon} ${c.title}`, `${c.last.username}: ${c.last.body}`, 'pkchat-' + k);
          });
          if (sound && !document.hidden) ding(sound);
        }
        prev = {};
        d.channels.forEach(c => { prev[c.type + ':' + c.id] = c.last ? c.last.id : 0; });
      } catch (e) { /* ignora */ }
      timer = setTimeout(tick, document.hidden ? every * 2 : every);
    }
    tick();
    return { stop: () => clearTimeout(timer) };
  }

  window.PKChat = { mount, watchUnread, ding, soundEnabled, setSound };
})();
