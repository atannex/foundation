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
        {model? : Optional model (e.g. App\\Models\\Category)}
        {--dry-run : Preview changes without saving}
        {--force : Skip confirmation}';

    protected $description = 'Efficiently regenerate slug paths for models using CanGenerateSlugPath';

    public function handle(): int
    {
        $models = $this->resolveModelsToProcess();

        if ($models->isEmpty()) {
            $this->warn('No models found using CanGenerateSlugPath.');
            return self::SUCCESS;
        }

        $this->info("Processing {$models->count()} model(s)...");

        if (! $this->option('dry-run') && ! $this->option('force') && $models->count() > 1) {
            if (! $this->confirm('Proceed with all models?', false)) {
                return self::SUCCESS;
            }
        }

        $total = 0;

        foreach ($models as $modelClass) {
            $total += $this->processModel($modelClass);
        }

        $this->newLine();
        $this->info("Done. {$total} root trees processed.");

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Core Processing (Optimized)
    |--------------------------------------------------------------------------
    */

    protected function processModel(string $modelClass): int
    {
        $this->info("→ {$modelClass}");

        /** @var Model $instance */
        $instance = new $modelClass();

        $parentColumn = $instance->getSlugConfig('parent');

        $dryRun = $this->option('dry-run');

        $count = 0;

        try {
            DB::transaction(function () use (
                $modelClass,
                $parentColumn,
                &$count,
                $dryRun
            ) {
                // ✅ Only process ROOT nodes
                $roots = $modelClass::query()
                    ->whereNull($parentColumn)
                    ->orWhere($parentColumn, 0);

                $totalRoots = $roots->count();

                $bar = $this->output->createProgressBar($totalRoots);
                $bar->start();

                $roots->lazyById()->each(function (Model $root) use (&$count, $dryRun, $bar) {
                    $this->processTree($root, $dryRun);
                    $count++;
                    $bar->advance();
                });

                $bar->finish();
            });

            $this->newLine();
            $this->line("  ✓ {$count} root tree(s) processed");
        } catch (Throwable $e) {
            $this->error("  ✗ Failed: {$e->getMessage()}");

            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }
        }

        return $count;
    }

    /**
     * Process full tree from root (single cascade only)
     */
    protected function processTree(Model $root, bool $dryRun): void
    {
        if (! method_exists($root, 'syncSlugPath')) {
            return;
        }

        $root->syncSlugPath();

        if ($dryRun) {
            $this->line("  [DRY] Root {$root->getKey()} → " . $this->getSlugPath($root));
            return;
        }

        $root->saveQuietly();

        // ✅ ONE cascade per tree (critical optimization)
        $root->cascadeSlugPathUpdate();
    }

    /*
    |--------------------------------------------------------------------------
    | Model Resolution
    |--------------------------------------------------------------------------
    */

    protected function resolveModelsToProcess(): Collection
    {
        $filter = $this->argument('model');

        $models = collect($this->scanAppModels())
            ->filter(fn($class) => is_subclass_of($class, Model::class))
            ->filter(fn($class) => $this->usesSlugTrait($class));

        if (! $filter) {
            return $models->values();
        }

        $filter = Str::of($filter)
            ->replace('/', '\\')
            ->ltrim('\\')
            ->toString();

        if (! class_exists($filter)) {
            $this->error("Model not found: {$filter}");
            exit(self::FAILURE);
        }

        if (! $this->usesSlugTrait($filter)) {
            $this->error("Model does not use CanGenerateSlugPath: {$filter}");
            exit(self::FAILURE);
        }

        return collect([$filter]);
    }

    protected function scanAppModels(): array
    {
        return collect(File::allFiles(app_path()))
            ->map(fn($file) => $this->getClassFromFile($file->getPathname()))
            ->filter()
            ->filter(fn($class) => class_exists($class))
            ->values()
            ->all();
    }

    protected function getClassFromFile(string $file): ?string
    {
        $content = File::get($file);

        if (! preg_match('/namespace\s+([^;]+);/', $content, $ns)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $content, $class)) {
            return null;
        }

        return $ns[1] . '\\' . $class[1];
    }

    protected function usesSlugTrait(string $class): bool
    {
        return in_array(
            CanGenerateSlugPath::class,
            class_uses_recursive($class),
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
    */

    protected function getSlugPath(Model $model): string
    {
        return $model->getAttribute($model->getSlugConfig('slug_path'));
    }
}
