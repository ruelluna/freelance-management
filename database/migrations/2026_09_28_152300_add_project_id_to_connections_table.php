<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->foreignUuid('project_id')->nullable()->after('team_id')->constrained()->cascadeOnDelete();
        });

        $this->backfillProjectIds();

        Schema::table('connections', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->uuid('project_id')->nullable(false)->change();
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'provider']);
            $table->dropConstrainedForeignId('project_id');
        });
    }

    protected function backfillProjectIds(): void
    {
        $connections = DB::table('connections')->get();

        foreach ($connections as $connection) {
            $sourceIds = DB::table('connected_sources')
                ->where('connection_id', $connection->id)
                ->pluck('id');

            $projects = DB::table('projects')
                ->whereIn('connected_source_id', $sourceIds)
                ->get();

            if ($projects->isEmpty()) {
                DB::table('connections')->where('id', $connection->id)->delete();

                continue;
            }

            if ($projects->count() === 1) {
                DB::table('connections')->where('id', $connection->id)->update([
                    'project_id' => $projects->first()->id,
                ]);

                continue;
            }

            $claimedSourceIds = [];

            foreach ($projects as $project) {
                $cloneId = (string) Str::uuid();

                DB::table('connections')->insert([
                    'id' => $cloneId,
                    'team_id' => $connection->team_id,
                    'project_id' => $project->id,
                    'provider' => $connection->provider,
                    'name' => $connection->name,
                    'token' => $connection->token,
                    'webhook_secret' => $connection->webhook_secret,
                    'settings' => $connection->settings,
                    'last_synced_at' => $connection->last_synced_at,
                    'created_at' => $connection->created_at,
                    'updated_at' => now(),
                ]);

                $sourceId = $project->connected_source_id;

                if ($sourceId === null || ! $sourceIds->contains($sourceId)) {
                    continue;
                }

                if (! in_array($sourceId, $claimedSourceIds, true)) {
                    DB::table('connected_sources')->where('id', $sourceId)->update([
                        'connection_id' => $cloneId,
                    ]);

                    DB::table('issues')->where('connected_source_id', $sourceId)->update([
                        'connection_id' => $cloneId,
                    ]);

                    $claimedSourceIds[] = $sourceId;

                    continue;
                }

                $source = DB::table('connected_sources')->where('id', $sourceId)->first();

                if ($source === null) {
                    continue;
                }

                $copiedSourceId = (string) Str::uuid();

                DB::table('connected_sources')->insert([
                    'id' => $copiedSourceId,
                    'connection_id' => $cloneId,
                    'team_id' => $source->team_id,
                    'external_id' => $source->external_id,
                    'name' => $source->name,
                    'settings' => $source->settings,
                    'last_synced_at' => $source->last_synced_at,
                    'created_at' => $source->created_at,
                    'updated_at' => now(),
                ]);

                DB::table('projects')->where('id', $project->id)->update([
                    'connected_source_id' => $copiedSourceId,
                ]);
            }

            DB::table('connections')->where('id', $connection->id)->delete();
        }
    }
};
