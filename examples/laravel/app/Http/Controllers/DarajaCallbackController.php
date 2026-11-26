<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Daraja\Callback\StkCallback;
use Daraja\Exception\InvalidCallbackPayloadException;
use Daraja\Exception\PaymentMismatchException;
use Daraja\Exception\UntrustedSourceException;
use Daraja\Safety\CallbackProcessor;
use Daraja\Safety\ExpectedPayment;
use Daraja\Stk\StkOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DarajaCallbackController
{
    public function stk(Request $request, string $secret, CallbackProcessor $processor): JsonResponse
    {
        if (!hash_equals((string) config('daraja.callback_secret'), $secret)) {
            abort(404);
        }

        try {
            $result = $processor->processStk(
                $request->getContent(),
                $this->apply(...),
                // Look up what we expect for this CheckoutRequestID. Unknown ids are rejected.
                static function (StkCallback $cb): ?ExpectedPayment {
                    $payment = DB::table('payments')->where('checkout_request_id', $cb->checkoutRequestId)->first();

                    return $payment === null ? null : ExpectedPayment::kes((int) $payment->amount_kes, (string) $payment->phone);
                },
                $request->server('REMOTE_ADDR'),
            );

            return response()->json($result->acknowledgement());
        } catch (UntrustedSourceException) {
            abort(403);
        } catch (InvalidCallbackPayloadException $e) {
            report($e);
            abort(400);
        } catch (PaymentMismatchException $e) {
            report($e); // alert a human: amount or phone differs from the order
            abort(422);
        }
        // Anything else (for example a database outage) propagates as HTTP 500 so a retry can succeed.
    }

    private function apply(StkCallback $cb): void
    {
        DB::transaction(function () use ($cb): void {
            if ($cb->isSuccessful()) {
                DB::table('payments')
                    ->where('checkout_request_id', $cb->checkoutRequestId)
                    ->whereIn('status', ['pending', 'failed'])
                    ->update(['status' => 'paid', 'mpesa_receipt' => $cb->mpesaReceiptNumber, 'updated_at' => now()]);

                return;
            }
            $status = match ($cb->outcome()) {
                StkOutcome::Cancelled => 'cancelled',
                StkOutcome::TimedOut => 'timed_out',
                StkOutcome::InsufficientFunds => 'insufficient_funds',
                StkOutcome::WrongPin => 'wrong_pin',
                default => 'failed',
            };
            DB::table('payments')->where('checkout_request_id', $cb->checkoutRequestId)->where('status', 'pending')
                ->update(['status' => $status, 'updated_at' => now()]);
        });
    }
}
