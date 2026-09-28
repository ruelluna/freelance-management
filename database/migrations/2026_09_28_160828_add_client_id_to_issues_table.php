<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->foreignUuid('client_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->index(['team_id', 'client_id']);
        });

        DB::statement('update issues set client_id = (select client_id from projects where projects.id = issues.project_id) where project_id is not null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'client_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
