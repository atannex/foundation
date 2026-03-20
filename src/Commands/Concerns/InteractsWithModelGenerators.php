<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

trait InteractsWithModelGenerators
{
    protected array $cachedModels = [];

    /*
    |--------------------------------------------------------------------------
    | MODEL DISCOVERY
    |--------------------------------------------------------------------------
    */

    protected function discoverModelsUsingTrait(string $trait): array
    {
        if (! empty($this->cachedModels[$trait])) {
            return $this->cachedModels[$trait];
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

            if (in_array($trait, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $this->cachedModels[$trait] = $models;
    }

    protected function getClassFromFile(string $path): ?string
    {
        $relative = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $path);

        return 'App\\' . str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATOR EXECUTION (UNIFIED)
    |--------------------------------------------------------------------------
    */

    protected function runGenerator(Model $model): bool
    {
        if (! method_exists($model, 'runGenerator')) {
            return false;
        }

        $before = $model->getAttributes();

        $model->runGenerator();

        return $model->getAttributes() !== $before;
    }

    /*
    |--------------------------------------------------------------------------
    | SLUG ENSURANCE (CRITICAL FOR SLUG PATH)
    |--------------------------------------------------------------------------
    */

    protected function ensureSlugIfPossible(Model $model): void
    {
        if (! method_exists($model, 'ensureSlug')) {
            return;
        }

        $config = $model->resolveSlugConfig();
        $column = $config['column'];

        if (empty($model->{$column})) {
            $model->ensureSlug();
        }
    }
}
