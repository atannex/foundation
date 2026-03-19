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
    | MODEL RESOLUTION
    |--------------------------------------------------------------------------
    */

    protected function resolveModels(): array
    {
        $input = (array) $this->argument('model');

        if (! empty($input)) {
            return collect($input)
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
        $path = app_path('Models');

        if (! is_dir($path)) {
            return [];
        }

        foreach (File::allFiles($path) as $file) {
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
    | PROCESSING
    |--------------------------------------------------------------------------
    */

    protected function processModel(string $modelClass): void
    {
        $this->newLine();
        $this->info("🔄 Processing: {$modelClass}");

        /** @var Model&CanGenerateSlug $model */
        $model = new $modelClass;

        if (! method_exists($model, 'ensureSlug')) {
            $this->warn('Skipped (missing ensureSlug).');

            return;
        }

        $query = method_exists($modelClass, 'withTrashed')
            ? $modelClass::withTrashed()
            : $modelClass::query();

        $processed = 0;

        foreach ($query->lazyById(200) as $record) {

            // ✅ SINGLE CONTRACT USAGE
            $config = $record->resolveSlugConfig();
            $column = $config['column'];

            if (! $this->option('force') && ! empty($record->{$column})) {
                continue;
            }

            try {
                $record->ensureSlug();

                if ($record->isDirty($column)) {
                    $record->save();
                    $processed++;
                }
            } catch (Throwable $e) {
                $this->error(sprintf(
                    '[%s] ID %s failed: %s',
                    class_basename($modelClass),
                    $record->getKey(),
                    $e->getMessage()
                ));
            }
        }

        $this->line("✔ {$processed} records updated.");
    }
}
