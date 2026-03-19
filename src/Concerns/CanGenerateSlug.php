<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

trait CanGenerateSlug
{
    /*
    |--------------------------------------------------------------------------
    | CONFIGURATION
    |--------------------------------------------------------------------------
    */

    protected function getSlugColumn(): string
    {
        return $this->slugColumn ?? 'slug';
    }

    protected function getSlugSource(): string|array
    {
        return $this->slugSource ?? 'title';
    }

    protected function getSlugMode(): string
    {
        return $this->slugMode ?? 'word';
    }

    protected function getSlugSeparator(): string
    {
        return $this->slugSeparator ?? '-';
    }

    protected function getSlugMaxAttempts(): int
    {
        return $this->slugMaxAttempts ?? 50;
    }

    /*
    |--------------------------------------------------------------------------
    | BOOT
    |--------------------------------------------------------------------------
    */

    protected static function bootCanGenerateSlug(): void
    {
        static::creating(function (Model $model): void {
            $model->generateSlugIfMissing();
        });

        static::updating(function (Model $model): void {
            if ($model->shouldRegenerateSlug()) {
                $model->ensureSlug();
            }
        });

        static::restoring(function (Model $model): void {
            if ($model->shouldRegenerateSlugOnRestore()) {
                $model->ensureSlug();
            }
        });

        static::deleting(function (Model $model): void {
            $model->handleSlugDeleting();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | PUBLIC API (SINGLE CONTRACT)
    |--------------------------------------------------------------------------
    */

    public function resolveSlugConfig(): array
    {
        return [
            'column' => $this->getSlugColumn(),
            'source' => $this->getSlugSource(),
            'mode' => $this->getSlugMode(),
            'separator' => $this->getSlugSeparator(),
            'max' => $this->getSlugMaxAttempts(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ENTRY POINTS
    |--------------------------------------------------------------------------
    */

    public function ensureSlug(): void
    {
        $config = $this->resolveSlugConfig();

        $slug = $this->buildSlug($config);

        if ($slug === '') {
            throw new RuntimeException(
                static::class.' cannot generate slug: empty source.'
            );
        }

        $this->{$config['column']} = $this->makeSlugUnique($slug, $config);
    }

    protected function generateSlugIfMissing(): void
    {
        $column = $this->getSlugColumn();

        if (! empty($this->{$column})) {
            return;
        }

        $this->ensureSlug();
    }

    /*
    |--------------------------------------------------------------------------
    | LIFECYCLE: SOFT DELETE AWARENESS
    |--------------------------------------------------------------------------
    */

    protected function handleSlugDeleting(): void
    {
        // Safe default: do nothing
        // Override if you want slug mutation on delete
    }

    protected function shouldRegenerateSlugOnRestore(): bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | SLUG BUILDER
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
        $value = trim($this->resolveSourceValue($config['source']));

        return $value === ''
            ? ''
            : Str::slug($value, $config['separator']);
    }

    /*
    |--------------------------------------------------------------------------
    | SOURCE RESOLUTION (DOT NOTATION)
    |--------------------------------------------------------------------------
    */

    protected function resolveSourceValue(string|array $source): string
    {
        if (is_array($source)) {
            return collect($source)
                ->map(fn ($field) => $this->resolveDotValue($field))
                ->filter()
                ->implode(' ');
        }

        return $this->resolveDotValue($source);
    }

    /**
     * Supports:
     * - title
     * - user.name
     * - team.owner.name
     */
    protected function resolveDotValue(string $path): string
    {
        $segments = explode('.', $path);

        $value = $this;

        foreach ($segments as $segment) {

            if (is_object($value)) {
                $value = $value->{$segment} ?? null;
            } elseif (is_array($value)) {
                $value = $value[$segment] ?? null;
            } else {
                return '';
            }

            if ($value === null) {
                return '';
            }
        }

        return (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | UNIQUENESS (INCLUDING SOFT DELETED)
    |--------------------------------------------------------------------------
    */

    protected function makeSlugUnique(string $slug, array $config): string
    {
        $base = $slug;
        $sep = $config['separator'];
        $max = $config['max'];

        for ($i = 0; $i <= $max; $i++) {

            $candidate = $i === 0
                ? $base
                : $base.$sep.$i;

            if (! $this->slugExists($candidate, $config['column'])) {
                return $candidate;
            }
        }

        return $base.$sep.$this->randomId(6);
    }

    protected function slugExists(string $slug, string $column): bool
    {
        $query = $this->newSlugQuery()
            ->where($column, $slug);

        // exclude current record when updating
        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        return $this->applySlugConstraints($query)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | QUERY HOOK (SOFT DELETE SUPPORT HERE)
    |--------------------------------------------------------------------------
    */

    protected function newSlugQuery(): Builder
    {
        /** @var Model $this */
        $query = $this->newQuery();

        // IMPORTANT: include trashed records for uniqueness check
        if (method_exists($this, 'withTrashed')) {
            $query = $this->withTrashed();
        }

        return $query;
    }

    protected function applySlugConstraints(Builder $query): Builder
    {
        if (method_exists($this, 'scopeSlugUniqueness')) {
            return $this->scopeSlugUniqueness($query);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | REGENERATION LOGIC
    |--------------------------------------------------------------------------
    */

    protected function shouldRegenerateSlug(): bool
    {
        $source = $this->getSlugSource();

        return is_array($source)
            ? $this->isDirty($source)
            : $this->isDirty([$source]);
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
