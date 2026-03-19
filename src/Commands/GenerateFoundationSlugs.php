<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateSlug;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class GenerateFoundationSlugs extends Command
{
    protected $signature = 'atannex:generate-slug
                            {model? : Fully qualified model class}
                            {--force : Regenerate slugs even if they already exist}';

    protected $description = 'Generate or regenerate slugs for any model using CanGenerateSlug trait.';

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

        $this->info("\n✅ Slug generation completed.");

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Model Resolution
    |--------------------------------------------------------------------------
    */

    protected function resolveModels(): array
    {
        // If a specific model is passed → use only that
        if ($input = $this->argument('model')) {
            return [$input];
        }

        // Otherwise auto-discover models using the trait
        return $this->discoverModelsUsingTrait();
    }

    protected function discoverModelsUsingTrait(): array
    {
        $models = [];

        $modelPath = app_path('Models');

        foreach (File::allFiles($modelPath) as $file) {
            $class = $this->getClassFromFile($file->getPathname());

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ($this->usesSlugTrait($class)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    protected function usesSlugTrait(string $class): bool
    {
        $traits = class_uses_recursive($class);

        return in_array(
            CanGenerateSlug::class,
            $traits,
            true
        );
    }

    protected function getClassFromFile(string $path): ?string
    {
        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $path);
        $class = 'App\\'.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative
        );

        return $class;
    }

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    */

    protected function processModel(string $modelClass): void
    {
        $this->info("\n🔄 Processing: {$modelClass}");

        /** @var Model $instance */
        $instance = new $modelClass;

        if (! method_exists($instance, 'ensureSlug')) {
            $this->warn('Skipped (no ensureSlug method).');

            return;
        }

        $query = $modelClass::query();

        // Include soft deleted if supported
        if (method_exists($modelClass, 'withTrashed')) {
            $query = $modelClass::withTrashed();
        }

        $processed = 0;

        $query->chunkById(200, function ($records) use (&$processed) {
            foreach ($records as $model) {

                if (! $this->option('force') && ! empty($model->{$model->getSlugColumn()})) {
                    continue;
                }

                try {
                    $model->ensureSlug();
                    $model->save();

                    $processed++;
                } catch (\Throwable $e) {
                    $this->error(
                        sprintf(
                            'Failed for ID %s: %s',
                            $model->getKey(),
                            $e->getMessage()
                        )
                    );
                }
            }
        });

        $this->line("✔ {$processed} records updated.");
    }
}
