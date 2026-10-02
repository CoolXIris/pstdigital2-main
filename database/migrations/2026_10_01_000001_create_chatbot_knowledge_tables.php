<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_name');
            $table->string('file_path');
            $table->string('file_type', 12);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('chunks_count')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('trained_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chatbot_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('chatbot_knowledge_sources')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->timestamps();
            $table->unique(['source_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_knowledge_chunks');
        Schema::dropIfExists('chatbot_knowledge_sources');
    }
};
