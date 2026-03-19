<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateFoundationCodes extends Command
{
    protected $signature = 'atannex:generate-code
        {model? : Model class (optional). If omitted, all models using the trait will be processed}
        {--force : Regenerate existing codes}
        {--dry-run : Do not persist changes}
        {--chunk=200 : Records per batch}
        {--memory-limit=512M : PHP memory limit}';

    protected $description = 'Generate codes for models using CanGenerateCode trait';

    public function handle(): int
    {
        $this->setMemoryLimit((string) $this->option('memory-limit'));

        try {
            $models = $this->resolveTargetModels();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($models->isEmpty()) {
            $this->warn('No valid models found using CanGenerateCode trait.');

            return self::FAILURE;
        }

        $this->info('Processing models:');
        foreach ($models as $model) {
            $this->line("  • {$model}");
        }

        $this->newLine();

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $totalSuccess = 0;
        $failures = collect();

        foreach ($models as $modelClass) {
            /** @var Model&CanGenerateCode $model */
            $model = new $modelClass;

            if (! method_exists($model, 'resolveCodeColumn')) {
                $this->warn("Skipping {$modelClass} (invalid trait implementation)");

                continue;
            }

            $codeColumn = $model->resolveCodeColumn();

            if (! $this->columnExists($model, $codeColumn)) {
                $this->warn("Skipping {$modelClass} (missing column: {$codeColumn})");

                continue;
            }

            $query = $modelClass::query()
                ->when(! $force, fn ($q) => $q->whereNull($codeColumn));

            $count = $query->count();

            if ($count === 0) {
                $this->line("No records to process for {$modelClass}");

                continue;
            }

            $this->info("Processing {$modelClass} ({$count} records)");

            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $query->chunkById($chunkSize, function ($records) use (
                $dryRun,
                $bar,
                &$totalSuccess,
                $failures
            ) {
                foreach ($records as $record) {
                    try {
                        DB::beginTransaction();

                        $record->applyGeneratedCode();

                        if (! $dryRun) {
                            $record->saveQuietly();
                        }

                        DB::commit();

                        $totalSuccess++;
                    } catch (Throwable $e) {
                        DB::rollBack();

                        $failures->push([
                            'model' => get_class($record),
                            'id' => $record->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine(2);
        }

        $this->info("Total Success: {$totalSuccess}");

        if ($failures->isNotEmpty()) {
            $this->error("Failures: {$failures->count()}");

            if ($this->output->isVerbose()) {
                foreach ($failures as $fail) {
                    $this->line("{$fail['model']}#{$fail['id']} → {$fail['error']}");
                }
            } else {
                $this->line('Run with -v for detailed errors.');
            }
        }

        if ($dryRun) {
            $this->comment('Dry-run completed. No data persisted.');
        }

        return $failures->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /* -----------------------------------------------------------------
     |  MODEL RESOLUTION
     |-----------------------------------------------------------------*/

    protected function resolveTargetModels(): Collection
    {
        $input = $this->argument('model');

        if ($input) {
            $class = $this->resolveModelClass($input);

            if (! class_exists($class)) {
                throw new RuntimeException("Model not found: {$class}");
            }

            if (! $this->usesTrait($class)) {
                throw new RuntimeException("{$class} does not use CanGenerateCode trait");
            }

            return collect([$class]);
        }

        return collect($this->discoverModels(app_path('Models')))
            ->filter(fn ($class) => $this->usesTrait($class))
            ->values();
    }

    protected function discoverModels(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        return collect(scandir($path))
            ->filter(fn ($file) => str_ends_with($file, '.php'))
            ->map(fn ($file) => 'App\\Models\\'.Str::replaceLast('.php', '', $file))
            ->filter(fn ($class) => class_exists($class))
            ->values()
            ->all();
    }

    protected function usesTrait(string $class): bool
    {
        return in_array(
            CanGenerateCode::class,
            class_uses_recursive($class),
            true
        );
    }

    /* -----------------------------------------------------------------
     |  UTILITIES
     |-----------------------------------------------------------------*/

    protected function columnExists(Model $model, string $column): bool
    {
        return $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }

    protected function resolveModelClass(string $input): string
    {
        if (class_exists($input)) {
            return $input;
        }

        $namespaces = [
            "App\\Models\\{$input}",
            "App\\{$input}",
            "Domain\\{$input}",
        ];

        foreach ($namespaces as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return $input;
    }

    protected function setMemoryLimit(string $limit): void
    {
        if (@ini_set('memory_limit', $limit) === false) {
            $this->warn("Unable to set memory limit to {$limit}");
        }
    }
}
