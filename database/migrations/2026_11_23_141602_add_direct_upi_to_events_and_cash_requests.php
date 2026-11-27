<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct UPI: members pay the organiser's UPI ID from their own UPI app (the money never passes
     * through the platform), then send the UTR. The organiser confirms it like a cash payment,
     * so `cash_requests` now also carries the method and the UTR.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('upi_id', 100)->nullable()->after('target_amount');
            $table->string('upi_payee_name', 100)->nullable()->after('upi_id');
        });

        Schema::table('cash_requests', function (Blueprint $table) {
            $table->string('method', 20)->default('cash')->after('amount');
            // The UPI transaction reference (UTR / RRN) the member copies from their UPI app.
            $table->string('utr', 35)->nullable()->after('method');

            $table->index(['event_id', 'utr']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_requests', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'utr']);
            $table->dropColumn(['method', 'utr']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['upi_id', 'upi_payee_name']);
        });
    }
};
