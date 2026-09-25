// mailforge SPA
'use strict';
const T = window.MF_TOKEN || '';
let token = localStorage.getItem('mf_token') || T;
let currentView = 'dashboard';
let pollTimer = null;

// ---------- API ----------
async function api(path, body, method = 'POST') {
  const opt = { method, headers: { 'Content-Type': 'application/json', 'X-Admin-Token': token || '' } };
  if (body !== undefined) opt.body = JSON.stringify(body);
  const r = await fetch(path, opt);
  let j; try { j = await r.json(); } catch (e) { j = { ok: false, error: 'bad response' }; }
  if (r.status === 401) { token = ''; showGate(); throw new Error('unauthorized'); }
  return j;
}
const get = (p) => api(p, undefined, 'GET');

// ---------- helpers ----------
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
function toast(msg, kind = 'ok') {
  const d = document.createElement('div'); d.className = 'toast ' + kind; d.textContent = msg;
  document.body.appendChild(d); setTimeout(() => d.remove(), 3800);
}
function badge(kind, text) { return `<span class="badge ${esc(kind)}">${esc(text ?? kind)}</span>`; }
function timeAgo(ts) { if (!ts) return '—'; const s = Math.floor(Date.now() / 1000 - ts); if (s < 5) return 'just now'; if (s < 60) return s + 's ago'; if (s < 3600) return Math.floor(s / 60) + 'm ago'; if (s < 86400) return Math.floor(s / 3600) + 'h ago'; return Math.floor(s / 86400) + 'd ago'; }
function copy(text, btn) { navigator.clipboard?.writeText(text); if (btn) { const o = btn.textContent; btn.textContent = 'copied!'; setTimeout(() => btn.textContent = o, 1200); } }
function stop(e) { e.stopPropagation(); }

// ---------- gate ----------
function showGate() {
  $('#tokenGate').classList.remove('hidden');
  $('#views').classList.add('hidden');
  setTimeout(() => $('#tokenInput').focus(), 50);
}
function hideGate() {
  $('#tokenGate').classList.add('hidden');
  $('#views').classList.remove('hidden');
}
function submitToken() {
  const v = $('#tokenInput').value.trim();
  token = v; localStorage.setItem('mf_token', v);
  get('/api/dashboard.php').then(r => {
    if (r.ok) { hideGate(); boot(); }
    else { $('#tokenErr').textContent = r.error || 'unauthorized'; }
  }).catch(() => { $('#tokenErr').textContent = 'check the token / network'; });
}

// ---------- routing ----------
function setView(v) {
  currentView = v;
  document.querySelectorAll('#nav a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  const titles = { dashboard: 'Dashboard', campaigns: 'Campaigns', compose: 'Compose', verify: 'Email Verifier', smtp: 'SMTP Pool', links: 'Link URLs', logs: 'Logs', settings: 'Settings' };
  $('#viewTitle').textContent = titles[v] || v;
  ({ dashboard: vDash, campaigns: vCamp, compose: vCompose, verify: vVerify, smtp: vSmtp, links: vLinks, logs: vLogs, settings: vSettings }[v] || vDash)();
}
function boot() {
  document.querySelectorAll('#nav a').forEach(a => a.onclick = () => setView(a.dataset.view));
  setView('dashboard');
}

// ============================================================
//  DASHBOARD
// ============================================================
async function vDash() {
  $('#view').innerHTML = `<div class="dim"><span class="spin"></span> loading…</div>`;
  const r = await get('/api/dashboard.php');
  if (!r.ok) return fail('#view', r);
  const d = r.data;
  const stats = [
    ['sent', d.sent, 'sent (all time)', 'ok'],
    ['today', d.sent_today, 'sent today', ''],
    ['campaigns', d.campaigns, 'campaigns', ''],
    ['accounts', d.accounts, 'smtp accounts', ''],
    ['url', d.url_hits, 'link hits', ''],
    ['failed', d.failed, 'failed sends', d.failed ? 'bad' : ''],
  ];
  $('#view').innerHTML = `
    <div class="grid cols-3 mb">
      ${stats.map(([id, n, l, cls]) => `<div class="stat ${cls}"><div class="n">${n ?? 0}</div><div class="l">${l}</div></div>`).join('')}
    </div>
    <div class="grid cols-2">
      <div class="card"><h3>Recent sends</h3>
        ${d.recent_sends.length ? `<div class="tablewrap"><table><thead><tr><th>recip</th><th>status</th><th>when</th></tr></thead><tbody>
          ${d.recent_sends.map(s => `<tr><td class="mono">${esc(s.recipient)}</td><td>${badge(s.status)}${s.reason && s.status !== 'sent' ? ` <span class="dim small">${esc(s.reason)}</span>` : ''}</td><td class="dim small">${timeAgo(s.created_at)}</td></tr>`).join('')}
        </tbody></table></div>` : `<div class="dim small">no sends yet</div>`}
      </div>
      <div class="card"><h3>Recent campaigns</h3>
        ${d.recent_campaigns.length ? `<div class="tablewrap"><table><thead><tr><th>name</th><th>status</th><th>when</th></tr></thead><tbody>
          ${d.recent_campaigns.map(c => `<tr><td>${esc(c.name) || '<span class="dim">untitled</span>'}</td><td>${badge(c.status)}</td><td class="dim small">${timeAgo(c.created_at)}</td></tr>`).join('')}
        </tbody></table></div>` : `<div class="dim small">no campaigns yet</div>`}
      </div>
    </div>`;
}
function fail(sel, r) { $(sel).innerHTML = `<div class="card"><div class="err">${esc(r.error || 'error')}</div></div>`; }

// ============================================================
//  CAMPAIGNS
// ============================================================
async function vCamp() {
  const r = await get('/api/campaigns.php');
  if (!r.ok) return fail('#view', r);
  const rows = r.campaigns || [];
  $('#view').innerHTML = `
    <div class="row mb">
      <button class="primary" onclick="campNew()">+ New campaign</button>
      <span class="dim small">${rows.length} campaign(s)</span>
    </div>
    <div id="campList"></div>`;
  renderCampList(rows);
  window.campNew = campNew;
}
function renderCampList(rows) {
  const el = $('#campList'); if (!el) return;
  if (!rows.length) { el.innerHTML = `<div class="card dim">No campaigns. Create one to get started.</div>`; return; }
  el.innerHTML = `<div class="tablewrap"><table><thead><tr>
    <th>#</th><th>name</th><th>recips</th><th>url</th><th>smtp</th><th>status</th><th>when</th><th></th>
  </tr></thead><tbody>
  ${rows.map(c => `<tr>
    <td class="dim mono">#${c.id}</td>
    <td>${esc(c.name) || '<span class="dim">untitled</span>'}</td>
    <td>${c.recipient_count}</td>
    <td class="dim small">${esc(c.url_mode)}</td>
    <td class="dim small">${esc(c.smtp_mode)}</td>
    <td>${badge(c.status)}</td>
    <td class="dim small">${timeAgo(c.created_at)}</td>
    <td class="right">
      <button class="ghost" onclick="campOpen(${c.id})">open</button>
      <button class="ghost" onclick="campSend(${c.id})">send</button>
      <button class="ghost danger" onclick="campDel(${c.id})">del</button>
    </td>
  </tr>`).join('')}
  </tbody></table></div>`;
}
async function campOpen(id) {
  const r = await get(`/api/campaigns.php?id=${id}`);
  const one = r.campaign || (r.campaigns || []).find(c => c.id === id);
  if (!one) return toast('not found', 'err');
  openCampEditor(one);
}
async function campNew() {
  openCampEditor({ id: 0, name: '', recipients: '', subject_base: '', body_base: 'Hi {{first_name}},\n\nI wanted to reach out about something useful for you.\n\n{{link}}\n\nThanks,', tone: 'neutral', url_mode: 'per_recipient', url_target: '', url_batch_size: 1, smtp_mode: 'rotate', smtp_account_id: 0, status: 'draft' });
}
async function openCampEditor(c) {
  const sm = await get('/api/smtp.php');
  const accts = (sm.accounts || []).map(a => `<option value="${a.id}" ${c.smtp_account_id === a.id ? 'selected' : ''}>${esc(a.name || a.host)} (${esc(a.from_addr)})</option>`).join('');
  $('#view').innerHTML = `
    <div class="row mb"><button class="ghost" onclick="vCamp()">← back</button></div>
    <div class="grid cols-2">
      <div class="card">
        <h3>Campaign</h3>
        <label>name</label><input id="c_name" value="${esc(c.name)}" placeholder="Q3 outreach">
        <label>recipients — one per line, optional |first|org suffix</label>
        <textarea id="c_recips" rows="7" placeholder="john@corp.com|John\nboss@acme.io|Jane|Acme">${esc(c.recipients)}</textarea>
        <label>subject (base — will be varied per recipient)</label><input id="c_subj" value="${esc(c.subject_base)}" placeholder="Quick note">
        <label>body (base — {{link}} becomes each recipient's URL; {{first_name}}/{{name}}/{{org}} personalize)</label>
        <textarea id="c_body" rows="9">${esc(c.body_base)}</textarea>
        <div class="row mt wrap">
          <div class="field"><label>tone</label>
            <select id="c_tone">${['neutral', 'friendly', 'formal', 'upbeat'].map(t => `<option ${c.tone === t ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
          <div class="field"><label>link mode</label>
            <select id="c_urlmode">${[['per_recipient', 'one URL per recipient'], ['per_batch', 'one URL per N recipients'], ['single', 'one shared URL']].map(([v, l]) => `<option value="${v}" ${c.url_mode === v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="field" id="batchWrap" style="${c.url_mode === 'per_batch' ? '' : 'display:none'}"><label>batch size</label><input type="number" id="c_batch" value="${c.url_batch_size || 1}" min="1"></div>
        </div>
        <label>link target URL (where {{link}} / /go/ redirects to)</label>
        <input id="c_urltarget" value="${esc(c.url_target)}" placeholder="https://example.com/landing">
      </div>
      <div class="card">
        <h3>Send settings</h3>
        <label>smtp account</label>
        <select id="c_smtpmode" class="mb">
          <option value="rotate" ${c.smtp_mode === 'rotate' ? 'selected' : ''}>rotate pool (least-used, quota-aware)</option>
          <option value="specific" ${c.smtp_mode === 'specific' ? 'selected' : ''}>specific account</option>
        </select>
        <div id="c_acctWrap" style="${c.smtp_mode === 'specific' ? '' : 'display:none'}"><label>account</label>
          <select id="c_acct">${accts || '<option value="0">none</option>'}</select></div>
        <label class="chk mt"><input type="checkbox" id="c_verify"> verify before send (skip invalid, keep risky)</label>
        <label class="chk"><input type="checkbox" id="c_minchk"> require min verifier score</label>
        <input type="number" id="c_minscore" value="70" min="0" max="100" class="mt" style="${$('#c_minchk') ? '' : ''}">
        <label class="chk mt"><input type="checkbox" id="c_unsub"> add List-Unsubscribe header</label>
        <div class="row mt">
          <div class="field"><label>jitter min (s)</label><input type="number" id="c_jmin" value="0" min="0" step="0.5"></div>
          <div class="field"><label>jitter max (s)</label><input type="number" id="c_jmax" value="2" min="0" step="0.5"></div>
          <div class="field"><label>max send (0=all)</label><input type="number" id="c_max" value="0" min="0"></div>
        </div>
        <label class="chk mt"><input type="checkbox" id="c_bg"> run in background (safe for big batches)</label>
        <div class="row mt">
          <button class="primary grow" onclick="campSaveSend(${c.id})">save & send</button>
          <button onclick="campSave(${c.id})">save only</button>
        </div>
        <div id="campSendOut" class="mt"></div>
      </div>
    </div>`;
  $('#c_smtpmode').onchange = () => { $('#c_acctWrap').style.display = $('#c_smtpmode').value === 'specific' ? '' : 'none'; };
  $('#c_urlmode').onchange = () => { $('#batchWrap').style.display = $('#c_urlmode').value === 'per_batch' ? '' : 'none'; };
  const mins = $('#c_minscore'); mins.style.display = $('#c_minchk').checked ? '' : 'none';
  $('#c_minchk').onchange = () => { mins.style.display = $('#c_minchk').checked ? '' : 'none'; };
}
function collectCamp(id) {
  return {
    id,
    name: $('#c_name').value.trim(),
    recipients: $('#c_recips').value,
    subject_base: $('#c_subj').value,
    body_base: $('#c_body').value,
    tone: $('#c_tone').value,
    url_mode: $('#c_urlmode').value,
    url_target: $('#c_urltarget').value.trim(),
    url_batch_size: +$('#c_batch').value || 1,
    smtp_mode: $('#c_smtpmode').value,
    smtp_account_id: +$('#c_acct').value || 0,
  };
}
async function campSave(id) {
  const body = collectCamp(id);
  const r = await api('/api/campaigns.php', body);
  if (r.ok) { toast('saved'); vCamp(); } else toast(r.error || 'save failed', 'err');
}
async function campSaveSend(id) {
  const body = collectCamp(id);
  const r = await api('/api/campaigns.php', body);
  if (!r.ok) return toast(r.error || 'save failed', 'err');
  const opts = {
    id: r.id || id,
    verify: $('#c_verify').checked,
    min_score: $('#c_minchk').checked ? +$('#c_minscore').value : 0,
    jitter_min: +$('#c_jmin').value || 0,
    jitter_max: +$('#c_jmax').value || 0,
    max_send: +$('#c_max').value || 0,
    unsubscribe: $('#c_unsub').checked,
    background: $('#c_bg').checked,
  };
  const out = $('#campSendOut');
  const sr = await api('/api/send.php', opts);
  if (sr.ok && sr.background) {
    out.innerHTML = `<div class="row"><span class="spin"></span> queued — <a href="#" onclick="pollSend(${opts.id});return false">poll status</a></div>`;
    pollSend(opts.id);
    return;
  }
  if (sr.ok) renderSendResult(sr, out);
  else out.innerHTML = `<div class="err">${esc(sr.error || 'send failed')}</div>`;
}
async function campSend(id) {
  const r = await api('/api/campaigns.php?id=' + id, undefined, 'GET');
  const one = (r.campaigns || []).find(c => c.id === id);
  if (!one) return toast('not found', 'err');
  openCampEditor(one);
  setTimeout(() => $('#c_bg') && ($('#c_bg').checked = true), 0);
}
async function pollSend(id) {
  const out = $('#campSendOut'); if (!out) return;
  const r = await get(`/api/send.php?id=${id}`);
  if (r.running || r.queued) {
    out.innerHTML = `<div class="row"><span class="spin"></span> ${r.queued ? 'queued…' : 'sending…'}</div>`;
    setTimeout(() => pollSend(id), 2500);
  } else if (r.result) {
    renderSendResult(r.result, out);
    setView('logs');
  }
}
function renderSendResult(sr, out) {
  const res = (sr.results || []);
  const sent = res.filter(x => x.status === 'sent').length;
  const failed = res.filter(x => x.status === 'failed').length;
  const skipped = res.filter(x => x.status === 'skipped').length;
  out.innerHTML = `
    <div class="row mb wrap">
      <span class="pill">sent <b style="color:var(--ok)">${sr.sent}</b></span>
      <span class="pill">failed <b style="color:var(--bad)">${sr.failed}</b></span>
      <span class="pill">skipped <b style="color:var(--warn)">${sr.skipped}</b></span>
      <span class="pill">status ${badge(sr.status)}</span>
      ${sr.truncated ? `<span class="pill dim">+${sr.truncated} more in logs</span>` : ''}
    </div>
    ${res.length ? `<div class="tablewrap" style="max-height:300px;overflow:auto"><table><thead><tr><th>recip</th><th>status</th><th>acct</th><th>score</th><th></th></tr></thead><tbody>
      ${res.slice(0, 200).map(x => `<tr><td class="mono">${esc(x.email)}</td><td>${badge(x.status)}${x.reason && x.status !== 'sent' ? ` <span class="dim small">${esc(x.reason)}</span>` : ''}</td><td class="dim small">${esc(x.account || '')}</td><td class="dim">${x.score || '—'}</td><td class="right"><a class="small" href="${esc(x.link)}" target="_blank">link</a></td></tr>`).join('')}
    </tbody></table></div>` : ''}`;
}
async function campDel(id) {
  if (!confirm('Delete campaign #' + id + ' and its links/logs?')) return;
  const r = await api('/api/campaigns.php', { id });
  toast(r.ok ? 'deleted' : r.error, r.ok ? 'ok' : 'err'); vCamp();
}

// ============================================================
//  COMPOSE (preview)
// ============================================================
async function vCompose() {
  $('#view').innerHTML = `
    <div class="card">
      <h3>Preview the composer</h3>
      <p class="dim small">See up to 5 unique compositions before sending. The AI path (if a key is set) rewrites each email; otherwise the offline variation engine does it. Tokens: <span class="mono">{{link}} {{first_name}} {{name}} {{org}}</span>.</p>
      <label>subject base</label><input id="p_subj" value="Quick note" placeholder="Quick note">
      <label>body base</label><textarea id="p_body" rows="7">Hi {{first_name}},\n\nI wanted to share something useful with you.\n\n{{link}}\n\nThanks,</textarea>
      <label>recipients (one per line, optional |first|org)</label><textarea id="p_recips" rows="5">john@corp.com|John\nboss@acme.io|Jane|Acme\nsam@example.org</textarea>
      <div class="row mt wrap">
        <div class="field"><label>tone</label><select id="p_tone">${['neutral', 'friendly', 'formal', 'upbeat'].map(t => `<option>${t}</option>`).join('')}</select></div>
        <div class="field"><label>link target</label><input id="p_url" value="https://example.com/landing"></div>
      </div>
      <button class="primary mt" onclick="composePreview()">preview 5 variations</button>
      <div id="p_out" class="mt"></div>
    </div>`;
  window.composePreview = async () => {
    const out = $('#p_out'); out.innerHTML = `<div class="dim"><span class="spin"></span> composing…</div>`;
    const r = await api('/api/compose.php', {
      subject_base: $('#p_subj').value, body_base: $('#p_body').value,
      tone: $('#p_tone').value, recipients: $('#p_recips').value, url_target: $('#p_url').value,
    });
    if (!r.ok) { out.innerHTML = `<div class="err">${esc(r.error || 'failed')}</div>`; return; }
    out.innerHTML = `
      <div class="row mb"><span class="pill">engine: ${esc(r.engine)}</span><span class="pill">provider: ${esc(r.provider)}</span></div>
      <div class="grid cols-1">
      ${r.samples.map((s, i) => `<div class="card"><div class="row mb"><b>to:</b> <span class="mono dim">${esc(s.recipient)}</span><span class="pill">${esc(s.engine)}</span></div>
        <div class="preview"><span class="subj">Subject: ${esc(s.subject)}</span>\n\n${esc(s.body)}</div></div>`).join('')}
      </div>`;
  };
}

// ============================================================
//  VERIFIER
// ============================================================
async function vVerify() {
  $('#view').innerHTML = `
    <div class="grid cols-2">
      <div class="card">
        <h3>Check / verify emails</h3>
        <textarea id="v_in" rows="9" placeholder="one email per line (or comma/space separated)"></textarea>
        <div class="row mt wrap">
          <label class="chk"><input type="checkbox" id="v_deep" checked> deep (MX/DNS + disposable/role)</label>
          <label class="chk"><input type="checkbox" id="v_probe"> SMTP RCPT probe (live, needs pool)</label>
        </div>
        <button class="primary mt" onclick="doVerify()">verify</button>
        <div id="v_out" class="mt"></div>
      </div>
      <div class="card">
        <h3>How scoring works</h3>
        <ul class="dim small" style="padding-left:18px;line-height:1.8">
          <li><b>0</b> — fails syntax</li>
          <li><b>5</b> — domain NXDOMAIN / no mail route</li>
          <li><b>40</b> — syntax ok, unverified (no deep)</li>
          <li><b>80</b> — valid: domain + MX + IP + personal</li>
          <li><b>≤40</b> — disposable throwaway domain</li>
          <li><b>risky</b> — role address (info@, sales@…)</li>
          <li><b>98</b> — SMTP RCPT probe accepted the address</li>
          <li><b>10</b> — SMTP RCPT probe rejected it</li>
        </ul>
      </div>
    </div>`;
  window.doVerify = async () => {
    const out = $('#v_out'); out.innerHTML = `<div class="dim"><span class="spin"></span> verifying…</div>`;
    const r = await api('/api/verify.php', { emails: $('#v_in').value, deep: $('#v_deep').checked, smtp_probe: $('#v_probe').checked });
    if (!r.ok) { out.innerHTML = `<div class="err">${esc(r.error || 'failed')}</div>`; return; }
    const t = r.totals || {};
    out.innerHTML = `
      <div class="row mb wrap">
        <span class="pill">total <b>${r.count}</b></span>
        <span class="pill">valid <b style="color:var(--ok)">${t.valid || 0}</b></span>
        <span class="pill">risky <b style="color:var(--warn)">${t.risky || 0}</b></span>
        <span class="pill">invalid <b style="color:var(--bad)">${t.invalid || 0}</b></span>
        <span class="pill">unknown <b>${t.unknown || 0}</b></span>
      </div>
      <div class="tablewrap" style="max-height:420px;overflow:auto"><table><thead><tr><th>email</th><th>result</th><th>score</th><th>detail</th></tr></thead><tbody>
      ${r.results.map(x => `<tr><td class="mono">${esc(x.email)}</td><td>${badge(x.result)}</td><td>${x.score}</td><td class="dim small">${esc(x.detail || '')}</td></tr>`).join('')}
      </tbody></table></div>`;
  };
}

// ============================================================
//  SMTP POOL
// ============================================================
let smtpCache = [];
async function vSmtp() {
  const r = await get('/api/smtp.php');
  if (!r.ok) return fail('#view', r);
  smtpCache = r.accounts || [];
  renderSmtp();
}
function renderSmtp() {
  const rows = smtpCache;
  $('#view').innerHTML = `
    <div class="row mb"><button class="primary" onclick="smtpNew()">+ Add SMTP account</button>
      <span class="dim small">${rows.filter(a => a.active).length} active / ${rows.length} total</span></div>
    <div id="smtpList"></div>`;
  if (!rows.length) { $('#smtpList').innerHTML = `<div class="card dim">No SMTP accounts. Add at least one to send.</div>`; return; }
  $('#smtpList').innerHTML = `<div class="tablewrap"><table><thead><tr>
    <th>name</th><th>host:port</th><th>from</th><th>tls</th><th>quota/day</th><th>sent today</th><th>status</th><th>last error</th><th></th>
  </tr></thead><tbody>
  ${rows.map(a => `<tr>
    <td>${esc(a.name) || '<span class="dim">—</span>'}</td>
    <td class="mono small">${esc(a.host)}:${a.port}</td>
    <td class="mono small">${esc(a.from_addr)}</td>
    <td class="dim">${a.use_tls ? 'on' : 'off'}</td>
    <td class="dim">${a.daily_quota}</td>
    <td class="dim">${a.sent_today ?? 0}</td>
    <td>${a.active ? badge('active') : badge('draft', 'off')}</td>
    <td class="dim small" title="${esc(a.last_error)}">${esc((a.last_error || '—').slice(0, 40))}</td>
    <td class="right">
      <button class="ghost" onclick="smtpEdit(${a.id})">edit</button>
      <button class="ghost" onclick="smtpTest(${a.id})">test</button>
      <button class="ghost danger" onclick="smtpDel(${a.id})">del</button>
    </td>
  </tr>`).join('')}
  </tbody></table></div>`;
  window.smtpNew = smtpNew; window.smtpEdit = smtpEdit; window.smtpTest = smtpTest; window.smtpDel = smtpDel;
}
function smtpForm(a) {
  const isEdit = a && a.id;
  $('#view').innerHTML = `
    <div class="row mb"><button class="ghost" onclick="vSmtp()">← back</button></div>
    <div class="card" style="max-width:640px">
      <h3>${isEdit ? 'Edit' : 'Add'} SMTP account</h3>
      <label>name (label)</label><input id="s_name" value="${esc(a?.name || '')}" placeholder="mail1">
      <div class="row">
        <div class="field grow"><label>host</label><input id="s_host" value="${esc(a?.host || '')}" placeholder="smtp.provider.com"></div>
        <div class="field"><label>port</label><input type="number" id="s_port" value="${a?.port || 587}"></div>
      </div>
      <div class="row">
        <div class="field grow"><label>username</label><input id="s_user" value="${esc(a?.username || '')}"></div>
        <div class="field grow"><label>password</label><input id="s_pass" type="password" placeholder="${isEdit ? 'leave blank to keep' : ''}" value=""></div>
      </div>
      <div class="row">
        <div class="field grow"><label>from address</label><input id="s_from" value="${esc(a?.from_addr || '')}" placeholder="you@domain.com"></div>
        <div class="field grow"><label>from name</label><input id="s_fromname" value="${esc(a?.from_name || '')}" placeholder="Your Name"></div>
      </div>
      <div class="row">
        <div class="field"><label>daily quota</label><input type="number" id="s_quota" value="${a?.daily_quota || 500}" min="0"></div>
        <div class="field"><label>tls</label><select id="s_tls"><option value="1" ${a?.use_tls ? 'selected' : ''}>STARTTLS on</option><option value="0" ${!a?.use_tls ? 'selected' : ''}>off</option></select></div>
        <div class="field"><label>active</label><select id="s_active"><option value="1" ${a?.active !== 0 ? 'selected' : ''}>yes</option><option value="0" ${a?.active === 0 ? 'selected' : ''}>no</option></select></div>
      </div>
      <div class="row mt">
        <button class="primary" onclick="smtpSave(${isEdit ? a.id : 0})">save</button>
        ${isEdit ? `<button onclick="smtpTestId(${a.id})">test connection</button>` : ''}
      </div>
      <div id="s_out" class="mt"></div>
    </div>`;
}
function smtpNew() { smtpForm(null); }
function smtpEdit(id) { smtpForm(smtpCache.find(a => a.id === id)); }
async function smtpSave(id) {
  const body = {
    id, name: $('#s_name').value.trim(), host: $('#s_host').value.trim(), port: +$('#s_port').value,
    username: $('#s_user').value, password: $('#s_pass').value,
    from_addr: $('#s_from').value.trim(), from_name: $('#s_fromname').value,
    daily_quota: +$('#s_quota').value, use_tls: +$('#s_tls').value, active: +$('#s_active').value,
  };
  if (body.password === '' && !id) body.password = '';
  const r = await api('/api/smtp.php', body, id ? 'PUT' : 'POST');
  if (r.ok) { toast('saved'); vSmtp(); } else $('#s_out').innerHTML = `<div class="err">${esc(r.error || 'failed')}</div>`;
}
async function smtpTest(id) {
  const a = smtpCache.find(x => x.id === id); if (!a) return;
  const r = await api('/api/smtp.php', { id, test: true });
  toast(r.ok ? ('connected: ' + (a.host || a.name)) : ('fail: ' + (r.error || '?')), r.ok ? 'ok' : 'err');
  vSmtp();
}
function smtpTestId(id) { smtpTest(id); }
async function smtpDel(id) {
  if (!confirm('Delete this SMTP account?')) return;
  await api('/api/smtp.php', { id }, 'DELETE'); toast('deleted'); vSmtp();
}

// ============================================================
//  LINK URLS
// ============================================================
const linkUrl = (base, x) => (base || '') + '/go.php?token=' + x.token;
async function vLinks() {
  const r = await get('/api/logs.php', { type: 'urls', limit: 200 });
  if (!r.ok) return fail('#view', r);
  const base = r.base || '';
  const rows = r.rows || [];
  const totalHits = rows.reduce((n, x) => n + (x.hits || 0), 0);
  $('#view').innerHTML = `
    ${base === '' ? `<div class="card warn mb">Public base URL isn't set — link URLs would be relative. Set it in <b>Settings → App</b> (e.g. <span class="mono">${esc(location.origin)}</span>) so link URLs in emails are absolute.</div>` : ``}
    <div class="row mb"><span class="pill">links <b>${rows.length}</b></span><span class="pill">hits <b>${totalHits}</b></span></div>
    ${rows.length ? `<div class="tablewrap"><table><thead><tr><th>url</th><th>target</th><th>campaign</th><th>batch</th><th>hits</th><th>created</th></tr></thead><tbody>
      ${rows.map(x => `<tr>
        <td class="mono small"><a href="${esc(linkUrl(base, x))}" target="_blank">${esc(linkUrl(base, x))}</a>
          <button class="ghost small" style="margin-left:6px" onclick='copy(${JSON.stringify(linkUrl(base, x)).replace(/'/g, "&apos;")},this)'>copy</button></td>
        <td class="dim small">${esc(x.target)}</td>
        <td class="dim">#${x.campaign_id}</td><td class="dim">${x.batch}</td>
        <td>${x.hits || 0}</td><td class="dim small">${timeAgo(x.created_at)}</td></tr>`).join('')}
    </tbody></table></div>` : `<div class="card dim">No link tokens yet. They're created when you run a campaign.</div>`}`;
}

// ============================================================
//  LOGS
// ============================================================
async function vLogs() {
  $('#view').innerHTML = `
    <div class="tabs">
      <button class="active" onclick="logTab('send',this)">send log</button>
      <button onclick="logTab('verify',this)">verify log</button>
      <button onclick="logTab('urls',this)">link hits</button>
    </div>
    <div id="logOut"></div>`;
  window.logTab = (t, btn) => {
    document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    logLoad(t);
  };
  logLoad('send');
}
async function logLoad(type) {
  const out = $('#logOut'); if (!out) return;
  out.innerHTML = `<div class="dim"><span class="spin"></span> loading…</div>`;
  const r = await get('/api/logs.php', { type, limit: 300 });
  if (!r.ok) { out.innerHTML = `<div class="err">${esc(r.error || 'failed')}</div>`; return; }
  if (type === 'send') {
    out.innerHTML = `${r.total} sends
      <div class="tablewrap mt" style="max-height:520px;overflow:auto"><table><thead><tr><th>time</th><th>campaign</th><th>recip</th><th>status</th><th>score</th><th>reason / msgid</th><th>subject</th></tr></thead><tbody>
      ${r.rows.map(s => `<tr><td class="dim small">${timeAgo(s.created_at)}</td><td class="dim">#${s.campaign_id}</td><td class="mono small">${esc(s.recipient)}</td><td>${badge(s.status)}</td><td class="dim">${s.score || '—'}</td><td class="dim small">${esc(s.reason || '')}${s.smtp_msgid ? ' <span class="mono">' + esc(s.smtp_msgid) + '</span>' : ''}</td><td class="dim small">${esc((s.subject || '').slice(0, 40))}</td></tr>`).join('')}
      </tbody></table></div>`;
  } else if (type === 'verify') {
    out.innerHTML = `<div class="tablewrap" style="max-height:520px;overflow:auto"><table><thead><tr><th>time</th><th>email</th><th>result</th><th>detail</th></tr></thead><tbody>
      ${r.rows.map(v => `<tr><td class="dim small">${timeAgo(v.created_at)}</td><td class="mono small">${esc(v.email)}</td><td>${badge(v.result)}</td><td class="dim small">${esc(v.detail || '')}</td></tr>`).join('')}
      </tbody></table></div>`;
  } else {
    const base = r.base || '';
    out.innerHTML = `<div class="tablewrap" style="max-height:520px;overflow:auto"><table><thead><tr><th>url</th><th>target</th><th>campaign</th><th>hits</th></tr></thead><tbody>
      ${r.rows.map(x => `<tr><td class="mono small">${esc(base + '/go/' + x.token)}</td><td class="dim small">${esc(x.target)}</td><td class="dim">#${x.campaign_id}</td><td>${x.hits || 0}</td></tr>`).join('')}
      </tbody></table></div>`;
  }
}

// ============================================================
//  SETTINGS
// ============================================================
async function vSettings() {
  const r = await get('/api/settings.php');
  if (!r.ok) return fail('#view', r);
  const s = r.settings || {};
  $('#view').innerHTML = `
    <div class="grid cols-2">
      <div class="card">
        <h3>AI composer (LLM)</h3>
        <p class="dim small">If no key is set, the offline variation engine is used (always works, fully unique). Any OpenAI-compatible endpoint is supported.</p>
        <label>provider</label>
        <select id="st_prov">${['openai', 'openrouter', 'anthropic', 'custom', 'off'].map(p => `<option ${s.llm_provider === p ? 'selected' : ''}>${p}</option>`).join('')}</select>
        <label>api key (blank keeps current)</label><input id="st_key" type="password" placeholder="${s.llm_api_key ? '(set)' : 'sk-...'}" value="">
        <label>base url</label><input id="st_base" value="${esc(s.llm_base_url || '')}" placeholder="https://api.openai.com/v1">
        <label>model</label><input id="st_model" value="${esc(s.llm_model || '')}" placeholder="gpt-4o-mini">
        <button class="primary mt" onclick="saveSettings()">save</button>
      </div>
      <div class="card">
        <h3>App</h3>
        <label>public base URL (for generating link URLs)</label>
        <input id="st_appbase" value="${esc(s.app_base || '')}" placeholder="https://your-domain.com">
        <div class="row mt">
          <button class="ghost" onclick="saveSettings()">save base url</button>
          ${s.admin_from_env
            ? `<span class="pill">admin token: <b>set via env</b> (${esc(s.admin_token || '')})</span>`
            : `<span class="pill">admin token: <b class="mono">${esc(s.admin_token || '(none — open)')}</b>
                <button class="ghost small" onclick="rotateToken()">rotate</button></span>`}
        </div>
        <label class="chk mt"><span>telegram alerts: <b>${s.tg_bot_token ? 'configured' : 'not set'}</b> (env TG_BOT_TOKEN / TG_CHAT_ID)</span></label>
        <div class="dim small mt">data dir: <span class="mono">${esc(s.data_dir || '')}</span></div>
      </div>
    </div>`;
  window.saveSettings = async () => {
    const body = { llm_provider: $('#st_prov')?.value, llm_base_url: $('#st_base')?.value, llm_model: $('#st_model')?.value, app_base: $('#st_appbase')?.value };
    if ($('#st_key')?.value) body.llm_api_key = $('#st_key').value;
    const r2 = await api('/api/settings.php', body);
    toast(r2.ok ? 'settings saved' : (r2.error || 'failed'), r2.ok ? 'ok' : 'err');
  };
  window.rotateToken = async () => {
    if (!confirm('Generate a NEW admin token? The current one stops working immediately.')) return;
    const r2 = await api('/api/settings.php', { admin_token: 'generate' });
    if (r2.ok && r2.admin_token) {
      token = r2.admin_token; localStorage.setItem('mf_token', token);
      toast('new token: ' + r2.admin_token + ' (saved to this browser)');
      vSettings();
    } else toast(r2.error || 'failed', 'err');
  };
}

// ---------- init ----------
document.addEventListener('DOMContentLoaded', () => {
  $('#tokenBtn').onclick = submitToken;
  $('#tokenInput').addEventListener('keydown', e => { if (e.key === 'Enter') submitToken(); });
  if (token) {
    get('/api/dashboard.php').then(r => r.ok ? (hideGate(), boot()) : showGate()).catch(showGate);
  } else showGate();
});
