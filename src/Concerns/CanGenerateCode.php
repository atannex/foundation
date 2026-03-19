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
        static::creating(static function (Model $model): void {
            /** @var self $model */
            $model->applyGeneratedCode();
        });
    }

    /* -----------------------------------------------------------------
     |  PUBLIC ACCESSORS (Used by Commands / External Systems)
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
            'year_format' => $this->getCodeYearFormat(),
            'abbr_length' => $this->getCodeAbbreviationLength(),
            'random_length' => $this->getCodeRandomDigits(),
        ];
    }

    /* -----------------------------------------------------------------
     |  CONFIGURATION (Override in Model if needed)
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
     * Override this if you want scoped uniqueness (multi-tenant, etc.)
     */
    protected function getUniquenessQuery(): Builder
    {
        return $this->newQuery();
    }

    /* -----------------------------------------------------------------
     |  CORE LOGIC
     |-----------------------------------------------------------------*/

    public function applyGeneratedCode(): void
    {
        $column = $this->getCodeColumn();

        // Skip if already set
        if (! empty($this->getAttribute($column))) {
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
                .$year
                .$abbr
                .$this->generateRandomNumericString($randomLength);

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

        // Prevent leading zero for better readability
        if ($length > 1 && $result[0] === '0') {
            $result[0] = (string) random_int(1, 9);
        }

        return $result;
    }
}
