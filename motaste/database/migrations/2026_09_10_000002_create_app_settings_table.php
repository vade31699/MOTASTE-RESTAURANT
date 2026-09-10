<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100);
                $table->text('value')->nullable();
                $table->timestamps();

                $table->unique('key', 'app_settings_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};