<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_tabel', function (Blueprint $table): void {
            $table->string('id_tabel', 191);
            $table->string('jenis_tabel', 100);
            $table->string('tahun', 32)->default('');
            $table->text('judul');
            $table->text('link_json');
            $table->longText('isi_json');
            $table->double('diambil_pada');
            $table->primary(['id_tabel', 'tahun']);
        });

        Schema::create('tabel_meta', function (Blueprint $table): void {
            $table->string('id_tabel', 191)->primary();
            $table->string('judul', 512)->nullable();
            $table->string('satuan', 255)->nullable();
            $table->string('subjek', 255)->nullable();
            $table->string('label_wilayah', 255)->nullable();
            $table->longText('catatan')->nullable();
            $table->string('last_update_bps', 100)->nullable();
            $table->double('diambil_pada');
        });

        Schema::create('nilai_data', function (Blueprint $table): void {
            $table->string('id_tabel', 191);
            $table->string('wilayah_id', 64);
            $table->string('wilayah', 255);
            $table->string('turvar_id', 64);
            $table->string('turvar', 255);
            $table->string('tahun', 32);
            $table->string('turtahun', 64);
            $table->double('nilai')->nullable();
            $table->primary(['id_tabel', 'wilayah_id', 'turvar_id', 'tahun', 'turtahun']);
            $table->index(['id_tabel', 'wilayah'], 'idx_nilai_wil');
        });

        Schema::create('editorial_cache', function (Blueprint $table): void {
            $table->char('cache_key', 64)->primary();
            $table->longText('isi_json');
            $table->double('diambil_pada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_cache');
        Schema::dropIfExists('nilai_data');
        Schema::dropIfExists('tabel_meta');
        Schema::dropIfExists('data_tabel');
    }
};
