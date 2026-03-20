<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Atannex\Foundation\Contracts\ValueResolver;
use Atannex\Foundation\Supports\HasAttributeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait CanGenerateSlug
{
    use HasAttributeGenerator;

    /*
    |--------------------------------------------------------------------------
    | BOOT
    |--------------------------------------------------------------------------
    */

    protected static function bootCanGenerateSlug(): void
    {
        static::creating(fn (Model $m) => $m->runGenerator());

        static::updating(function (Model $m): void {
            if ($m->shouldGenerate()) {
                $m->runGenerator();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATOR IMPLEMENTATION
    |--------------------------------------------------------------------------
    */

    protected function generatorConfig(): array
    {
        return [
            'column' => $this->slugColumn ?? 'slug',
            'source' => $this->slugSource ?? 'title',
            'mode' => $this->slugMode ?? 'word',
            'separator' => $this->slugSeparator ?? '-',
            'max' => $this->slugMaxAttempts ?? 50,
        ];
    }

    protected function shouldGenerate(): bool
    {
        $column = $this->slugColumn ?? 'slug';

        return empty($this->{$column})
            || $this->isDirty((array) ($this->slugSource ?? 'title'));
    }

    protected function generateValue(array $config): string
    {
        $slug = $this->buildSlug($config);

        return $this->makeSlugUnique($slug, $config);
    }

    protected function assignValue(mixed $value, array $config): void
    {
        $this->{$config['column']} = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | BUILDING
    |--------------------------------------------------------------------------
    */

    protected function buildSlug(array $config): string
    {
        return match ($config['mode']) {
            'random' => $this->randomId(),

            'mixed' => $this->wordSlug($config)
                .$config['separator']
                .$this->randomId(),

            default => $this->wordSlug($config),
        };
    }

    protected function wordSlug(array $config): string
    {
        $value = $this->resolveSourceValue($config['source']);

        return $value
            ? Str::slug($value, $config['separator'])
            : '';
    }

    protected function resolveSourceValue(string|array $source): string
    {
        $resolver = app(ValueResolver::class);

        if (is_array($source)) {
            return collect($source)
                ->map(fn ($field) => $resolver->resolve($this, $field))
                ->filter()
                ->implode(' ');
        }

        return (string) $resolver->resolve($this, $source);
    }

    /*
    |--------------------------------------------------------------------------
    | UNIQUENESS
    |--------------------------------------------------------------------------
    */

    protected function makeSlugUnique(string $slug, array $config): string
    {
        $base = $slug;
        $sep = $config['separator'];

        for ($i = 0; $i <= $config['max']; $i++) {
            $candidate = $i === 0 ? $base : $base.$sep.$i;

            if (! $this->slugExists($candidate, $config['column'])) {
                return $candidate;
            }
        }

        return $base.$sep.$this->randomId(6);
    }

    protected function slugExists(string $slug, string $column): bool
    {
        $query = $this->newQuery()->where($column, $slug);

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        if (method_exists($this, 'withTrashed')) {
            $query = $this->withTrashed();
        }

        return $query->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function randomId(int $length = 12): string
    {
        return Str::lower(Str::random($length));
    }
}
