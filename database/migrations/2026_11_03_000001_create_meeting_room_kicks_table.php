<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_room_kicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meeting')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('kicked_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_room_kicks');
    }
};
