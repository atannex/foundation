<?php

declare(strict_types=1);

namespace Atannex\Foundation\Supports;

use RuntimeException;

trait HasAttributeGenerator
{
    abstract protected function generatorConfig(): array;

    abstract protected function shouldGenerate(): bool;

    abstract protected function generateValue(array $config): mixed;

    abstract protected function assignValue(mixed $value, array $config): void;

    /*
    |--------------------------------------------------------------------------
    | EXECUTION
    |--------------------------------------------------------------------------
    */

    protected function runGenerator(): void
    {
        if (! $this->shouldGenerate()) {
            return;
        }

        $config = $this->generatorConfig();

        $value = $this->generateValue($config);

        if ($this->isEmptyGeneratedValue($value)) {
            throw new RuntimeException(
                static::class.' generated an empty value.'
            );
        }

        $this->assignValue($value, $config);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function isEmptyGeneratedValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
