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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('holder_name', 250);
            $table->text('account_number');
            $table->char('account_last4', 4);
            $table->char('ifsc', 11);
            $table->string('bank_name', 250);
            $table->string('upi_id', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('razorpay_contact_id', 50)->nullable();
            $table->string('razorpay_fund_account_id', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
