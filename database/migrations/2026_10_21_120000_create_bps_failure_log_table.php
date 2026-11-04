<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_gagal', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('id_tabel', 191);
            $table->string('tahun', 32)->default('');
            $table->double('waktu');
            $table->string('pesan', 255);
            $table->index(['id_tabel', 'tahun', 'waktu'], 'idx_log_gagal_tabel_waktu');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_gagal');
    }
};
