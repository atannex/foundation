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
 * Code Pattern:
 *   PREFIX + YEAR + ABBREVIATION + RANDOM_DIGITS
 *
 * Example:
 *   ATA25PR1234
 *
 * Design Goals:
 * - Deterministic structure for consistency
 * - High cohesion with low coupling
 * - Clear extension points for customization
 * - Strict encapsulation of implementation details
 * - Robust error handling and reporting
 *
 * @example
 * class Product extends Model
 * {
 *     use CanGenerateCode;
 *     protected string $codePrefix = 'PRD';
 *     protected string $codeSourceColumn = 'product_name';
 * }
 */
trait CanGenerateCode
{
    /**
     * Boot the trait and register model events.
     */
    protected static function bootCanGenerateCode(): void
    {
        static::creating(static function (Model $model): void {
            $model->initializeGeneratedCode();
        });
    }

    /* =====================================================================
     |  Lifecycle Entry Points
     | ===================================================================== */

    /**
     * Initialize code generation during model creation.
     *
     * Called automatically during the model lifecycle. Checks for existing
     * code before generating a new one.
     *
     * @internal Called by the creating event listener.
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

    /**
     * Public API for code regeneration.
     *
     * Safe to call externally from CLI, services, or jobs. Optionally
     * force regeneration even if a code already exists.
     *
     * @param  bool  $force  Force regeneration of existing code
     * @throws RuntimeException If generation fails after max attempts
     */
    public function regenerateCode(bool $force = false): void
    {
        $column = $this->codeColumn();

        if ($force) {
            $this->setAttribute($column, null);
        }

        $this->initializeGeneratedCode();
    }

    /* =====================================================================
     |  Configuration (Customization Points)
     | ===================================================================== */

    /**
     * Get the column name where the generated code is stored.
     *
     * @return string
     */
    protected function codeColumn(): string
    {
        return $this->codeColumn ?? 'code';
    }

    /**
     * Get the source column to derive the abbreviation from.
     *
     * @return string
     */
    protected function sourceColumn(): string
    {
        return $this->codeSourceColumn ?? 'name';
    }

    /**
     * Get the prefix for generated codes.
     *
     * @return string
     */
    protected function codePrefix(): string
    {
        return $this->codePrefix ?? 'ATA';
    }

    /**
     * Get the year format for code generation.
     *
     * Accepts any valid PHP date format (e.g., 'y' for 2-digit year, 'Y' for full).
     *
     * @return string
     */
    protected function yearFormat(): string
    {
        return $this->codeYearFormat ?? 'y';
    }

    /**
     * Get the length of the abbreviation component.
     *
     * @return int Must be greater than 0
     */
    protected function abbreviationLength(): int
    {
        return $this->codeAbbreviationLength ?? 2;
    }

    /**
     * Get the length of the random numeric component.
     *
     * @return int Must be greater than 0
     */
    protected function randomLength(): int
    {
        return $this->codeRandomLength ?? 4;
    }

    /**
     * Get the maximum number of generation attempts before failing.
     *
     * Increase this for high-collision scenarios.
     *
     * @return int
     */
    protected function maxAttempts(): int
    {
        return $this->codeMaxAttempts ?? 12;
    }

    /**
     * Customize uniqueness scope (multi-tenant, segments, etc).
     *
     * Override this method to restrict code uniqueness to specific scopes.
     *
     * @return Builder
     *
     * @example
     * protected function uniquenessQuery(): Builder
     * {
     *     return $this->newQuery()->where('tenant_id', auth()->id());
     * }
     */
    protected function uniquenessQuery(): Builder
    {
        return $this->newQuery();
    }

    /* =====================================================================
     |  Core Generation Logic
     | ===================================================================== */

    /**
     * Generate a unique code with automatic retry strategy.
     *
     * Attempts to create a unique code up to maxAttempts times before
     * raising an exception.
     *
     * @param  string  $abbreviation  The abbreviation component
     * @return string The generated unique code
     *
     * @throws RuntimeException If unable to generate unique code within max attempts
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
     * Build the immutable context for code generation.
     *
     * @param  string  $abbreviation
     * @return array
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
     * Compose the final code string from context.
     *
     * @param  array  $context
     * @return string
     */
    private function composeCode(array $context): string
    {
        return $context['prefix']
            . $context['year']
            . $context['abbr']
            . $this->generateRandomDigits($context['randomLen']);
    }

    /**
     * Check if a code already exists in the database.
     *
     * @param  string  $code
     * @return bool
     */
    private function codeExists(string $code): bool
    {
        return $this->uniquenessQuery()
            ->where($this->codeColumn(), $code)
            ->exists();
    }

    /* =====================================================================
     |  Abbreviation Pipeline
     | ===================================================================== */

    /**
     * Build abbreviation from source string.
     *
     * Extracts the first letter from each word in the source string.
     *
     * @param  string  $value  The source string (e.g., name field)
     * @return string The generated abbreviation
     *
     * @example
     * buildAbbreviation('Product Research Team')  // => 'PRT'
     * buildAbbreviation('John Doe')                // => 'JD'
     */
    protected function buildAbbreviation(string $value): string
    {
        $value = $this->sanitizeText($value);

        $letters = collect(preg_split('/\s+/', $value))
            ->filter()
            ->map(fn(string $word) => Str::upper(Str::substr($word, 0, 1)));

        return $letters
            ->take($this->abbreviationLength())
            ->implode('');
    }

    /**
     * Normalize abbreviation to correct length and uppercase.
     *
     * @param  string  $abbr
     * @return string
     */
    protected function normalizeAbbreviation(string $abbr): string
    {
        return Str::upper(
            Str::substr($abbr, 0, $this->abbreviationLength())
        );
    }

    /**
     * Sanitize text by removing special characters.
     *
     * @param  string  $value
     * @return string
     */
    private function sanitizeText(string $value): string
    {
        return trim(
            (string) preg_replace('/[^A-Za-z0-9\s]/', '', $value)
        );
    }

    /* =====================================================================
     |  Random Number Generation
     | ===================================================================== */

    /**
     * Generate a fixed-length random numeric string.
     *
     * @param  int  $length  The desired length (must be > 0)
     * @return string Numeric string of specified length
     *
     * @example
     * generateRandomDigits(4)  // => '7382'
     * generateRandomDigits(0)  // => ''
     */
    private function generateRandomDigits(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $min = (int) ('1' . str_repeat('0', $length - 1));
        $max = (int) str_repeat('9', $length);

        return (string) random_int($min, $max);
    }

    /* =====================================================================
     |  Validation & Error Handling
     | ===================================================================== */

    /**
     * Determine if a code is already defined on the model.
     *
     * @param  string  $column
     * @return bool
     */
    private function hasPredefinedCode(string $column): bool
    {
        return ! empty($this->getAttribute($column));
    }

    /**
     * Build detailed exception with generation context.
     *
     * @param  array  $context
     * @return RuntimeException
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
