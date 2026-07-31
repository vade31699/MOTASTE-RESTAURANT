<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            if (!Schema::hasColumn('staff', 'full_name')) {
                $table->string('full_name', 191)->nullable();
            }
            if (!Schema::hasColumn('staff', 'role')) {
                $table->string('role', 100)->nullable();
            }
            if (!Schema::hasColumn('staff', 'email')) {
                $table->string('email', 191)->nullable();
            }
            if (!Schema::hasColumn('staff', 'password_hash')) {
                $table->string('password_hash', 191)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            if (Schema::hasColumn('staff', 'password_hash')) {
                $table->dropColumn('password_hash');
            }
            if (Schema::hasColumn('staff', 'email')) {
                $table->dropColumn('email');
            }
            if (Schema::hasColumn('staff', 'role')) {
                $table->dropColumn('role');
            }
            if (Schema::hasColumn('staff', 'full_name')) {
                $table->dropColumn('full_name');
            }
        });
    }
};
