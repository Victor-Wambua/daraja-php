// Parity tests: the JavaScript port against golden values written by the real PHP code
// (demo/tools/golden.php). Run with: node --test demo/test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash, createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { sha256, hmacSha256Hex, toHex } from '../js/sha256.js';
import {
  DarajaError, DarajaClient, CallbackProcessor, InMemoryIdempotencyStore, Money, Msisdn, EatTime, StkPassword, PhpFloat,
  darajaConfig, eatIso, eatTimestamp, expectedKes, isHttpUrl, jsonEncode, parseStkCallback, stkPushRequest,
  TransactionType, MAX_BODY_BYTES, TokenIssuer, ProcessOutcome, BeginResult,
} from '../js/daraja.js';
import { MockDaraja, DEFAULT_MOCK_CONFIG, makeRng } from '../js/mock.js';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const golden = JSON.parse(readFileSync(join(here, 'golden.json'), 'utf8'));
const cases = JSON.parse(readFileSync(join(here, 'cases.json'), 'utf8'));
const fixture = (name) => readFileSync(join(root, 'tests', 'Fixtures', name + '.json'), 'utf8');

const FROZEN = Date.parse('2026-01-15T09:30:45Z');

class TestClock {
  constructor(ms = FROZEN) { this.ms = ms; }
  now() { return new Date(this.ms); }
  nowSeconds() { return this.ms / 1000; }
  advance(s) { this.ms += s * 1000; }
}

/** Same replacements as normalise() in golden.php. */
function normalise(s) {
  return s
    .replace(/\b\d{5}-\d{8}-\d\b/g, '<id>')
    .replace(/ws_CO_(\d{14})\d{9}/g, 'ws_CO_$1<n>')
    .replace(/"MpesaReceiptNumber","Value":"[A-HJ-NP-Z0-9]{10}"/g, '"MpesaReceiptNumber","Value":"<receipt>"');
}

const describeRequest = (r) => ({ method: r.method, url: r.url, headers: r.headers.map(([k, v]) => [k, v]), body: normalise(r.body ?? '') });

function callbackRaw(c) {
  if (c.oversized) return '{"x":"' + 'a'.repeat(MAX_BODY_BYTES) + '"}';
  if (c.raw !== undefined) return c.raw;
  let raw = fixture(c.fixture);
  for (const [find, replace] of c.replace ?? []) raw = raw.split(find).join(replace);
  return raw;
}

const errorShape = (e) => {
  assert.ok(e instanceof DarajaError, 'expected a DarajaError, got ' + e);
  return { ok: false, type: e.type, message: e.message };
};

// ---------------------------------------------------------------- primitives

test('sha256 and HMAC agree with node:crypto', () => {
  for (const text of ['', 'abc', 'a'.repeat(55), 'a'.repeat(56), 'a'.repeat(64), 'a'.repeat(1000), 'café']) {
    assert.equal(toHex(sha256(new TextEncoder().encode(text))), createHash('sha256').update(text).digest('hex'));
  }
  for (const [k, m] of [['key', 'message'], ['mock-signing-key', '1768473044'], ['k'.repeat(100), 'x']]) {
    assert.equal(hmacSha256Hex(k, m), createHmac('sha256', k).update(m).digest('hex'));
  }
});

test('Msisdn matches PHP on every vector', () => {
  for (const g of golden.msisdn) assert.equal(Msisdn.tryParse(g.input), g.result, JSON.stringify(g.input));
});

test('Money.fromWire matches PHP (PHP int/float split aside, see daraja.js)', () => {
  for (const g of golden.money) {
    let got;
    try {
      const cents = Money.fromWire(g.input);
      got = { ok: true, cents, text: Money.format(cents) };
    } catch (e) {
      got = errorShape(e);
    }
    const want = g.ok ? { ok: true, cents: g.cents, text: g.text } : { ok: false, type: g.type, message: g.message };
    assert.deepEqual(got, want, JSON.stringify(g.input));
  }
});

test('EAT timestamps and passwords match PHP', () => {
  for (const g of golden.timestamps) assert.equal(StkPassword.timestamp(new Date(g.input)), g.timestamp, g.input);
  for (const g of golden.passwords) assert.equal(StkPassword.generate(g.shortCode, g.passKey, g.timestamp), g.password);
});

test('EatTime.parse matches PHP, including impossible dates', () => {
  for (const g of golden.eatParse) {
    let got;
    try {
      got = { ok: true, iso: eatIso(EatTime.parse(g.input)) };
    } catch (e) {
      got = errorShape(e);
    }
    const want = g.ok ? { ok: true, iso: g.iso } : { ok: false, type: g.type, message: g.message };
    assert.deepEqual(got, want, g.input);
  }
});

test('isHttpUrl agrees with FILTER_VALIDATE_URL on every vector', () => {
  for (const g of golden.urls) assert.equal(isHttpUrl(g.input), g.valid, JSON.stringify(g.input));
});

// ---------------------------------------------------------------- requests

const requestInput = (c) => ({
  amount: c.amount,
  phoneNumber: c.phone,
  callbackUrl: c.callbackUrl,
  accountReference: c.accountReference,
  transactionDesc: c.transactionDesc,
  type: c.type === 'BuyGoods' ? TransactionType.BuyGoods : TransactionType.PayBill,
  partyB: c.partyB ?? null,
});

test('StkPushRequest validation and the exact HTTP the client builds match PHP', () => {
  const queue = () => [
    { status: 200, headers: [], body: fixture('oauth_token').trim() },
    { status: 200, headers: [], body: fixture('stk_push_response').trim() },
  ];
  for (const g of golden.stkRequests) {
    const c = cases.stkRequests.find((x) => x.name === g.name);
    let request;
    try {
      request = stkPushRequest(requestInput(c));
      assert.deepEqual({ ok: true, phone: request.phoneNumber, type: request.type }, g.request, g.name);
    } catch (e) {
      assert.deepEqual(errorShape(e), g.request, g.name);
      assert.equal(g.push, undefined, g.name);
      continue;
    }
    const sent = [];
    const responses = queue();
    const transport = (r) => { sent.push(r); return responses.shift(); };
    const config = darajaConfig({ consumerKey: 'test-key', consumerSecret: 'test-secret', shortCode: '174379', passKey: 'test-passkey', baseUrl: 'https://sandbox.safaricom.co.ke' });
    const client = new DarajaClient(config, transport, new TestClock());
    try {
      client.stkPush(request);
      assert.deepEqual({ ok: true, requests: sent.map(describeRequest) }, g.push, g.name);
    } catch (e) {
      assert.deepEqual(errorShape(e), g.push, g.name);
    }
  }
});

// ---------------------------------------------------------------- callbacks

function describeCallback(cb) {
  return {
    merchantRequestId: cb.merchantRequestId,
    checkoutRequestId: cb.checkoutRequestId,
    resultCode: cb.resultCode,
    resultDesc: cb.resultDesc,
    amountCents: cb.amountCents,
    receipt: cb.mpesaReceiptNumber,
    transactionDate: cb.transactionDate === null ? null : eatIso(cb.transactionDate),
    phone: cb.phoneNumber,
    metadata: cb.metadata,
    isSuccessful: cb.isSuccessful,
    outcome: cb.outcome,
    resultName: cb.resultName,
  };
}

test('the strict STK callback parser matches PHP on every body', () => {
  for (const g of golden.callbacks) {
    const c = cases.callbacks.find((x) => x.name === g.name);
    let got;
    try {
      got = { ok: true, parsed: describeCallback(parseStkCallback(callbackRaw(c))) };
    } catch (e) {
      got = errorShape(e);
    }
    const want = g.ok ? { ok: true, parsed: g.parsed } : { ok: false, type: g.type, message: g.message };
    assert.deepEqual(got, want, g.name);
  }
});

test('the callback processor, idempotency store and cross-check match PHP', () => {
  for (const g of golden.processor) {
    const seq = cases.processor.find((x) => x.name === g.name);
    const clock = new TestClock();
    const processor = new CallbackProcessor(new InMemoryIdempotencyStore(clock));
    let handlerCalls = 0;
    seq.steps.forEach((step, i) => {
      const raw = callbackRaw(step);
      let expected = step.expected ?? null;
      if (expected !== null && typeof expected === 'object') expected = expectedKes(expected.kes, expected.phone ?? null);
      else if (expected === 'unknown') expected = () => null;
      let inner = null;
      const handler = () => {
        handlerCalls += 1;
        if (step.handlerThrows) throw new DarajaError('RuntimeException', 'database is down');
        if (step.reenter) inner = processor.processStk(raw, () => {}).outcome;
      };
      let row;
      try {
        const r = processor.processStk(raw, handler, expected);
        row = { outcome: r.outcome, key: r.key, ack: '{"ResultCode":0,"ResultDesc":"Accepted"}' };
      } catch (e) {
        row = { exception: { type: e.type ?? 'RuntimeException', message: e.message } };
      }
      row.handlerCalls = handlerCalls;
      row.inner = inner;
      assert.deepEqual(row, g.steps[i], `${g.name}, step ${i + 1}`);
    });
  }
});

test('an in-progress lock expires after lockSeconds, like the PHP store', () => {
  const clock = new TestClock();
  const store = new InMemoryIdempotencyStore(clock);
  assert.equal(store.begin('k', 300), BeginResult.Acquired);
  assert.equal(store.begin('k', 300), BeginResult.InProgress);
  clock.advance(299);
  assert.equal(store.begin('k', 300), BeginResult.InProgress);
  clock.advance(1);
  assert.equal(store.begin('k', 300), BeginResult.Acquired);
  store.complete('k');
  assert.equal(store.begin('k', 300), BeginResult.AlreadyProcessed);
  store.release('k');
  assert.equal(store.begin('k', 300), BeginResult.AlreadyProcessed, 'release only frees a key that is still processing');
});

// ---------------------------------------------------------------- the mock server

function makeWorld() {
  const clock = new TestClock();
  const mock = new MockDaraja({ config: DEFAULT_MOCK_CONFIG, clock, rng: makeRng(1) });
  const log = [];
  const transport = (request) => {
    const response = mock.handle(request);
    log.push({ request: describeRequest(request), response: { status: response.status, body: normalise(response.body) } });
    return response;
  };
  const config = darajaConfig({ consumerKey: 'mock-consumer-key', consumerSecret: 'mock-consumer-secret', shortCode: '174379', passKey: 'mock-passkey-not-a-real-secret', baseUrl: 'http://127.0.0.1:4150' });
  return { clock, mock, log, client: new DarajaClient(config, transport, clock) };
}

test('the wire traffic (OAuth, push, query, callback) is byte-identical to the real client against the real mock', () => {
  assert.ok(golden.wire.length >= 18);
  for (const g of golden.wire) {
    const c = cases.wire.find((x) => x.name === g.name);
    const w = makeWorld();
    const push = w.client.stkPush(stkPushRequest(requestInput(c)));
    const first = w.client.stkQuery(push.checkoutRequestId);
    const txn = w.mock.byCheckoutId(push.checkoutRequestId);
    const callbacks = [];
    const send = (request) => { callbacks.push(describeRequest(request)); return { status: 200 }; };
    const deliverDue = () => {
      for (const d of w.mock.deliveries) if (d.status === 'pending') w.mock.attempt(d, send);
    };
    if (g.action === 'lost') {
      w.mock.resolveStk(txn, 'accept', false);
      w.clock.advance(30);
    } else if (g.action === 'timeout') {
      w.clock.advance(w.mock.config.stkTimeoutSeconds);
      w.mock.expirePending();
      deliverDue();
    } else {
      w.mock.resolveStk(txn, g.action);
      deliverDue();
    }
    const after = w.client.stkQuery(push.checkoutRequestId);

    const label = `${g.name} / ${g.action}`;
    assert.deepEqual(w.log, g.exchanges, label);
    assert.equal(first.pending, g.firstQueryPending, label);
    assert.deepEqual({ pending: after.pending, resultCode: after.resultCode, outcome: after.outcome }, g.afterQuery, label);
    assert.deepEqual(callbacks, g.callbacks, label);
  }
});

test('the mock rejects bad fields exactly like the PHP mock', () => {
  for (const g of golden.mockBadFields) {
    const w = makeWorld();
    const token = w.client.tokens.token();
    const timestamp = '20260115123045';
    const body = {
      BusinessShortCode: 174379,
      Password: StkPassword.generate('174379', DEFAULT_MOCK_CONFIG.passKey, timestamp),
      Timestamp: timestamp,
      TransactionType: 'CustomerPayBillOnline',
      Amount: 10,
      PartyA: 254700000001,
      PartyB: 174379,
      PhoneNumber: 254700000001,
      CallBackURL: 'http://127.0.0.1:4151/cb',
      AccountReference: 'A',
      TransactionDesc: 'B',
    };
    body[g.field] = g.value;
    const r = w.mock.handle({
      method: 'POST',
      url: 'http://127.0.0.1:4150/mpesa/stkpush/v1/processrequest',
      headers: [['Authorization', 'Bearer ' + token], ['Content-Type', 'application/json']],
      body: JSON.stringify(body),
    });
    assert.deepEqual({ status: r.status, body: normalise(r.body) }, { status: g.status, body: g.body }, `${g.field}=${JSON.stringify(g.value)}`);
  }
});

test('the mock demands a valid bearer token', () => {
  const w = makeWorld();
  const call = (auth) => w.mock.handle({ method: 'POST', url: 'http://127.0.0.1:4150/mpesa/stkpush/v1/processrequest', headers: auth ? [['Authorization', auth]] : [], body: '{"x":1}' });
  const none = call(null);
  assert.deepEqual({ status: none.status, body: normalise(none.body) }, golden.mockAuth.noToken);
  assert.equal(call('Bearer forged-token').status, golden.mockAuth.forged.status);
});

test('bearer tokens are HMAC tokens that expire', () => {
  const token = TokenIssuer.issue('mock-signing-key', 1000);
  assert.ok(TokenIssuer.isValid('mock-signing-key', token, 999));
  assert.ok(!TokenIssuer.isValid('mock-signing-key', token, 1000));
  assert.ok(!TokenIssuer.isValid('other-key', token, 999));
  assert.ok(!TokenIssuer.isValid('mock-signing-key', token + 'x', 999));
});

test('the client caches the token for expires_in minus the safety margin and retries one 401', () => {
  const w = makeWorld();
  const request = stkPushRequest(requestInput(cases.wire[0]));
  w.client.stkPush(request);
  w.client.stkPush(request);
  const oauth = w.log.filter((l) => l.request.url.includes('/oauth/'));
  assert.equal(oauth.length, 1, 'second push reuses the cached token');
  assert.equal(w.client.tokens.cache.ttl, 3599 - 60);

  w.clock.advance(3599 - 60);
  w.client.stkPush(request);
  assert.equal(w.log.filter((l) => l.request.url.includes('/oauth/')).length, 2, 'refetched once the margin is reached');

  // A token the server no longer accepts: the client drops it, fetches a new one and repeats once.
  w.client.tokens.cache.value = 'revoked';
  const before = w.log.length;
  w.client.stkPush(request);
  const kinds = w.log.slice(before).map((l) => l.response.status);
  assert.deepEqual(kinds, [401, 200, 200]);
});

test('JSON encoding follows json_encode flags: floats keep .0, slashes and unicode are not escaped', () => {
  assert.equal(jsonEncode({ a: new PhpFloat(150), b: new PhpFloat(10.5), c: 'https://x/y', d: 'café', e: [1, 'two'], f: null }), '{"a":150.0,"b":10.5,"c":"https://x/y","d":"café","e":[1,"two"],"f":null}');
  assert.equal(jsonEncode('a b'), '"a\\u2028b"');
  assert.equal(jsonEncode({ a: [] , b: {} }), '{"a":[],"b":{}}');
});

test('timestamps roll over the date like PHP in Nairobi', () => {
  assert.equal(eatTimestamp(new Date('2026-12-31T21:00:00Z')), '20270101000000');
});
