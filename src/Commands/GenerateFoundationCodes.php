<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateFoundationCodes extends Command
{
    protected $signature = 'atannex:generate-code
        {model? : Model class (optional)}
        {--force : Force regenerate codes}
        {--with-trashed : Include soft deleted records}
        {--dry-run : Do not persist changes}
        {--chunk=200 : Chunk size}
        {--memory-limit=512M : PHP memory limit}';

    protected $description = 'Generate and manage codes for models using CanGenerateCode trait';

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
            $this->warn('No valid models found.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $withTrashed = (bool) $this->option('with-trashed');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->info('Processing models:');
        foreach ($models as $m) {
            $this->line("  • {$m}");
        }
        $this->newLine();

        $totalSuccess = 0;
        $failures = collect();

        foreach ($models as $modelClass) {
            /** @var Model&\Atannex\Foundation\Concerns\CanGenerateCode $model */
            $model = new $modelClass;

            if (! method_exists($model, 'resolveCodeColumn')) {
                $this->warn("Skipping {$modelClass} (invalid trait)");
                continue;
            }

            $codeColumn = $model->resolveCodeColumn();

            if (! $this->columnExists($model, $codeColumn)) {
                $this->warn("Skipping {$modelClass} (missing column: {$codeColumn})");
                continue;
            }

            $query = $modelClass::query();

            // Soft delete handling
            if ($withTrashed && $this->usesSoftDeletes($model)) {
                $query = $query->withTrashed();
            }

            // Only missing unless forced
            if (! $force) {
                $query->whereNull($codeColumn);
            }

            $count = $query->count();

            if ($count === 0) {
                $this->line("No records for {$modelClass}");
                continue;
            }

            $this->info("Processing {$modelClass} ({$count})");

            $bar = $this->output->createProgressBar($count);
            $bar->start();

            $query->chunkById($chunkSize, function ($records) use (
                $dryRun,
                $force,
                $bar,
                &$totalSuccess,
                $failures
            ) {
                foreach ($records as $record) {
                    try {
                        $this->processRecord($record, $force, $dryRun);
                        $totalSuccess++;
                    } catch (Throwable $e) {
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
                foreach ($failures as $f) {
                    $this->line("{$f['model']}#{$f['id']} → {$f['error']}");
                }
            } else {
                $this->line('Run with -v for details.');
            }
        }

        if ($dryRun) {
            $this->comment('Dry-run complete. No changes saved.');
        }

        return $failures->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /* -----------------------------------------------------------------
     |  RECORD PROCESSING (RETRY SAFE)
     |-----------------------------------------------------------------*/

    protected function processRecord(Model $record, bool $force, bool $dryRun): void
    {
        $attempts = 0;
        $maxRetries = 3;

        retry:
        try {
            DB::beginTransaction();

            $record->applyGeneratedCode($force);

            if (! $dryRun) {
                $record->saveQuietly();
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            if ($this->isUniqueConstraintError($e) && $attempts < $maxRetries) {
                $attempts++;
                goto retry;
            }

            throw $e;
        }
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
                throw new RuntimeException("{$class} does not use CanGenerateCode");
            }

            return collect([$class]);
        }

        return collect($this->discoverModels(app_path('Models')))
            ->filter(fn($c) => $this->usesTrait($c))
            ->values();
    }

    protected function discoverModels(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        return collect(scandir($path))
            ->filter(fn($f) => str_ends_with($f, '.php'))
            ->map(fn($f) => 'App\\Models\\' . Str::replaceLast('.php', '', $f))
            ->filter(fn($c) => class_exists($c))
            ->values()
            ->all();
    }

    protected function usesTrait(string $class): bool
    {
        return in_array(
            \Atannex\Foundation\Concerns\CanGenerateCode::class,
            class_uses_recursive($class),
            true
        );
    }

    protected function usesSoftDeletes(Model $model): bool
    {
        return in_array(
            SoftDeletes::class,
            class_uses_recursive($model),
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

    protected function isUniqueConstraintError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'UNIQUE')
            || str_contains($e->getMessage(), 'duplicate');
    }

    protected function setMemoryLimit(string $limit): void
    {
        if (@ini_set('memory_limit', $limit) === false) {
            $this->warn("Unable to set memory limit to {$limit}");
        }
    }
}
