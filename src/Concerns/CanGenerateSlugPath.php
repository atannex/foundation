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
    | Configuration (Override in Model if needed)
    |--------------------------------------------------------------------------
    */

    protected function getParentColumn(): string
    {
        return property_exists($this, 'parentColumn') ? $this->parentColumn : 'parent_id';
    }

    protected function getSlugColumn(): string
    {
        return property_exists($this, 'slugColumn') ? $this->slugColumn : 'slug';
    }

    protected function getSlugPathColumn(): string
    {
        return property_exists($this, 'slugPathColumn') ? $this->slugPathColumn : 'slug_path';
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

    public function generateSlugPath(): string
    {
        $slugColumn = $this->getSlugColumn();
        $slugPathColumn = $this->getSlugPathColumn();
        $parentColumn = $this->getParentColumn();

        if (! $this->{$parentColumn}) {
            return $this->{$slugColumn};
        }

        $parent = $this->relationLoaded('parent')
            ? $this->parent
            : $this->parent()
            ->withoutGlobalScopes()
            ->select(['id', $slugColumn, $slugPathColumn])
            ->first();

        if (! $parent) {
            return $this->{$slugColumn};
        }

        $base = $parent->{$slugPathColumn} ?: $parent->{$slugColumn};

        return trim($base . '/' . $this->{$slugColumn}, '/');
    }

    public function updateSlugPathIfNeeded(): void
    {
        $column = $this->getSlugPathColumn();
        $newPath = $this->generateSlugPath();

        if ($this->{$column} !== $newPath) {
            $this->updateQuietly([$column => $newPath]);
        }
    }

    public function updateDescendantsSlugPaths(): void
    {
        $this->loadMissing('children');

        foreach ($this->children as $child) {
            $child->updateSlugPathIfNeeded();

            // Hook for custom model-specific logic
            $child->afterSlugPathUpdated();

            $child->updateDescendantsSlugPaths();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Hooks (Override in Model)
    |--------------------------------------------------------------------------
    */

    protected function afterSlugPathUpdated(): void
    {
        // Override in model if needed (e.g., update related models)
    }

    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function bootHasSlugPath(): void
    {
        static::creating(function (Model $model) {
            $model->ensureSlugColumnExists();

            $column = $model->getSlugPathColumn();
            $model->{$column} = $model->generateSlugPath();
        });

        static::updating(function (Model $model) {
            if ($model->isDirty([
                $model->getSlugColumn(),
                $model->getParentColumn()
            ])) {
                $column = $model->getSlugPathColumn();
                $model->{$column} = $model->generateSlugPath();
            }
        });

        static::updated(function (Model $model) {
            if ($model->wasChanged([
                $model->getSlugColumn(),
                $model->getParentColumn()
            ])) {
                DB::transaction(function () use ($model) {
                    $model->refresh();

                    $model->updateDescendantsSlugPaths();
                    $model->afterSlugPathUpdated();
                });
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    protected function ensureSlugColumnExists(): void
    {
        $slugColumn = $this->getSlugColumn();

        if (! isset($this->{$slugColumn})) {
            throw new \RuntimeException(sprintf(
                'Model [%s] must have a "%s" attribute to use HasSlugPath.',
                static::class,
                $slugColumn
            ));
        }
    }
}
