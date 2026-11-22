<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - payments.on_behalf_of: the member an owner pays for (null = the payer themselves).
     * - contributions.paid_by: who actually paid when it wasn't the member (null = the member).
     * - contributions come from either an online payment or an approved cash request.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('on_behalf_of')->nullable()->after('user_id')->constrained('users')->restrictOnDelete();
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->change();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreignId('cash_request_id')->nullable()->unique()->after('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->after('user_id')->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Fails while cash contributions exist (they have no payment_id), which is intended.
     */
    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropForeign(['cash_request_id']);
            $table->dropUnique(['cash_request_id']);
            $table->dropColumn('cash_request_id');
            $table->dropForeign(['payment_id']);
        });

        Schema::table('contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable(false)->change();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_behalf_of');
        });
    }
};
