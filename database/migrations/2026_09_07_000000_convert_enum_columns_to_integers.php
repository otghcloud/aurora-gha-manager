<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert persisted application enum values from their string names to stable integers.
     */
    public function up(): void
    {
        $this->assertKnownValues('image_builds', 'status', [
            'queued', 'running', 'succeeded', 'failed', 'cancelled',
        ]);
        $this->assertKnownValues('workflow_jobs', 'conclusion', [
            'success', 'failure', 'cancelled', 'skipped', 'timed_out', 'action_required', 'neutral',
        ], true);
        $this->assertKnownValues('runners', 'state', [
            'spawning', 'idle', 'busy', 'reaping', 'failed', 'destroyed',
        ]);
        $this->assertKnownValues('runners', 'spawn_reason', ['job', 'warm']);
        $this->assertKnownValues('runner_events', 'from_state', [
            'spawning', 'idle', 'busy', 'reaping', 'failed', 'destroyed',
        ], true);
        $this->assertKnownValues('runner_events', 'to_state', [
            'spawning', 'idle', 'busy', 'reaping', 'failed', 'destroyed',
        ], true);
        $this->assertKnownValues('runner_templates', 'os', ['linux', 'windows']);
        $this->assertKnownValues('credentials', 'os', ['linux', 'windows']);
        $this->assertKnownValues('build_credentials', 'os', ['linux', 'windows']);

        $this->dropRunnerStateIndexes();

        $this->replaceValues('image_builds', 'status', [
            'queued' => 0,
            'running' => 1,
            'succeeded' => 2,
            'failed' => 3,
            'cancelled' => 4,
        ]);
        $this->replaceValues('workflow_jobs', 'conclusion', [
            'success' => 0,
            'failure' => 1,
            'cancelled' => 2,
            'skipped' => 3,
            'timed_out' => 4,
            'action_required' => 5,
            'neutral' => 6,
        ]);
        $runnerStates = [
            'spawning' => 0,
            'idle' => 1,
            'busy' => 2,
            'reaping' => 3,
            'failed' => 4,
            'destroyed' => 5,
        ];
        $this->replaceValues('runners', 'state', $runnerStates);
        $this->replaceValues('runners', 'spawn_reason', ['job' => 0, 'warm' => 1]);
        $this->replaceValues('runner_events', 'from_state', $runnerStates);
        $this->replaceValues('runner_events', 'to_state', $runnerStates);
        $this->replaceValues('runner_templates', 'os', ['linux' => 0, 'windows' => 1]);
        $this->replaceValues('credentials', 'os', ['linux' => 0, 'windows' => 1]);
        $this->replaceValues('build_credentials', 'os', ['linux' => 0, 'windows' => 1]);

        $this->changeColumn('image_builds', 'status', false);
        $this->changeColumn('workflow_jobs', 'conclusion', true);
        $this->changeColumn('runners', 'state', false);
        $this->changeColumn('runners', 'spawn_reason', false, 'unsignedTinyInteger', 1);
        $this->changeColumn('runner_events', 'from_state', true);
        $this->changeColumn('runner_events', 'to_state', true);
        $this->changeColumn('runner_templates', 'os', false);
        $this->changeColumn('credentials', 'os', false);
        $this->changeColumn('build_credentials', 'os', false);
        $this->restoreRunnerStateIndexes('5');
    }

    /**
     * Restore the original string representation for rollback where all values are known.
     */
    public function down(): void
    {
        $this->changeColumn('image_builds', 'status', false, 'string');
        $this->changeColumn('workflow_jobs', 'conclusion', true, 'string');
        $this->dropRunnerStateIndexes();
        $this->changeColumn('runners', 'state', false, 'string');
        $this->changeColumn('runners', 'spawn_reason', false, 'string', 'warm');
        $this->changeColumn('runner_events', 'from_state', true, 'string');
        $this->changeColumn('runner_events', 'to_state', true, 'string');
        $this->changeColumn('runner_templates', 'os', false, 'string');
        $this->changeColumn('credentials', 'os', false, 'string');
        $this->changeColumn('build_credentials', 'os', false, 'string');

        $this->replaceValues('image_builds', 'status', [
            0 => 'queued', 1 => 'running', 2 => 'succeeded', 3 => 'failed', 4 => 'cancelled',
        ]);
        $this->replaceValues('workflow_jobs', 'conclusion', [
            0 => 'success', 1 => 'failure', 2 => 'cancelled', 3 => 'skipped', 4 => 'timed_out', 5 => 'action_required', 6 => 'neutral',
        ]);
        $runnerStates = [0 => 'spawning', 1 => 'idle', 2 => 'busy', 3 => 'reaping', 4 => 'failed', 5 => 'destroyed'];
        $this->replaceValues('runners', 'state', $runnerStates);
        $this->replaceValues('runners', 'spawn_reason', [0 => 'job', 1 => 'warm']);
        $this->replaceValues('runner_events', 'from_state', $runnerStates);
        $this->replaceValues('runner_events', 'to_state', $runnerStates);
        $this->replaceValues('runner_templates', 'os', [0 => 'linux', 1 => 'windows']);
        $this->replaceValues('credentials', 'os', [0 => 'linux', 1 => 'windows']);
        $this->replaceValues('build_credentials', 'os', [0 => 'linux', 1 => 'windows']);
        $this->restoreRunnerStateIndexes("'destroyed'");
    }

    /**
     * @param array<int, string> $knownValues
     */
    private function assertKnownValues(string $table, string $column, array $knownValues, bool $nullable = false): void
    {
        $query = DB::table($table)->whereNotNull($column);
        if ($knownValues !== []) {
            $query->whereNotIn($column, $knownValues);
        }

        if ($query->exists()) {
            throw new RuntimeException("Unknown value found in {$table}.{$column}; refusing enum conversion.");
        }

        if (! $nullable && DB::table($table)->whereNull($column)->exists()) {
            throw new RuntimeException("Null value found in non-nullable {$table}.{$column}; refusing enum conversion.");
        }
    }

    /**
     * @param array<int|string, int|string> $values
     */
    private function replaceValues(string $table, string $column, array $values): void
    {
        foreach ($values as $from => $to) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }

    private function changeColumn(string $table, string $column, bool $nullable, string $type = 'unsignedTinyInteger', int|string|null $default = null): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $nullable, $type, $default): void {
            $definition = $blueprint->{$type}($column);
            if ($nullable) {
                $definition->nullable();
            }
            if ($default !== null) {
                $definition->default($default);
            }
            $definition->change();
        });
    }

    private function dropRunnerStateIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS runners_env_vmid_live_unique');
        DB::statement('DROP INDEX IF EXISTS runners_env_job_live_unique');
    }

    private function restoreRunnerStateIndexes(string $destroyedState): void
    {
        DB::statement("CREATE UNIQUE INDEX runners_env_vmid_live_unique ON runners (environment_id, vmid) WHERE state <> {$destroyedState}");
        DB::statement("CREATE UNIQUE INDEX runners_env_job_live_unique ON runners (environment_id, workflow_job_id) WHERE state <> {$destroyedState} AND workflow_job_id IS NOT NULL");
    }
};
