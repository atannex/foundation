<?php

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateSlugPath;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class GenerateFoundationSlugPaths extends Command
{
    protected $signature = 'generate:atannex-slug-path
        {model? : Optional model class}
        {--dry-run : Preview changes only}
        {--force : Skip confirmation}
        {--chunk=200 : Chunk size for processing}';

    protected $description = 'Enterprise slug_path regeneration for CanGenerateSlugPath models';

    public function handle(): int
    {
        $models = $this->resolveModelsToProcess();

        if ($models->isEmpty()) {
            $this->warn('No models found.');

            return self::SUCCESS;
        }

        $this->info("Found {$models->count()} model(s).");

        foreach ($models as $model) {
            $this->line(" • {$model}");
        }

        if (
            ! $this->option('dry-run') &&
            ! $this->option('force') &&
            $models->count() > 1
        ) {
            if (! $this->confirm('Proceed with ALL models?', false)) {
                return self::SUCCESS;
            }
        }

        $total = 0;

        foreach ($models as $modelClass) {
            $this->newLine();
            $this->info("Processing: <fg=yellow>{$modelClass}</>");

            $count = $this->processModel($modelClass);
            $total += $count;

            $this->line(" → {$count} records processed");
        }

        $this->newLine();
        $this->info("DONE → {$total} total records processed");

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode: no changes were saved.');
        }

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | MODEL DISCOVERY
    |--------------------------------------------------------------------------
    */

    protected function resolveModelsToProcess(): Collection
    {
        $filter = $this->argument('model');

        $models = collect($this->scanModels())
            ->filter(fn ($class) => is_subclass_of($class, Model::class))
            ->filter(fn ($class) => in_array(
                CanGenerateSlugPath::class,
                class_uses_recursive($class)
            ));

        if ($filter) {
            $filter = Str::of($filter)
                ->replace('/', '\\')
                ->trim()
                ->ltrim('\\')
                ->toString();

            if (! class_exists($filter)) {
                $this->error("Model not found: {$filter}");
                exit(self::FAILURE);
            }

            if (! in_array(CanGenerateSlugPath::class, class_uses_recursive($filter))) {
                $this->error("Model {$filter} does not use CanGenerateSlugPath.");
                exit(self::FAILURE);
            }

            return collect([$filter]);
        }

        return $models->values();
    }

    protected function scanModels(): array
    {
        return collect(File::allFiles(app_path()))
            ->map(fn ($file) => $this->extractClass($file->getPathname()))
            ->filter()
            ->filter(fn ($class) => class_exists($class))
            ->values()
            ->all();
    }

    protected function extractClass(string $file): ?string
    {
        $content = File::get($file);

        if (! preg_match('/namespace\s+([^;]+);/', $content, $ns)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $content, $class)) {
            return null;
        }

        return $ns[1].'\\'.$class[1];
    }

    /*
    |--------------------------------------------------------------------------
    | PROCESS MODEL (CAPABILITY-AWARE)
    |--------------------------------------------------------------------------
    */

    protected function processModel(string $modelClass): int
    {
        /** @var Model&CanGenerateSlugPath $instance */
        $instance = new $modelClass;

        if (method_exists($instance, 'hasHierarchy') && $instance->hasHierarchy()) {
            return $this->processHierarchicalModel($modelClass);
        }

        return $this->processFlatModel($modelClass);
    }

    /*
    |--------------------------------------------------------------------------
    | HIERARCHICAL MODELS (ROOT-FIRST STRATEGY)
    |--------------------------------------------------------------------------
    */

    protected function processHierarchicalModel(string $modelClass): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        $instance = new $modelClass;
        $parentColumn = $instance->resolveSlugPathConfig()['parent'];

        // Start from ROOT nodes (prevents redundant recalculations)
        $modelClass::query()
            ->whereNull($parentColumn)
            ->chunkById($chunkSize, function ($roots) use (&$total, $dryRun) {

                foreach ($roots as $root) {
                    try {
                        $total += $this->processTree($root, $dryRun);
                    } catch (Throwable $e) {
                        $this->logError($root, $e);
                    }
                }
            });

        return $total;
    }

    protected function processTree(Model $model, bool $dryRun): int
    {
        $count = 0;

        DB::transaction(function () use ($model, $dryRun, &$count) {

            $count += $this->processRecord($model, $dryRun);

            if (! $dryRun && method_exists($model, 'updateDescendants')) {
                $model->updateDescendants();
            }
        });

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | NON-HIERARCHICAL MODELS
    |--------------------------------------------------------------------------
    */

    protected function processFlatModel(string $modelClass): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $total = 0;

        $modelClass::query()
            ->chunkById($chunkSize, function ($models) use (&$total, $dryRun) {

                DB::transaction(function () use ($models, &$total, $dryRun) {
                    foreach ($models as $model) {
                        try {
                            $total += $this->processRecord($model, $dryRun);
                        } catch (Throwable $e) {
                            $this->logError($model, $e);
                        }
                    }
                });
            });

        return $total;
    }

    /*
    |--------------------------------------------------------------------------
    | RECORD PROCESSING
    |--------------------------------------------------------------------------
    */

    protected function processRecord(Model $model, bool $dryRun): int
    {
        if (! method_exists($model, 'generateSlugPath')) {
            return 0;
        }

        $config = $model->resolveSlugPathConfig();
        $column = $config['path'];

        $original = $model->getAttribute($column);

        $model->generateSlugPath();

        $updated = $model->getAttribute($column);

        if ($original === $updated) {
            return 0;
        }

        if ($dryRun) {
            $this->line("[DRY RUN] {$model->getKey()} → {$updated}");

            return 1;
        }

        $model->saveQuietly();

        return 1;
    }

    /*
    |--------------------------------------------------------------------------
    | ERROR HANDLING
    |--------------------------------------------------------------------------
    */

    protected function logError(Model $model, Throwable $e): void
    {
        $this->error(
            'Error ['.get_class($model)." ID: {$model->getKey()}]: {$e->getMessage()}"
        );

        if ($this->output->isVerbose()) {
            $this->line($e->getTraceAsString());
        }
    }
}
