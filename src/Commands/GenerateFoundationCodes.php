<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

class GenerateFoundationCodes extends Command
{
    protected $signature = 'atannex:generate-code
        {model : Model class or alias}
        {--column= : Override code column (optional)}
        {--force : Regenerate codes even if they exist}
        {--dry-run : Simulate execution without saving}
        {--chunk=150 : Number of records per batch}
        {--memory-limit=512M : Memory limit}';

    protected $description = 'Generate codes for models using CanGenerateCode trait (enterprise-safe, no reflection)';

    public function handle(): int
    {
        $this->configureMemory();

        $modelClass = $this->resolveModelClass($this->argument('model'));

        if (! class_exists($modelClass)) {
            return $this->failCommand("Model not found: {$modelClass}");
        }

        if (! $this->usesCodeGeneration($modelClass)) {
            return $this->failCommand("Model {$modelClass} must use CanGenerateCode trait.");
        }

        /** @var Model $model */
        $model = new $modelClass;

        $column = $this->resolveCodeColumn($model);

        if (! $this->columnExists($model, $column)) {
            return $this->failCommand("Column {$column} does not exist on table {$model->getTable()}");
        }

        $query = $this->buildQuery($modelClass, $column);

        $total = $query->count();

        if ($total === 0) {
            $this->info('No records to process.');

            return self::SUCCESS;
        }

        $this->renderSummary($modelClass, $column, $total);

        if (! $this->confirm('Proceed?', true)) {
            $this->info('Command cancelled.');

            return self::SUCCESS;
        }

        return $this->process($query, $column);
    }

    /* -----------------------------------------------------------------
     |  Processing Pipeline
     | -----------------------------------------------------------------
     */

    protected function process($query, string $column): int
    {
        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $success = 0;
        $failures = collect();

        $query->chunk((int) $this->option('chunk'), function (Collection $records) use (

            &$success,
            $failures,
            $bar
        ) {
            foreach ($records as $record) {
                try {
                    $this->processRecord($record);

                    if (! $this->option('dry-run')) {
                        $record->saveQuietly();
                    }

                    $success++;
                } catch (Throwable $e) {
                    $failures->push([
                        'id' => $record->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        return $this->renderResults($success, $failures);
    }

    protected function processRecord(Model $record): void
    {
        if (! method_exists($record, 'regenerateCode')) {
            throw new \LogicException(sprintf(
                'Model [%s] must expose regenerateCode() method.',
                get_class($record)
            ));
        }

        $record->regenerateCode((bool) $this->option('force'));
    }

    /* -----------------------------------------------------------------
     |  Query & Validation
     | -----------------------------------------------------------------
     */

    protected function buildQuery(string $modelClass, string $column)
    {
        return $modelClass::query()
            ->when(
                ! $this->option('force'),
                fn ($q) => $q->whereNull($column)
            );
    }

    protected function usesCodeGeneration(string $modelClass): bool
    {
        return in_array(
            'Atannex\Foundation\Concerns\CanGenerateCode',
            class_uses_recursive($modelClass),
            true
        );
    }

    protected function resolveCodeColumn(Model $model): string
    {
        return $this->option('column')
            ?: (method_exists($model, 'codeColumn') ? $model->codeColumn() : 'code');
    }

    protected function columnExists(Model $model, string $column): bool
    {
        return $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }

    /* -----------------------------------------------------------------
     |  Output Rendering
     | -----------------------------------------------------------------
     */

    protected function renderSummary(string $model, string $column, int $total): void
    {
        $this->info("Processing model: {$model}");
        $this->line("  • Column: {$column}");
        $this->line("  • Records: {$total}");
        $this->line('  • Force: '.($this->option('force') ? 'YES' : 'no'));
        $this->line('  • Dry-run: '.($this->option('dry-run') ? 'YES' : 'no'));
        $this->newLine();
    }

    protected function renderResults(int $success, Collection $failures): int
    {
        $this->info("Successfully processed: {$success}");

        if ($failures->isNotEmpty()) {
            $this->error("Failures: {$failures->count()}");

            if ($this->output->isVerbose()) {
                foreach ($failures as $failure) {
                    $this->line(" - ID {$failure['id']}: {$failure['error']}");
                }
            } else {
                $this->line('Run with -v for detailed errors.');
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry-run completed. No data was modified.');
        }

        return $failures->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /* -----------------------------------------------------------------
     |  Utilities
     | -----------------------------------------------------------------
     */

    protected function configureMemory(): void
    {
        $limit = $this->option('memory-limit');

        if ($limit && ini_set('memory_limit', $limit) === false) {
            $this->warn("Unable to set memory limit to {$limit}");
        }
    }

    protected function resolveModelClass(string $input): string
    {
        $input = trim($input);

        if (class_exists($input)) {
            return $input;
        }

        $namespaces = [
            '',
            'App\\Models\\',
            'App\\',
            'Domain\\',
        ];

        foreach ($namespaces as $ns) {
            $candidate = $ns.$input;

            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return "App\\Models\\{$input}";
    }

    protected function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
