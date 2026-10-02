<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable();
            $table->string('documentation_path')->nullable();
            $table->string('documentation_original_name')->nullable();
        });

        Schema::create('meeting_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meeting')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('signal_type', 20);
            $table->longText('payload');
            $table->timestamps();
            $table->index(['meeting_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_signals');
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'documentation_path', 'documentation_original_name']);
        });
    }
};
