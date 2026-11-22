<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `amount` stays the contribution (what the organiser receives). The payer is charged
     * `charged_amount` = amount + platform_fee; the fee stays in the platform's Razorpay account.
     * After capture, `amount` is transferred with Razorpay Route to the organiser's linked account.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('platform_fee', 12, 2)->default(0)->after('amount');
            $table->decimal('charged_amount', 12, 2)->nullable()->after('platform_fee');
            $table->string('razorpay_linked_account_id', 50)->nullable()->after('razorpay_signature');
            $table->string('razorpay_transfer_id', 50)->nullable()->unique()->after('razorpay_linked_account_id');
            // pending (to transfer) | transferred | failed
            $table->string('transfer_status', 20)->nullable()->after('razorpay_transfer_id');
            $table->string('transfer_error', 500)->nullable()->after('transfer_status');
            $table->dateTime('transferred_at')->nullable()->after('transfer_error');

            $table->index(['status', 'transfer_status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'transfer_status']);
            $table->dropUnique(['razorpay_transfer_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'platform_fee', 'charged_amount', 'razorpay_linked_account_id', 'razorpay_transfer_id',
                'transfer_status', 'transfer_error', 'transferred_at',
            ]);
        });
    }
};
