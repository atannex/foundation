<?php

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateSlugPath;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class GenerateFoundationSlugPaths extends Command
{
    protected $signature = 'generate:atannex-slug-path {model?}';

    protected $description = 'Generate slug-path for all models using CanGenerateSlugPath trait';

    public function handle(): void
    {
        $models = $this->resolveModels();

        if ($models->isEmpty()) {
            $this->warn('No models found using CanGenerateSlugPath trait.');
            return;
        }

        foreach ($models as $modelClass) {
            $this->processModel($modelClass);
        }

        $this->info('Slug path generation completed successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Model Resolver
    |--------------------------------------------------------------------------
    */

    protected function resolveModels()
    {
        $filter = $this->argument('model');

        return collect($this->scanAppModels())
            ->filter(fn ($model) => is_subclass_of($model, Model::class))
            ->filter(fn ($model) => $this->usesSlugTrait($model))
            ->when($filter, fn ($c) => $c->filter(fn ($m) => $m === $filter));
    }

    protected function scanAppModels(): array
    {
        $path = app_path();

        $files = File::allFiles($path);

        $classes = [];

        foreach ($files as $file) {
            $class = $this->getClassFromFile($file->getPathname());

            if ($class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    protected function getClassFromFile(string $file): ?string
    {
        $content = file_get_contents($file);

        if (! preg_match('/namespace\s+(.+?);/', $content, $ns)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $content, $class)) {
            return null;
        }

        return $ns[1].'\\'.$class[1];
    }

    /*
    |--------------------------------------------------------------------------
    | Trait Detection
    |--------------------------------------------------------------------------
    */

    protected function usesSlugTrait(string $class): bool
    {
        $traits = class_uses_recursive($class);

        return in_array(
            CanGenerateSlugPath::class,
            $traits
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    */

    protected function processModel(string $modelClass): void
    {
        $this->info("Processing: {$modelClass}");

        /** @var Model $modelClass */
        $modelClass::query()
            ->chunkById(100, function ($items) use ($modelClass) {
                foreach ($items as $model) {
                    $this->syncModelSlugPath($model);
                }
            });
    }

    protected function syncModelSlugPath(Model $model): void
    {
        if (! method_exists($model, 'syncSlugPath')) {
            return;
        }

        $model->syncSlugPath();
        $model->saveQuietly();

        if (method_exists($model, 'cascadeSlugPathUpdate')) {
            $model->cascadeSlugPathUpdate();
        }
    }
}
