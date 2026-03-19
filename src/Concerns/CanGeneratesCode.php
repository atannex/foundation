<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait CanGenerateCode
{
    /*
    |--------------------------------------------------------------------------
    | Configuration (Override in Model)
    |--------------------------------------------------------------------------
    */

    protected function getCodePrefix(): string
    {
        return property_exists($this, 'codePrefix') ? $this->codePrefix : 'ATA';
    }

    protected function getCodeYearFormat(): string
    {
        return property_exists($this, 'codeYearFormat') ? $this->codeYearFormat : 'y';
    }

    protected function getCodeRandomLength(): int
    {
        return property_exists($this, 'codeRandomLength') ? $this->codeRandomLength : 4;
    }

    protected function getCodeMaxAttempts(): int
    {
        return property_exists($this, 'codeMaxAttempts') ? $this->codeMaxAttempts : 10;
    }

    /**
     * Override if model uses scoped queries (tenant, status, etc.)
     */
    protected function newCodeQuery(): Builder
    {
        /** @var Model $this */
        return $this->newQuery();
    }

    /*
    |--------------------------------------------------------------------------
    | Generator
    |--------------------------------------------------------------------------
    */

    public function generateUniqueCode(string $abbreviation, string $field = 'code'): string {

        $prefix = $this->getCodePrefix();
        $year = now()->format($this->getCodeYearFormat());
        $abbr = $this->normalizeAbbreviation($abbreviation);
        $length = $this->getCodeRandomLength();
        $maxAttempts = $this->getCodeMaxAttempts();

        $tries = 0;

        do {
            $randomNumber = $this->generateRandomNumber($length);

            $code = "{$prefix}{$year}{$abbr}{$randomNumber}";

            $exists = $this->newCodeQuery()
                ->where($field, $code)
                ->exists();

            $tries++;
        } while ($exists && $tries < $maxAttempts);

        if ($exists) {
            throw new \RuntimeException(sprintf(
                'Unable to generate unique code for [%s] after %d attempts.',
                static::class,
                $maxAttempts
            ));
        }

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function normalizeAbbreviation(string $value): string
    {
        return Str::upper(Str::substr($value, 0, 2));
    }

    protected function generateRandomNumber(int $length): string
    {
        $min = (int) str_pad('1', $length, '0'); // 1000...
        $max = (int) str_pad('9', $length, '9'); // 9999...

        return (string) random_int($min, $max);
    }

    /**
     * Generate abbreviation from string
     */
    protected function generateAbbreviation(string $text, int $max = 2): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]/', '', $text);

        return Str::upper(
            collect(explode(' ', $clean))
                ->filter()
                ->map(fn($word) => Str::substr($word, 0, 1))
                ->take($max)
                ->implode('')
        );
    }
}
