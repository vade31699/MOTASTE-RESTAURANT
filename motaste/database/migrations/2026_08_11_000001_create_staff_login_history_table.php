<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_login_history')) {
            Schema::create('staff_login_history', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('role', 100)->nullable();
                $table->string('full_name', 191)->nullable();
                $table->string('device_label', 191)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('logged_in_at')->nullable();
                $table->timestamps();

                $table->index('email', 'staff_login_history_email_idx');
                $table->index('role', 'staff_login_history_role_idx');
                $table->index('logged_in_at', 'staff_login_history_logged_in_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_login_history');
    }
};
