<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('razorpay_order_id', 50)->unique();
            $table->string('razorpay_qr_id', 50)->nullable()->unique();
            $table->string('razorpay_payment_id', 50)->nullable()->unique();
            $table->string('razorpay_signature')->nullable();
            $table->text('qr_payload')->nullable();
            $table->string('qr_image_url', 500)->nullable();
            $table->string('method', 20)->nullable();
            $table->string('status', 20)->default('created');
            $table->dateTime('expires_at');
            $table->dateTime('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
