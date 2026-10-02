<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->string('assigned_staff', 100)->nullable()->after('status');
            $table->index(['assigned_staff', 'status', 'tanggal'], 'meeting_staff_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropIndex('meeting_staff_schedule_index');
            $table->dropColumn('assigned_staff');
        });
    }
};
