<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_invite_tokens')) {
            Schema::create('staff_invite_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('role', 100);
                $table->string('code_hash', 191);
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('admin_credential_change_tokens')) {
            Schema::create('admin_credential_change_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('current_email', 191);
                $table->string('code_hash', 191);
                $table->string('pending_email', 191);
                $table->string('pending_password', 191);
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_credential_change_tokens');
        Schema::dropIfExists('staff_invite_tokens');
    }
};
