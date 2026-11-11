<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->string('room_provider', 20)->default('webrtc')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropColumn('room_provider');
        });
    }
};
