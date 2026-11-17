<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the single `name` column with first / middle / last name and add
     * profile picture and account status columns.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 250)->default('')->after('id');
            $table->string('middle_name', 250)->nullable()->after('first_name');
            $table->string('last_name', 250)->nullable()->after('middle_name');
            $table->string('profile_pic', 250)->nullable()->after('last_name');
            $table->boolean('status')->default(true)->after('password');
            $table->timestamp('disabled_at')->nullable()->after('updated_at');
        });

        DB::table('users')->select(['id', 'name'])->orderBy('id')->each(function (object $user): void {
            $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
            $first = array_shift($parts) ?? '';
            $last = count($parts) > 0 ? array_pop($parts) : null;

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $first,
                'middle_name' => count($parts) > 0 ? implode(' ', $parts) : null,
                'last_name' => $last,
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim(implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name]))),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'profile_pic', 'status', 'disabled_at']);
        });
    }
};
