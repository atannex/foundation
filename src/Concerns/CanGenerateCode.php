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
 *     protected string $codeColumn = 'code';
 *     protected string $codeSourceColumn = 'name';
 * }
 *
 * @since 1.1.0 Improved to use property-based configuration
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
        $column = $this->getCodeColumn();

        if ($this->hasPredefinedCode($column)) {
            return;
        }

        $abbreviation = $this->buildAbbreviation(
            (string) $this->getAttribute($this->getSourceColumn())
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
     *
     * @throws RuntimeException If generation fails after max attempts
     */
    public function regenerateCode(bool $force = false): void
    {
        $column = $this->getCodeColumn();

        if ($force) {
            $this->setAttribute($column, null);
        }

        $this->initializeGeneratedCode();
    }

    /* =====================================================================
     |  Configuration Access (Property-Based with Method Fallback)
     | ===================================================================== */

    /**
     * Get the column name where the generated code is stored.
     *
     * Uses property if defined, otherwise falls back to default.
     */
    protected function getCodeColumn(): string
    {
        return $this->getConfigProperty('codeColumn', 'code');
    }

    /**
     * Get the source column to derive the abbreviation from.
     *
     * Uses property if defined, otherwise falls back to default.
     */
    protected function getSourceColumn(): string
    {
        return $this->getConfigProperty('codeSourceColumn', 'name');
    }

    /**
     * Get the prefix for generated codes.
     *
     * Uses property if defined, otherwise falls back to default.
     */
    protected function getCodePrefix(): string
    {
        return $this->getConfigProperty('codePrefix', 'ATA');
    }

    /**
     * Get the year format for code generation.
     *
     * Accepts any valid PHP date format (e.g., 'y' for 2-digit year, 'Y' for full).
     *
     * Uses property if defined, otherwise falls back to default.
     */
    protected function getYearFormat(): string
    {
        return $this->getConfigProperty('codeYearFormat', 'y');
    }

    /**
     * Get the length of the abbreviation component.
     *
     * Uses property if defined, otherwise falls back to default.
     *
     * @return int Must be greater than 0
     */
    protected function getAbbreviationLength(): int
    {
        $value = $this->getConfigProperty('codeAbbreviationLength', 2);

        return is_int($value) ? $value : 2;
    }

    /**
     * Get the length of the random numeric component.
     *
     * Uses property if defined, otherwise falls back to default.
     *
     * @return int Must be greater than 0
     */
    protected function getRandomLength(): int
    {
        $value = $this->getConfigProperty('codeRandomLength', 4);

        return is_int($value) ? $value : 4;
    }

    /**
     * Get the maximum number of generation attempts before failing.
     *
     * Increase this for high-collision scenarios.
     *
     * Uses property if defined, otherwise falls back to default.
     */
    protected function getMaxAttempts(): int
    {
        $value = $this->getConfigProperty('codeMaxAttempts', 12);

        return is_int($value) ? $value : 12;
    }

    /**
     * Safely retrieve configuration property.
     *
     * This method prevents conflicts with Eloquent's magic accessor system
     * by checking property existence before accessing it.
     */
    private function getConfigProperty(string $property, mixed $default = null): mixed
    {
        // Check if property is declared on the model
        if (property_exists($this, $property)) {
            // Directly access the property without triggering __get
            return $this->{$property} ?? $default;
        }

        return $default;
    }

    /**
     * Customize uniqueness scope (multi-tenant, segments, etc).
     *
     * Override this method to restrict code uniqueness to specific scopes.
     *
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
     */
    private function buildContext(string $abbreviation): array
    {
        return [
            'prefix' => $this->getCodePrefix(),
            'year' => now()->format($this->getYearFormat()),
            'abbr' => $this->normalizeAbbreviation($abbreviation),
            'randomLen' => $this->getRandomLength(),
            'maxAttempts' => $this->getMaxAttempts(),
        ];
    }

    /**
     * Compose the final code string from context.
     */
    private function composeCode(array $context): string
    {
        return $context['prefix']
            .$context['year']
            .$context['abbr']
            .$this->generateRandomDigits($context['randomLen']);
    }

    /**
     * Check if a code already exists in the database.
     */
    private function codeExists(string $code): bool
    {
        return $this->uniquenessQuery()
            ->where($this->getCodeColumn(), $code)
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
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)));

        return $letters
            ->take($this->getAbbreviationLength())
            ->implode('');
    }

    /**
     * Normalize abbreviation to correct length and uppercase.
     */
    protected function normalizeAbbreviation(string $abbr): string
    {
        return Str::upper(
            Str::substr($abbr, 0, $this->getAbbreviationLength())
        );
    }

    /**
     * Sanitize text by removing special characters.
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

        $min = (int) ('1'.str_repeat('0', $length - 1));
        $max = (int) str_repeat('9', $length);

        return (string) random_int($min, $max);
    }

    /* =====================================================================
     |  Validation & Error Handling
     | ===================================================================== */

    /**
     * Determine if a code is already defined on the model.
     */
    private function hasPredefinedCode(string $column): bool
    {
        return ! empty($this->getAttribute($column));
    }

    /**
     * Build detailed exception with generation context.
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
