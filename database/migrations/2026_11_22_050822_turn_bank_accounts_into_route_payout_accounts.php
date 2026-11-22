<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's single payout account: their bank, registered with Razorpay as a Route
     * linked account so online contributions to their events are paid straight into it.
     * The RazorpayX payout columns (withdrawals) are no longer needed.
     *
     * On MySQL the user_id foreign key relies on a composite index that starts with
     * user_id, so the new (user_id, route_status) index is added before the old
     * (user_id, is_primary) index is dropped, and the reverse in down().
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            // KYC details Razorpay needs to open the linked account.
            $table->string('email', 250)->nullable()->after('bank_name');
            $table->text('pan')->nullable()->after('email');
            $table->string('street', 250)->nullable()->after('pan');
            $table->string('city', 100)->nullable()->after('street');
            $table->string('state', 100)->nullable()->after('city');
            $table->char('postal_code', 6)->nullable()->after('state');
            $table->string('razorpay_account_id', 50)->nullable()->unique()->after('postal_code');
            $table->string('razorpay_product_id', 50)->nullable()->after('razorpay_account_id');
            $table->string('route_status', 30)->default('not_linked')->after('razorpay_product_id');
            $table->string('route_status_reason', 500)->nullable()->after('route_status');

            $table->index(['user_id', 'route_status']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_primary']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['upi_id', 'is_primary', 'razorpay_contact_id', 'razorpay_fund_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('upi_id', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('razorpay_contact_id', 50)->nullable();
            $table->string('razorpay_fund_account_id', 50)->nullable();

            $table->index(['user_id', 'is_primary']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'route_status']);
            $table->dropUnique(['razorpay_account_id']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'email', 'pan', 'street', 'city', 'state', 'postal_code',
                'razorpay_account_id', 'razorpay_product_id', 'route_status', 'route_status_reason',
            ]);
        });
    }
};
