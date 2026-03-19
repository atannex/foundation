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
        static::creating(static function (Model $model): void {
            /** @var self $model */
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

    protected function getUniquenessQuery(): Builder
    {
        return $this->newQuery();
    }

    public function applyGeneratedCode(): void
    {
        $column = $this->getCodeColumn();

        if (! empty($this->getAttribute($column))) {
            return;
        }

        $sourceValue = (string) $this->getAttribute($this->getCodeSourceColumn());

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

            if (! $this->getUniquenessQuery()->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to generate unique code for %s after %d attempts.',
            static::class,
            $maxAttempts
        ));
    }

    protected function createAbbreviation(string $text): string
    {
        $text = trim(preg_replace('/[^A-Za-z0-9\s]/', '', $text) ?? '');

        if ($text === '') {
            return 'XX'; // safe fallback
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

        return str_pad($abbr, $length, 'X'); // guarantees fixed length
    }

    protected function generateRandomNumericString(int $length): string
    {
        // Avoid integer overflow for large lengths
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= (string) random_int(0, 9);
        }

        // Prevent leading zero for multi-digit numbers
        if ($length > 1 && $result[0] === '0') {
            $result[0] = (string) random_int(1, 9);
        }

        return $result;
    }
}
