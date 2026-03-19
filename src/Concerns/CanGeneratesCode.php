<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adds automatic unique code generation capability to Eloquent models.
 *
 * The generated code follows the pattern: PREFIX + YEAR + ABBREVIATION + RANDOM_DIGITS
 *
 * Override any of the protected methods in your model to customize behavior.
 */
trait CanGenerateCode
{
    /**
     * Register model event listeners.
     */
    protected static function bootCanGenerateCode(): void
    {
        static::creating(static function (Model $model): void {
            $model->applyGeneratedCode();
        });
    }

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
        return $this->codeYearFormat ?? 'y'; // 'y' → 25, 'Y' → 2025, 'ym' → 2503, etc.
    }

    protected function getCodeAbbreviationLength(): int
    {
        return $this->codeAbbreviationLength ?? 2;
    }

    protected function getCodeRandomDigits(): int
    {
        return $this->codeRandomLength ?? 4;
    }

    protected function getMaxGenerationAttempts(): int
    {
        return $this->codeMaxAttempts ?? 12;
    }

    /**
     * Allows customizing the query used to check for code uniqueness.
     * Useful when you need to scope uniqueness (e.g. per tenant, per user, etc.)
     */
    protected function getUniquenessQuery(): Builder
    {
        return $this->newQuery();
    }

    protected function applyGeneratedCode(): void
    {
        $column = $this->getCodeColumn();

        // Skip if code is already set (manual assignment or database default)
        if (!empty($this->getAttribute($column))) {
            return;
        }

        $sourceColumn = $this->getCodeSourceColumn();
        $sourceValue = $this->getAttribute($sourceColumn) ?? '';

        $abbreviation = $this->createAbbreviation($sourceValue);

        $this->setAttribute(
            $column,
            $this->generateUniqueCode($abbreviation)
        );
    }

    /**
     * @throws RuntimeException if a unique code cannot be generated after max attempts
     */
    protected function generateUniqueCode(string $abbreviation): string
    {
        $prefix      = $this->getCodePrefix();
        $year        = now()->format($this->getCodeYearFormat());
        $abbr        = $this->normalizeAbbreviation($abbreviation);
        $randomLen   = $this->getCodeRandomDigits();
        $maxAttempts = $this->getMaxGenerationAttempts();

        $attempt = 0;

        do {
            $randomPart = $this->generateRandomNumericString($randomLen);
            $candidate  = $prefix . $year . $abbr . $randomPart;

            $exists = $this->getUniquenessQuery()
                ->where($this->getCodeColumn(), $candidate)
                ->exists();

            if (!$exists) {
                return $candidate;
            }

            $attempt++;
        } while ($attempt < $maxAttempts);

        throw new RuntimeException(sprintf(
            'Failed to generate unique code for %s after %d attempts. ' .
                'Prefix: %s | Year: %s | Abbr: %s | Digits: %d',
            static::class,
            $maxAttempts,
            $prefix,
            $year,
            $abbr,
            $randomLen
        ));
    }

    /**
     * Creates a short uppercase abbreviation from the source text (usually 2–3 letters)
     */
    protected function createAbbreviation(string $text): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9\s]/', '', $text);
        $words = collect(explode(' ', trim($cleaned)))
            ->filter()
            ->map(static fn(string $word): string => Str::upper(Str::substr($word, 0, 1)));

        return $words->take($this->getCodeAbbreviationLength())->implode('');
    }

    protected function normalizeAbbreviation(string $abbr): string
    {
        return Str::upper(Str::substr($abbr, 0, $this->getCodeAbbreviationLength()));
    }

    /**
     * Generates a zero-padded random numeric string of exact length
     * (e.g. length=4 → "0073" .. "9841", never starts with 0 unless length=1)
     */
    protected function generateRandomNumericString(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $min = (int) ('1' . str_repeat('0', $length - 1));
        $max = (int) str_repeat('9', $length);

        return sprintf("%0{$length}d", random_int($min, $max));
    }
}
