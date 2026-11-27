// A JavaScript port of the part of the mock Daraja server that the demo uses: OAuth, STK Push,
// STK query, the phone's answers and the callback delivery with retries.
//
// Mirrors src/Mock/MockApp.php, Scenarios.php, CallbackFactory.php, Dispatcher.php, Ids.php and
// TokenIssuer.php. It is not the PHP server: there is no SQLite, no HTTP socket, and C2B, B2C,
// Transaction Status and Reversal are not ported. Checked against the real MockApp by golden.php.

import {
  DarajaError, jsonEncode, decodeObject, Payload, Msisdn, StkPassword, TokenIssuer, PhpFloat,
  base64Decode, eatTimestamp, isHttpUrl, PENDING_ERROR_CODE,
} from './daraja.js';

export const DEFAULT_MOCK_CONFIG = Object.freeze({
  consumerKey: 'mock-consumer-key',
  consumerSecret: 'mock-consumer-secret',
  shortCode: '174379',
  passKey: 'mock-passkey-not-a-real-secret',
  tokenTtlSeconds: 3599,
  stkTimeoutSeconds: 60,
  retryDelays: [1.0, 3.0, 10.0, 30.0],
  signingKey: 'mock-signing-key',
  baseUrl: 'http://127.0.0.1:4150',
});

/** Seeded generator (mulberry32), so a demo run and its tests are reproducible. IDs are invented. */
export function makeRng(seed = 20260115) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Mock/Ids.php */
export function makeIds(rng) {
  const int = (min, max) => min + Math.floor(rng() * (max - min + 1));
  const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
  return {
    receipt: () => Array.from({ length: 10 }, () => ALPHABET[int(0, ALPHABET.length - 1)]).join(''),
    merchantRequestId: () => `${int(10000, 99999)}-${int(10000000, 99999999)}-${int(1, 9)}`,
    checkoutRequestId: (date) => `ws_CO_${eatTimestamp(date)}${int(100000000, 999999999)}`,
  };
}

const OUTCOMES = {
  accept: ['success', 0, 'The service request is processed successfully.'],
  cancel: ['cancelled', 1032, 'Request cancelled by user'],
  timeout: ['timeout', 1037, 'DS timeout user cannot be reached'],
  insufficient: ['insufficient_funds', 1, 'The balance is insufficient for the transaction'],
  wrong_pin: ['wrong_pin', 2001, 'The initiator information is invalid.'],
};

export const PHONE_ACTIONS = Object.keys(OUTCOMES);

class BadField extends Error {
  constructor(field) {
    super(field);
    this.field = field;
  }
}

const json = (status, data) => ({ status, body: jsonEncode(data), headers: [['Content-Type', 'application/json']] });

/** Mock/CallbackFactory.php, stk() */
export function stkCallbackBody(txn, date) {
  const callback = {
    MerchantRequestID: txn.merchantRequestId,
    CheckoutRequestID: txn.checkoutRequestId,
    ResultCode: txn.resultCode ?? 0,
    ResultDesc: txn.resultDesc,
  };
  if ((txn.resultCode ?? -1) === 0) {
    callback.CallbackMetadata = {
      Item: [
        { Name: 'Amount', Value: new PhpFloat(txn.amountCents / 100) },
        { Name: 'MpesaReceiptNumber', Value: txn.receipt },
        { Name: 'TransactionDate', Value: Number(eatTimestamp(date)) },
        { Name: 'PhoneNumber', Value: Number(txn.msisdn) },
      ],
    };
  }
  return { Body: { stkCallback: callback } };
}

export class MockDaraja {
  /** @param {{config?:object, clock:{now():Date, nowSeconds():number}, rng?:()=>number}} opts */
  constructor({ config = DEFAULT_MOCK_CONFIG, clock, rng = makeRng() }) {
    this.config = config;
    this.clock = clock;
    this.ids = makeIds(rng);
    this.transactions = [];
    this.deliveries = [];
    this.nextId = 1;
  }

  maxAttempts() {
    return this.config.retryDelays.length + 1;
  }

  // ------------------------------------------------------------ HTTP surface

  /** @param {{method:string,url:string,headers:[string,string][],body:string|null}} r */
  handle(r) {
    const url = new URL(r.url);
    const header = (name) => r.headers.find(([k]) => k.toLowerCase() === name)?.[1] ?? '';
    const path = url.pathname;
    if (path === '/oauth/v1/generate') return r.method === 'GET' ? this.#oauth(url, header('authorization')) : this.#error(405, '405.001.01', 'Method not allowed');
    if (path.startsWith('/mpesa/')) return r.method === 'POST' ? this.#mpesa(path, header('authorization'), r.body ?? '') : this.#error(405, '405.001.01', 'Method not allowed');
    return this.#error(404, '404.001.01', 'Resource not found');
  }

  #error(status, code, message) {
    return json(status, { requestId: this.ids.merchantRequestId(), errorCode: code, errorMessage: message });
  }

  #oauth(url, authorization) {
    if (url.searchParams.get('grant_type') !== 'client_credentials') return this.#error(400, '400.008.02', 'Invalid grant type passed');
    if (!authorization.startsWith('Basic ')) return this.#error(400, '400.008.01', 'Invalid Authentication passed');
    const decoded = base64Decode(authorization.slice(6));
    if (decoded === null || decoded !== `${this.config.consumerKey}:${this.config.consumerSecret}`) {
      return this.#error(400, '400.008.01', 'Invalid Authentication passed');
    }
    const ttl = this.config.tokenTtlSeconds;
    return json(200, {
      access_token: TokenIssuer.issue(this.config.signingKey, Math.floor(this.clock.nowSeconds()) + ttl),
      expires_in: String(ttl),
    });
  }

  #mpesa(path, authorization, rawBody) {
    if (!authorization.startsWith('Bearer ') || !TokenIssuer.isValid(this.config.signingKey, authorization.slice(7), Math.floor(this.clock.nowSeconds()))) {
      return this.#error(401, '404.001.03', 'Invalid Access Token');
    }
    let body;
    try {
      body = Payload.root(decodeObject(rawBody));
    } catch {
      return this.#error(400, '400.002.05', 'Invalid Request Payload');
    }
    try {
      if (path === '/mpesa/stkpush/v1/processrequest') return this.#stkPush(body);
      if (path === '/mpesa/stkpushquery/v1/query') return this.#stkQuery(body);
      return this.#error(404, '404.001.01', 'Resource not found');
    } catch (e) {
      if (e instanceof BadField) return this.#error(400, '400.002.02', 'Bad Request - Invalid ' + e.field);
      if (e instanceof DarajaError && e.type === 'MalformedPayloadException') return this.#error(400, '400.002.05', 'Invalid Request Payload');
      throw e;
    }
  }

  #checkShortCodeAndPassword(b) {
    if (b.string('BusinessShortCode') !== this.config.shortCode) throw new BadField('BusinessShortCode');
    const timestamp = b.string('Timestamp');
    if (!/^\d{14}\n?$/.test(timestamp)) throw new BadField('Timestamp');
    const expected = StkPassword.generate(this.config.shortCode, this.config.passKey, timestamp);
    if (expected !== b.string('Password')) throw new BadField('Password');
  }

  #msisdn(value, field) {
    const parsed = typeof value === 'number' || typeof value === 'string' ? Msisdn.tryParse(String(value)) : null;
    if (parsed === null || !String(value).startsWith('254')) throw new BadField(field);
    return parsed;
  }

  #stkPush(b) {
    this.#checkShortCodeAndPassword(b);
    const type = b.string('TransactionType');
    if (!['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'].includes(type)) throw new BadField('TransactionType');
    const amount = b.scalar('Amount');
    const okType = Number.isInteger(amount) || (typeof amount === 'string' && /^\d{1,9}\n?$/.test(amount));
    if (!okType || Number(amount) < 1) throw new BadField('Amount');
    const phone = this.#msisdn(b.scalar('PhoneNumber'), 'PhoneNumber');
    this.#msisdn(b.scalar('PartyA'), 'PartyA');
    const partyB = b.string('PartyB');
    if (!/^\d{5,7}\n?$/.test(partyB)) throw new BadField('PartyB');
    const callback = b.string('CallBackURL');
    if (!isHttpUrl(callback)) throw new BadField('CallBackURL');
    const reference = b.string('AccountReference');
    const description = b.string('TransactionDesc');

    const now = this.clock.now();
    const merchantRequestId = this.ids.merchantRequestId();
    const checkoutRequestId = this.ids.checkoutRequestId(now);
    this.transactions.push({
      id: this.nextId++,
      status: 'pending',
      merchantRequestId,
      checkoutRequestId,
      shortCode: this.config.shortCode,
      partyB,
      txnType: type,
      amountCents: Number(amount) * 100,
      msisdn: phone,
      accountReference: reference,
      description,
      callbackUrl: callback,
      resultCode: null,
      resultDesc: '',
      receipt: '',
      createdAt: this.clock.nowSeconds(),
      expiresAt: this.clock.nowSeconds() + this.config.stkTimeoutSeconds,
      callbackWithheld: false,
    });
    return json(200, {
      MerchantRequestID: merchantRequestId,
      CheckoutRequestID: checkoutRequestId,
      ResponseCode: '0',
      ResponseDescription: 'Success. Request accepted for processing',
      CustomerMessage: 'Success. Request accepted for processing',
    });
  }

  #stkQuery(b) {
    this.#checkShortCodeAndPassword(b);
    const txn = this.byCheckoutId(b.string('CheckoutRequestID'));
    if (txn === null) throw new BadField('CheckoutRequestID');
    if (txn.status === 'pending') return this.#error(500, PENDING_ERROR_CODE, 'The transaction is being processed');
    return json(200, {
      ResponseCode: '0',
      ResponseDescription: 'The service request has been accepted successfully',
      MerchantRequestID: txn.merchantRequestId,
      CheckoutRequestID: txn.checkoutRequestId,
      ResultCode: String(txn.resultCode ?? 0),
      ResultDesc: txn.resultDesc,
    });
  }

  // ------------------------------------------------------------ the phone

  byCheckoutId(id) {
    return this.transactions.find((t) => t.checkoutRequestId === id) ?? null;
  }

  /** Scenarios::resolveStk. Returns null when the prompt was not pending. */
  resolveStk(txn, action, sendCallback = true) {
    const outcome = OUTCOMES[action];
    if (outcome === undefined) throw new Error('Unknown action ' + action);
    if (txn.status !== 'pending') return null;
    const [status, code, desc] = outcome;
    txn.status = status;
    txn.resultCode = code;
    txn.resultDesc = desc;
    txn.receipt = code === 0 ? this.ids.receipt() : '';
    txn.callbackWithheld = !sendCallback;
    if (sendCallback) this.enqueueStkCallback(txn);
    return txn;
  }

  enqueueStkCallback(txn, payload = null) {
    const body = payload ?? jsonEncode(stkCallbackBody(txn, this.clock.now()));
    const delivery = {
      id: this.deliveries.length + 1,
      txnId: txn.id,
      url: txn.callbackUrl,
      payload: body,
      status: 'pending',
      attempts: 0,
      maxAttempts: this.maxAttempts(),
      nextAttemptAt: this.clock.nowSeconds(),
      lastHttpStatus: null,
    };
    this.deliveries.push(delivery);
    txn.callbackWithheld = false;
    return delivery;
  }

  /** Time out prompts nobody answered (Dispatcher::tick, first half). */
  expirePending() {
    const now = this.clock.nowSeconds();
    for (const t of this.transactions) {
      if (t.status === 'pending' && t.expiresAt <= now) this.resolveStk(t, 'timeout');
    }
  }

  /** Put a finished delivery back in the queue (the console's "Redeliver (duplicate)" button). */
  requeue(delivery) {
    delivery.status = 'pending';
    delivery.attempts = 0;
    delivery.nextAttemptAt = this.clock.nowSeconds();
  }

  /**
   * Dispatcher::attempt for one due delivery. `send(request)` returns {status}. Returns the record
   * of what happened so the caller can show it.
   */
  attempt(delivery, send) {
    const request = {
      method: 'POST',
      url: delivery.url,
      headers: [['Content-Type', 'application/json'], ['Accept', 'application/json'], ['User-Agent', 'daraja-mock/1'], ['Host', new URL(delivery.url).host]],
      body: delivery.payload,
    };
    const response = send(request);
    const attemptNumber = delivery.attempts + 1;
    delivery.attempts = attemptNumber;
    delivery.lastHttpStatus = response.status;
    if (response.status >= 200 && response.status < 300) {
      delivery.status = 'delivered';
      return { request, response, attemptNumber, retryInSeconds: null };
    }
    let retryInSeconds = null;
    if (attemptNumber < delivery.maxAttempts) {
      const delays = this.config.retryDelays;
      retryInSeconds = delays[Math.min(attemptNumber, delays.length) - 1] ?? 1.0;
      delivery.nextAttemptAt = this.clock.nowSeconds() + retryInSeconds;
      delivery.status = 'pending';
    } else {
      delivery.status = 'failed';
    }
    return { request, response, attemptNumber, retryInSeconds };
  }
}
