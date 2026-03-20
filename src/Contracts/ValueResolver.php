<?php

declare(strict_types=1);

namespace Atannex\Foundation\Contracts;

interface ValueResolver
{
    public function resolve(mixed $target, string $path, bool $strict = false): mixed;
}
