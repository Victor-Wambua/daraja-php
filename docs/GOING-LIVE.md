# Going live checklist

This library has not been run against Safaricom's live or sandbox API. Treat the first sandbox run as a
test of the library as much as of your app, and do not skip the sandbox. Work through this list in order.

## 1. Credentials

- [ ] Consumer key and secret, shortcode and passkey live in environment variables or a secrets manager. Never in git, never in the page source, never in logs.
- [ ] Separate sandbox and production values. `DARAJA_ENV=production` is set only on production hosts.
- [ ] Rotate the consumer secret if it was ever pasted into a ticket or chat.
- [ ] B2C, Transaction Status and Reversal: build the `SecurityCredential` once, at deploy time, with the certificate Safaricom provides for that environment (`SecurityCredential::fromInitiatorPassword`). Store the result, not the initiator password.
- [ ] Use a dedicated Daraja initiator with only the roles you need.

## 2. Callback URLs

- [ ] Production callback, validation, confirmation and result URLs are **https** with a valid certificate. The library refuses plain http for production.
- [ ] Publicly reachable from Safaricom, not behind a VPN or basic auth.
- [ ] The URL contains an unguessable path segment (see the example) so strangers cannot find it.
- [ ] Check Safaricom's current rules for what C2B URLs may contain. Registration can be refused for certain words; the documentation, not this repo, is the authority.
- [ ] The endpoint answers quickly with `{"ResultCode":0,"ResultDesc":"Accepted"}` and does slow work in a queue.
- [ ] If you use an IP allow-list, **you** fill it from Safaricom's current published addresses (`IpAllowList`). The library ships none. Behind a proxy, resolve the real client address before checking it.

## 3. Idempotency

- [ ] Use `PdoIdempotencyStore` (or your own `IdempotencyStore`), not the in-memory one, in any multi-process setup.
- [ ] Add a `UNIQUE` constraint on the M-Pesa receipt column of your ledger. The store protects the handler, the constraint protects the money.
- [ ] Make the handler safe to run twice anyway: every update guarded by the current status.
- [ ] Schedule `purgeCompletedOlderThan()` so the table does not grow forever.
- [ ] Return 5xx when your own database is down, so a retry can succeed, and 2xx only once you have accepted responsibility for the callback.

## 4. Never trust the callback alone for high-value goods

- [ ] Pass an `ExpectedPayment` lookup to `processStk` so amount and phone are compared with the order, and unknown `CheckoutRequestID`s are rejected.
- [ ] For anything expensive or hard to claw back (electronics, stock release, large top-ups), confirm with **Transaction Status** (or your Safaricom statement) before fulfilment.
- [ ] A payment settled by STK **query** carries no receipt number. Treat it as "paid, unverified" until a callback or Transaction Status supplies the receipt.
- [ ] Alert a human on `PaymentMismatchException`. It means amount or payer differ from the order.

## 5. Reconciliation

- [ ] A scheduled job queries prompts that are still pending after a couple of minutes (`stk()->query()`). The Laravel snippet shows one.
- [ ] A daily comparison of your ledger with Safaricom's statement for the shortcode. Callbacks can be lost; statements cannot.
- [ ] A runbook for "customer paid, order not credited": find the receipt, run Transaction Status, credit once, record who did it.

## 6. Operations

- [ ] Give the client a PSR-16 cache shared across workers (Redis, APCu, files) so the OAuth token is reused.
- [ ] Give it a PSR-3 logger. The library logs method, path, status and timing only. Do not add request bodies; they hold phone numbers.
- [ ] Alert on `AuthenticationException` (credentials revoked) and on a rising rate of `ApiException`.
- [ ] Do not retry payment requests automatically after a timeout. Query first.
- [ ] Set sensible HTTP timeouts. The bundled cURL client defaults to 30 seconds, 10 for connect.
- [ ] Confirm the field length limits in `DarajaConfig` (account reference, description) against Safaricom's current documentation.

## 7. Before the first real shilling

- [ ] Run the full flow in Safaricom's sandbox, including a wrong PIN, a cancel and a timeout.
- [ ] Replace the fixtures in `tests/Fixtures` with real recorded sandbox responses and re-run the tests.
- [ ] Make one live payment of the smallest amount and reconcile it by hand end to end.
