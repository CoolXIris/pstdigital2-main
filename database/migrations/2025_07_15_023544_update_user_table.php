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
        //

        Schema::table('users', function (Blueprint $table) {
            $table->string('pekerjaan')->nullable();
            $table->integer('jenis_kelamin')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('asal_prov')->nullable();
            $table->string('asal_kab')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('pendidikan')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
