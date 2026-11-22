<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The in-app wallet is gone: online contributions are paid by Razorpay Route straight to the
     * organiser's bank, so there is no balance to keep or withdraw.
     */
    public function up(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('withdrawals');
    }

    public function down(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->string('razorpay_payout_id', 50)->nullable()->unique();
            $table->string('utr', 50)->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->decimal('amount', 12, 2);
            $table->string('title', 250);
            $table->string('subtitle', 250)->nullable();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }
};
