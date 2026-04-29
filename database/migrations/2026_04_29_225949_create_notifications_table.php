<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // morphs('notifiable') が内部でやっていること
            // $table->string('notifiable_type');       // ← モデルクラス名
            // $table->unsignedBigInteger('notifiable_id'); // ← レコードの id  ← これも含まれている
            // $table->index(['notifiable_type', 'notifiable_id']); // ← 複合インデックス
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
