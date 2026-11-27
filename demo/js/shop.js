// The "your app" side of the demo: a port of the plain-PHP example shop
// (examples/plain-php/src/Orders.php and the callback route in public/index.php), reduced to an
// in-memory order list. It is here so the callback handler has something real to update.

import { DarajaError, expectedKes, ackJson, jsonEncode } from './daraja.js';

export const STATUS_LABELS = {
  pending: 'Waiting for the customer',
  paid: 'Paid',
  cancelled: 'Cancelled by the customer',
  timed_out: 'Prompt timed out',
  insufficient_funds: 'Insufficient funds',
  wrong_pin: 'Wrong PIN',
  failed: 'Failed',
};

const OUTCOME_TO_LABEL = {
  cancelled: 'cancelled',
  timed_out: 'timed_out',
  insufficient_funds: 'insufficient_funds',
  wrong_pin: 'wrong_pin',
};

export class Shop {
  constructor() {
    this.orders = [];
  }

  /** Orders::create */
  create({ ref, amount, phone }) {
    const order = {
      ref,
      amountKes: amount,
      phone,
      status: 'pending',
      detail: '',
      checkoutRequestId: null,
      receipt: null,
      paidVia: null,
      handlerRuns: 0,
    };
    this.orders.push(order);
    return order;
  }

  byCheckoutId(id) {
    return this.orders.find((o) => o.checkoutRequestId === id) ?? null;
  }

  /** Orders::attachCheckoutId */
  attachCheckoutId(order, checkoutRequestId) {
    order.checkoutRequestId = checkoutRequestId;
  }

  markFailed(order, detail) {
    if (order.status === 'pending') {
      order.status = 'failed';
      order.detail = detail;
    }
  }

  /** Orders::expectation: what we expect Daraja to confirm for this CheckoutRequestID, or null. */
  expectation(checkoutRequestId) {
    const order = this.byCheckoutId(checkoutRequestId);
    return order === null ? null : expectedKes(order.amountKes, order.phone);
  }

  /** Orders::applyCallback. Every change is guarded by the current status. */
  applyCallback(callback) {
    const order = this.byCheckoutId(callback.checkoutRequestId);
    if (order === null) return;
    order.handlerRuns += 1;
    if (callback.isSuccessful) {
      if (order.status === 'pending' || order.status === 'failed') {
        order.status = 'paid';
        order.receipt = callback.mpesaReceiptNumber;
        order.paidVia = 'callback';
        order.detail = '';
      }
      if (order.status === 'paid' && order.receipt === null) order.receipt = callback.mpesaReceiptNumber;
      return;
    }
    if (order.status === 'pending') {
      order.status = OUTCOME_TO_LABEL[callback.outcome] ?? 'failed';
      order.detail = callback.resultDesc;
    }
  }

  /** Orders::applyQuery. An STK query answer carries no receipt number. */
  applyQuery(order, success, label, detail) {
    if (order.status !== 'pending') return;
    if (success) {
      order.status = 'paid';
      order.paidVia = 'stk_query';
      return;
    }
    order.status = label;
    order.detail = detail;
  }

  /**
   * The callback route from public/index.php. Maps the library's exceptions to the HTTP status the
   * example returns. Returns {status, body, stages}.
   */
  hook(rawBody, processor) {
    const stages = [];
    try {
      const result = processor.processStk(
        rawBody,
        (callback) => this.applyCallback(callback),
        (callback) => this.expectation(callback.checkoutRequestId),
        stages,
      );
      return { status: 200, body: ackJson(), stages, outcome: result.outcome, key: result.key, error: null };
    } catch (e) {
      const type = e instanceof DarajaError ? e.type : 'Error';
      if (type === 'InvalidCallbackPayloadException') return { status: 400, body: jsonEncode({ error: 'bad payload' }), stages, outcome: 'invalid', key: null, error: e };
      if (type === 'PaymentMismatchException') return { status: 422, body: jsonEncode({ error: 'mismatch' }), stages, outcome: 'rejected', key: null, error: e };
      return { status: 500, body: jsonEncode({ error: 'try again' }), stages, outcome: 'error', key: null, error: e };
    }
  }
}
