<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Trait CanGenerateCode
 *
 * Provides deterministic, unique, and extensible code generation for Eloquent models.
 *
 * Pattern:
 *   PREFIX + YEAR + ABBR + RANDOM
 *
 * Example:
 *   ATA25PR1234
 *
 * Design Goals:
 * - Deterministic structure
 * - High cohesion, low coupling
 * - Extensible override points
 * - Strict encapsulation
 */
trait CanGenerateCode
{
    /**
     * Boot the trait.
     */
    protected static function bootCanGenerateCode(): void
    {
        static::creating(static function (Model $model): void {
            $model->initializeGeneratedCode();
        });
    }

    /**
     * Entry point for code generation.
     *
     * @internal Called during model lifecycle.
     */
    protected function initializeGeneratedCode(): void
    {
        $column = $this->codeColumn();

        if ($this->hasPredefinedCode($column)) {
            return;
        }

        $abbreviation = $this->buildAbbreviation(
            (string) $this->getAttribute($this->sourceColumn())
        );

        $this->setAttribute(
            $column,
            $this->resolveUniqueCode($abbreviation)
        );
    }

    /* -----------------------------------------------------------------
     |  Configuration (Override Points)
     | -----------------------------------------------------------------
     */

    protected function codeColumn(): string
    {
        return $this->codeColumn ?? 'code';
    }

    protected function sourceColumn(): string
    {
        return $this->codeSourceColumn ?? 'name';
    }

    protected function codePrefix(): string
    {
        return $this->codePrefix ?? 'ATA';
    }

    protected function yearFormat(): string
    {
        return $this->codeYearFormat ?? 'y';
    }

    protected function abbreviationLength(): int
    {
        return $this->codeAbbreviationLength ?? 2;
    }

    protected function randomLength(): int
    {
        return $this->codeRandomLength ?? 4;
    }

    protected function maxAttempts(): int
    {
        return $this->codeMaxAttempts ?? 12;
    }

    /**
     * Override to scope uniqueness (multi-tenant, etc.)
     */
    protected function uniquenessQuery(): Builder
    {
        return $this->newQuery();
    }

    /* -----------------------------------------------------------------
     |  Core Generation Logic
     | -----------------------------------------------------------------
     */

    /**
     * Generate a unique code with retry strategy.
     */
    final protected function resolveUniqueCode(string $abbreviation): string
    {
        $context = $this->buildContext($abbreviation);

        for ($attempt = 1; $attempt <= $context['maxAttempts']; $attempt++) {
            $candidate = $this->composeCode($context);

            if (! $this->codeExists($candidate)) {
                return $candidate;
            }
        }

        throw $this->buildGenerationException($context);
    }

    /**
     * Build immutable generation context.
     */
    private function buildContext(string $abbreviation): array
    {
        return [
            'prefix' => $this->codePrefix(),
            'year' => now()->format($this->yearFormat()),
            'abbr' => $this->normalizeAbbreviation($abbreviation),
            'randomLen' => $this->randomLength(),
            'maxAttempts' => $this->maxAttempts(),
        ];
    }

    /**
     * Compose final code string.
     */
    private function composeCode(array $context): string
    {
        return $context['prefix']
            .$context['year']
            .$context['abbr']
            .$this->generateRandomDigits($context['randomLen']);
    }

    /**
     * Check if code already exists.
     */
    private function codeExists(string $code): bool
    {
        return $this->uniquenessQuery()
            ->where($this->codeColumn(), $code)
            ->exists();
    }

    /* -----------------------------------------------------------------
     |  Abbreviation Pipeline
     | -----------------------------------------------------------------
     */

    /**
     * Create abbreviation from source string.
     */
    protected function buildAbbreviation(string $value): string
    {
        $value = $this->sanitizeText($value);

        $letters = collect(preg_split('/\s+/', $value))
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)));

        return $letters
            ->take($this->abbreviationLength())
            ->implode('');
    }

    /**
     * Normalize abbreviation length & casing.
     */
    protected function normalizeAbbreviation(string $abbr): string
    {
        return Str::upper(
            Str::substr($abbr, 0, $this->abbreviationLength())
        );
    }

    /**
     * Remove unwanted characters.
     */
    private function sanitizeText(string $value): string
    {
        return trim(
            preg_replace('/[^A-Za-z0-9\s]/', '', $value) ?? ''
        );
    }

    /* -----------------------------------------------------------------
     |  Random Generation
     | -----------------------------------------------------------------
     */

    /**
     * Generate fixed-length numeric string.
     */
    private function generateRandomDigits(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $min = (int) ('1'.str_repeat('0', $length - 1));
        $max = (int) str_repeat('9', $length);

        return (string) random_int($min, $max);
    }

    /* -----------------------------------------------------------------
     |  Guards & Validation
     | -----------------------------------------------------------------
     */

    /**
     * Determine if code already exists on model.
     */
    private function hasPredefinedCode(string $column): bool
    {
        return ! empty($this->getAttribute($column));
    }

    /**
     * Build detailed exception.
     */
    private function buildGenerationException(array $context): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Code generation failed for [%s] after %d attempts. Context: %s',
            static::class,
            $context['maxAttempts'],
            json_encode($context, JSON_THROW_ON_ERROR)
        ));
    }
}
