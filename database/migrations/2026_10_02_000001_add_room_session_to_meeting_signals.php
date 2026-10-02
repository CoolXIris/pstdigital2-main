<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->uuid('room_session_id')->nullable();
            $table->timestamp('room_last_seen_at')->nullable();
        });

        Schema::table('meeting_signals', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->after('meeting_id');
            $table->index(['meeting_id', 'session_id', 'id'], 'meeting_signals_active_session_index');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_signals', function (Blueprint $table) {
            $table->dropIndex('meeting_signals_active_session_index');
            $table->dropColumn('session_id');
        });
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropColumn(['room_session_id', 'room_last_seen_at']);
        });
    }
};
