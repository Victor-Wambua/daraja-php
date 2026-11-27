// The scenarios the page offers, run end to end on the ported client, mock and example shop.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createWorld, runScenario, redeliver, summarise, validateInput, SCENARIOS, SEED_INPUT } from '../js/engine.js';

const FIXED = Date.parse('2026-01-15T09:30:45Z');
const world = () => createWorld({ now: () => FIXED });
const run = (id, input = {}, w = world()) => runScenario(w, input, id);
const kinds = (r) => r.events.map((e) => e.kind);

test('every scenario ends in the state the explainer promises', () => {
  const expected = {
    accept: { status: 'paid', handler: 1, outcomes: ['handled'] },
    cancel: { status: 'cancelled', handler: 1, outcomes: ['handled'] },
    insufficient: { status: 'insufficient_funds', handler: 1, outcomes: ['handled'] },
    wrong_pin: { status: 'wrong_pin', handler: 1, outcomes: ['handled'] },
    timeout: { status: 'timed_out', handler: 1, outcomes: ['handled'] },
    duplicate: { status: 'paid', handler: 1, outcomes: ['handled', 'duplicate'] },
    out_of_order: { status: 'paid', handler: 1, outcomes: ['rejected', 'handled'] },
    stale_cancel: { status: 'paid', handler: 1, outcomes: ['handled', 'duplicate'] },
    tampered: { status: 'pending', handler: 0, outcomes: ['rejected', 'rejected', 'rejected', 'rejected', 'rejected'] },
    lost: { status: 'paid', handler: 0, outcomes: [] },
  };
  assert.deepEqual(Object.keys(expected).sort(), SCENARIOS.map((s) => s.id).sort());
  for (const [id, want] of Object.entries(expected)) {
    const sum = summarise(run(id));
    assert.equal(sum.statusKey, want.status, id);
    assert.equal(sum.handlerRuns, want.handler, id);
    assert.deepEqual(sum.outcomes, want.outcomes, id);
  }
});

test('a duplicate gets HTTP 200 both times and credits once', () => {
  const r = run('duplicate');
  const d = r.events.filter((e) => e.kind === 'delivery');
  assert.deepEqual(d.map((e) => e.response.status), [200, 200]);
  assert.equal(d[0].request.body, d[1].request.body, 'same body, byte for byte');
  assert.equal(r.order.handlerRuns, 1);
  assert.equal(d[1].stages.find((s) => s.id === 'claim').status, 'stop');
  assert.equal(d[1].stages.find((s) => s.id === 'handler').status, 'skip');
});

test('out of order: 422 first, retried after the mock\'s first delay, then handled', () => {
  const r = run('out_of_order');
  const d = r.events.filter((e) => e.kind === 'delivery');
  assert.deepEqual(d.map((e) => e.response.status), [422, 200]);
  assert.equal(d[0].retryInSeconds, 1);
  assert.equal(d[1].at - d[0].at, 1);
  assert.equal(d[0].stages.find((s) => s.id === 'lookup').status, 'fail');
  assert.match(d[0].error.message, /no originating request found for CheckoutRequestID ws_CO_/);
});

test('a changed amount never runs the handler and the mock retries on its schedule', () => {
  const r = run('tampered');
  const d = r.events.filter((e) => e.kind === 'delivery');
  assert.equal(d.length, 5);
  assert.deepEqual(d.map((e) => e.retryInSeconds), [1, 3, 10, 30, null]);
  assert.match(d[0].error.message, /paid amount KES 1\.00, expected KES 150\.00/);
  assert.equal(d.at(-1).at, 44);
  assert.equal(r.order.status, 'pending');
});

test('a lost callback is settled by an STK query, with no receipt', () => {
  const r = run('lost');
  const q = r.events.filter((e) => e.kind === 'http' && e.label === 'STK query');
  assert.equal(q.length, 2);
  assert.equal(q[0].response.status, 500);
  assert.equal(q[0].result.pending, true);
  assert.equal(q[1].result.isSuccessful, true);
  assert.equal(r.order.paidVia, 'stk_query');
  assert.equal(r.order.receipt, null);
});

test('the timeout scenario waits for the mock\'s 60 seconds', () => {
  const r = run('timeout');
  assert.equal(r.events.find((e) => e.kind === 'delivery').at, 60);
  assert.equal(r.txn.resultCode, 1037);
});

test('the token is fetched once and then reused across runs in the same world', () => {
  const w = world();
  const a = run('accept', {}, w);
  const b = run('cancel', {}, w);
  assert.ok(a.events.some((e) => e.kind === 'http' && e.label === 'OAuth token'));
  assert.ok(!b.events.some((e) => e.kind === 'http' && e.label === 'OAuth token'));
  assert.ok(b.events.some((e) => e.kind === 'token-cached'));
});

test('the idempotency store keeps keys between runs and a new world forgets them', () => {
  const w = world();
  run('accept', {}, w);
  run('cancel', {}, w);
  assert.equal(summarise(run('accept', {}, w)).store.length, 3);
  assert.equal(summarise(run('accept', {}, world())).store.length, 1);
});

test('redeliver sends the last callback again and the handler does not run again', () => {
  const r = run('accept');
  assert.equal(redeliver(r), true);
  assert.deepEqual(summarise(r).outcomes, ['handled', 'duplicate']);
  assert.equal(r.order.handlerRuns, 1);
  assert.equal(redeliver(run('tampered')), false, 'a delivery that never succeeded is not redelivered');
});

test('the push body carries the password and EAT timestamp the explainer shows', () => {
  const r = run('accept');
  const push = r.events.find((e) => e.kind === 'http' && e.label === 'STK Push');
  const body = JSON.parse(push.request.body);
  assert.equal(body.Timestamp, '20260115123045');
  assert.equal(push.derivation.decoded, '174379mock-passkey-not-a-real-secret20260115123045');
  assert.equal(push.derivation.password, body.Password);
  assert.equal(body.PhoneNumber, 254700000001);
});

test('invalid input is refused by the client and nothing is sent', () => {
  const r = run('accept', { phone: '12345' });
  assert.deepEqual(kinds(r), ['client-error']);
  assert.equal(summarise(r).statusKey, 'not_sent');
  assert.equal(r.world.mock.transactions.length, 0);
});

test('field errors are all reported, the first thrown is the library\'s', () => {
  const errors = validateInput({ ...SEED_INPUT, phone: 'x', amount: '0', accountReference: '', callbackUrl: 'nope' });
  assert.deepEqual(Object.keys(errors).sort(), ['accountReference', 'amount', 'callbackUrl', 'phone']);
  const r = run('accept', { amount: '0', phone: 'x' });
  assert.equal(r.clientError.message, 'Amount must be a whole number of shillings, at least 1.');
});

test('a reference longer than 12 characters is refused by push() and the shop marks the order failed', () => {
  const r = run('accept', { accountReference: 'THIS-IS-TOO-LONG' });
  assert.equal(r.clientError.type, 'ValidationException');
  assert.equal(summarise(r).statusKey, 'failed');
  assert.equal(r.world.mock.transactions.length, 0);
});

test('buy goods sends the till as PartyB and the short code as BusinessShortCode', () => {
  const r = run('accept', { type: 'CustomerBuyGoodsOnline', partyB: '5123456' });
  const body = JSON.parse(r.events.find((e) => e.label === 'STK Push').request.body);
  assert.equal(body.TransactionType, 'CustomerBuyGoodsOnline');
  assert.equal(body.PartyB, 5123456);
  assert.equal(body.BusinessShortCode, 174379);
});
