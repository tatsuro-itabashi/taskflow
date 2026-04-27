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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type');   // 'task', 'project' など操作対象の種別
            $table->unsignedBigInteger('subject_id');   // 操作対象の ID
            $table->string('action');         // 'created', 'updated', 'deleted'
            $table->json('changes')->nullable(); // 変更前後の値
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('workspace_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
