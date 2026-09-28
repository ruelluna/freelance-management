<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->longText('body_html')->nullable()->after('body');
        });

        Schema::table('issue_comments', function (Blueprint $table) {
            $table->longText('body_html')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });

        Schema::table('issue_comments', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
