<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('account_change_tokens')) {
            Schema::create('account_change_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('target_email', 191);
                $table->string('change_type', 20);
                $table->string('code_hash', 191);
                $table->timestamp('expires_at');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_change_tokens');
    }
};