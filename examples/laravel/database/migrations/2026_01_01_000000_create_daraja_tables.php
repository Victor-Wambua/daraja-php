<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->unsignedInteger('amount_kes');
            $table->string('phone', 12);
            $table->string('status', 24)->index();
            $table->string('checkout_request_id')->nullable()->index();
            // UNIQUE: the same M-Pesa receipt can never be attached to two payments.
            $table->string('mpesa_receipt', 32)->nullable()->unique();
            $table->timestamps();
        });

        // Same shape as PdoIdempotencyStore::migrate().
        Schema::create('daraja_idempotency', function (Blueprint $table): void {
            $table->string('idem_key', 191)->primary();
            $table->string('state', 16);
            $table->bigInteger('locked_until');
            $table->bigInteger('created_at');
            $table->bigInteger('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daraja_idempotency');
        Schema::dropIfExists('payments');
    }
};
