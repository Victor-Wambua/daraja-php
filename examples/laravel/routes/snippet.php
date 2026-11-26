<?php

declare(strict_types=1);

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DarajaCallbackController;
use Illuminate\Support\Facades\Route;

// Append to routes/api.php (stateless, no CSRF).
Route::post('/checkout/pay', [CheckoutController::class, 'pay']);

// Daraja posts here. The secret segment keeps strangers from guessing the URL.
Route::post('/hooks/stk/{secret}', [DarajaCallbackController::class, 'stk'])->name('daraja.stk.callback');
