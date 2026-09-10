<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verification codes issued during the forgot-password flow. The reset link
     * is only emailed after the staff member confirms this code. Only the
     * SHA-256 hash of the code is stored; the plaintext code is emailed and
     * never persisted.
     */
    public function up(): void
    {
        if (!Schema::hasTable('password_reset_codes')) {
            Schema::create('password_reset_codes', function (Blueprint $table) {
                $table->id();
                $table->string('email', 191);
                $table->string('code_hash', 64);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index('email', 'password_reset_codes_email_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};