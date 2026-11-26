# Plain PHP example: a tiny checkout

A two-file shop ("Soko Fresh", sample data) that takes an M-Pesa payment end to end:

1. `POST /api/pay` creates an order and sends the STK prompt.
2. The page polls `GET /api/orders/{ref}`.
3. `POST /hooks/stk/{secret}` receives Daraja's callback through `CallbackProcessor`
   (strict parse, order lookup and amount/phone cross-check, idempotency, then the handler).
4. `POST /api/orders/{ref}/reconcile` asks Daraja with an STK query when the callback never arrived.

Run it against the mock:

```bash
composer install                                  # from the repository root
bin/daraja-mock                                   # terminal 1, console at http://127.0.0.1:4150
php -S 127.0.0.1:4151 -t examples/plain-php/public    # terminal 2, shop at http://127.0.0.1:4151
```

Open the shop, pay, then answer the prompt on the phone in the console. Tick "Lose the callback" first
and press "Check payment status" in the shop to see reconciliation. Press "Redeliver (duplicate)" in the
console and watch the shop credit the order only once.

Settings come from the `DARAJA_*` environment variables (see `../../.env.example`) and default to the mock.
Orders live in `var/shop.sqlite`, created on first request.
