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
    | Boot
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

        // Optional hook for soft deletes or auditing
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'onDeletingSlug')) {
                $model->onDeletingSlug();
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

        if ($slug === '') {
            throw new RuntimeException(sprintf(
                'Cannot generate slug: empty source for model [%s].',
                static::class
            ));
        }

        $this->{$this->getSlugColumn()} = $this->makeSlugUnique($slug);
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
    | Slug Builder
    |--------------------------------------------------------------------------
    */

    protected function buildSlug(): string
    {
        return match ($this->getSlugMode()) {
            'random' => $this->generateRandomId(),
            'mixed' => $this->buildWordSlug()
                .$this->getSeparator()
                .$this->generateRandomId(),
            default => $this->buildWordSlug(),
        };
    }

    protected function buildWordSlug(): string
    {
        $source = $this->transformSource($this->slugSourceValue());

        if ($source === '') {
            return '';
        }

        return Str::slug($source, $this->getSeparator());
    }

    protected function slugSourceValue(): string
    {
        $source = $this->getSlugSource();

        if (is_array($source)) {
            return collect($source)
                ->map(fn ($field) => (string) ($this->{$field} ?? ''))
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
        $separator = $this->getSeparator();
        $maxAttempts = $this->getSlugMaxAttempts();

        for ($i = 0; $i <= $maxAttempts; $i++) {
            $candidate = $i === 0
                ? $base
                : $base.$separator.$i;

            if (! $this->slugExists($candidate)) {
                return $candidate;
            }
        }

        // Final fallback (guaranteed uniqueness)
        return $base.$separator.$this->generateRandomId(6);
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
    | Query Customization
    |--------------------------------------------------------------------------
    */

    protected function newSlugQuery(): Builder
    {
        /** @var Model $this */
        return $this->newQuery();
    }

    /**
     * Override for multi-tenant / scoped uniqueness.
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
        if (! $this->shouldAutoGenerateSlugOnUpdate()) {
            return false;
        }

        $source = $this->getSlugSource();

        return is_array($source)
            ? $this->isDirty($source)
            : $this->isDirty([$source]);
    }

    protected function shouldRegenerateSlugOnRestore(): bool
    {
        return false; // safe default
    }

    protected function shouldAutoGenerateSlugOnUpdate(): bool
    {
        return true;
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
            : 'word'; // word | mixed | random
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
