<?php

declare(strict_types=1);

namespace App\Jobs;

use Daraja\Daraja;
use Daraja\Exception\DarajaException;
use Daraja\Stk\StkOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Schedule every few minutes: `Schedule::job(new ReconcilePendingPayments)->everyFiveMinutes();`
 *
 * Asks Daraja about prompts that are still "pending" two or more minutes after they were sent.
 * The STK query answers with a result code but no receipt number, so a payment settled here is
 * marked `paid_unverified`. Confirm it with Transaction Status (or wait for a late callback) before
 * releasing anything valuable.
 */
final class ReconcilePendingPayments implements ShouldQueue
{
    use Queueable;

    public function handle(Daraja $daraja): void
    {
        $stale = DB::table('payments')
            ->where('status', 'pending')
            ->whereNotNull('checkout_request_id')
            ->where('created_at', '<', now()->subMinutes(2))
            ->limit(50)
            ->get();

        foreach ($stale as $payment) {
            try {
                $result = $daraja->stk()->query((string) $payment->checkout_request_id);
            } catch (DarajaException $e) {
                report($e);
                continue;
            }
            if ($result->pending) {
                continue;
            }
            $status = match ($result->outcome()) {
                StkOutcome::Success => 'paid_unverified',
                StkOutcome::Cancelled => 'cancelled',
                StkOutcome::TimedOut => 'timed_out',
                StkOutcome::InsufficientFunds => 'insufficient_funds',
                StkOutcome::WrongPin => 'wrong_pin',
                default => 'failed',
            };
            DB::table('payments')->where('id', $payment->id)->where('status', 'pending')->update(['status' => $status, 'updated_at' => now()]);
        }
    }
}
