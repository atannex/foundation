<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

trait CanGenerateCode
{
    /**
     * Boot the trait.
     */
    protected static function bootCanGenerateCode(): void
    {
        // CREATE
        static::creating(static function (Model $model): void {
            /** @var self $model */
            $model->applyGeneratedCode();
        });

        // UPDATE
        static::updating(static function (Model $model): void {
            /** @var self $model */
            $model->handleCodeOnUpdate();
        });

        // RESTORE (if SoftDeletes used)
        static::restoring(static function (Model $model): void {
            /** @var self $model */
            $model->handleCodeOnRestore();
        });

        // OPTIONAL: DELETE HOOK (for audit/logging/extensions)
        static::deleting(static function (Model $model): void {
            /** @var self $model */
            $model->handleCodeOnDelete();
        });
    }

    /* -----------------------------------------------------------------
     |  PUBLIC ACCESSORS
     |-----------------------------------------------------------------*/

    public function resolveCodeColumn(): string
    {
        return $this->getCodeColumn();
    }

    public function resolveCodeConfig(): array
    {
        return [
            'column' => $this->getCodeColumn(),
            'source' => $this->getCodeSourceColumn(),
            'prefix' => $this->getCodePrefix(),
        ];
    }

    /* -----------------------------------------------------------------
     |  CONFIGURATION (Override in Model)
     |-----------------------------------------------------------------*/

    protected function getCodeColumn(): string
    {
        return $this->codeColumn ?? 'code';
    }

    protected function getCodeSourceColumn(): string
    {
        return $this->codeSourceColumn ?? 'name';
    }

    protected function getCodePrefix(): string
    {
        return $this->codePrefix ?? 'ATA';
    }

    protected function getCodeYearFormat(): string
    {
        return $this->codeYearFormat ?? 'y';
    }

    protected function getCodeAbbreviationLength(): int
    {
        return max(1, (int) ($this->codeAbbreviationLength ?? 2));
    }

    protected function getCodeRandomDigits(): int
    {
        return max(1, (int) ($this->codeRandomLength ?? 4));
    }

    protected function getMaxGenerationAttempts(): int
    {
        return max(1, (int) ($this->codeMaxAttempts ?? 12));
    }

    /**
     * Should code be immutable after creation?
     */
    protected function isCodeImmutable(): bool
    {
        return $this->codeImmutable ?? true;
    }

    /**
     * Should code regenerate when source changes?
     */
    protected function shouldRegenerateOnUpdate(): bool
    {
        return $this->regenerateCodeOnUpdate ?? true;
    }

    /**
     * Override for scoped uniqueness (tenant, etc.)
     */
    protected function getUniquenessQuery(): Builder
    {
        return $this->newQuery();
    }

    /* -----------------------------------------------------------------
     |  CORE LOGIC
     |-----------------------------------------------------------------*/

    public function applyGeneratedCode(bool $force = false): void
    {
        $column = $this->getCodeColumn();

        if (! $force && ! empty($this->getAttribute($column))) {
            return;
        }

        $sourceValue = (string) $this->getAttribute(
            $this->getCodeSourceColumn()
        );

        $abbreviation = $this->createAbbreviation($sourceValue);

        $this->setAttribute(
            $column,
            $this->generateUniqueCode($abbreviation)
        );
    }

    protected function handleCodeOnUpdate(): void
    {
        $column = $this->getCodeColumn();
        $sourceColumn = $this->getCodeSourceColumn();

        // If immutable → never change
        if ($this->isCodeImmutable()) {
            return;
        }

        // Only regenerate if source changed
        if ($this->shouldRegenerateOnUpdate() && $this->isDirty($sourceColumn)) {
            $this->applyGeneratedCode(true);
        }
    }

    protected function handleCodeOnRestore(): void
    {
        // Optional: ensure code exists after restore
        if (empty($this->getAttribute($this->getCodeColumn()))) {
            $this->applyGeneratedCode(true);
        }
    }

    protected function handleCodeOnDelete(): void
    {
        // Hook for future use (audit, archive, logging, etc.)
        // Keep empty for now (clean design)
    }

    /**
     * @throws RuntimeException
     */
    protected function generateUniqueCode(string $abbreviation): string
    {
        $prefix = $this->getCodePrefix();
        $year = now()->format($this->getCodeYearFormat());
        $abbr = $this->normalizeAbbreviation($abbreviation);
        $randomLength = $this->getCodeRandomDigits();
        $maxAttempts = $this->getMaxGenerationAttempts();
        $column = $this->getCodeColumn();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidate = $prefix
                . $year
                . $abbr
                . $this->generateRandomNumericString($randomLength);

            if (! $this->getUniquenessQuery()
                ->where($column, $candidate)
                ->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to generate unique code for %s after %d attempts.',
            static::class,
            $maxAttempts
        ));
    }

    /* -----------------------------------------------------------------
     |  HELPERS
     |-----------------------------------------------------------------*/

    protected function createAbbreviation(string $text): string
    {
        $text = trim(preg_replace('/[^A-Za-z0-9\s]/', '', $text) ?? '');

        if ($text === '') {
            return 'XX';
        }

        $words = preg_split('/\s+/', $text) ?: [];

        $abbr = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $abbr .= strtoupper($word[0]);

            if (strlen($abbr) >= $this->getCodeAbbreviationLength()) {
                break;
            }
        }

        return $abbr !== '' ? $abbr : 'XX';
    }

    protected function normalizeAbbreviation(string $abbr): string
    {
        $length = $this->getCodeAbbreviationLength();

        $abbr = strtoupper(substr($abbr, 0, $length));

        return str_pad($abbr, $length, 'X');
    }

    protected function generateRandomNumericString(int $length): string
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= (string) random_int(0, 9);
        }

        if ($length > 1 && $result[0] === '0') {
            $result[0] = (string) random_int(1, 9);
        }

        return $result;
    }
}
