<?php

declare(strict_types=1);

namespace Atannex\Foundation\Supports;

use Illuminate\Database\Eloquent\Model;

trait ResolveDotValue
{
    /*
    |--------------------------------------------------------------------------
    | MAIN RESOLVER
    |--------------------------------------------------------------------------
    */

    protected function resolveDotValue(string $path, bool $strict = false): string
    {
        if ($path === '') {
            return '';
        }

        $segments = explode('.', $path);
        $value = $this;

        foreach ($segments as $segment) {

            if ($value instanceof Model) {
                $value = $this->resolveFromModel($value, $segment, $strict);
            } elseif (is_array($value)) {
                $value = $value[$segment] ?? null;
            } elseif (is_object($value)) {
                $value = $value->{$segment} ?? null;
            } else {
                return '';
            }

            if ($value === null) {
                return '';
            }
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /*
    |--------------------------------------------------------------------------
    | MODEL RESOLUTION (ELOQUENT SAFE)
    |--------------------------------------------------------------------------
    */

    protected function resolveFromModel(Model $model, string $key, bool $strict): mixed
    {
        /*
        |-----------------------------------------
        | 1. DIRECT ATTRIBUTE ACCESS (FAST PATH)
        |-----------------------------------------
        */

        if ($model->offsetExists($key)) {
            return $model->getAttribute($key);
        }

        /*
        |-----------------------------------------
        | 2. RELATION ACCESS (LAZY SAFE)
        |-----------------------------------------
        */

        if ($model->relationLoaded($key) || method_exists($model, $key)) {
            $relation = $model->{$key};

            return $relation instanceof Model || is_array($relation)
                ? $relation
                : $relation;
        }

        /*
        |-----------------------------------------
        | 3. STRICT MODE (OPTIONAL DEBUGGING)
        |-----------------------------------------
        */

        if ($strict) {
            throw new \RuntimeException(
                "Unable to resolve dot segment [{$key}] on model [" . get_class($model) . "]"
            );
        }

        return $model->getAttribute($key);
    }
}
