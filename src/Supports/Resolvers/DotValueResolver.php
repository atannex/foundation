<?php

declare(strict_types=1);

namespace Atannex\Foundation\Support\Resolvers;

use Atannex\Foundation\Contracts\ValueResolver;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class DotValueResolver implements ValueResolver
{
    public function resolve(mixed $target, string $path, bool $strict = false): mixed
    {
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $value = $target;

        foreach ($segments as $segment) {
            $value = $this->resolveSegment($value, $segment, $strict);

            if ($value === null) {
                return null;
            }
        }

        return $this->normalize($value);
    }

    protected function resolveSegment(mixed $value, string $key, bool $strict): mixed
    {
        if ($value instanceof Model) {
            return $this->fromModel($value, $key, $strict);
        }

        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        if (is_object($value)) {
            return $value->{$key} ?? null;
        }

        return null;
    }

    protected function fromModel(Model $model, string $key, bool $strict): mixed
    {
        // Attribute
        if ($model->offsetExists($key)) {
            return $model->getAttribute($key);
        }

        // Relation (lazy-safe)
        if ($model->relationLoaded($key) || method_exists($model, $key)) {
            return $model->{$key};
        }

        if ($strict) {
            throw new RuntimeException(
                "Unable to resolve [{$key}] on model [" . get_class($model) . ']'
            );
        }

        return $model->getAttribute($key);
    }

    protected function normalize(mixed $value): mixed
    {
        return is_scalar($value) ? $value : $value;
    }
}
