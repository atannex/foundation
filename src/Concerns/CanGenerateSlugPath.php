<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

trait CanGenerateSlugPath
{
    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function bootCanGenerateSlugPath(): void
    {
        static::creating(fn (Model $model) => $model->syncSlugPath());

        static::updating(function (Model $model) {
            if ($model->isSlugRelevantDirty()) {
                $model->syncSlugPath();
            }
        });

        static::updated(function (Model $model) {
            if ($model->wasSlugRelevantChanged()) {
                DB::transaction(fn () => $model->cascadeSlugPathUpdate());
            }
        });

        // Optional: handle restore if using SoftDeletes
        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->cascadeSlugPathUpdate());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->parentColumn());
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->parentColumn());
    }

    /*
    |--------------------------------------------------------------------------
    | Core Logic
    |--------------------------------------------------------------------------
    */

    protected function syncSlugPath(): void
    {
        $this->ensureSlugExists();

        $this->{$this->slugPathColumn()} = $this->buildSlugPath();
    }

    protected function buildSlugPath(): string
    {
        $slug = $this->{$this->slugColumn()};

        if (! $this->{$this->parentColumn()}) {
            return $slug;
        }

        $parent = $this->getParentForSlug();

        if (! $parent) {
            return $slug;
        }

        $base = $parent->{$this->slugPathColumn()} ?: $parent->{$this->slugColumn()};

        return trim("{$base}/{$slug}", '/');
    }

    protected function cascadeSlugPathUpdate(): void
    {
        $this->refresh();

        $this->updateDescendants();
        $this->afterSlugPathUpdated();
    }

    protected function updateDescendants(): void
    {
        $this->loadMissing('children');

        foreach ($this->children as $child) {
            $child->syncSlugPath();

            // Avoid triggering full model events again
            $child->saveQuietly();

            $child->updateDescendants();
            $child->afterSlugPathUpdated();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getParentForSlug(): ?Model
    {
        if ($this->relationLoaded('parent')) {
            return $this->parent;
        }

        return $this->parent()
            ->withoutGlobalScopes()
            ->select([
                'id',
                $this->slugColumn(),
                $this->slugPathColumn(),
            ])
            ->first();
    }

    protected function isSlugRelevantDirty(): bool
    {
        return $this->isDirty([
            $this->slugColumn(),
            $this->parentColumn(),
        ]);
    }

    protected function wasSlugRelevantChanged(): bool
    {
        return $this->wasChanged([
            $this->slugColumn(),
            $this->parentColumn(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Column Resolvers (No duplication)
    |--------------------------------------------------------------------------
    */

    protected function parentColumn(): string
    {
        return $this->resolveColumn('parentColumn', 'parent_id');
    }

    protected function slugColumn(): string
    {
        return $this->resolveColumn('slugColumn', 'slug');
    }

    protected function slugPathColumn(): string
    {
        return $this->resolveColumn('slugPathColumn', 'slug_path');
    }

    protected function resolveColumn(string $property, string $default): string
    {
        return property_exists($this, $property)
            ? $this->{$property}
            : $default;
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    protected function ensureSlugExists(): void
    {
        if (! isset($this->{$this->slugColumn()})) {
            throw new \RuntimeException(sprintf(
                'Model [%s] requires "%s" attribute for slug generation.',
                static::class,
                $this->slugColumn()
            ));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hooks (Extend in Model)
    |--------------------------------------------------------------------------
    */

    protected function afterSlugPathUpdated(): void
    {
        // Extend if needed
    }
}
