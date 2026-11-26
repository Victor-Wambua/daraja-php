<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Daraja\Daraja;
use Daraja\Exception\DarajaException;
use Daraja\Exception\ValidationException;
use Daraja\Stk\StkPushRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutController
{
    public function pay(Request $request, Daraja $daraja): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $reference = 'PAY-' . strtoupper(Str::random(8));

        try {
            $push = new StkPushRequest(
                amount: (int) $data['amount'],
                phoneNumber: $data['phone'],
                callbackUrl: route('daraja.stk.callback', ['secret' => config('daraja.callback_secret')]),
                accountReference: $reference,
                transactionDesc: 'Order',
            );
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Record the order first, so the callback always finds what it is supposed to confirm.
        $id = DB::table('payments')->insertGetId([
            'reference' => $reference,
            'amount_kes' => $push->amount,
            'phone' => $push->phoneNumber->value,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $response = $daraja->stk()->push($push);
        } catch (DarajaException $e) {
            report($e);
            DB::table('payments')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);

            return response()->json(['error' => 'The payment service could not start the payment.'], 502);
        }

        DB::table('payments')->where('id', $id)->update([
            'checkout_request_id' => $response->checkoutRequestId,
            'updated_at' => now(),
        ]);

        return response()->json(['reference' => $reference], 202);
    }
}
