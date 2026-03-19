<?php

namespace Atannex\Foundation\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

class GenerateFoundationCodes extends Command
{
    protected $signature = 'atannex:generate-code
        {model : Model name or full class (Department, App\Models\User, App\Models\Regions\Department, ...)}
        {--column=code           : The column that stores the code}
        {--force                 : Regenerate codes even when they already exist}
        {--dry-run               : Show what would be done without saving anything}
        {--chunk=150             : Number of records to process per chunk}
        {--memory-limit=512M     : PHP memory limit for this command}';

    protected $description = 'Generate missing (or force-regenerate) codes for any model that uses CanGenerateCode trait';

    public function handle(): int
    {
        $this->setMemoryLimit($this->option('memory-limit'));

        $modelInput = $this->argument('model');
        $modelClass = $this->resolveModelClass($modelInput);

        if (! class_exists($modelClass)) {
            $this->error("Model class not found: <comment>{$modelClass}</comment>");

            return self::FAILURE;
        }

        if (! in_array('Atannex\Foundation\Concerns\CanGenerateCode', class_uses_recursive($modelClass), true)) {
            $this->error("Model <comment>{$modelClass}</comment> does not use the CanGenerateCode trait.");

            return self::FAILURE;
        }

        /** @var Model $model */
        $model = new $modelClass;
        $codeColumn = $this->option('column');

        // Make sure the column actually exists on the model/table
        if (! $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $codeColumn)) {
            $this->error("Column <comment>{$codeColumn}</comment> does not exist on table <comment>{$model->getTable()}</comment>");

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $query = $modelClass::query()
            ->when(! $force, fn ($q) => $q->whereNull($codeColumn));

        $totalToProcess = $query->count();

        if ($totalToProcess === 0) {
            $this->info('No records need code generation.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Found <fg=yellow>{$totalToProcess}</> records to process in model <comment>{$modelClass}</comment>");
        $this->line("  • Column:       <comment>{$codeColumn}</comment>");
        $this->line('  • Force mode:   '.($force ? '<fg=red>YES</>' : 'no'));
        $this->line('  • Dry-run:      '.($dryRun ? '<fg=yellow>YES (no changes will be saved)</>' : 'no'));
        $this->newLine();

        if (! $this->confirm('Continue?', true)) {
            $this->info('Command cancelled.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalToProcess);
        $bar->start();

        $successCount = 0;
        $failures = collect();

        $query->chunk($this->option('chunk'), function (Collection $items) use (
            $dryRun,
            $bar,
            &$successCount,
            $failures
        ) {
            foreach ($items as $record) {
                try {
                    // The trait method — generates code only if missing
                    $record->applyGeneratedCode();

                    if (! $dryRun) {
                        $record->saveQuietly(); // skip events / observers if possible
                    }

                    $successCount++;
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

        $this->info("Successfully processed: <fg=green>{$successCount}</> records");

        if ($failures->isNotEmpty()) {
            $this->error("Failed: {$failures->count()} records");

            if ($this->output->isVerbose()) {
                $failures->each(function ($f) {
                    $this->line("  • ID {$f['id']}: <fg=red>{$f['error']}</>");
                });
            } else {
                $this->line('  Run with <comment>-v</comment> to see detailed errors.');
            }
        }

        if ($dryRun) {
            $this->comment('Dry run finished — no data was changed.');
        }

        return $failures->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Try different common namespace patterns
     */
    protected function resolveModelClass(string $input): string
    {
        $input = trim($input);

        // Already full class name
        if (class_exists($input)) {
            return $input;
        }

        // Common patterns
        $attempts = [
            $input,
            "App\\Models\\{$input}",
            "App\\Models\\Regions\\{$input}",
            "App\\{$input}",
            "Domain\\{$input}",
            "App\\Models\\{$input}Model",
        ];

        foreach ($attempts as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        // Last resort — assume it's in App\Models
        return "App\\Models\\{$input}";
    }

    protected function setMemoryLimit(string $limit): void
    {
        if (ini_set('memory_limit', $limit) === false) {
            $this->warn("Could not set memory_limit to {$limit}");
        }
    }
}
