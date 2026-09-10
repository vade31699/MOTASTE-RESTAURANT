<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_invite_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('staff_invite_tokens', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('code_hash');
            }
        });

        Schema::table('admin_credential_change_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_credential_change_tokens', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('code_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_invite_tokens', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });

        Schema::table('admin_credential_change_tokens', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
