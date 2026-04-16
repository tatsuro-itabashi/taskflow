<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->string('provider')->nullable()->after('avatar');      // 'github', 'google' など
            $table->string('provider_id')->nullable()->after('provider'); // OAuth の一意ID
            $table->string('password')->nullable()->change();             // OAuth ユーザーはパスワードなし
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'provider', 'provider_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
