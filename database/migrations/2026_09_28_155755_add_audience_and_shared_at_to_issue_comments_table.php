<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
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
        Schema::table('issue_comments', function (Blueprint $table) {
            $table->string('audience')->default('internal')->after('origin');
            $table->timestamp('shared_at')->nullable()->after('audience');
        });

        $clientCommentIds = DB::table('issue_comments')
            ->join('issues', 'issues.id', '=', 'issue_comments.issue_id')
            ->join('team_members', function (JoinClause $join): void {
                $join->on('team_members.user_id', '=', 'issue_comments.user_id')
                    ->on('team_members.team_id', '=', 'issues.team_id');
            })
            ->where('team_members.role', 'client')
            ->pluck('issue_comments.id');

        if ($clientCommentIds->isNotEmpty()) {
            DB::table('issue_comments')->whereIn('id', $clientCommentIds)->update([
                'audience' => 'client',
            ]);
        }

        $ownerReplyIds = DB::table('issue_comments')
            ->join('issues', 'issues.id', '=', 'issue_comments.issue_id')
            ->join('team_members', function (JoinClause $join): void {
                $join->on('team_members.user_id', '=', 'issue_comments.user_id')
                    ->on('team_members.team_id', '=', 'issues.team_id');
            })
            ->where('team_members.role', 'owner')
            ->where('issue_comments.origin', 'local')
            ->pluck('issue_comments.id');

        if ($ownerReplyIds->isNotEmpty()) {
            DB::table('issue_comments')->whereIn('id', $ownerReplyIds)->update([
                'audience' => 'client',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('issue_comments', function (Blueprint $table) {
            $table->dropColumn(['audience', 'shared_at']);
        });
    }
};
