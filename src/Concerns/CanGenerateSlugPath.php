<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait CanGenerateSlugPath
{
    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function bootHasSlugPath(): void
    {
        static::saving(function (Model $model) {
            if ($model->shouldGenerateSlugPath()) {
                $model->generateSlugPath();
            }
        });

        static::saved(function (Model $model) {
            if ($model->wasChanged($model->slugRelevantColumns())) {
                DB::afterCommit(fn() => $model->updateDescendants());
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getParentColumn());
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getParentColumn());
    }

    /*
    |--------------------------------------------------------------------------
    | Core Logic
    |--------------------------------------------------------------------------
    */

    protected function generateSlugPath(): void
    {
        $slug = $this->getSlug();

        if (! $this->{$this->getParentColumn()}) {
            $this->{$this->getSlugPathColumn()} = $slug;
            return;
        }

        $parent = $this->getParentForPath();

        if (! $parent) {
            $this->{$this->getSlugPathColumn()} = $slug;
            return;
        }

        $this->{$this->getSlugPathColumn()} = trim(
            ($parent->{$this->getSlugPathColumn()} ?? $parent->{$this->getSlugColumn()}) . '/' . $slug,
            '/'
        );
    }

    protected function updateDescendants(): void
    {
        $this->guardAgainstCycles();

        $this->loadMissing('children');

        foreach ($this->children as $child) {
            $child->generateSlugPath();
            $child->saveQuietly();

            $child->updateDescendants();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    protected function guardAgainstCycles(): void
    {
        $visited = [];
        $current = $this;

        while ($current) {
            if (in_array($current->getKey(), $visited, true)) {
                throw new RuntimeException('Circular hierarchy detected.');
            }

            $visited[] = $current->getKey();
            $current = $current->getParentForPath();
        }
    }

    protected function shouldGenerateSlugPath(): bool
    {
        return $this->isDirty($this->slugRelevantColumns());
    }

    protected function slugRelevantColumns(): array
    {
        return [
            $this->getSlugColumn(),
            $this->getParentColumn(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getParentForPath(): ?Model
    {
        if ($this->relationLoaded('parent')) {
            return $this->parent;
        }

        return $this->parent()
            ->withoutGlobalScopes()
            ->select([
                $this->getKeyName(),
                $this->getSlugColumn(),
                $this->getSlugPathColumn(),
                $this->getParentColumn(),
            ])
            ->first();
    }

    protected function getSlug(): string
    {
        $column = $this->getSlugColumn();

        $value = $this->{$column} ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Slug column [{$column}] must be a non-empty string.");
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Column Configuration
    |--------------------------------------------------------------------------
    */

    protected function getSlugColumn(): string
    {
        return property_exists($this, 'slugColumn')
            ? $this->slugColumn
            : 'slug';
    }

    protected function getSlugPathColumn(): string
    {
        return property_exists($this, 'slugPathColumn')
            ? $this->slugPathColumn
            : 'slug_path';
    }

    protected function getParentColumn(): string
    {
        return property_exists($this, 'parentColumn')
            ? $this->parentColumn
            : 'parent_id';
    }

    /*
    |--------------------------------------------------------------------------
    | Optional Hooks
    |--------------------------------------------------------------------------
    */

    protected function afterSlugPathUpdated(): void
    {
        // Extend if needed
    }
}
