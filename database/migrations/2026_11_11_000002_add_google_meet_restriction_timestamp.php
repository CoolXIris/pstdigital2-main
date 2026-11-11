<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->timestamp('google_meet_restricted_at')->nullable()->after('google_meet_link');
        });
    }

    public function down(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropColumn('google_meet_restricted_at');
        });
    }
};
