import { createWorld, runScenario, redeliver, summarise, validateInput, SCENARIOS, SEED_INPUT, CLIENT_CONFIG } from './engine.js';
import { Msisdn, TransactionType, base64, base64Decode } from './daraja.js';
import { SNIPPETS, QUICKSTART } from './snippets.js';

const STORAGE_KEY = 'daraja-playground-v1';
const REPO = 'https://github.com/Victor-Wambua/daraja-php';
const $ = (id) => document.getElementById(id);
const FIELDS = ['phone', 'amount', 'accountReference', 'transactionDesc', 'callbackUrl', 'partyB'];

const SHOWS = {
  accept: 'The whole happy path: a token, the push, the callback, and an order that is paid once. Note how the Password is built from the short code, the passkey and the East Africa Time stamp.',
  cancel: 'A failed payment is still a callback. The handler records the outcome. There is no receipt, because no money moved.',
  insufficient: 'A failed payment is still a callback. The library maps result code 1 to InsufficientFunds, and your code decides what to tell the customer.',
  wrong_pin: 'A failed payment is still a callback. Result code 2001 maps to WrongPin. The description is the mock’s wording, not necessarily Safaricom’s.',
  timeout: 'Silence is also an outcome. After the timeout the mock sends result code 1037, and the order closes as timed out instead of waiting forever.',
  duplicate: 'Both deliveries get HTTP 200, so Daraja stops retrying. Only the first one runs your handler: the second finds its key already completed.',
  out_of_order: 'A callback for a request your app does not know yet is rejected with HTTP 422, not dropped. The mock retries, and once the id is saved the retry is handled.',
  stale_cancel: 'The idempotency key is the CheckoutRequestID, so a contradictory later callback for the same request is acknowledged and ignored. The order stays paid.',
  tampered: 'The amount in the callback does not match the order, so the handler never runs and the order stays pending. Every retry is rejected the same way. Settling it would need an STK query.',
  lost: 'Without a callback, the STK query is the fallback. While the customer has not answered, Daraja replies with an error that the library turns into pending: true instead of throwing.',
};

const STAGE_LABELS = {
  source: 'Check the source IP',
  parse: 'Parse the body strictly',
  lookup: 'Find the order by CheckoutRequestID',
  crosscheck: 'Cross-check amount and phone',
  claim: 'Claim the idempotency key',
  handler: 'Run your handler',
};
const STAGE_WORDS = { pass: 'Passed', fail: 'Failed here', stop: 'Stopped here', skip: 'Skipped', none: 'Not reached' };
const HTTP_TEXT = { 200: 'OK', 400: 'Bad Request', 401: 'Unauthorized', 404: 'Not Found', 405: 'Method Not Allowed', 422: 'Unprocessable Entity', 500: 'Internal Server Error' };

const state = {
  world: createWorld(),
  run: null,
  scenario: 'accept',
  compact: false,
  curls: new Map(),
};

// ------------------------------------------------------------------ helpers

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

function loadSaved() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function save() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ input: readForm(), scenario: state.scenario, compact: state.compact }));
  } catch { /* storage blocked: the page still works */ }
}

function clearSaved() {
  try { localStorage.removeItem(STORAGE_KEY); } catch { /* ignore */ }
}

/** Re-indent JSON text without touching its tokens, so 150.0 stays 150.0. */
function prettyJson(text) {
  let out = '';
  let depth = 0;
  let inString = false;
  const nl = () => '\n' + '  '.repeat(depth);
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inString) {
      out += c;
      if (c === '\\') out += text[++i];
      else if (c === '"') inString = false;
      continue;
    }
    if (c === '"') { inString = true; out += c; } else if (c === '{' || c === '[') {
      const close = c === '{' ? '}' : ']';
      if (text[i + 1] === close) { out += c + close; i++; } else { depth++; out += c + nl(); }
    } else if (c === '}' || c === ']') { depth--; out += nl() + c; } else if (c === ',') out += ',' + nl();
    else if (c === ':') out += ': ';
    else out += c;
  }
  return out;
}

function fmtWhen(at) {
  if (at < 0.05) return '';
  return `<span class="when">+${at < 10 ? at.toFixed(1) : Math.round(at)} s, simulated</span>`;
}

function curlFor(req) {
  const q = (s) => `'${s.replace(/'/g, `'\\''`)}'`;
  const isOauth = new URL(req.url).pathname === '/oauth/v1/generate';
  const lines = [];
  if (!isOauth) {
    // A real daraja-mock signs its tokens with a key it makes up at start-up, so fetch a fresh one.
    const base = new URL(req.url).origin;
    const basic = base64(`${CLIENT_CONFIG.consumerKey}:${CLIENT_CONFIG.consumerSecret}`);
    lines.push(`TOKEN=$(curl -s ${q(`${base}/oauth/v1/generate?grant_type=client_credentials`)} -H ${q(`Authorization: Basic ${basic}`)} | sed -E 's/.*"access_token":"([^"]+)".*/\\1/')`, '');
  }
  const parts = [`curl -X ${req.method} ${q(req.url)}`];
  for (const [k, v] of req.headers) {
    if (k.toLowerCase() === 'host') continue;
    parts.push(!isOauth && k === 'Authorization' ? `-H "Authorization: Bearer $TOKEN"` : `-H ${q(`${k}: ${v}`)}`);
  }
  if (req.body) parts.push(`-d ${q(req.body)}`);
  lines.push(parts.join(' \\\n  '));
  return lines.join('\n');
}

// ------------------------------------------------------------------ http blocks

function bodyHtml(body, marks) {
  let html = esc(state.compact ? body : prettyJson(body));
  for (const m of marks) if (m) html = html.split(esc(m)).join(`<mark>${esc(m)}</mark>`);
  return html;
}

function requestBlock(req, { marks = [], copy = false } = {}) {
  const url = new URL(req.url);
  const host = req.headers.find(([k]) => k === 'Host')?.[1];
  const headers = [...(host ? [['Host', host]] : []), ...req.headers.filter(([k]) => k !== 'Host')];
  let id = '';
  if (copy) {
    id = `curl-${state.curls.size}`;
    state.curls.set(id, curlFor(req));
  }
  const head = `<span class="m">${esc(req.method)}</span> ${esc(url.pathname + url.search)}`;
  const hdrs = headers.map(([k, v]) => `<span class="hn">${esc(k)}:</span> ${esc(v)}`).join('\n');
  const body = req.body ? '\n\n' + bodyHtml(req.body, marks) : '';
  return `<div class="msg"><div class="msg-head"><span>Request</span>${copy ? `<button type="button" class="copy" data-curl="${id}">Copy as curl</button>` : ''}</div><pre tabindex="0" aria-label="HTTP request">${head}\n${hdrs}${body}</pre></div>`;
}

function responseBlock(res, { marks = [] } = {}) {
  const ct = res.headers?.find(([k]) => k === 'Content-Type')?.[1];
  const head = `<span class="m">HTTP ${res.status}</span> ${esc(HTTP_TEXT[res.status] ?? '')}`;
  const hdr = ct ? `\n<span class="hn">Content-Type:</span> ${esc(ct)}` : '';
  const body = res.body ? '\n\n' + bodyHtml(res.body, marks) : '';
  return `<div class="msg"><div class="msg-head"><span>Response</span></div><pre tabindex="0" aria-label="HTTP response">${head}${hdr}${body}</pre></div>`;
}

function derivationBlock(d) {
  return `<div class="derive"><h5>How Timestamp and Password are built</h5><dl>
    <div><dt>Clock</dt><dd><code>${esc(d.utc)}</code></dd></div>
    <div><dt>In Nairobi</dt><dd><code>${esc(d.eat)}</code>, UTC+3. Formatted as <code>YmdHis</code>: <mark>${esc(d.timestamp)}</mark></dd></div>
    <div><dt>Short code</dt><dd><code>${esc(d.shortCode)}</code></dd></div>
    <div><dt>Passkey</dt><dd><code>${esc(d.passKey)}</code> (fake: the mock's built-in value)</dd></div>
    <div><dt>Joined</dt><dd><code>${esc(d.shortCode + d.passKey + d.timestamp)}</code></dd></div>
    <div><dt>Password</dt><dd>base64 of the joined text: <mark>${esc(d.password)}</mark></dd></div>
  </dl></div>`;
}

// ------------------------------------------------------------------ trace

function renderEvent(e) {
  const when = fmtWhen(e.at);
  const simple = (kind, title, meta, text, extra = '') =>
    `<li class="step step--${kind}"><div class="step-head"><h4>${esc(title)}</h4><span class="meta">${esc(meta)}</span>${when}</div>${text ? `<p>${esc(text)}</p>` : ''}${extra}</li>`;

  switch (e.kind) {
    case 'order':
      return simple('app', e.title, 'Your app', `Order ${e.order.ref} for KES ${e.order.amountKes.toLocaleString('en-US')} to ${e.order.phone} starts as pending.`);
    case 'token-cached':
      return simple('app', e.title, 'Your client', `The client keeps the access token it fetched earlier, with about ${Math.floor(e.remaining / 60)} minutes left, so there is no OAuth request this time.`);
    case 'app':
      return simple('app', e.title, 'Your app', e.text);
    case 'phone':
      return simple('phone', e.title, 'Mock Daraja and the phone', e.text);
    case 'time':
      return simple('time', e.title, 'Time passes', e.text);
    case 'note':
      return simple('note', e.title, 'Demo only', e.text);
    case 'client-error':
      return simple('client-error', e.title, 'Your client', '', `<p><code>${esc(e.where)}</code> throws <code>${esc(e.error.type)}</code>: ${esc(e.error.message)}</p><p class="note">Nothing was sent to Daraja.</p>`);
    case 'http':
      return renderHttp(e);
    case 'delivery':
      return renderDelivery(e);
    default:
      return '';
  }
}

function renderHttp(e) {
  const marks = e.derivation ? [e.derivation.password, e.derivation.timestamp] : [];
  let extra = '';
  let text = '';
  if (e.label === 'OAuth token') {
    const auth = e.request.headers.find(([k]) => k === 'Authorization')?.[1] ?? '';
    const decoded = base64Decode(auth.replace(/^Basic /, ''));
    text = 'A GET with HTTP Basic credentials. The client does this once and caches the token.';
    extra = `<p class="note">The Authorization header is <code>Basic</code> plus base64 of <code>${esc(decoded ?? '')}</code>. Those are the mock's fake development credentials, not secrets.</p>`;
  } else if (e.label === 'STK Push') {
    text = 'A POST with the bearer token. The body is what the client builds from your request; the phone number is already normalised.';
  } else {
    text = 'The same Password derivation with a fresh timestamp. The library treats Daraja’s "still processing" error as pending, not as a failure.';
  }
  let after = '';
  if (e.label === 'OAuth token') {
    after = '<p class="note">The access_token is the mock’s own signed token, computed in this page. The client caches it for expires_in minus a 60 second safety margin.</p>';
  }
  if (e.label === 'STK query' && e.result) {
    const r = e.result;
    after = `<p class="verdict ${r.pending ? 'wait' : r.isSuccessful ? 'ok' : 'bad'}">${r.pending ? 'Result: pending, the customer has not answered.' : `Result: code ${r.resultCode}, ${r.outcome.replace('_', ' ')}.`}</p>`;
  }
  const when = fmtWhen(e.at);
  return `<li class="step step--http"><div class="step-head"><h4>${esc(e.title)}</h4><span class="meta">Your client to the mock</span>${when}</div><p>${esc(text)}</p>${requestBlock(e.request, { marks, copy: true })}${extra}${e.derivation ? derivationBlock(e.derivation) : ''}${responseBlock(e.response, { marks: [] })}${after}</li>`;
}

function renderDelivery(e) {
  const byId = new Map(e.stages.map((s) => [s.id, s]));
  const rows = Object.keys(STAGE_LABELS).map((id) => {
    const s = byId.get(id) ?? { status: 'none', detail: '' };
    return `<li class="s-${s.status}"><span class="st">${STAGE_WORDS[s.status]}</span><span><span class="nm">${esc(STAGE_LABELS[id])}</span>${s.detail ? `<span class="dt">${esc(s.detail)}</span>` : ''}</span></li>`;
  }).join('');

  const verdicts = {
    handled: ['ok', 'Handled. Your handler ran once.'],
    duplicate: ['ok', 'Duplicate. Acknowledged with HTTP 200 and ignored, so Daraja stops retrying.'],
    in_progress: ['wait', 'Another worker holds this key. Acknowledged and not processed.'],
    rejected: ['bad', `Rejected. ${e.error?.message ?? ''}`],
    invalid: ['bad', `Not a valid callback. ${e.error?.message ?? ''}`],
    error: ['bad', `Your handler failed. ${e.error?.message ?? ''}`],
  };
  const [tone, verdict] = verdicts[e.outcome] ?? ['wait', e.outcome];
  const ok = e.response.status >= 200 && e.response.status < 300;
  let retry;
  if (ok) retry = 'A 2xx answer, so the mock marks the delivery done and stops retrying.';
  else if (e.retryInSeconds !== null) retry = `Not a 2xx answer, so the mock retries in ${e.retryInSeconds} s (attempt ${e.attempt + 1} of ${e.maxAttempts}).`;
  else retry = `Not a 2xx answer, and attempt ${e.attempt} of ${e.maxAttempts} was the last. The mock gives up. Your app would have to reconcile with an STK query.`;

  const req = requestBlock(e.request, {});
  const reqHtml = e.attempt === 1 ? req : `<details class="msg"><summary>Request (same body)</summary>${req.replace('<div class="msg">', '<div>')}</details>`;
  const kind = tone === 'bad' ? 'delivery step--rejected' : 'delivery';
  const meta = e.maxAttempts ? `Mock to your app, attempt ${e.attempt} of ${e.maxAttempts}` : 'Mock to your app';
  return `<li class="step step--${kind}"><div class="step-head"><h4>${esc(e.title)}</h4><span class="meta">${esc(meta)}</span>${fmtWhen(e.at)}</div>${reqHtml}<ol class="stages" aria-label="What CallbackProcessor did">${rows}</ol><p class="verdict ${tone}">${esc(verdict)}</p><p>Your endpoint answers <strong>HTTP ${e.response.status}</strong> <code>${esc(e.response.body)}</code>. ${esc(retry)}</p></li>`;
}

// ------------------------------------------------------------------ result panel

function toneFor(key) {
  if (key === 'paid') return 'ok';
  if (key === 'pending') return 'wait';
  if (key === 'not_sent') return 'none';
  return 'bad';
}

function renderResult(run) {
  const sum = summarise(run);
  const ref = run.input.accountReference || 'order';
  const outcomes = sum.outcomes?.length ? ` (${sum.outcomes.join(', ')})` : '';
  const ack = sum.lastAck ? `HTTP ${sum.lastAck.status} <code>${esc(sum.lastAck.body)}</code>` : 'No callback was delivered';
  let receipt = sum.receipt ? `<code>${esc(sum.receipt)}</code>` : 'None';
  if (!sum.receipt && sum.paidVia === 'stk_query') receipt = 'None. An STK query answer carries no receipt number.';
  const facts = sum.sent
    ? `<dl class="facts">
        <div><dt>Your handler ran</dt><dd>${plural(sum.handlerRuns, 'time', 'times')}</dd></div>
        <div><dt>Callbacks received</dt><dd>${sum.deliveries}${esc(outcomes)}</dd></div>
        <div><dt>Receipt</dt><dd>${receipt}</dd></div>
        ${sum.paidVia ? `<div><dt>Settled by</dt><dd>${sum.paidVia === 'callback' ? 'The callback' : 'An STK query'}</dd></div>` : ''}
        <div><dt>Last reply to Daraja</dt><dd>${ack}</dd></div>
      </dl>`
    : '';
  const rows = sum.store.map((r) => `<tr><td><code>${esc(r.key)}</code></td><td>${esc(r.state)}</td></tr>`).join('');
  const store = `<div class="store"><h4>Idempotency store, kept between runs</h4>${rows ? `<table><thead><tr><th scope="col">Key</th><th scope="col">State</th></tr></thead><tbody>${rows}</tbody></table>` : '<p class="small">Empty.</p>'}</div>`;
  const shows = run.clientError ? '' : `<p class="shows"><strong>What this shows.</strong> ${esc(SHOWS[run.scenario.id])}</p>`;
  $('result').innerHTML = `<p class="status tone-${toneFor(sum.statusKey)}"><span class="of">Order ${esc(ref)}</span><strong>${esc(sum.statusLabel)}</strong></p>${sum.detail ? `<p class="detail">${esc(sum.detail)}</p>` : ''}${facts}${shows}${store}`;

  const said = sum.sent
    ? `Order ${ref}: ${sum.statusLabel}. Your handler ran ${plural(sum.handlerRuns, 'time', 'times')}.`
    : `Not sent. ${sum.detail}`;
  $('announce').textContent = said;
  document.title = `${sum.statusLabel} · daraja-php playground`;
}

function render(run) {
  state.curls.clear();
  state.run = run;
  renderResult(run);
  $('trace').innerHTML = run.events.map(renderEvent).join('');
  const last = run.deliveries.at(-1);
  $('redeliver').disabled = !last || last.status !== 'delivered';
}

// ------------------------------------------------------------------ form

function readForm() {
  const v = {};
  for (const id of FIELDS) v[id] = $(id).value;
  v.type = document.querySelector('input[name="type"]:checked')?.value ?? TransactionType.PayBill;
  return v;
}

function writeForm(input) {
  for (const id of FIELDS) $(id).value = input[id] ?? '';
  for (const r of document.querySelectorAll('input[name="type"]')) r.checked = r.value === input.type;
  syncType();
  syncPhoneHint();
}

function syncType() {
  const till = document.querySelector('input[name="type"]:checked')?.value === TransactionType.BuyGoods;
  $('partyB-field').hidden = !till;
}

function syncPhoneHint() {
  const n = Msisdn.tryParse($('phone').value);
  $('phone-norm').textContent = n ?? 'nothing yet (not a Kenyan mobile number)';
}

function showError(id, message) {
  const input = $(id);
  const err = $(`${id}-err`);
  if (message) {
    err.textContent = message;
    err.hidden = false;
    input.setAttribute('aria-invalid', 'true');
  } else {
    err.hidden = true;
    err.textContent = '';
    input.removeAttribute('aria-invalid');
  }
}

function validateAll() {
  const errors = validateInput(readForm());
  for (const id of FIELDS) showError(id, errors[id] ?? '');
  return errors;
}

function renderScenarios() {
  const groups = [...new Set(SCENARIOS.map((s) => s.group))];
  $('scenarios').innerHTML = groups.map((g) => `<fieldset class="groupbox"><legend>${esc(g)}</legend>${SCENARIOS.filter((s) => s.group === g).map((s) => `
    <label class="scenario"><input type="radio" name="scenario" value="${s.id}"><span><span class="name">${esc(s.label)}${s.injected ? ' <span class="inj">demo-injected</span>' : ''}</span><span class="blurb">${esc(s.blurb)}</span></span></label>`).join('')}</fieldset>`).join('');
}

function setScenario(id) {
  state.scenario = id;
  for (const r of document.querySelectorAll('input[name="scenario"]')) r.checked = r.value === id;
}

const stacked = () => !window.matchMedia('(min-width: 980px)').matches;

function send({ user = true } = {}) {
  const errors = validateAll();
  const run = runScenario(state.world, readForm(), state.scenario);
  render(run);
  save();
  if (!user) return;
  const firstBad = FIELDS.find((id) => errors[id]);
  if (firstBad) {
    $(firstBad).focus();
    return;
  }
  if (stacked()) {
    const out = $('output');
    out.scrollIntoView({ block: 'start' });
    out.focus({ preventScroll: true });
  }
}

function reset() {
  clearSaved();
  state.world = createWorld();
  writeForm(SEED_INPUT);
  setScenario('accept');
  state.compact = false;
  $('compact').checked = false;
  for (const id of FIELDS) showError(id, '');
  send({ user: false });
  $('announce').textContent = 'Reset. The seed request ran again on a fresh simulated server.';
}

// ------------------------------------------------------------------ snippets

function renderSnippets() {
  $('snippets').innerHTML = SNIPPETS.map((s, i) => `
    <article class="snip">
      <div class="snip-head"><h3>${esc(s.title)}</h3><a class="src" href="${REPO}/blob/main/${s.file}#L${s.startLine}-L${s.endLine}">${esc(s.file)}:${s.startLine}-${s.endLine}</a></div>
      <p>${esc(s.note)}</p>
      <div class="msg"><div class="msg-head"><span>PHP</span><button type="button" class="copy" data-snippet="${i}">Copy</button></div><pre tabindex="0" aria-label="${esc(s.title)}, PHP code"><code>${esc(s.code)}</code></pre></div>
    </article>`).join('');
  $('quickstart').innerHTML = `<div class="msg"><div class="msg-head"><span>Shell, from the README</span><button type="button" class="copy" data-snippet="q">Copy</button></div><pre tabindex="0" aria-label="Quick start commands"><code>${esc(QUICKSTART.code)}</code></pre></div>`;
}

async function copyText(text, button) {
  const label = button.textContent;
  try {
    await navigator.clipboard.writeText(text);
    button.textContent = 'Copied';
  } catch {
    button.textContent = 'Select and copy';
  }
  setTimeout(() => { button.textContent = label; }, 1600);
}

// ------------------------------------------------------------------ wiring

function init() {
  renderScenarios();
  renderSnippets();

  const saved = loadSaved();
  const input = { ...SEED_INPUT, ...(saved?.input ?? {}) };
  writeForm(input);
  setScenario(SCENARIOS.some((s) => s.id === saved?.scenario) ? saved.scenario : 'accept');
  state.compact = saved?.compact === true;
  $('compact').checked = state.compact;

  $('form').addEventListener('submit', (ev) => { ev.preventDefault(); send(); });
  $('reset').addEventListener('click', reset);

  for (const id of FIELDS) {
    const el = $(id);
    el.addEventListener('input', () => {
      if (id === 'phone') syncPhoneHint();
      if (!$(`${id}-err`).hidden) showError(id, validateInput(readForm())[id] ?? '');
      save();
    });
    el.addEventListener('blur', () => showError(id, validateInput(readForm())[id] ?? ''));
  }
  for (const r of document.querySelectorAll('input[name="type"]')) {
    r.addEventListener('change', () => {
      syncType();
      if (!$('partyB').value && r.checked && r.value === TransactionType.BuyGoods) $('partyB').value = '5123456';
      save();
    });
  }
  $('scenarios').addEventListener('change', (ev) => {
    if (ev.target.name === 'scenario') { state.scenario = ev.target.value; save(); }
  });

  document.querySelector('.try').addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-try]');
    if (!b) return;
    setScenario(b.dataset.try);
    send();
  });

  $('compact').addEventListener('change', (ev) => {
    state.compact = ev.target.checked;
    if (state.run) render(state.run);
    save();
  });

  $('redeliver').addEventListener('click', () => {
    if (!state.run || !redeliver(state.run)) return;
    render(state.run);
    $('announce').textContent = 'The last callback was delivered again. ' + $('announce').textContent;
  });

  document.addEventListener('click', (ev) => {
    const b = ev.target.closest('button.copy');
    if (!b) return;
    if (b.dataset.curl) copyText(state.curls.get(b.dataset.curl) ?? '', b);
    else if (b.dataset.snippet === 'q') copyText(QUICKSTART.code, b);
    else if (b.dataset.snippet !== undefined) copyText(SNIPPETS[Number(b.dataset.snippet)].code, b);
  });

  send({ user: false });
}

init();

if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  navigator.serviceWorker.register('sw.js').catch(() => { /* offline support is optional */ });
}

