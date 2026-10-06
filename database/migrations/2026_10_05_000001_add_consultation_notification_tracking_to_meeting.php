<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->foreignId('approved_by_user_id')->nullable()->after('assigned_staff')->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable()->after('approved_by_user_id');
            $table->string('approved_by_email')->nullable()->after('approved_by_name');
            $table->timestamp('approval_user_notified_at')->nullable();
            $table->timestamp('approval_admin_notified_at')->nullable();
            $table->timestamp('reminder_user_notified_at')->nullable();
            $table->timestamp('reminder_admin_notified_at')->nullable();
            $table->index(['status', 'tanggal'], 'meeting_notification_schedule_index');
        });
    }

    public function down(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropIndex('meeting_notification_schedule_index');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn([
                'approved_by_name',
                'approved_by_email',
                'approval_user_notified_at',
                'approval_admin_notified_at',
                'reminder_user_notified_at',
                'reminder_admin_notified_at',
            ]);
        });
    }
};
