// Runs one scenario end to end and records what happened as a list of events for the page to draw.
// Everything here is the ported client (daraja.js), the ported mock (mock.js) and the ported example
// shop (shop.js). Nothing is sent over a network.

import {
  DarajaError, DarajaClient, CallbackProcessor, InMemoryIdempotencyStore, PhpFloat, Money, Msisdn,
  darajaConfig, stkPushRequest, TransactionType, StkPassword, base64Decode, decodeObject, eatTimestamp, eatIso,
  jsonEncode, isHttpUrl,
} from './daraja.js';
import { MockDaraja, DEFAULT_MOCK_CONFIG, makeRng, stkCallbackBody } from './mock.js';
import { Shop, STATUS_LABELS } from './shop.js';

export const SCENARIOS = [
  { id: 'accept', group: 'The customer answers', label: 'Accepts and enters the PIN', blurb: 'Result code 0. The callback carries the amount, a receipt and the date.' },
  { id: 'cancel', group: 'The customer answers', label: 'Cancels the prompt', blurb: 'Result code 1032. No receipt, nothing to credit.' },
  { id: 'insufficient', group: 'The customer answers', label: 'Has insufficient funds', blurb: 'Result code 1.' },
  { id: 'wrong_pin', group: 'The customer answers', label: 'Enters the wrong PIN', blurb: 'Result code 2001, as the mock sends it.' },
  { id: 'timeout', group: 'The customer answers', label: 'Does not answer', blurb: 'The mock waits 60 seconds, then sends result code 1037.' },
  { id: 'duplicate', group: 'The callback misbehaves', label: 'Delivered twice', blurb: 'The same success callback arrives again. The second one must not credit the order.' },
  { id: 'out_of_order', group: 'The callback misbehaves', label: 'Arrives before your app has saved the request id', blurb: 'The callback overtakes your own bookkeeping. The demo reorders it; the mock cannot.', injected: true },
  { id: 'stale_cancel', group: 'The callback misbehaves', label: 'A cancel arrives after the success', blurb: 'A contradictory callback for the same request. The demo adds it; the mock cannot.', injected: true },
  { id: 'tampered', group: 'The callback misbehaves', label: 'Reports a different amount', blurb: 'A success callback whose amount does not match the order. The demo edits it; the mock cannot.', injected: true },
  { id: 'lost', group: 'The callback misbehaves', label: 'Never arrives', blurb: 'The mock withholds it. Your app asks Daraja with an STK query instead.' },
];

export const SEED_INPUT = Object.freeze({
  phone: '0700 000 001',
  amount: '150',
  accountReference: 'ORD-1042',
  transactionDesc: 'Shop order',
  callbackUrl: 'https://shop.example/hooks/stk/9f3a1c',
  type: TransactionType.PayBill,
  partyB: '',
});

export const CLIENT_CONFIG = Object.freeze({
  consumerKey: DEFAULT_MOCK_CONFIG.consumerKey,
  consumerSecret: DEFAULT_MOCK_CONFIG.consumerSecret,
  shortCode: DEFAULT_MOCK_CONFIG.shortCode,
  passKey: DEFAULT_MOCK_CONFIG.passKey,
  baseUrl: DEFAULT_MOCK_CONFIG.baseUrl,
});

// ------------------------------------------------------------------ validation for the form

/**
 * Check the form the way the library would, but report every bad field instead of only the first.
 * `first` is the message the PHP code would actually throw.
 */
export function validateInput(input, limits = { ref: 12, desc: 13 }) {
  const errors = {};
  const amountText = String(input.amount).trim();
  const amount = /^\d{1,9}$/.test(amountText) ? Number(amountText) : NaN;
  if (!Number.isInteger(amount) || amount < 1) errors.amount = 'Amount must be a whole number of shillings, at least 1.';
  if (Msisdn.tryParse(input.phone) === null) {
    errors.phone = 'Phone number must be a Kenyan mobile number: 07XXXXXXXX, 01XXXXXXXX, +254XXXXXXXXX or 254XXXXXXXXX.';
  }
  const ref = input.accountReference;
  if (ref === '') errors.accountReference = 'Account reference is required.';
  else if (Array.from(ref).length > limits.ref) errors.accountReference = `Account reference is longer than ${limits.ref} characters.`;
  const desc = input.transactionDesc;
  if (desc.trim() === '') errors.transactionDesc = 'Transaction description is required.';
  else if (Array.from(desc).length > limits.desc) errors.transactionDesc = `Transaction description is longer than ${limits.desc} characters.`;
  if (!isHttpUrl(input.callbackUrl)) errors.callbackUrl = 'Callback URL must be an absolute http(s) URL.';
  if (input.type === TransactionType.BuyGoods && !/^\d{5,7}$/.test(input.partyB)) {
    errors.partyB = 'PartyB must be a 5 to 7 digit short code or till number.';
  }
  return errors;
}

// ------------------------------------------------------------------ world

/**
 * Time stands still while a run is computed, so a run does not show invented elapsed times. It moves
 * only when the scenario waits (a prompt timeout, a callback retry), and `touch()` re-syncs it with
 * the device clock at the start of each run.
 */
class SimClock {
  constructor(realNowMs) {
    this.realNowMs = realNowMs;
    this.baseMs = realNowMs();
    this.offsetMs = 0;
  }
  touch() {
    this.baseMs = Math.max(this.baseMs, this.realNowMs());
  }
  now() {
    return new Date(this.baseMs + this.offsetMs);
  }
  nowSeconds() {
    return (this.baseMs + this.offsetMs) / 1000;
  }
  advance(seconds) {
    this.offsetMs += seconds * 1000;
  }
}

/**
 * One in-memory "process": the mock server, your app's orders, the OAuth token cache and the
 * idempotency store. Reset by making a new world, exactly like restarting PHP would.
 */
export function createWorld({ now = () => Date.now(), seed = 20260115, mockConfig = DEFAULT_MOCK_CONFIG } = {}) {
  const clock = new SimClock(now);
  const mock = new MockDaraja({ config: mockConfig, clock, rng: makeRng(seed) });
  const store = new InMemoryIdempotencyStore(clock);
  const processor = new CallbackProcessor(store);
  const shop = new Shop();
  const sink = { events: null, startSeconds: 0 };
  const config = darajaConfig({ ...CLIENT_CONFIG, baseUrl: mockConfig.baseUrl });

  const transport = (request) => {
    const response = mock.handle(request);
    sink.events?.push({ kind: 'http', at: clock.nowSeconds() - sink.startSeconds, request, response });
    return response;
  };
  const client = new DarajaClient(config, transport, clock);
  return { clock, mock, store, processor, shop, client, config, sink, seq: 0, runs: [] };
}

// ------------------------------------------------------------------ one run

function derivation(config, body, clock) {
  const parsed = decodeObject(body);
  const timestamp = String(parsed.Timestamp);
  const date = clock.now();
  return {
    shortCode: config.shortCode,
    passKey: config.passKey,
    timestamp,
    utc: date.toISOString().replace(/\.\d{3}Z$/, 'Z'),
    eat: eatIso(date),
    password: parsed.Password,
    decoded: base64Decode(parsed.Password),
    consistent: parsed.Password === StkPassword.generate(config.shortCode, config.passKey, timestamp),
  };
}

function label(event) {
  const url = new URL(event.request.url);
  if (url.pathname === '/oauth/v1/generate') return 'OAuth token';
  if (url.pathname.endsWith('/processrequest')) return 'STK Push';
  if (url.pathname.endsWith('/query')) return 'STK query';
  return url.pathname;
}

/** Run one scenario. Returns a run object that the page renders. */
export function runScenario(world, rawInput, scenarioId) {
  const input = { ...SEED_INPUT, ...rawInput };
  const scenario = SCENARIOS.find((s) => s.id === scenarioId);
  if (!scenario) throw new Error('Unknown scenario ' + scenarioId);

  const events = [];
  const { clock, mock, shop, processor, client, sink, config } = world;
  world.seq += 1;
  clock.touch();
  const startSeconds = clock.nowSeconds();
  sink.events = events;
  sink.startSeconds = startSeconds;
  const at = () => clock.nowSeconds() - startSeconds;
  const emit = (e) => events.push({ at: at(), ...e });

  const run = { scenario, input, events, order: null, txn: null, deliveries: [], clientError: null, world };

  try {
    // ---- the client builds the request (throws before any HTTP is sent)
    const amount = Number(String(input.amount).trim());
    let request;
    try {
      request = stkPushRequest({
        amount: /^\d{1,9}$/.test(String(input.amount).trim()) ? amount : NaN,
        phoneNumber: input.phone,
        callbackUrl: input.callbackUrl,
        accountReference: input.accountReference,
        transactionDesc: input.transactionDesc,
        type: input.type,
        partyB: input.type === TransactionType.BuyGoods ? input.partyB : null,
      });
    } catch (e) {
      return fail(run, e, 'new StkPushRequest(...)');
    }

    const order = shop.create({ ref: input.accountReference, amount: request.amount, phone: request.phoneNumber });
    run.order = order;
    emit({ kind: 'order', title: 'Your app creates the order', order: snapshot(order) });

    const cache = client.tokens.cache;
    if (cache !== null && cache.expiresAt > Math.floor(clock.nowSeconds())) {
      emit({ kind: 'token-cached', title: 'Token already cached', remaining: cache.expiresAt - Math.floor(clock.nowSeconds()) });
    }

    // ---- STK push (the OAuth request happens inside when the cache is empty)
    let push;
    try {
      push = client.stkPush(request);
    } catch (e) {
      return fail(run, e, '$daraja->stk()->push(...)');
    }
    decorateHttp(events, config, clock);
    run.txn = mock.byCheckoutId(push.checkoutRequestId);

    if (scenario.id !== 'out_of_order') {
      shop.attachCheckoutId(order, push.checkoutRequestId);
      emit({ kind: 'app', title: 'Your app stores the CheckoutRequestID', text: `Order ${order.ref} now carries ${push.checkoutRequestId}. The callback will be matched to the order by this id.` });
    }

    emit({ kind: 'phone', title: 'The prompt reaches the phone', text: `A PIN prompt for ${Money.format(request.amount * 100)} to business ${config.shortCode} appears on ${request.phoneNumber}. The mock now waits for an answer.` });

    // ---- scenario specific
    const txn = run.txn;
    const deliver = (delivery, opts = {}) => deliverAll(world, run, delivery, emit, opts);

    switch (scenario.id) {
      case 'accept':
      case 'cancel':
      case 'insufficient':
      case 'wrong_pin': {
        answer(world, run, scenario.id, emit);
        deliver(mock.deliveries.at(-1));
        break;
      }
      case 'timeout': {
        emit({ kind: 'time', title: 'Nobody answers', seconds: mock.config.stkTimeoutSeconds, text: `The customer ignores the prompt. After ${mock.config.stkTimeoutSeconds} seconds (the mock's default --stk-timeout) it expires.` });
        clock.advance(mock.config.stkTimeoutSeconds);
        mock.expirePending();
        emit({ kind: 'phone', title: 'The mock times the prompt out', text: `Result code ${txn.resultCode}: ${txn.resultDesc.replace(/\.$/, '')}. It queues the callback.` });
        deliver(mock.deliveries.at(-1));
        break;
      }
      case 'duplicate': {
        answer(world, run, 'accept', emit);
        const delivery = mock.deliveries.at(-1);
        deliver(delivery);
        emit({ kind: 'phone', title: 'The same callback is sent again', text: 'This is the console\'s "Redeliver (duplicate)" button. The body is byte for byte the same, as a Daraja retry would be.' });
        mock.requeue(delivery);
        deliver(delivery);
        break;
      }
      case 'out_of_order': {
        answer(world, run, 'accept', emit);
        const delivery = mock.deliveries.at(-1);
        emit({ kind: 'note', title: 'Demo reordering', text: 'The callback is delivered now, before your app has stored the CheckoutRequestID. A real mock would not answer this fast, but real Daraja can overtake your code.' });
        deliver(delivery, {
          afterFirstAttempt: () => {
            shop.attachCheckoutId(order, push.checkoutRequestId);
            emit({ kind: 'app', title: 'Your app finishes saving the CheckoutRequestID', text: `push() returned and the order now carries ${push.checkoutRequestId}. The mock retries after its first delay.` });
          },
        });
        break;
      }
      case 'stale_cancel': {
        answer(world, run, 'accept', emit);
        deliver(mock.deliveries.at(-1));
        const stale = { ...txn, resultCode: 1032, resultDesc: 'Request cancelled by user', receipt: '' };
        emit({ kind: 'note', title: 'Demo injection', text: 'The demo now sends a "cancelled" callback for the same CheckoutRequestID. Daraja would not do this; it shows how the idempotency key is chosen.' });
        const delivery = mock.enqueueStkCallback(txn, jsonEncode(stkCallbackBody(stale, clock.now())));
        deliver(delivery);
        break;
      }
      case 'tampered': {
        answer(world, run, 'accept', emit, { sendCallback: false });
        const tamperedKes = request.amount === 1 ? 2 : 1;
        const body = stkCallbackBody(txn, clock.now());
        body.Body.stkCallback.CallbackMetadata.Item[0].Value = new PhpFloat(tamperedKes);
        emit({ kind: 'note', title: 'Demo edit', text: `The demo rewrites the Amount in the callback to ${Money.format(tamperedKes * 100)}. The mock would send ${Money.format(request.amount * 100)}.` });
        const delivery = mock.enqueueStkCallback(txn, jsonEncode(body));
        deliver(delivery);
        break;
      }
      case 'lost': {
        const first = queryOnce(world, run, push.checkoutRequestId);
        emit({ kind: 'app', title: 'Your app keeps waiting', text: first.pending ? 'Daraja says the transaction is still being processed. The library returns pending: true instead of throwing, and the order stays pending.' : '' });
        answer(world, run, 'accept', emit, { sendCallback: false });
        emit({ kind: 'time', title: 'No callback arrives', seconds: 30, text: 'The mock withheld it (the console\'s "Lose the callback" tick box). Your app waits, then reconciles.' });
        clock.advance(30);
        const second = queryOnce(world, run, push.checkoutRequestId);
        if (!second.pending) {
          shop.applyQuery(order, second.isSuccessful, second.outcome, second.resultDesc);
          emit({ kind: 'app', title: 'Your app settles the order from the query answer', text: second.isSuccessful ? 'The order is marked paid with paid_via stk_query. The query answer has no receipt number, so the receipt stays empty until a callback or a Transaction Status check supplies it.' : `The order is marked ${second.outcome}.` });
        }
        break;
      }
    }
  } finally {
    sink.events = null;
  }
  world.runs.push(run);
  return run;
}

function fail(run, e, where) {
  if (!(e instanceof DarajaError)) throw e;
  run.clientError = e;
  // The example shop marks its order failed when push() throws.
  if (run.order) run.world.shop.markFailed(run.order, e.type === 'ApiException' ? 'The payment service refused the request' : 'The payment service is unreachable');
  run.events.push({ at: 0, kind: 'client-error', title: 'The client refuses before sending anything', where, error: { type: e.type, message: e.message } });
  run.world.sink.events = null;
  return run;
}

const snapshot = (o) => ({ ref: o.ref, amountKes: o.amountKes, phone: o.phone, status: o.status });

/** Add label and the password derivation to http events recorded by the transport. */
function decorateHttp(events, config, clock) {
  for (const e of events) {
    if (e.kind !== 'http' || e.label) continue;
    e.label = label(e);
    e.title = e.label === 'OAuth token' ? 'Your client asks for an access token' : e.label === 'STK Push' ? 'Your client sends the STK push' : 'Your client asks for the payment status';
    if (e.request.body && e.request.body.includes('"Password"')) e.derivation = derivation(config, e.request.body, clock);
  }
}

/** The customer answers on the phone. */
function answer(world, run, action, emit, { sendCallback = true } = {}) {
  const txn = run.txn;
  world.mock.resolveStk(txn, action, sendCallback);
  const names = { accept: 'accepts and enters the PIN', cancel: 'cancels', insufficient: 'has insufficient funds', wrong_pin: 'enters the wrong PIN' };
  emit({
    kind: 'phone',
    title: `The customer ${names[action] ?? action}`,
    text: `Result code ${txn.resultCode}: ${txn.resultDesc.replace(/\.$/, '')}.${txn.receipt ? ' Receipt ' + txn.receipt + '.' : ''} ${sendCallback ? 'The mock queues the callback.' : 'The mock does not queue a callback.'}`,
  });
}

function queryOnce(world, run, checkoutRequestId) {
  const before = run.events.length;
  const result = world.client.stkQuery(checkoutRequestId);
  decorateHttp(run.events, world.config, world.clock);
  for (const e of run.events.slice(before)) if (e.kind === 'http' && e.label === 'STK query') e.result = result;
  return result;
}

/** Deliver one queued callback, retrying on non-2xx like the mock's dispatcher. */
function deliverAll(world, run, delivery, emit, { afterFirstAttempt = null } = {}) {
  const { clock, mock, shop, processor } = world;
  run.deliveries.push(delivery);
  let first = true;
  while (delivery.status === 'pending') {
    const wait = delivery.nextAttemptAt - clock.nowSeconds();
    if (wait > 0.0005) clock.advance(wait);
    let result;
    const record = mock.attempt(delivery, (request) => {
      result = shop.hook(request.body, processor);
      return { status: result.status, body: result.body, headers: [['Content-Type', 'application/json']] };
    });
    emit({
      kind: 'delivery',
      title: record.attemptNumber === 1 ? 'Daraja posts the callback to your app' : `Daraja retries the callback (attempt ${record.attemptNumber})`,
      attempt: record.attemptNumber,
      maxAttempts: delivery.maxAttempts,
      request: record.request,
      response: record.response,
      retryInSeconds: record.retryInSeconds,
      stages: result.stages,
      outcome: result.outcome,
      error: result.error ? { type: result.error.type ?? 'Error', message: result.error.message } : null,
    });
    if (first && afterFirstAttempt) afterFirstAttempt();
    first = false;
  }
}

// ------------------------------------------------------------------ after the run

/** Send the last callback again (the "Redeliver" button), appending to the same run. */
export function redeliver(run) {
  const { world } = run;
  const delivery = run.deliveries.at(-1);
  if (!delivery || delivery.status !== 'delivered') return false;
  const events = run.events;
  world.clock.touch();
  const startSeconds = world.sink.startSeconds;
  world.sink.events = events;
  const emit = (e) => events.push({ at: world.clock.nowSeconds() - startSeconds, ...e });
  emit({ kind: 'phone', title: 'You press "Redeliver" in the mock', text: 'The same body is queued again.' });
  world.mock.requeue(delivery);
  deliverAll(world, run, delivery, emit);
  world.sink.events = null;
  return true;
}

/** What the page shows in the "Result" panel. */
export function summarise(run) {
  if (run.clientError) {
    const o = run.order;
    return {
      sent: false,
      statusKey: o ? 'failed' : 'not_sent',
      statusLabel: o ? STATUS_LABELS.failed : 'Not sent',
      detail: run.clientError.message,
      receipt: null,
      paidVia: null,
      handlerRuns: 0,
      deliveries: 0,
      outcomes: [],
      lastAck: null,
      order: o ? snapshot(o) : null,
      store: run.world.store.snapshot(),
    };
  }
  const order = run.order;
  const deliveries = run.events.filter((e) => e.kind === 'delivery');
  const lastDelivery = deliveries.at(-1) ?? null;
  return {
    sent: true,
    statusKey: order.status,
    statusLabel: STATUS_LABELS[order.status] ?? order.status,
    detail: order.detail,
    receipt: order.receipt,
    paidVia: order.paidVia,
    handlerRuns: order.handlerRuns,
    deliveries: deliveries.length,
    outcomes: deliveries.map((d) => d.outcome),
    lastAck: lastDelivery ? { status: lastDelivery.response.status, body: lastDelivery.response.body } : null,
    order: snapshot(order),
    store: run.world.store.snapshot(),
  };
}

