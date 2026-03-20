<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Commands\Concerns\InteractsWithModelGenerators;
use Atannex\Foundation\Concerns\CanGenerateSlug;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class GenerateFoundationSlugs extends Command
{
    use InteractsWithModelGenerators;

    protected $signature = 'generate:atannex-slug
                            {model?*}
                            {--force}';

    protected $description = 'Generate slugs using unified generator system.';

    public function handle(): int
    {
        $models = $this->resolveModels();

        if (empty($models)) {
            $this->warn('No models found.');

            return self::SUCCESS;
        }

        foreach ($models as $modelClass) {
            $this->processModel($modelClass);
        }

        $this->info('✅ Slug generation completed.');

        return self::SUCCESS;
    }

    protected function resolveModels(): array
    {
        $input = (array) $this->argument('model');

        if (! empty($input)) {
            return $input;
        }

        return $this->discoverModelsUsingTrait(CanGenerateSlug::class);
    }

    protected function processModel(string $modelClass): void
    {
        $this->newLine();
        $this->info("Processing: {$modelClass}");

        /** @var Model&CanGenerateSlug $instance */
        $instance = new $modelClass;

        $column = $instance->resolveSlugConfig()['column'];

        $query = method_exists($modelClass, 'withTrashed')
            ? $modelClass::withTrashed()
            : $modelClass::query();

        $count = 0;

        foreach ($query->lazyById(200) as $model) {

            if (! $this->option('force') && ! empty($model->{$column})) {
                continue;
            }

            try {
                $changed = $this->runGenerator($model);

                if ($changed) {
                    $model->save();
                    $count++;
                }
            } catch (Throwable $e) {
                $this->error("ID {$model->getKey()} failed: ".$e->getMessage());
            }
        }

        $this->line("✔ {$count} updated");
    }
}
