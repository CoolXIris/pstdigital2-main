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
        Schema::create('konsultasi', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->dateTime('jadwal');
            $table->string('jenis_konsultasi')->nullable();
            $table->string('link_konsultasi')->nullable();
            $table->string('link_record')->nullable();
            $table->integer('status');
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('katalog', function (Blueprint $table) {


            $table->integer('pub_id')->autoIncrement();
            $table->string('title');
            $table->char('no_rak', 10)->nullable();
            $table->char('domain', 2)->nullable();
            $table->integer('status_website');
            $table->char('qrcode', 10)->nullable();
            $table->dateTime('terakhir_discan')->nullable();
            $table->date('rl_date')->nullable();
            $table->text('abstract')->nullable();
            $table->text('cover')->nullable();
            $table->text('pdf')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('konsultasi');
        Schema::dropIfExists('katalog');
    }
};
