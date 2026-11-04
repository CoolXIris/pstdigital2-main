<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_room_participants', function (Blueprint $table) {
            $table->string('client_session_hash', 64)->nullable()->after('user_id');
        });

        Schema::table('meeting_signals', function (Blueprint $table) {
            $table->foreignId('recipient_user_id')->nullable()->after('sender_user_id')
                ->constrained('users')->cascadeOnDelete();
            $table->index(['meeting_id', 'recipient_user_id', 'id'], 'meeting_signals_recipient_index');
        });

        Schema::create('meeting_room_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meeting')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_room_invites');

        Schema::table('meeting_signals', function (Blueprint $table) {
            $table->dropIndex('meeting_signals_recipient_index');
            $table->dropConstrainedForeignId('recipient_user_id');
        });

        Schema::table('meeting_room_participants', function (Blueprint $table) {
            $table->dropColumn('client_session_hash');
        });
    }
};
