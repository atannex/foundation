<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateSlug;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Throwable;

class GenerateFoundationSlugs extends Command
{
    protected $signature = 'generate:atannex-slug
                            {model?* : Fully qualified model class(es)}
                            {--force : Regenerate slugs even if they already exist}';

    protected $description = 'Generate or regenerate slugs for models using CanGenerateSlug trait.';

    /** @var array<class-string<Model>> */
    protected array $cachedModels = [];

    public function handle(): int
    {
        $models = $this->resolveModels();

        if (empty($models)) {
            $this->warn('No models found using CanGenerateSlug trait.');

            return self::SUCCESS;
        }

        foreach ($models as $modelClass) {
            $this->processModel($modelClass);
        }

        $this->newLine();
        $this->info('✅ Slug generation completed.');

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Model Resolution
    |--------------------------------------------------------------------------
    */

    protected function resolveModels(): array
    {
        $inputModels = (array) $this->argument('model');

        if (! empty($inputModels)) {
            return collect($inputModels)
                ->filter(fn ($class) => $this->isValidModel($class))
                ->values()
                ->all();
        }

        return $this->discoverModelsUsingTrait();
    }

    protected function isValidModel(string $class): bool
    {
        if (! class_exists($class)) {
            $this->error("Invalid model: {$class}");

            return false;
        }

        if (! is_subclass_of($class, Model::class)) {
            $this->error("Not an Eloquent model: {$class}");

            return false;
        }

        if (! $this->usesSlugTrait($class)) {
            $this->warn("Skipped (missing CanGenerateSlug): {$class}");

            return false;
        }

        return true;
    }

    protected function discoverModelsUsingTrait(): array
    {
        if (! empty($this->cachedModels)) {
            return $this->cachedModels;
        }

        $models = [];
        $modelPath = app_path('Models');

        if (! is_dir($modelPath)) {
            return [];
        }

        foreach (File::allFiles($modelPath) as $file) {
            $class = $this->getClassFromFile($file->getPathname());

            if (! $class || ! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ($this->usesSlugTrait($class)) {
                $models[] = $class;
            }
        }

        return $this->cachedModels = $models;
    }

    protected function usesSlugTrait(string $class): bool
    {
        return in_array(
            CanGenerateSlug::class,
            class_uses_recursive($class),
            true
        );
    }

    protected function getClassFromFile(string $path): ?string
    {
        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $path);

        return 'App\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    */

    protected function processModel(string $modelClass): void
    {
        $this->newLine();
        $this->info("🔄 Processing: {$modelClass}");

        /** @var Model&CanGenerateSlug $instance */
        $instance = new $modelClass;

        if (! method_exists($instance, 'ensureSlug')) {
            $this->warn('Skipped (no ensureSlug method).');

            return;
        }

        $query = method_exists($modelClass, 'withTrashed')
            ? $modelClass::withTrashed()
            : $modelClass::query();

        $processed = 0;

        foreach ($query->lazyById(200) as $model) {

            $slugColumn = $model->getSlugColumn();

            if (! $this->option('force') && ! empty($model->{$slugColumn})) {
                continue;
            }

            try {
                $model->ensureSlug();

                if ($model->isDirty($slugColumn)) {
                    $model->save();
                    $processed++;
                }
            } catch (Throwable $e) {
                $this->error(
                    sprintf(
                        '[%s] ID %s failed: %s',
                        class_basename($modelClass),
                        $model->getKey(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->line("✔ {$processed} records updated.");
    }
}
