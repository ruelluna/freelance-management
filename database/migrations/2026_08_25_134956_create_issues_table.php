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
        Schema::create('issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connected_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->unsignedInteger('number')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('open');
            $table->string('external_url')->nullable();
            $table->timestamp('external_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connected_source_id', 'external_id']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'external_updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
