// A JavaScript port of the pure parts of daraja-php, written for the browser demo.
//
// This is NOT the PHP library. Each section names the PHP file it mirrors. test/port.test.mjs runs
// this module against golden values produced by the real PHP code (demo/tools/golden.php), and CI
// fails if the two disagree.
//
// Known, deliberate differences from PHP:
//  - JSON has no int/float split in JavaScript, so 1.0 and 1 are the same number here. Money.fromWire
//    rejects every amount above 1e9 (PHP only range-checks floats).
//  - FILTER_VALIDATE_URL is approximated (isHttpUrl) and checked against golden vectors.
//  - Dates are converted with a fixed UTC+3 offset, which is right for any date since 1960.

import { hmacSha256Hex } from './sha256.js';

// ---------------------------------------------------------------- errors

/** One class for every Daraja\Exception\* type. `type` is the PHP class name. */
export class DarajaError extends Error {
  constructor(type, message, props = {}) {
    super(message);
    this.name = type;
    this.type = type;
    Object.assign(this, props);
  }
}

const fail = (type, message, props) => new DarajaError(type, message, props);

// ---------------------------------------------------------------- small PHP helpers

const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;
const phpTrim = (s) => s.replace(PHP_TRIM, '');
const textEncoder = new TextEncoder();

export function base64(text) {
  const bytes = textEncoder.encode(text);
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}

export function base64Decode(b64) {
  try {
    const bin = atob(b64);
    const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return null;
  }
}

function base64Url(text) {
  return base64(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function base64UrlDecode(text) {
  const padded = text.replace(/-/g, '+').replace(/_/g, '/');
  return base64Decode(padded + '='.repeat((4 - (padded.length % 4)) % 4));
}

const codePoints = (s) => Array.from(s).length;

// ---------------------------------------------------------------- JSON (Support/Json.php)

/** A number that PHP would hold as a float, so it prints as 150.0 (JSON_PRESERVE_ZERO_FRACTION). */
export class PhpFloat {
  constructor(value) {
    this.value = value;
  }
}

function encodeString(s) {
  return JSON.stringify(s).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
}

function encodeNumber(n) {
  return String(n);
}

/** json_encode(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION). */
export function jsonEncode(value, indent = null, depth = 0) {
  const pad = indent === null ? '' : '\n' + ' '.repeat(indent * (depth + 1));
  const padEnd = indent === null ? '' : '\n' + ' '.repeat(indent * depth);
  const colon = indent === null ? ':' : ': ';
  if (value === null) return 'null';
  if (value instanceof PhpFloat) {
    return Number.isInteger(value.value) ? value.value.toFixed(1) : String(value.value);
  }
  if (typeof value === 'string') return encodeString(value);
  if (typeof value === 'number') return encodeNumber(value);
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (Array.isArray(value)) {
    if (value.length === 0) return '[]';
    return '[' + value.map((v) => pad + jsonEncode(v, indent, depth + 1)).join(',') + padEnd + ']';
  }
  const keys = Object.keys(value);
  if (keys.length === 0) return '{}';
  return '{' + keys.map((k) => pad + encodeString(k) + colon + jsonEncode(value[k], indent, depth + 1)).join(',') + padEnd + '}';
}

const MAX_DEPTH = 16;

function depthOf(v) {
  if (v === null || typeof v !== 'object') return 0;
  const children = Array.isArray(v) ? v : Object.values(v);
  let max = 0;
  for (const c of children) max = Math.max(max, depthOf(c));
  return max + 1;
}

class JsonError extends Error {}

/** Json::decodeObject. Throws JsonError unless the text is a JSON object. */
export function decodeObject(json) {
  let data;
  try {
    data = JSON.parse(json);
  } catch {
    throw new JsonError('Syntax error');
  }
  if (depthOf(data) > MAX_DEPTH) throw new JsonError('Maximum stack depth exceeded');
  const isEmptyArray = Array.isArray(data) && data.length === 0;
  if (data === null || typeof data !== 'object' || (Array.isArray(data) && !isEmptyArray)) {
    throw new JsonError('Expected a JSON object');
  }
  return isEmptyArray ? {} : data;
}

// ---------------------------------------------------------------- Support/Msisdn.php

export const Msisdn = {
  /** @returns {string|null} 254XXXXXXXXX or null */
  tryParse(input) {
    let compact = phpTrim(String(input)).replace(/[ -]/g, '');
    if (compact === '') return null;
    if (compact[0] === '+') {
      compact = compact.slice(1);
      if (!compact.startsWith('254')) return null;
    }
    const m = /^(?:0|254)([71]\d{8})$/.exec(compact);
    return m ? '254' + m[1] : null;
  },
  parse(input) {
    const parsed = Msisdn.tryParse(input);
    if (parsed === null) {
      throw fail('ValidationException', 'Phone number must be a Kenyan mobile number: 07XXXXXXXX, 01XXXXXXXX, +254XXXXXXXXX or 254XXXXXXXXX.');
    }
    return parsed;
  },
};

// ---------------------------------------------------------------- Support/Money.php (integer cents)

export const Money = {
  kes: (shillings) => shillings * 100,
  /** @returns {number} cents */
  fromWire(value) {
    if (typeof value === 'number') {
      if (!Number.isFinite(value) || Math.abs(value) > 1.0e9) {
        throw fail('MalformedPayloadException', 'Amount is out of range.');
      }
      if (Number.isInteger(value)) return value * 100;
      return Math.sign(value) * Math.round(Math.abs(value) * 100);
    }
    const m = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(phpTrim(String(value)));
    if (!m) throw fail('MalformedPayloadException', 'Amount is not a plain decimal number.');
    const fraction = (m[2] ?? '').padEnd(2, '0');
    return Number(m[1]) * 100 + Number(fraction);
  },
  /** KES 1,500.00 */
  format(cents) {
    const whole = Math.trunc(cents / 100);
    return 'KES ' + whole.toLocaleString('en-US') + '.' + String(cents % 100).padStart(2, '0');
  },
};

// ---------------------------------------------------------------- Support/EatTime.php and Stk/StkPassword.php

const pad = (n, w = 2) => String(n).padStart(w, '0');

/** YmdHis in East Africa Time (UTC+3) for a Date. */
export function eatTimestamp(date) {
  const d = new Date(date.getTime() + 3 * 3600_000);
  return `${pad(d.getUTCFullYear(), 4)}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}${pad(d.getUTCSeconds())}`;
}

/** ISO-8601 with the +03:00 offset, like DateTime::format('c') in Africa/Nairobi. */
export function eatIso(date) {
  const d = new Date(date.getTime() + 3 * 3600_000);
  return `${pad(d.getUTCFullYear(), 4)}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}+03:00`;
}

export const EatTime = {
  /** @returns {Date} */
  parse(yyyymmddhhmmss) {
    const m = /^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/.exec(yyyymmddhhmmss);
    const bad = () => fail('MalformedPayloadException', 'Timestamp is not a valid YmdHis value.');
    if (!m) throw bad();
    const [y, mo, d, h, mi, s] = m.slice(1).map(Number);
    const date = new Date(Date.UTC(y, mo - 1, d, h - 3, mi, s));
    // Round-trip, exactly like the PHP check: impossible dates roll over and no longer match.
    if (eatTimestamp(date) !== yyyymmddhhmmss) throw bad();
    return date;
  },
};

export const StkPassword = {
  generate: (shortCode, passKey, timestamp) => base64(shortCode + passKey + timestamp),
  timestamp: (date) => eatTimestamp(date),
};

// ---------------------------------------------------------------- Support/Payload.php

export class Payload {
  constructor(data, path = '') {
    this.data = data;
    this.path = path;
  }

  static root(data) {
    return new Payload(data, '');
  }

  #child(key) {
    return this.path === '' ? key : this.path + '.' + key;
  }

  #fail(key, expected) {
    return fail('MalformedPayloadException', `Field "${this.#child(key)}" must be ${expected}.`);
  }

  #get(key) {
    return Object.prototype.hasOwnProperty.call(this.data, key) ? this.data[key] : null;
  }

  has(key) {
    return this.#get(key) !== null;
  }

  object(key) {
    const v = this.#get(key);
    if (v === null || typeof v !== 'object' || (Array.isArray(v) && v.length > 0)) throw this.#fail(key, 'an object');
    return new Payload(Array.isArray(v) ? {} : v, this.#child(key));
  }

  optionalObject(key) {
    return this.has(key) ? this.object(key) : null;
  }

  objects(key) {
    const v = this.#get(key);
    const isList = Array.isArray(v) || (v !== null && typeof v === 'object' && Object.keys(v).length === 0);
    if (!isList) throw this.#fail(key, 'a list');
    const list = Array.isArray(v) ? v : [];
    return list.map((item, i) => {
      if (item === null || typeof item !== 'object' || (Array.isArray(item) && item.length > 0)) {
        throw this.#fail(`${key}[${i}]`, 'an object');
      }
      return new Payload(Array.isArray(item) ? {} : item, this.#child(`${key}[${i}]`));
    });
  }

  string(key) {
    let v = this.#get(key);
    if (typeof v === 'number') v = String(v);
    if (typeof v !== 'string' || v === '') throw this.#fail(key, 'a non-empty string');
    return v;
  }

  looseString(key, dflt = '') {
    const v = this.#get(key);
    if (v === null) return dflt;
    if (typeof v === 'number') return String(v);
    if (typeof v !== 'string') throw this.#fail(key, 'a string');
    return v;
  }

  optionalString(key) {
    return this.has(key) ? this.string(key) : null;
  }

  int(key) {
    const v = this.#get(key);
    if (typeof v === 'string' && /^-?\d{1,18}\n?$/.test(v)) return Number.parseInt(v, 10);
    if (typeof v === 'number' && Number.isInteger(v)) return v;
    throw this.#fail(key, 'an integer');
  }

  optionalInt(key) {
    return this.has(key) ? this.int(key) : null;
  }

  scalar(key) {
    const v = this.#get(key);
    if (v !== null && typeof v === 'object') throw this.#fail(key, 'a scalar value');
    return v;
  }
}

// ---------------------------------------------------------------- Stk/StkResultCode.php

const RESULT_CODES = {
  0: ['Success', 'success', 'The service request is processed successfully.'],
  1: ['InsufficientFunds', 'insufficient_funds', 'The balance is insufficient for the transaction.'],
  1001: ['TransactionInProgress', 'failed', 'Another transaction is already in progress for this subscriber.'],
  1019: ['TransactionExpired', 'timed_out', 'The transaction expired before the customer responded.'],
  1025: ['PushRequestError', 'failed', 'An error occurred while sending the push request.'],
  1032: ['CancelledByUser', 'cancelled', 'The customer cancelled the request.'],
  1037: ['UserUnreachable', 'timed_out', 'The customer could not be reached (prompt timed out).'],
  2001: ['WrongPin', 'wrong_pin', 'The customer entered a wrong PIN.'],
};

/** @returns {string|null} the enum case name, or null for a code the library does not list */
export const resultCodeName = (code) => RESULT_CODES[code]?.[0] ?? null;
export const resultCodeDescription = (code) => RESULT_CODES[code]?.[2] ?? null;
/** StkOutcome value: success, cancelled, timed_out, insufficient_funds, wrong_pin or failed. */
export const outcomeOf = (code) => RESULT_CODES[code]?.[1] ?? 'failed';

// ---------------------------------------------------------------- Callback/CallbackParser.php

export const MAX_BODY_BYTES = 65_536;

function root(rawBody) {
  if (rawBody === '' || textEncoder.encode(rawBody).length > MAX_BODY_BYTES) {
    throw fail('InvalidCallbackPayloadException', 'Callback body is empty or too large.');
  }
  try {
    return Payload.root(decodeObject(rawBody));
  } catch (e) {
    if (e instanceof JsonError) throw fail('InvalidCallbackPayloadException', 'Callback body is not a JSON object.');
    throw e;
  }
}

function receipt(value) {
  if (typeof value !== 'string' || !/^[A-Za-z0-9]{6,32}\n?$/.test(value)) {
    throw fail('MalformedPayloadException', 'Receipt / transaction id is missing or has an unexpected format.');
  }
  return value;
}

const wrap = (e) => {
  if (e instanceof DarajaError && e.type === 'MalformedPayloadException') {
    return fail('InvalidCallbackPayloadException', e.message);
  }
  return e;
};

/**
 * CallbackParser::parseStk. Returns a plain object shaped like StkCallback:
 * amountCents, transactionDate (Date) and phoneNumber are null when absent.
 */
export function parseStkCallback(rawBody) {
  try {
    const callback = root(rawBody).object('Body').object('stkCallback');
    const resultCode = callback.int('ResultCode');
    const items = {};
    const metadata = callback.optionalObject('CallbackMetadata');
    if (metadata !== null) {
      for (const item of metadata.objects('Item')) items[item.string('Name')] = item.scalar('Value');
    }

    let amountCents = null;
    let receiptNumber = null;
    let date = null;
    let phone = null;
    if (resultCode === 0) {
      if (metadata === null) throw fail('MalformedPayloadException', 'A successful callback must carry CallbackMetadata.');
      const rawAmount = items.Amount ?? null;
      if (rawAmount === null || typeof rawAmount === 'boolean') {
        throw fail('MalformedPayloadException', 'A successful callback must carry an Amount item.');
      }
      amountCents = Money.fromWire(rawAmount);
      if (amountCents < 1) throw fail('MalformedPayloadException', 'Amount must be positive.');
      receiptNumber = receipt(items.MpesaReceiptNumber ?? null);
      const rawDate = items.TransactionDate ?? null;
      if (typeof rawDate !== 'number' && typeof rawDate !== 'string') {
        throw fail('MalformedPayloadException', 'A successful callback must carry a TransactionDate item.');
      }
      date = EatTime.parse(String(rawDate));
    }
    const rawPhone = items.PhoneNumber;
    if (rawPhone !== undefined && rawPhone !== null && (typeof rawPhone === 'number' || typeof rawPhone === 'string')) {
      phone = Msisdn.tryParse(String(rawPhone));
    }

    return {
      merchantRequestId: callback.string('MerchantRequestID'),
      checkoutRequestId: callback.string('CheckoutRequestID'),
      resultCode,
      resultDesc: callback.looseString('ResultDesc'),
      amountCents,
      mpesaReceiptNumber: receiptNumber,
      transactionDate: date,
      phoneNumber: phone,
      metadata: items,
      isSuccessful: resultCode === 0,
      outcome: outcomeOf(resultCode),
      resultName: resultCodeName(resultCode),
    };
  } catch (e) {
    throw wrap(e);
  }
}

// ---------------------------------------------------------------- Safety/*

export const BeginResult = { Acquired: 'acquired', InProgress: 'in_progress', AlreadyProcessed: 'already_processed' };
export const ProcessOutcome = { Handled: 'handled', Duplicate: 'duplicate', InProgress: 'in_progress' };

/** Safety/InMemoryIdempotencyStore.php. `clock.nowSeconds()` supplies the time. */
export class InMemoryIdempotencyStore {
  constructor(clock) {
    this.clock = clock;
    this.entries = new Map();
  }

  begin(key, lockSeconds = 300) {
    const now = Math.floor(this.clock.nowSeconds());
    const entry = this.entries.get(key) ?? null;
    if (entry === null || (entry.state === 'processing' && entry.lockedUntil <= now)) {
      this.entries.set(key, { state: 'processing', lockedUntil: now + lockSeconds });
      return BeginResult.Acquired;
    }
    return entry.state === 'completed' ? BeginResult.AlreadyProcessed : BeginResult.InProgress;
  }

  complete(key) {
    this.entries.set(key, { state: 'completed', lockedUntil: 0 });
  }

  release(key) {
    if (this.entries.get(key)?.state === 'processing') this.entries.delete(key);
  }

  /** For display only. */
  snapshot() {
    return [...this.entries].map(([key, v]) => ({ key, state: v.state }));
  }

  clear() {
    this.entries.clear();
  }
}

/** Safety/ExpectedPayment.php */
export const expectedKes = (shillings, phone = null, accountReference = null) => ({
  amountCents: Money.kes(shillings),
  phoneNumber: phone === null ? null : Msisdn.parse(phone),
  accountReference,
});

/** Safety/PaymentCrossCheck.php, verifyStk. */
export function verifyStk(callback, expected) {
  if (!callback.isSuccessful) return;
  const problems = [];
  if (callback.amountCents === null || callback.amountCents !== expected.amountCents) {
    problems.push(`paid amount ${callback.amountCents === null ? 'none' : Money.format(callback.amountCents)}, expected ${Money.format(expected.amountCents)}`);
  }
  if (expected.phoneNumber !== null && (callback.phoneNumber === null || callback.phoneNumber !== expected.phoneNumber)) {
    problems.push('payer phone number differs from the one the prompt was sent to');
  }
  if (problems.length > 0) throw paymentMismatch(problems);
}

function paymentMismatch(problems) {
  return fail('PaymentMismatchException', 'Callback does not match the originating request: ' + problems.join('; '), { problems });
}

/**
 * Safety/CallbackProcessor.php, processStk only (the demo is about STK). IP allow-list is not
 * simulated. Pass `trace` (an array) to receive one record per stage, in order.
 */
export class CallbackProcessor {
  constructor(store, lockSeconds = 300) {
    this.store = store;
    this.lockSeconds = lockSeconds;
  }

  processStk(rawBody, handler, expected = null, trace = null) {
    const stage = (id, status, detail = '') => trace?.push({ id, status, detail });
    stage('source', 'skip', 'No IP allow-list is configured, so every source is accepted.');

    let callback;
    try {
      callback = parseStkCallback(rawBody);
    } catch (e) {
      stage('parse', 'fail', e.message);
      throw e;
    }
    stage('parse', 'pass', `ResultCode ${callback.resultCode}, CheckoutRequestID ${callback.checkoutRequestId}`);

    if (expected !== null) {
      const order = typeof expected === 'function' ? expected(callback) : expected;
      if (order === null) {
        const e = paymentMismatch(['no originating request found for CheckoutRequestID ' + callback.checkoutRequestId]);
        stage('lookup', 'fail', e.message);
        throw e;
      }
      stage('lookup', 'pass', 'Found the order that sent this request.');
      try {
        verifyStk(callback, order);
      } catch (e) {
        stage('crosscheck', 'fail', e.message);
        throw e;
      }
      stage('crosscheck', 'pass', callback.isSuccessful ? 'Amount and phone number match the order.' : 'Nothing to compare: a failed payment carries no amount.');
    } else {
      stage('lookup', 'skip', 'No expected payment supplied.');
      stage('crosscheck', 'skip', '');
    }

    return this.#once('stk:' + callback.checkoutRequestId, () => handler(callback), stage);
  }

  #once(key, work, stage) {
    const begin = this.store.begin(key, this.lockSeconds);
    if (begin === BeginResult.AlreadyProcessed) {
      stage('claim', 'stop', `Key ${key} is already completed.`);
      stage('handler', 'skip', 'Not called.');
      return { outcome: ProcessOutcome.Duplicate, key };
    }
    if (begin === BeginResult.InProgress) {
      stage('claim', 'stop', `Key ${key} is held by another worker.`);
      stage('handler', 'skip', 'Not called.');
      return { outcome: ProcessOutcome.InProgress, key };
    }
    stage('claim', 'pass', `Acquired key ${key}.`);
    try {
      work();
    } catch (e) {
      this.store.release(key);
      stage('handler', 'fail', `Your handler threw: ${e.message}. The key was released so a later delivery can retry.`);
      throw e;
    }
    this.store.complete(key);
    stage('handler', 'pass', 'Your handler ran once; the key is now completed.');
    return { outcome: ProcessOutcome.Handled, key };
  }
}

export const ACK_BODY = { ResultCode: 0, ResultDesc: 'Accepted' };
export const ackJson = () => jsonEncode(ACK_BODY);

// ---------------------------------------------------------------- Stk/StkPushRequest.php

/** FILTER_VALIDATE_URL approximation for http(s) URLs. Verified against golden vectors. */
export function isHttpUrl(url) {
  if (!/^https?:\/\//i.test(url)) return false;
  if (!/^[A-Za-z0-9$\-_.+!*'(),{}|\\^~[\]`<>#%";/?:@&=]*$/.test(url)) return false;
  const m = /^https?:\/\/(?:[^/?#@]*@)?(\[[0-9A-Fa-f:.]+\]|[^/?#:@]+)(?::(\d{1,5}))?(?:[/?#]|$)/i.exec(url);
  if (!m) return false;
  if (m[2] !== undefined && Number(m[2]) > 65535) return false;
  const host = m[1];
  if (host.startsWith('[')) return true;
  const labels = host.replace(/\.$/, '').split('.');
  return labels.every((l) => /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/.test(l));
}

export const TransactionType = { PayBill: 'CustomerPayBillOnline', BuyGoods: 'CustomerBuyGoodsOnline' };

/**
 * new StkPushRequest(...). Throws the first ValidationException, in the order the PHP constructor checks.
 * @returns {{amount:number, phoneNumber:string, callbackUrl:string, accountReference:string, transactionDesc:string, type:string, partyB:string|null}}
 */
export function stkPushRequest({ amount, phoneNumber, callbackUrl, accountReference, transactionDesc, type = TransactionType.PayBill, partyB = null }) {
  if (!Number.isInteger(amount) || amount < 1) {
    throw fail('ValidationException', 'Amount must be a whole number of shillings, at least 1.');
  }
  const phone = Msisdn.parse(phoneNumber);
  if (accountReference === '' || phpTrim(transactionDesc) === '') {
    throw fail('ValidationException', 'Account reference and transaction description are required.');
  }
  if (!isHttpUrl(callbackUrl)) {
    throw fail('ValidationException', 'Callback URL must be an absolute http(s) URL.');
  }
  if (partyB !== null && !/^\d{5,7}$/.test(partyB)) {
    throw fail('ValidationException', 'PartyB must be a 5 to 7 digit short code or till number.');
  }
  return { amount, phoneNumber: phone, callbackUrl, accountReference, transactionDesc, type, partyB };
}

// ---------------------------------------------------------------- Config/DarajaConfig.php (the parts the demo uses)

export function darajaConfig({ consumerKey, consumerSecret, shortCode, passKey = '', baseUrl, tokenSafetyMarginSeconds = 60, accountReferenceMaxLength = 12, transactionDescMaxLength = 13, production = false }) {
  if (consumerKey === '' || consumerSecret === '') throw fail('ConfigurationException', 'Consumer key and secret are required.');
  if (!/^\d{5,7}$/.test(shortCode)) throw fail('ConfigurationException', 'Short code must be 5 to 7 digits.');
  return { consumerKey, consumerSecret, shortCode, passKey, baseUrl: baseUrl.replace(/\/+$/, ''), tokenSafetyMarginSeconds, accountReferenceMaxLength, transactionDescMaxLength, production };
}

// ---------------------------------------------------------------- Http/ApiException

function apiExceptionFromResponse(status, body) {
  let code = '';
  let message = '';
  let requestId = '';
  let decoded = null;
  try {
    decoded = JSON.parse(body);
  } catch {
    decoded = null;
  }
  if (decoded !== null && typeof decoded === 'object') {
    const s = (v) => (v !== null && v !== undefined && typeof v !== 'object' ? String(v) : '');
    code = s(decoded.errorCode);
    message = s(decoded.errorMessage);
    requestId = s(decoded.requestId);
  }
  let summary = message !== '' ? message : 'Daraja returned HTTP ' + status;
  if (code !== '') summary = `[${code}] ${summary}`;
  return fail('ApiException', summary, { httpStatus: status, errorCode: code, errorMessage: message, requestId, rawBody: body.slice(0, 2000) });
}

// ---------------------------------------------------------------- Auth/CachedTokenProvider.php

/** A transport is (request) => response, with request {method,url,headers:[[k,v]],body} and response {status,headers,body}. */
const headerValue = (headers, name) => headers.find(([k]) => k.toLowerCase() === name.toLowerCase())?.[1] ?? '';

/**
 * Fetches the OAuth token once and reuses it. The PHP class also does probabilistic early refresh
 * (XFetch); that needs a measured fetch time and is not simulated here.
 */
export class CachedTokenProvider {
  constructor(config, transport, clock) {
    this.config = config;
    this.transport = transport;
    this.clock = clock;
    this.cache = null; // {value, expiresAt}
    this.lastSource = 'fetched';
  }

  token() {
    const now = Math.floor(this.clock.nowSeconds());
    if (this.cache !== null && this.cache.expiresAt > now) {
      this.lastSource = 'cache';
      return this.cache.value;
    }
    this.lastSource = 'fetched';
    return this.#fetch();
  }

  invalidate() {
    this.cache = null;
  }

  #fetch() {
    const request = {
      method: 'GET',
      url: `${this.config.baseUrl}/oauth/v1/generate?grant_type=client_credentials`,
      headers: [
        ['Authorization', 'Basic ' + base64(`${this.config.consumerKey}:${this.config.consumerSecret}`)],
        ['Accept', 'application/json'],
        ['Host', hostOf(this.config.baseUrl)],
      ],
      body: null,
    };
    const response = this.transport(request);
    if (response.status < 200 || response.status >= 300) {
      const e = apiExceptionFromResponse(response.status, response.body);
      throw fail('AuthenticationException', 'Could not obtain an access token: ' + e.message, { httpStatus: response.status, errorCode: e.errorCode, errorMessage: e.errorMessage });
    }
    let value;
    let lifetime;
    try {
      const payload = Payload.root(decodeObject(response.body));
      value = payload.string('access_token');
      lifetime = payload.int('expires_in');
    } catch (e) {
      throw fail('MalformedPayloadException', 'Daraja token response was not understood: ' + e.message);
    }
    if (lifetime < 1) throw fail('MalformedPayloadException', 'Daraja token response had a non-positive expires_in.');
    const now = Math.floor(this.clock.nowSeconds());
    const ttl = Math.max(1, lifetime - this.config.tokenSafetyMarginSeconds);
    this.cache = { value, expiresAt: now + ttl, ttl, lifetime };
    return value;
  }
}

export function hostOf(baseUrl) {
  const u = new URL(baseUrl);
  return u.host;
}

// ---------------------------------------------------------------- Http/ApiClient.php, Stk/StkService.php, Daraja.php

/** The client facade: Daraja::create(...)->stk(). */
export class DarajaClient {
  constructor(config, transport, clock) {
    this.config = config;
    this.transport = transport;
    this.clock = clock;
    this.tokens = new CachedTokenProvider(config, transport, clock);
  }

  /** ApiClient::post: one retry after a 401, nothing else is retried. */
  #post(path, body) {
    const json = jsonEncode(body);
    for (let attempt = 1; ; attempt++) {
      const request = {
        method: 'POST',
        url: this.config.baseUrl + path,
        headers: [
          ['Authorization', 'Bearer ' + this.tokens.token()],
          ['Content-Type', 'application/json'],
          ['Accept', 'application/json'],
          ['Host', hostOf(this.config.baseUrl)],
        ],
        body: json,
      };
      const response = this.transport(request);
      const status = response.status;
      if (status === 401 && attempt === 1) {
        this.tokens.invalidate();
        continue;
      }
      if (status === 401) {
        const e = apiExceptionFromResponse(status, response.body);
        throw fail('AuthenticationException', e.message, { httpStatus: status, errorCode: e.errorCode, errorMessage: e.errorMessage, requestId: e.requestId });
      }
      if (status < 200 || status >= 300) throw apiExceptionFromResponse(status, response.body);
      try {
        return Payload.root(decodeObject(response.body));
      } catch (e) {
        if (e instanceof JsonError) throw fail('MalformedPayloadException', `Daraja returned a body that is not a JSON object (HTTP ${status}).`);
        throw e;
      }
    }
  }

  #requirePassKey() {
    if (this.config.passKey === '') throw fail('ConfigurationException', 'A passkey is required for STK Push. Set DarajaConfig::$passKey.');
    return this.config.passKey;
  }

  /** StkService::push */
  stkPush(request) {
    const c = this.config;
    if (codePoints(request.accountReference) > c.accountReferenceMaxLength) {
      throw fail('ValidationException', `Account reference is longer than ${c.accountReferenceMaxLength} characters.`);
    }
    if (codePoints(request.transactionDesc) > c.transactionDescMaxLength) {
      throw fail('ValidationException', `Transaction description is longer than ${c.transactionDescMaxLength} characters.`);
    }
    if (c.production && !/^https:\/\//i.test(request.callbackUrl)) {
      throw fail('ValidationException', 'Production callback URLs must use https.');
    }
    const timestamp = StkPassword.timestamp(this.clock.now());
    const response = this.#post('/mpesa/stkpush/v1/processrequest', {
      BusinessShortCode: Number(c.shortCode),
      Password: StkPassword.generate(c.shortCode, this.#requirePassKey(), timestamp),
      Timestamp: timestamp,
      TransactionType: request.type,
      Amount: request.amount,
      PartyA: Number(request.phoneNumber),
      PartyB: Number(request.partyB ?? c.shortCode),
      PhoneNumber: Number(request.phoneNumber),
      CallBackURL: request.callbackUrl,
      AccountReference: request.accountReference,
      TransactionDesc: request.transactionDesc,
    });
    const code = response.string('ResponseCode');
    if (code !== '0') {
      const description = response.looseString('ResponseDescription');
      throw fail('ApiException', `[${code}] ${description !== '' ? description : 'Request not accepted'}`, { httpStatus: 200, errorCode: code, errorMessage: description });
    }
    return {
      merchantRequestId: response.string('MerchantRequestID'),
      checkoutRequestId: response.string('CheckoutRequestID'),
      responseCode: code,
      responseDescription: response.looseString('ResponseDescription'),
      customerMessage: response.looseString('CustomerMessage'),
    };
  }

  /** StkService::query. `pending` is true for the 500.001.1001 answer. */
  stkQuery(checkoutRequestId) {
    if (checkoutRequestId === '' || !/^[\w-]{1,100}\n?$/.test(checkoutRequestId)) {
      throw fail('ValidationException', 'CheckoutRequestID looks malformed.');
    }
    const timestamp = StkPassword.timestamp(this.clock.now());
    let response;
    try {
      response = this.#post('/mpesa/stkpushquery/v1/query', {
        BusinessShortCode: Number(this.config.shortCode),
        Password: StkPassword.generate(this.config.shortCode, this.#requirePassKey(), timestamp),
        Timestamp: timestamp,
        CheckoutRequestID: checkoutRequestId,
      });
    } catch (e) {
      if (e instanceof DarajaError && e.type === 'ApiException' && e.errorCode === PENDING_ERROR_CODE) {
        return { checkoutRequestId, pending: true, resultCode: null, resultDesc: null, outcome: null, isSuccessful: false };
      }
      throw e;
    }
    const resultCode = response.int('ResultCode');
    return {
      checkoutRequestId: response.optionalString('CheckoutRequestID') ?? checkoutRequestId,
      pending: false,
      resultCode,
      resultDesc: response.looseString('ResultDesc'),
      outcome: outcomeOf(resultCode),
      isSuccessful: resultCode === 0,
    };
  }
}

export const PENDING_ERROR_CODE = '500.001.1001';

/** Token helpers shared with the mock (Mock/TokenIssuer.php). */
export const TokenIssuer = {
  issue(signingKey, expiresAt) {
    return base64Url(expiresAt + '.' + hmacSha256Hex(signingKey, String(expiresAt)));
  },
  isValid(signingKey, token, now) {
    const decoded = base64UrlDecode(token);
    const m = decoded === null ? null : /^(\d{1,12})\.([0-9a-f]{64})\n?$/.exec(decoded);
    if (!m) return false;
    return hmacSha256Hex(signingKey, m[1]) === m[2] && Number(m[1]) > now;
  },
};

export { headerValue };
