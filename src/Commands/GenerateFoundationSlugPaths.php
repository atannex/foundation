<?php

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Commands\Concerns\InteractsWithModelGenerators;
use Atannex\Foundation\Concerns\CanGenerateSlugPath;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateFoundationSlugPaths extends Command
{
    use InteractsWithModelGenerators;

    protected $signature = 'generate:atannex-slug-path
        {model?}
        {--dry-run}
        {--force}
        {--chunk=200}';

    protected $description = 'Generate slug paths with dependency-aware flow';

    public function handle(): int
    {
        $models = $this->resolveModels();

        if ($models->isEmpty()) {
            $this->warn('No models found.');

            return self::SUCCESS;
        }

        foreach ($models as $modelClass) {
            $this->info("Processing: {$modelClass}");
            $this->processModel($modelClass);
        }

        return self::SUCCESS;
    }

    protected function resolveModels(): Collection
    {
        $filter = $this->argument('model');

        if ($filter) {
            return collect([$filter]);
        }

        return collect(
            $this->discoverModelsUsingTrait(CanGenerateSlugPath::class)
        );
    }

    protected function processModel(string $modelClass): void
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $modelClass::query()->chunkById($chunk, function ($models) use ($dryRun) {

            DB::transaction(function () use ($models, $dryRun) {

                foreach ($models as $model) {
                    try {
                        $this->processRecord($model, $dryRun);
                    } catch (Throwable $e) {
                        $this->error("ID {$model->getKey()} failed: ".$e->getMessage());
                    }
                }
            });
        });
    }

    protected function processRecord(Model $model, bool $dryRun): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. ENSURE SLUG FIRST (CRITICAL DEPENDENCY)
        |--------------------------------------------------------------------------
        */

        $this->ensureSlugIfPossible($model);

        /*
        |--------------------------------------------------------------------------
        | 2. RUN GENERATOR (SLUG PATH)
        |--------------------------------------------------------------------------
        */

        $changed = $this->runGenerator($model);

        if (! $changed) {
            return;
        }

        if ($dryRun) {
            $this->line("[DRY RUN] {$model->getKey()} updated");

            return;
        }

        $model->saveQuietly();
    }
}
