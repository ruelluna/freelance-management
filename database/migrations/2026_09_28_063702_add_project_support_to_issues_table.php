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
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropForeign(['connected_source_id']);
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->uuid('connection_id')->nullable()->change();
            $table->uuid('connected_source_id')->nullable()->change();
            $table->string('external_id')->nullable()->change();
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->foreign('connection_id')->references('id')->on('connections')->cascadeOnDelete();
            $table->foreign('connected_source_id')->references('id')->on('connected_sources')->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['team_id', 'project_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropForeign(['connected_source_id']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['team_id', 'project_id']);
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->uuid('connection_id')->nullable(false)->change();
            $table->uuid('connected_source_id')->nullable(false)->change();
            $table->string('external_id')->nullable(false)->change();
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->foreign('connection_id')->references('id')->on('connections')->cascadeOnDelete();
            $table->foreign('connected_source_id')->references('id')->on('connected_sources')->cascadeOnDelete();
        });
    }
};
