<?php

declare(strict_types=1);

namespace Atannex\Foundation\Commands;

use Atannex\Foundation\Concerns\CanGenerateCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Command: GenerateFoundationCodes
 *
 * Batch-generates codes for models using the CanGenerateCode trait.
 *
 * Features:
 * - Safe code generation with retry logic
 * - Dry-run mode for safe testing
 * - Configurable batch processing
 * - Detailed error reporting
 * - Memory management
 * - Model alias resolution
 *
 * Usage:
 *   php artisan atannex:generate-code Product
 *   php artisan atannex:generate-code Invoice --force --dry-run
 *   php artisan atannex:generate-code "App\Models\Order" --chunk=500
 *
 * @see CanGenerateCode
 */
class GenerateFoundationCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'atannex:generate-code
        {model : Model class name or alias (e.g., Product, Invoice, App\Models\Order)}
        {--column= : Override the code column name (optional)}
        {--force : Regenerate codes even if they already exist}
        {--dry-run : Simulate execution without persisting changes to database}
        {--chunk=150 : Number of records to process per batch}
        {--memory-limit=512M : PHP memory limit for execution}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate codes for models using CanGenerateCode trait (batch processing, safe, no reflection)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->configureMemory();

        $modelClass = $this->resolveModelClass($this->argument('model'));

        if (! class_exists($modelClass)) {
            return $this->failCommand("Model class not found: {$modelClass}");
        }

        if (! $this->usesCodeGeneration($modelClass)) {
            return $this->failCommand(
                "Model [{$modelClass}] must use the CanGenerateCode trait."
            );
        }

        /** @var Model $model */
        $model = new $modelClass;

        $column = $this->resolveCodeColumn($model);

        if (! $this->columnExists($model, $column)) {
            return $this->failCommand(
                "Column '{$column}' does not exist on table '{$model->getTable()}'"
            );
        }

        $query = $this->buildQuery($modelClass, $column);
        $total = $query->count();

        if ($total === 0) {
            $this->components->info('No records to process.');

            return self::SUCCESS;
        }

        $this->renderSummary($modelClass, $column, $total);

        if (! $this->confirm('Proceed with code generation?', true)) {
            $this->components->info('Command cancelled.');

            return self::SUCCESS;
        }

        return $this->process($query, $column);
    }

    /* =====================================================================
     |  Processing Pipeline
     | ===================================================================== */

    /**
     * Process records in chunks with progress tracking.
     */
    protected function process(Builder $query, string $column): int
    {
        $total = $query->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $statistics = [
            'success' => 0,
            'failures' => collect(),
        ];

        $query->chunk((int) $this->option('chunk'), function (Collection $records) use (
            &$statistics,
            &$bar
        ): void {
            foreach ($records as $record) {
                try {
                    $this->processRecord($record);

                    if (! $this->option('dry-run')) {
                        $record->saveQuietly();
                    }

                    $statistics['success']++;
                } catch (Throwable $exception) {
                    $statistics['failures']->push([
                        'id' => $record->getKey(),
                        'error' => $exception->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        return $this->renderResults($statistics['success'], $statistics['failures']);
    }

    /**
     * Regenerate code for a single record.
     *
     *
     * @throws \LogicException If model doesn't expose regenerateCode method
     */
    protected function processRecord(Model $record): void
    {
        if (! method_exists($record, 'regenerateCode')) {
            throw new \LogicException(
                sprintf(
                    'Model [%s] must expose the regenerateCode() method.',
                    get_class($record)
                )
            );
        }

        $record->regenerateCode((bool) $this->option('force'));
    }

    /* =====================================================================
     |  Query & Validation
     | ===================================================================== */

    /**
     * Build the query for records to process.
     *
     * Filters by null codes unless --force is specified.
     */
    protected function buildQuery(string $modelClass, string $column): Builder
    {
        return $modelClass::query()
            ->when(
                ! $this->option('force'),
                fn (Builder $query) => $query->whereNull($column)
            );
    }

    /**
     * Determine if a model uses the CanGenerateCode trait.
     */
    protected function usesCodeGeneration(string $modelClass): bool
    {
        return in_array(
            CanGenerateCode::class,
            class_uses_recursive($modelClass),
            true
        );
    }

    /**
     * Resolve the code column name.
     *
     * Priority:
     * 1. --column option override
     * 2. $codeColumn property on model
     * 3. Default 'code'
     */
    protected function resolveCodeColumn(Model $model): string
    {
        if ($override = $this->option('column')) {
            return $override;
        }

        return property_exists($model, 'codeColumn')
            ? $model->codeColumn
            : 'code';
    }

    /**
     * Check if a column exists on the model's table.
     */
    protected function columnExists(Model $model, string $column): bool
    {
        return $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($model->getTable(), $column);
    }

    /* =====================================================================
     |  Output Rendering
     | ===================================================================== */

    /**
     * Render a summary of the operation before confirming.
     */
    protected function renderSummary(string $modelClass, string $column, int $total): void
    {
        $this->components->info('Code generation summary:');
        $this->components->twoColumnDetail('Model', $modelClass);
        $this->components->twoColumnDetail('Column', $column);
        $this->components->twoColumnDetail('Records', (string) $total);
        $this->components->twoColumnDetail('Force', $this->option('force') ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Dry-run', $this->option('dry-run') ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Batch size', (string) $this->option('chunk'));
        $this->newLine();
    }

    /**
     * Render the results of the operation.
     */
    protected function renderResults(int $successCount, Collection $failures): int
    {
        $this->components->info('Processing completed.');
        $this->components->twoColumnDetail('Processed', (string) $successCount);

        if ($failures->isNotEmpty()) {
            $this->components->error((string) $failures->count().' failure(s) encountered');

            if ($this->output->isVerbose()) {
                $this->renderFailureDetails($failures);
            } else {
                $this->line('Run with <fg=blue>-v</> for detailed error information.');
            }
        } else {
            $this->components->info('All records processed successfully.');
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry-run mode: No data was modified in the database.');
        }

        $this->newLine();

        return $failures->isEmpty()
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Display detailed failure information.
     */
    protected function renderFailureDetails(Collection $failures): void
    {
        $this->newLine();
        $this->line('<fg=red>Error Details:</fg=red>');

        foreach ($failures as $failure) {
            $this->line(sprintf(
                '  <fg=yellow>ID %s:</> %s',
                $failure['id'],
                $failure['error']
            ));
        }

        $this->newLine();
    }

    /* =====================================================================
     |  Configuration & Utilities
     | ===================================================================== */

    /**
     * Configure PHP memory limit for execution.
     */
    protected function configureMemory(): void
    {
        $limit = $this->option('memory-limit');

        if (! $limit) {
            return;
        }

        if (@ini_set('memory_limit', $limit) === false) {
            $this->components->warn(
                "Unable to set memory limit to {$limit}. ".
                    'Current limit: '.ini_get('memory_limit')
            );
        }
    }

    /**
     * Resolve a model class from various input formats.
     *
     * Attempts resolution in order:
     * 1. Exact class name
     * 2. Common namespace prefixes (App\Models\, App\, Domain\)
     * 3. Default to App\Models\ namespace
     *
     *
     * @example
     * resolveModelClass('Product')  // => App\Models\Product
     * resolveModelClass('App\Models\Order')  // => App\Models\Order
     */
    protected function resolveModelClass(string $input): string
    {
        $input = trim($input);

        // Check exact class name first
        if (class_exists($input)) {
            return $input;
        }

        // Try common namespace prefixes
        $namespaces = [
            '',
            'App\\Models\\',
            'App\\',
            'Domain\\',
        ];

        foreach ($namespaces as $namespace) {
            $candidate = $namespace.$input;

            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        // Default to App\Models namespace
        return "App\\Models\\{$input}";
    }

    /**
     * Terminate the command with an error message.
     */
    protected function failCommand(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
