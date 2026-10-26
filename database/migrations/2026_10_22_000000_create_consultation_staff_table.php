<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $names = collect(config('pst.consultation_staff', []))
            ->merge(DB::table('meeting')->whereNotNull('assigned_staff')->pluck('assigned_staff'))
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn ($name) => trim($name))
            ->unique(fn ($name) => mb_strtolower($name))
            ->take(100)
            ->values();

        foreach ($names as $sortOrder => $name) {
            DB::table('consultation_staff')->insert([
                'name' => mb_substr($name, 0, 100),
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('meeting', function (Blueprint $table) {
            $table->foreignId('assigned_staff_id')
                ->nullable()
                ->after('assigned_staff')
                ->constrained('consultation_staff')
                ->nullOnDelete();
        });

        DB::table('consultation_staff')->orderBy('id')->get()->each(function ($staff) {
            DB::table('meeting')
                ->where('assigned_staff', $staff->name)
                ->update(['assigned_staff_id' => $staff->id]);
        });
    }

    public function down(): void
    {
        Schema::table('meeting', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_staff_id');
        });

        Schema::dropIfExists('consultation_staff');
    }
};
