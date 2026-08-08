<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('trusted_devices')) {
            Schema::create('trusted_devices', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                // SHA-256 hex fingerprint that already incorporates the account email.
                $table->string('fingerprint', 64);
                $table->string('device_label', 191)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique('fingerprint', 'trusted_devices_fingerprint_unique');
                $table->index('email', 'trusted_devices_email_idx');
            });
        }

        if (!Schema::hasTable('login_verification_tokens')) {
            Schema::create('login_verification_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('fingerprint', 64);
                $table->string('code_hash', 191);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index('email', 'login_verification_tokens_email_idx');
                $table->index('fingerprint', 'login_verification_tokens_fingerprint_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_verification_tokens');
        Schema::dropIfExists('trusted_devices');
    }
};
