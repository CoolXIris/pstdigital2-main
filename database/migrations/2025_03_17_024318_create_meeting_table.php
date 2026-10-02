<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meeting', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->integer('status');
            $table->integer('user_id');
            $table->text('description');
            $table->date('tanggal');
            $table->time('start_time');
            $table->time('end_time');
            $table->text('google_event_id')->nullable();
            $table->text('google_meet_link')->nullable();
            $table->integer('rating')->nullable();
            $table->text('kritik_saran')->nullable();
            $table->text('ringkasan')->nullable();
            $table->text('link_dokumentasi')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting');
    }
};
