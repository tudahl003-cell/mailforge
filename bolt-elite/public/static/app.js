// BOLT ELITE REDIRECT - admin SPA.
'use strict';
const S = {
  token: localStorage.getItem('bolt_token') || '',
  view: 'dash', tokens: [], stats: null, templates: [],
  editing: null, hits: [], settings: null, hitFilter: '',
};
const $ = (s, r = document) => r.querySelector(s);
const el = (t, a = {}, kids = []) => {
  const n = document.createElement(t);
  for (const k in a) {
    if (k === 'class') n.className = a[k];
    else if (k === 'html') n.innerHTML = a[k];
    else if (k === 'text') n.textContent = a[k];
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), a[k]);
    else if (a[k] !== null && a[k] !== undefined && a[k] !== false) n.setAttribute(k, a[k]);
  }
  (Array.isArray(kids) ? kids : [kids]).forEach(k => k && n.appendChild(k));
  return n;
};
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const cc = c => (c || '').toUpperCase().replace(/./g, ch => String.fromCodePoint(127397 + ch.charCodeAt(0)));

function toast(msg, bad) {
  const t = el('div', { class: 'toast' + (bad ? ' bad' : ''), text: msg });
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 4200);
}

async function api(path, opts = {}) {
  const r = await fetch('/api/' + path, Object.assign({
    headers: { 'Content-Type': 'application/json', 'X-Admin-Token': S.token },
  }, opts));
  if (r.status === 401) { S.token = ''; localStorage.removeItem('bolt_token'); render(); throw new Error('unauthorized'); }
  const j = await r.json().catch(() => ({ ok: false, error: 'bad json' }));
  if (!j.ok) throw new Error(j.error || 'request failed');
  return j;
}

function shell(body) {
  const nav = [['dash', 'Dashboard'], ['links', 'Links'], ['builder', 'AI Builder'], ['hits', 'Visitors'], ['settings', 'Settings']];
  const top = el('header', { class: 'top' }, el('div', { class: 'wrap between' }, [
    el('div', { class: 'brand', html: 'BOLT <span>ELITE</span> REDIRECT' }),
    el('nav', { class: 'tabs' }, nav.map(([k, label]) =>
      el('button', { class: S.view === k ? 'on' : '', text: label, onclick: () => { S.view = k; render(); } }))),
    el('div', {}, el('button', { class: 'btn ghost sm', text: 'Sign out', onclick: () => { S.token = ''; localStorage.removeItem('bolt_token'); render(); } })),
  ]));
  return [top, el('div', { class: 'wrap' }, body)];
}

function render() {
  const app = $('#app');
  app.innerHTML = '';
  if (!S.token) return renderLogin();
  const body = el('div', {}, el('div', { class: 'mut', text: 'Loading...' }));
  shell(body).forEach(n => app.appendChild(n));
  ({ dash: viewDash, links: viewLinks, builder: viewBuilder, hits: viewHits, settings: viewSettings }[S.view] || viewDash)(body);
}

function renderLogin() {
  const app = $('#app');
  const inp = el('input', { type: 'password', placeholder: 'Admin token', value: S.token });
  const go = async () => {
    S.token = inp.value.trim();
    try {
      await api('ping');
      localStorage.setItem('bolt_token', S.token);
      render();
    } catch (e) { toast('Invalid token', true); }
  };
  inp.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
  app.appendChild(el('div', { class: 'center' }, el('div', { class: 'card login' }, [
    el('div', { class: 'brand', html: 'BOLT <span>ELITE</span> REDIRECT' }),
    el('div', { class: 'mut sm', style: 'margin:8px 0 16px', text: 'Enter your admin token to continue.' }),
    inp, el('button', { class: 'btn', style: 'margin-top:12px;width:100%', text: 'Sign in', onclick: go }),
  ])));
  setTimeout(() => inp.focus(), 30);
}
async function viewDash(body) {
  try {
    const [st, tk] = await Promise.all([api('stats'), api('tokens')]);
    S.stats = st; S.tokens = tk.tokens;
  } catch (e) { body.appendChild(el('div', { class: 'card', text: 'Failed to load: ' + e.message })); return; }
  const t = S.stats.totals;
  const kpis = [['Links', t.tokens], ['Active', t.active], ['Total hits', t.hits], ['Hits 24h', t.hits_24h], ['Unique IPs', t.visitors], ['Countries', t.countries]];
  body.appendChild(el('div', { class: 'grid g2', style: 'margin-bottom:14px' },
    kpis.map(([k, v]) => el('div', { class: 'card' }, [el('h3', { text: k }), el('div', { class: 'kpi', text: String(v) })]))));

  const max = Math.max(1, ...S.stats.by_day.map(d => d.hits));
  const chart = el('div', { class: 'card' }, [
    el('h3', { text: 'Hits - last 14 days' }),
    el('div', { style: 'display:flex;gap:5px;align-items:flex-end;height:110px' },
      S.stats.by_day.map(d => el('div', { style: 'flex:1;text-align:center', title: d.date + ': ' + d.hits }, [
        el('div', { style: 'height:' + Math.round(d.hits / max * 92) + 'px;background:var(--ac);border-radius:4px 4px 0 0;min-height:2px' }),
        el('div', { class: 'xs mut', style: 'margin-top:5px', text: d.date.slice(8) }),
      ]))),
  ]);

  const listCard = (title, rows) => el('div', { class: 'card' }, [
    el('h3', { text: title }),
    rows.length ? el('div', {}, rows.map(r => el('div', { class: 'between', style: 'padding:5px 0' }, [
      el('span', { class: 'sm', text: r.k }), el('span', { class: 'pill', text: r.c }),
    ]))) : el('div', { class: 'mut sm', text: 'No data yet' }),
  ]);

  body.appendChild(el('div', { class: 'split', style: 'margin-bottom:14px' }, [
    chart,
    listCard('Top countries', S.stats.countries.map(r => ({ k: cc(r.k.slice(0, 2)) + ' ' + r.k, c: r.c }))),
  ]));
  body.appendChild(el('div', { class: 'grid g3' }, [
    listCard('Devices', S.stats.devices), listCard('Operating systems', S.stats.os), listCard('Browsers', S.stats.browsers),
  ]));

  const tt = el('table', {}, [
    el('thead', {}, el('tr', {}, ['Link', 'Slug', 'Status', 'Hits', 'Unique', 'Target'].map(h => el('th', { text: h })))),
    el('tbody', {}, S.stats.top_tokens.map(r => el('tr', {}, [
      el('td', { text: r.name }), el('td', { class: 'mono', text: r.slug }),
      el('td', {}, el('span', { class: 'pill ' + (r.status === 'active' ? 'ok' : 'warn'), text: r.status })),
      el('td', { text: String(r.hits) }), el('td', { text: String(r.uniq) }),
      el('td', { class: 'mono mut', text: String(r.target).slice(0, 42) }),
    ]))),
  ]);
  body.appendChild(el('div', { class: 'card', style: 'margin-top:14px' }, [el('h3', { text: 'Top links' }), el('div', { class: 'tblwrap' }, tt)]));
}

async function viewHits(body) {
  const sel = el('select', {}, [el('option', { value: '', text: 'All links' })].concat(
    S.tokens.map(t => el('option', { value: t.id, text: t.name, selected: S.hitFilter === t.id }))));
  sel.addEventListener('change', () => { S.hitFilter = sel.value; viewHitsRefresh(body); });
  body.appendChild(el('div', { class: 'between', style: 'margin-bottom:14px' }, [
    el('div', { class: 'row', style: 'align-items:center;gap:10px' }, [el('div', { style: 'width:240px' }, sel),
      el('span', { class: 'mut sm', text: 'Live visitor log - refresh to see new hits.' })]),
    el('div', { class: 'row' }, [
      el('button', { class: 'btn ghost sm', text: 'Clear log', onclick: async () => {
        if (!confirm('Delete ' + (S.hitFilter ? 'selected' : 'all') + ' hit records?')) return;
        await api('hits/clear', { method: 'POST', body: JSON.stringify({ token_id: S.hitFilter }) });
        toast('Log cleared'); viewHitsRefresh(body);
      } }),
      el('button', { class: 'btn sm', text: 'Refresh', onclick: () => viewHitsRefresh(body) }),
    ]),
  ]));
  body.appendChild(el('div', { id: 'hitbox' }));
  viewHitsRefresh(body);
}

async function viewHitsRefresh(body) {
  const box = $('#hitbox'); if (!box) return;
  let rows = [];
  try { rows = (await api('hits?limit=200' + (S.hitFilter ? '&token_id=' + encodeURIComponent(S.hitFilter) : ''))).hits || []; }
  catch (e) { box.innerHTML = ''; box.appendChild(el('div', { class: 'card', text: e.message })); return; }
  const table = el('table', {}, [
    el('thead', {}, el('tr', {}, ['Time', 'Geo', 'IP / ISP', 'Device', 'OS', 'Browser', 'Referrer', 'Flags'].map(h => el('th', { text: h })))),
    el('tbody', {}, rows.map(r => el('tr', {}, [
      el('td', { class: 'xs mut', text: new Date(r.ts * 1000).toLocaleString() }),
      el('td', { class: 'sm', text: cc(r.country_code) + ' ' + (r.country || '-') + (r.city ? ' / ' + r.city : '') }),
      el('td', { class: 'xs' }, [el('div', { class: 'mono', text: r.ip }), el('div', { class: 'mut', text: r.isp || '' })]),
      el('td', { class: 'sm', text: r.device }),
      el('td', { class: 'sm', text: (r.os || '') + ' ' + (r.os_ver || '') }),
      el('td', { class: 'sm', text: (r.browser || '') + ' ' + (r.br_ver || '') }),
      el('td', { class: 'xs mut', text: (r.referrer || '-').slice(0, 34) }),
      el('td', {}, el('span', { class: 'pill ' + (r.is_bot ? 'bad' : 'ok'), text: r.is_bot ? ('bot: ' + (r.bot || '?')) : 'human' })),
    ]))),
  ]);
  box.innerHTML = '';
  box.appendChild(el('div', { class: 'card' }, [
    el('h3', { text: rows.length + ' visits' }), el('div', { class: 'tblwrap' }, table),
  ]));
}
function tplGrid(sel, onPick) {
  return el('div', { class: 'tplgrid' }, (S.templates || []).map(t => {
    const on = sel === t.id;
    return el('div', { class: 'tpl' + (on ? ' on' : ''), onclick: () => onPick(t.id) }, [
      el('div', { class: 'ic', text: t.icon }),
      el('div', { class: 'nm', text: t.name }),
      el('div', { class: 'fm', text: t.family }),
    ]);
  }));
}

function linkUrl(slug) {
  const base = (S.baseUrl || location.origin).replace(/\/$/, '');
  return base + '/' + slug;
}
function copyText(txt) {
  navigator.clipboard.writeText(txt).then(() => toast('Copied: ' + txt), () => toast('Copy failed', true));
}

async function viewLinks(body) {
  if (S.editing) { body.appendChild(editorCard()); return; }
  try { const tk = await api('tokens'); S.tokens = tk.tokens; S.baseUrl = tk.base; } catch (e) {}
  body.appendChild(el('div', { class: 'between', style: 'margin-bottom:14px' }, [
    el('div', { class: 'mut sm', text: S.tokens.length + ' link(s). Each link can redirect instantly or show a landing page first.' }),
    el('button', { class: 'btn', text: '+ New link', onclick: () => openEditor(null) }),
  ]));

  const t = el('table', {}, [
    el('thead', {}, el('tr', {}, ['Name', 'URL', 'Mode', 'Target', 'Hits', 'Status', ''].map(h => el('th', { text: h })))),
    el('tbody', {}, S.tokens.map(k => el('tr', {}, [
      el('td', {}, [el('div', { text: k.name }), el('div', { class: 'xs mut', text: k.template })]),
      el('td', {}, el('div', { class: 'copy' }, [
        el('input', { class: 'mono', readonly: true, value: linkUrl(k.slug), onclick: e => e.target.select() }),
        el('button', { class: 'btn ghost sm', text: 'Copy', onclick: () => copyText(linkUrl(k.slug)) }),
      ])),
      el('td', {}, el('span', { class: 'pill', text: k.mode })),
      el('td', { class: 'mono mut xs', text: String(k.target).slice(0, 40) }),
      el('td', {}, el('span', { class: 'pill', text: k.hits })),
      el('td', {}, el('span', { class: 'pill ' + (k.status === 'active' ? 'ok' : 'warn'), text: k.status })),
      el('td', {}, el('div', { class: 'row' }, [
        el('button', { class: 'btn ghost sm', text: 'Open', onclick: () => window.open(linkUrl(k.slug), '_blank') }),
        el('button', { class: 'btn ghost sm', text: 'Edit', onclick: () => openEditor(k) }),
        el('button', { class: 'btn ghost sm', text: k.status === 'active' ? 'Pause' : 'Resume', onclick: async () => {
          await api('tokens/' + (k.status === 'active' ? 'pause' : 'resume'), { method: 'POST', body: JSON.stringify({ id: k.id }) });
          toast('Updated'); render();
        } }),
        el('button', { class: 'btn danger sm', text: 'Del', onclick: async () => {
          if (!confirm('Delete "' + k.name + '" and its hit history?')) return;
          await api('tokens/delete', { method: 'POST', body: JSON.stringify({ id: k.id }) });
          toast('Deleted'); render();
        } }),
      ])),
    ]))),
  ]);
  body.appendChild(el('div', { class: 'card' }, [el('div', { class: 'tblwrap' }, t)]));
}

function openEditor(tok) {
  S.editing = tok ? JSON.parse(JSON.stringify(tok)) : {
    name: '', slug: '', domain: '*', mode: 'redirect', redirect_code: 302, target: '',
    template: 'update', params_mode: 'merge', param_allow: '', param_block: '',
    delay: 0, max_hits: 0, alert_tg: 0, status: 'active', branding: {},
  };
  S.view = 'links';
  render();
}

function field(label, input) { return el('div', {}, [el('label', { text: label }), input]); }

function editorCard() {
  const k = S.editing;
  const b = k.branding || (k.branding = {});
  const set = (o, key, v) => { o[key] = v; };
  const txt = (key, holder) => el('input', { value: k[key] == null ? '' : k[key], placeholder: holder || '',
    oninput: e => set(k, key, e.target.value) });

  const mode = el('select', { onchange: e => { k.mode = e.target.value; render(); } },
    ['redirect', 'landing', 'chain'].map(m => el('option', { value: m, text: m, selected: k.mode === m })));
  const code = el('select', { onchange: e => k.redirect_code = +e.target.value },
    [301, 302, 303, 307, 308].map(c => el('option', { value: c, text: String(c), selected: +k.redirect_code === c })));

  const left = el('div', { class: 'card' }, [
    el('h3', { text: k.id ? 'Edit link' : 'New link' }),
    field('Name', txt('name', 'Q3 invoice campaign')),
    field('Custom slug (letters, numbers, - _)', txt('slug', 'inv-4821')),
    field('Target URL', txt('target', 'https://example.com/offer')),
    field('Bound domain (blank or * = any)', txt('domain', 'go.yourdomain.com')),
    el('div', { class: 'row', style: 'gap:14px' }, [
      el('div', { style: 'flex:1' }, field('Mode', mode)),
      el('div', { style: 'flex:1' }, field('Redirect code', code)),
    ]),
    el('h3', { style: 'margin-top:18px', text: 'Landing template' }),
    tplGrid(k.template, id => { k.template = id; render(); }),
    el('h3', { style: 'margin-top:18px', text: 'Forwarding' }),
    field('Param mode', el('select', { onchange: e => k.params_mode = e.target.value },
      [['merge', 'Forward all (merge into target)'], ['forward_only', 'Forward only allow-list'], ['strip', 'Forward nothing']]
        .map(([v, t]) => el('option', { value: v, text: t, selected: k.params_mode === v })))),
    field('Allow list (comma, blank = all)', txt('param_allow', 'utm_source, email')),
    field('Block list (comma)', txt('param_block', 'fbclid, gclid')),
    el('h3', { style: 'margin-top:18px', text: 'Behaviour' }),
    el('div', { class: 'row', style: 'gap:14px' }, [
      el('div', { style: 'flex:1' }, field('Delay before auto-forward (s, 0=off)', el('input', { type: 'number', value: k.delay,
        oninput: e => k.delay = +e.target.value }))),
      el('div', { style: 'flex:1' }, field('Max hits (0=unlimited)', el('input', { type: 'number', value: k.max_hits,
        oninput: e => k.max_hits = +e.target.value }))),
    ]),
    el('label', {}, el('span', { text: 'Telegram alert on every visit' })),
    el('input', { type: 'checkbox', style: 'width:auto', checked: !!k.alert_tg, onchange: e => k.alert_tg = e.target.checked ? 1 : 0 }),
  ]);

  const right = el('div', { class: 'card' }, [
    el('h3', { text: 'Page copy' }),
    field('Kicker (small label)', el('input', { value: b.kicker || '', oninput: e => b.kicker = e.target.value })),
    field('Headline', el('input', { value: b.h1 || '', oninput: e => b.h1 = e.target.value })),
    field('Subheading', el('input', { value: b.sub || '', oninput: e => b.sub = e.target.value })),
    field('Body', el('textarea', { rows: 3, oninput: e => b.body = e.target.value }, b.body || '')),
    el('div', { class: 'row', style: 'gap:14px' }, [
      el('div', { style: 'flex:1' }, field('Button label', el('input', { value: b.cta || '', oninput: e => b.cta = e.target.value }))),
      el('div', { style: 'flex:1' }, field('Brand', el('input', { value: b.brand || '', oninput: e => b.brand = e.target.value }))),
    ]),
    el('div', { class: 'row', style: 'gap:14px' }, [
      el('div', { style: 'flex:1' }, field('Icon', el('input', { value: b.icon || '', oninput: e => b.icon = e.target.value }))),
      el('div', { style: 'flex:1' }, field('Accent', el('input', { type: 'color', value: b.accent || '#4c8dff', oninput: e => b.accent = e.target.value }))),
    ]),
    el('div', { class: 'row', style: 'margin-top:16px' }, [
      el('button', { class: 'btn', text: k.id ? 'Save changes' : 'Create link', onclick: saveLink }),
      el('button', { class: 'btn ghost', text: 'Cancel', onclick: () => { S.editing = null; render(); } }),
      k.id ? el('button', { class: 'btn ghost', text: 'Preview', onclick: () => window.open(linkUrl(k.slug), '_blank') }) : null,
    ]),
  ]);

  return el('div', { class: 'split' }, [left, right]);
}

async function saveLink() {
  const k = S.editing;
  if (!k.target) return toast('Target URL is required', true);
  try {
    const r = await api('tokens/save', { method: 'POST', body: JSON.stringify(k) });
    S.editing = null;
    toast(r.created ? 'Link created: ' + linkUrl(r.token.slug) : 'Saved');
    render();
  } catch (e) { toast(e.message, true); }
}
function viewBuilder(body) {
  const f = { brief: '', target: '', brand: '', tone: 'professional', accent: '' };
  const res = el('div', { id: 'aiout' });
  const left = el('div', { class: 'card' }, [
    el('h3', { text: 'AI redirect builder' }),
    el('div', { class: 'mut sm', style: 'margin-bottom:12px', text: 'Describe the campaign in plain words. The builder picks a template and writes the copy.' }),
    field('Brief', el('textarea', { rows: 4, placeholder: 'A security notice telling the reader a new sign-in was detected and asking them to review it.',
      oninput: e => f.brief = e.target.value })),
    field('Destination URL', el('input', { placeholder: 'https://example.com/target', oninput: e => f.target = e.target.value })),
    el('div', { class: 'row', style: 'gap:14px' }, [
      el('div', { style: 'flex:1' }, field('Brand', el('input', { oninput: e => f.brand = e.target.value }))),
      el('div', { style: 'flex:1' }, field('Tone', el('select', { onchange: e => f.tone = e.target.value },
        ['professional', 'friendly', 'urgent', 'formal', 'casual'].map(t => el('option', { value: t, text: t }))))),
    ]),
    el('button', { class: 'btn', style: 'margin-top:16px', text: 'Generate with AI', onclick: async (e) => {
      e.target.disabled = true; e.target.textContent = 'Generating...';
      try {
        const r = (await api('ai/build', { method: 'POST', body: JSON.stringify(f) })).result;
        S.aiResult = r; res.innerHTML = ''; res.appendChild(aiPreview(r, f));
        toast(r.source === 'ai' ? 'Generated with AI' : 'Generated offline (no AI key set)');
      } catch (err) { toast(err.message, true); }
      e.target.disabled = false; e.target.textContent = 'Generate with AI';
    } }),
  ]);
  body.appendChild(el('div', { class: 'split' }, [left, res]));
}

function aiPreview(r, f) {
  return el('div', { class: 'card' }, [
    el('h3', { text: 'Result - ' + r.template + ' (' + r.source + ')' }),
    el('div', { class: 'pill', text: r.kicker }),
    el('div', { style: 'font-size:19px;font-weight:700;margin:10px 0 6px', text: r.h1 }),
    el('div', { class: 'mut sm', text: r.sub }),
    el('div', { class: 'sm', style: 'margin:12px 0', text: r.body }),
    el('div', { class: 'pill', text: r.cta }),
    el('button', { class: 'btn', style: 'margin-top:16px', text: 'Create link from this', onclick: () => {
      S.editing = {
        name: r.h1.slice(0, 40), slug: '', domain: '*', mode: 'landing', redirect_code: 302,
        target: f.target || '', template: r.template, params_mode: 'merge', param_allow: '',
        param_block: 'fbclid, gclid', delay: 0, max_hits: 0, alert_tg: 0, status: 'active',
        branding: { kicker: r.kicker, h1: r.h1, sub: r.sub, body: r.body, cta: r.cta, accent: r.accent, icon: r.icon, brand: (f.brand || r.brand) },
      };
      S.view = 'links'; render();
    } }),
  ]);
}

async function viewSettings(body) {
  let st = {};
  try { st = (await api('settings')).settings || {}; } catch (e) {}
  const sg = { tg_bot_token: '', tg_chat_id: st.tg_chat_id || '', llm_key: '', llm_model: st.llm_model || '', llm_base: st.llm_base || '' };
  const save = async () => {
    try { await api('settings', { method: 'POST', body: JSON.stringify(sg) }); toast('Settings saved'); render(); }
    catch (e) { toast(e.message, true); }
  };
  body.appendChild(el('div', { class: 'grid g3' }, [
    el('div', { class: 'card' }, [
      el('h3', { text: 'Telegram alerts' }),
      el('div', { class: 'mut sm', style: 'margin-bottom:10px', text: st.tg_configured ? 'Configured. Alerts fire on flagged links.' : 'Not configured yet.' }),
      field('Bot token', el('input', { type: 'password', placeholder: st.tg_bot_token || '123456:ABC...', oninput: e => sg.tg_bot_token = e.target.value })),
      field('Chat IDs (comma for several)', el('input', { value: sg.tg_chat_id, placeholder: '7901102007', oninput: e => sg.tg_chat_id = e.target.value })),
      el('div', { class: 'row', style: 'margin-top:14px' }, [
        el('button', { class: 'btn', text: 'Save', onclick: save }),
        el('button', { class: 'btn ghost', text: 'Send test alert', onclick: async () => {
          try { const r = await api('alert/test', { method: 'POST', body: '{}' }); toast(r.sent ? 'Test alert sent' : 'Telegram not configured', !r.sent); }
          catch (e) { toast(e.message, true); }
        } }),
      ]),
    ]),
    el('div', { class: 'card' }, [
      el('h3', { text: 'AI builder' }),
      el('div', { class: 'mut sm', style: 'margin-bottom:10px', text: st.ai_available ? 'A key is configured.' : 'No key - the builder falls back to offline copy.' }),
      field('API key', el('input', { type: 'password', placeholder: st.llm_key === 'set' ? 'saved' : 'sk-...', oninput: e => sg.llm_key = e.target.value })),
      field('Model', el('input', { value: sg.llm_model, oninput: e => sg.llm_model = e.target.value })),
      field('Base URL', el('input', { value: sg.llm_base, oninput: e => sg.llm_base = e.target.value })),
      el('button', { class: 'btn', style: 'margin-top:14px', text: 'Save', onclick: save }),
    ]),
    el('div', { class: 'card' }, [
      el('h3', { text: 'Security' }),
      el('div', { class: 'mut sm', text: 'Admin token source: ' + esc(st.admin_token || 'env') + '. Set ADMIN_TOKEN in the environment to rotate it.' }),
      el('div', { class: 'mut sm', style: 'margin-top:10px', text: 'All links are noindex,nofollow. Visit logs keep IP and geo for 14 days of cache.' }),
    ]),
  ]));
}

async function init() {
  try { S.templates = (await api('templates')).templates || []; } catch (e) {}
  try { const tk = await api('tokens'); S.tokens = tk.tokens; S.baseUrl = tk.base; } catch (e) {}
  render();
}
render();
if (S.token) init();
