<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

trait CanGenerateCode
{
    protected static function bootCanGenerateCode(): void
    {
        static::creating(fn (Model $model) => $model->applyGeneratedCode());

        static::updating(fn (Model $model) => $model->handleCodeOnUpdate());

        static::restoring(fn (Model $model) => $model->handleCodeOnRestore());

        static::deleting(fn (Model $model) => $model->handleCodeOnDelete());
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
     |  CONFIGURATION
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

    protected function isCodeImmutable(): bool
    {
        return $this->codeImmutable ?? true;
    }

    protected function shouldRegenerateOnUpdate(): bool
    {
        return $this->regenerateCodeOnUpdate ?? true;
    }

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

        $sourceValue = $this->getCodeSourceValue();

        $abbreviation = $this->createAbbreviation($sourceValue);

        $this->setAttribute(
            $column,
            $this->generateUniqueCode($abbreviation)
        );
    }

    protected function handleCodeOnUpdate(): void
    {
        if ($this->isCodeImmutable()) {
            return;
        }

        if (! $this->shouldRegenerateOnUpdate()) {
            return;
        }

        // Detect change in source (supports dot notation)
        if ($this->hasSourceChanged()) {
            $this->applyGeneratedCode(true);
        }
    }

    protected function handleCodeOnRestore(): void
    {
        if (empty($this->getAttribute($this->getCodeColumn()))) {
            $this->applyGeneratedCode(true);
        }
    }

    protected function handleCodeOnDelete(): void
    {
        // Extension hook (intentionally empty)
    }

    /**
     * Detect if source value changed (supports relationships)
     */
    protected function hasSourceChanged(): bool
    {
        $path = $this->getCodeSourceColumn();

        // Simple column → use isDirty
        if (! str_contains($path, '.')) {
            return $this->isDirty($path);
        }

        // Relationship-based → always regenerate (safe fallback)
        return true;
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

        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = $prefix
                .$year
                .$abbr
                .$this->generateRandomNumericString($randomLength);

            if (! $this->getUniquenessQuery()
                ->where($column, $candidate)
                ->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Failed to generate unique code for '.static::class
        );
    }

    /* -----------------------------------------------------------------
     |  SOURCE RESOLUTION (DOT NOTATION SUPPORT)
     |-----------------------------------------------------------------*/

    protected function getCodeSourceValue(): string
    {
        $path = $this->getCodeSourceColumn();

        if (! str_contains($path, '.')) {
            return (string) $this->getAttribute($path);
        }

        return (string) ($this->resolvePath($path) ?? '');
    }

    protected function resolvePath(string $path): mixed
    {
        $segments = explode('.', $path);
        $value = $this;

        foreach ($segments as $segment) {
            if ($value === null) {
                return null;
            }

            if ($value instanceof Model) {
                if ($value->relationLoaded($segment)) {
                    $value = $value->getRelation($segment);

                    continue;
                }

                if (method_exists($value, $segment)) {
                    $value = $value->$segment;

                    continue;
                }

                $value = $value->getAttribute($segment);

                continue;
            }

            if (is_array($value)) {
                $value = $value[$segment] ?? null;

                continue;
            }

            if (is_object($value)) {
                $value = $value->{$segment} ?? null;

                continue;
            }

            return null;
        }

        return $value;
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
            $abbr .= strtoupper($word[0]);

            if (strlen($abbr) >= $this->getCodeAbbreviationLength()) {
                break;
            }
        }

        return $abbr ?: 'XX';
    }

    protected function normalizeAbbreviation(string $abbr): string
    {
        $length = $this->getCodeAbbreviationLength();

        return str_pad(
            strtoupper(substr($abbr, 0, $length)),
            $length,
            'X'
        );
    }

    protected function generateRandomNumericString(int $length): string
    {
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= random_int(0, 9);
        }

        if ($length > 1 && $result[0] === '0') {
            $result[0] = (string) random_int(1, 9);
        }

        return $result;
    }
}
