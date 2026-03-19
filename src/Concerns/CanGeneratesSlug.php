<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait CanGenerateSlug
{
    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function bootCanGenerateSlug(): void
    {
        static::creating(function (Model $model) {
            $model->ensureSlug();
        });

        static::updating(function (Model $model) {
            if ($model->shouldRegenerateSlug()) {
                $model->ensureSlug();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    public function ensureSlug(): void
    {
        $slug = $this->buildSlug();
        $slug = $this->makeSlugUnique($slug);

        $this->{$this->getSlugColumn()} = $slug;
    }

    /*
    |--------------------------------------------------------------------------
    | Slug Builder
    |--------------------------------------------------------------------------
    */

    protected function buildSlug(): string
    {
        return match ($this->getSlugMode()) {
            'random' => $this->generateRandomId(),
            'mixed' => $this->buildWordSlug() . $this->getSeparator() . $this->generateRandomId(),
            default  => $this->buildWordSlug(),
        };
    }

    protected function buildWordSlug(): string
    {
        $source = $this->slugSourceValue();
        $source = $this->transformSource($source);

        return Str::slug($source, $this->getSeparator());
    }

    protected function slugSourceValue(): string
    {
        $source = $this->getSlugSource();

        if (is_array($source)) {
            return collect($source)
                ->map(fn($field) => (string) ($this->{$field} ?? ''))
                ->filter()
                ->implode(' ');
        }

        return (string) ($this->{$source} ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | Uniqueness
    |--------------------------------------------------------------------------
    */

    protected function makeSlugUnique(string $slug): string
    {
        $base = $slug;
        $count = 1;
        $maxAttempts = $this->getSlugMaxAttempts();

        while ($this->slugExists($slug)) {
            if ($count > $maxAttempts) {
                throw new \RuntimeException(sprintf(
                    'Unable to generate unique slug for [%s] after %d attempts.',
                    static::class,
                    $maxAttempts
                ));
            }

            $slug = $base . $this->getSeparator() . $count++;
        }

        return $slug;
    }

    protected function slugExists(string $slug): bool
    {
        $query = $this->newSlugQuery()
            ->where($this->getSlugColumn(), $slug);

        if ($this->exists) {
            $query->where(
                $this->getKeyName(),
                '!=',
                $this->getKey()
            );
        }

        return $this->applySlugConstraints($query)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Query Customization (GLOBAL POWER)
    |--------------------------------------------------------------------------
    */

    protected function newSlugQuery(): Builder
    {
        /** @var Model $this */
        return $this->newQuery();
    }

    /**
     * Override this in model for tenant / status scoping.
     */
    protected function applySlugConstraints(Builder $query): Builder
    {
        if (method_exists($this, 'scopeSlugUniqueness')) {
            return $this->scopeSlugUniqueness($query);
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Regeneration Logic
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
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function transformSource(string $value): string
    {
        return trim($value);
    }

    protected function generateRandomId(int $length = 12): string
    {
        return Str::lower(Str::random($length));
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration (Override in Model)
    |--------------------------------------------------------------------------
    */

    protected function getSlugMode(): string
    {
        return property_exists($this, 'slugMode')
            ? $this->slugMode
            : 'word';
    }

    protected function getSlugColumn(): string
    {
        return property_exists($this, 'slugColumn')
            ? $this->slugColumn
            : 'slug';
    }

    protected function getSlugSource(): string|array
    {
        return property_exists($this, 'slugSource')
            ? $this->slugSource
            : 'title';
    }

    protected function getSeparator(): string
    {
        return property_exists($this, 'slugSeparator')
            ? $this->slugSeparator
            : '-';
    }

    protected function getSlugMaxAttempts(): int
    {
        return property_exists($this, 'slugMaxAttempts')
            ? $this->slugMaxAttempts
            : 50;
    }
}
