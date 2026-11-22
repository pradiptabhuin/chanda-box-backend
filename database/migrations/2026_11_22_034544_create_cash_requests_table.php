<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash handed to the event owner. A member's request waits for the owner's approval;
     * cash the owner records (for themselves or on a member's behalf) is approved at once.
     */
    public function up(): void
    {
        Schema::create('cash_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            // The member the money counts for.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Who created it: the member, or the owner paying on their behalf.
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('note', 250)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 250)->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_requests');
    }
};
