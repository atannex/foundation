<?php

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateSlugPath;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class GenerateFoundationSlugPaths extends Command
{
    protected $signature = 'generate:atannex-slug-paths
                            {model? : Optional: Process only this specific model (e.g. App\\Models\\Category)}
                            {--dry-run : Show what would be updated without saving changes}
                            {--force : Skip confirmation when processing all models}';

    protected $description = 'Regenerate slug_path for all (or selected) models that use the CanGenerateSlugPath trait';

    public function handle(): int
    {
        $models = $this->resolveModelsToProcess();

        if ($models->isEmpty()) {
            $this->warn('No models found using CanGenerateSlugPath trait.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Found {$models->count()} model(s) with slug path support:");

        foreach ($models as $modelClass) {
            $this->line("  • {$modelClass}");
        }

        if (! $this->option('dry-run') && ! $this->option('force') && $models->count() > 1) {
            if (! $this->confirm('Do you want to regenerate slug paths for ALL these models?', false)) {
                $this->info('Command cancelled.');
                return self::SUCCESS;
            }
        }

        $dryRun = $this->option('dry-run');
        $totalUpdated = 0;

        foreach ($models as $modelClass) {
            $updated = $this->processModel($modelClass, $dryRun);
            $totalUpdated += $updated;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run — no changes were actually saved.');
        }

        $this->newLine();
        $this->info("Completed. {$totalUpdated} record(s) processed/updated.");

        return self::SUCCESS;
    }

    protected function resolveModelsToProcess(): \Illuminate\Support\Collection
    {
        $filter = $this->argument('model');

        $allModels = collect($this->scanAppModels())
            ->filter(fn($class) => is_subclass_of($class, Model::class))
            ->filter(fn($class) => $this->usesSlugTrait($class));

        if ($filter) {
            // Normalize input (App\Models\Category → App\Models\Category)
            $filter = Str::of($filter)
                ->trim()
                ->replace('/', '\\')
                ->ltrim('\\')
                ->toString();

            if (! class_exists($filter)) {
                $this->error("Model not found: {$filter}");
                exit(self::FAILURE);
            }

            if (! $this->usesSlugTrait($filter)) {
                $this->error("Model {$filter} does not use CanGenerateSlugPath trait.");
                exit(self::FAILURE);
            }

            return collect([$filter]);
        }

        return $allModels;
    }

    protected function scanAppModels(): array
    {
        $path = app_path();
        $files = File::allFiles($path);

        $classes = [];

        foreach ($files as $file) {
            $class = $this->getClassFromFile($file->getPathname());
            if ($class && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    protected function getClassFromFile(string $file): ?string
    {
        $content = File::get($file);

        if (! preg_match('/namespace\s+([^;]+);/i', $content, $ns)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/i', $content, $classMatch)) {
            return null;
        }

        return trim($ns[1] . '\\' . $classMatch[1]);
    }

    protected function usesSlugTrait(string $class): bool
    {
        return in_array(
            CanGenerateSlugPath::class,
            class_uses_recursive($class),
            true
        );
    }

    protected function processModel(string $modelClass, bool $dryRun = false): int
    {
        $this->info("Processing model: <fg=yellow>{$modelClass}</>");

        $count = 0;

        try {
            $modelClass::query()
                ->lazyById(150) // more memory-friendly than chunkById in many cases
                ->each(function (Model $model) use (&$count, $dryRun) {
                    $this->syncAndCascade($model, $dryRun);
                    $count++;
                });

            $this->line("  → {$count} record(s) processed");
        } catch (Throwable $e) {
            $this->error("Error processing {$modelClass}: " . $e->getMessage());
            if ($this->output->isVerbose()) {
                $this->line($e->getTraceAsString());
            }
        }

        return $count;
    }

    protected function syncAndCascade(Model $model, bool $dryRun): void
    {
        // Make sure the trait is actually present (defensive)
        if (! method_exists($model, 'syncSlugPath')) {
            return;
        }

        // Recompute slug path based on current slug + parent
        $model->syncSlugPath();

        if ($dryRun) {
            $this->line("  [DRY] Would update: {$model->getKey()} → {$model->getAttribute($model->getSlugConfig('slug_path'))}");
            return;
        }

        // Save without firing events (prevents loops)
        $model->saveQuietly();

        // If parent or slug changed → cascade to children
        if (method_exists($model, 'cascadeSlugPathUpdate')) {
            $model->cascadeSlugPathUpdate();
        }
    }
}
