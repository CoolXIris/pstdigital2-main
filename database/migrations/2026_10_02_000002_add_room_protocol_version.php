<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->string('room_protocol_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropColumn('room_protocol_version');
        });
    }
};
