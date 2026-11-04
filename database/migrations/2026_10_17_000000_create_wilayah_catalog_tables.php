<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wilayah_provinces', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('name')->unique();
        });

        Schema::create('wilayah_regencies', function (Blueprint $table) {
            $table->string('code', 5)->primary();
            $table->string('province_code', 2);
            $table->string('name');
            $table->foreign('province_code')
                ->references('code')
                ->on('wilayah_provinces')
                ->cascadeOnDelete();
            $table->unique(['province_code', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wilayah_regencies');
        Schema::dropIfExists('wilayah_provinces');
    }
};
