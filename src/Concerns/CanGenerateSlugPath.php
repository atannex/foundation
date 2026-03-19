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

    protected static function bootCanGenerateSlugPath(): void
    {
        static::creating(fn (Model $model) => $model->syncSlugPath());

        static::updating(function (Model $model): void {
            if ($model->isSlugRelevantDirty()) {
                $model->syncSlugPath();
            }
        });

        static::updated(function (Model $model): void {
            if ($model->wasSlugRelevantChanged()) {
                DB::transaction(fn () => $model->cascadeSlugPathUpdate());
            }
        });

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
        $slug = (string) $this->{$this->getSlugConfig('slug')};

        $parent = $this->getParentForSlug();

        if (! $parent) {
            return $slug;
        }

        $parentPath = $parent->{$this->getSlugConfig('slug_path')}
            ?: $parent->{$this->getSlugConfig('slug')};

        return trim($parentPath.'/'.$slug, '/');
    }

    /**
     * Cascade update using iterative breadth-first approach.
     */
    protected function cascadeSlugPathUpdate(): void
    {
        $this->refresh();

        $queue = [$this];
        $visited = [];

        while (! empty($queue)) {
            /** @var Model $node */
            $node = array_shift($queue);

            if (in_array($node->getKey(), $visited, true)) {
                throw new RuntimeException('Circular hierarchy detected in slug tree.');
            }

            $visited[] = $node->getKey();

            $node->loadMissing('children');

            foreach ($node->children as $child) {
                $child->syncSlugPath();

                // Save quietly to avoid infinite event loops
                $child->saveQuietly();

                $queue[] = $child;

                $child->afterSlugPathUpdated();
            }
        }

        $this->afterSlugPathUpdated();
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
                $this->getKeyName(),
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

    protected array $slugConfigCache = [];

    public function resolveSlugConfig(): array
    {
        return [
            'parent' => 'parent_id',
            'slug' => 'slug',
            'slug_path' => 'slug_path',
        ];
    }

    protected function getSlugConfig(string $key): string
    {
        if (empty($this->slugConfigCache)) {
            $this->slugConfigCache = $this->resolveSlugConfig();
        }

        if (! array_key_exists($key, $this->slugConfigCache)) {
            throw new RuntimeException("Missing slug config key: '{$key}'");
        }

        $value = $this->slugConfigCache[$key];

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Invalid column name for key '{$key}'");
        }

        return $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Safety
    |--------------------------------------------------------------------------
    */

    protected function ensureSlugExists(): void
    {
        $slugColumn = $this->getSlugConfig('slug');

        if (! isset($this->{$slugColumn}) || $this->{$slugColumn} === '') {
            throw new RuntimeException(sprintf(
                'Model [%s] requires non-empty "%s" for slug generation.',
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
        // Override in model if needed
    }
}
