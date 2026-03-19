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
        static::creating(fn(Model $model) => $model->syncSlugPath());

        static::updating(function (Model $model) {
            if ($model->isSlugRelevantDirty()) {
                $model->syncSlugPath();
            }
        });

        static::updated(function (Model $model) {
            if ($model->wasSlugRelevantChanged()) {
                DB::transaction(fn() => $model->cascadeSlugPathUpdate());
            }
        });

        // Optional: handle restore if using SoftDeletes
        if (method_exists(static::class, 'restored')) {
            static::restored(fn(Model $model) => $model->cascadeSlugPathUpdate());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getSlugConfig('parent'));
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getSlugConfig('parent'));
    }

    /*
    |--------------------------------------------------------------------------
    | Core Logic
    |--------------------------------------------------------------------------
    */

    protected function syncSlugPath(): void
    {
        $this->ensureSlugExists();
        $this->{$this->getSlugConfig('slug_path')} = $this->buildSlugPath();
    }

    protected function buildSlugPath(): string
    {
        $slug = $this->{$this->getSlugConfig('slug')};

        if (! $this->{$this->getSlugConfig('parent')}) {
            return $slug;
        }

        $parent = $this->getParentForSlug();

        if (! $parent) {
            return $slug;
        }

        $parentSlugPath = $parent->{$this->getSlugConfig('slug_path')}
            ?: $parent->{$this->getSlugConfig('slug')};

        return trim("{$parentSlugPath}/{$slug}", '/');
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
            $child->saveQuietly(); // Avoids firing events again
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
        $parentColumn = $this->getSlugConfig('parent');

        if ($this->relationLoaded('parent')) {
            return $this->parent;
        }

        return $this->parent()
            ->withoutGlobalScopes()
            ->select([
                'id',
                $this->getSlugConfig('slug'),
                $this->getSlugConfig('slug_path'),
            ])
            ->first();
    }

    protected function isSlugRelevantDirty(): bool
    {
        return $this->isDirty([
            $this->getSlugConfig('slug'),
            $this->getSlugConfig('parent'),
        ]);
    }

    protected function wasSlugRelevantChanged(): bool
    {
        return $this->wasChanged([
            $this->getSlugConfig('slug'),
            $this->getSlugConfig('parent'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Override this method in your model if you want custom column names.
     */
    public function resolveSlugConfig(): array
    {
        return [
            'parent'    => 'parent_id',
            'slug'      => 'slug',
            'slug_path' => 'slug_path',
        ];
    }

    /**
     * Helper to access config values safely.
     */
    protected function getSlugConfig(string $key): string
    {
        $config = $this->resolveSlugConfig();

        if (! array_key_exists($key, $config)) {
            throw new \RuntimeException("Missing slug config key: '$key'");
        }

        if (! is_string($config[$key]) || trim($config[$key]) === '') {
            throw new \RuntimeException("Invalid column name for key '$key' in resolveSlugConfig()");
        }

        return $config[$key];
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    protected function ensureSlugExists(): void
    {
        $slugColumn = $this->getSlugConfig('slug');

        if (! isset($this->{$slugColumn})) {
            throw new \RuntimeException(sprintf(
                'Model [%s] requires "%s" attribute for slug generation.',
                static::class,
                $slugColumn
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
