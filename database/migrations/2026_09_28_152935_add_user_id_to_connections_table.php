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
        Schema::table('connections', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropUnique(['project_id', 'provider']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'provider']);
            $table->unique(['team_id', 'user_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'user_id', 'provider']);
            $table->dropUnique(['project_id', 'provider']);
            $table->dropForeign(['project_id']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->uuid('project_id')->nullable(false)->change();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'provider']);
        });
    }
};
