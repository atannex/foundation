<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Atannex\Foundation\Supports\ResolveDotValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait CanGenerateSlugPath
{
    use ResolveDotValue;

    /*
    |--------------------------------------------------------------------------
    | BOOT
    |--------------------------------------------------------------------------
    */

    protected static function bootCanGenerateSlugPath(): void
    {
        static::saving(function (Model $model): void {
            if ($model->shouldGenerateSlugPath()) {
                $model->generateSlugPath();
            }
        });

        static::saved(function (Model $model): void {
            if ($model->shouldUpdateDescendants()) {
                DB::afterCommit(fn () => $model->updateDescendants());
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIG
    |--------------------------------------------------------------------------
    */

    public function resolveSlugPathConfig(): array
    {
        return [
            'slug' => $this->slugColumn ?? 'slug',
            'path' => $this->slugPathColumn ?? 'slug_path',

            // IMPORTANT: optional (no default!)
            'parent' => $this->parentColumn ?? null,

            // optional context (dot notation supported)
            'context' => $this->slugPathContext ?? null,

            'separator' => $this->slugPathSeparator ?? '/',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CAPABILITIES
    |--------------------------------------------------------------------------
    */

    public function hasHierarchy(): bool
    {
        return ! empty($this->cfg('parent'));
    }

    public function hasContext(): bool
    {
        return ! empty($this->cfg('context'));
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS (SAFE)
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        $parentColumn = $this->cfg('parent');

        if (! $parentColumn) {
            throw new RuntimeException('Parent relationship not configured.');
        }

        return $this->belongsTo(static::class, $parentColumn);
    }

    public function children(): HasMany
    {
        $parentColumn = $this->cfg('parent');

        if (! $parentColumn) {
            throw new RuntimeException('Children relationship not configured.');
        }

        return $this->hasMany(static::class, $parentColumn);
    }

    /*
    |--------------------------------------------------------------------------
    | CORE GENERATION
    |--------------------------------------------------------------------------
    */

    public function generateSlugPath(): void
    {
        $cfg = $this->resolveSlugPathConfig();

        $slug = $this->{$cfg['slug']} ?? null;

        if (! is_string($slug) || $slug === '') {
            throw new RuntimeException('Slug is required for slug path generation.');
        }

        $base = $this->resolveBasePath($cfg);

        $this->{$cfg['path']} = $base
            ? trim($base.$cfg['separator'].$slug, $cfg['separator'])
            : $slug;
    }

    /*
    |--------------------------------------------------------------------------
    | BASE PATH RESOLUTION (STRATEGY CORE)
    |--------------------------------------------------------------------------
    */

    protected function resolveBasePath(array $cfg): string
    {
        // 1. HIERARCHY (TREE)
        if ($this->hasHierarchy()) {
            $parent = $this->getParentForPath();

            if ($parent) {
                return $parent->{$cfg['path']}
                    ?? $parent->{$cfg['slug']}
                    ?? '';
            }
        }

        // 2. CONTEXT (RELATION)
        if ($this->hasContext()) {
            $contextValue = $this->resolveDotValue($cfg['context']);

            if (! empty($contextValue)) {
                return $contextValue;
            }
        }

        // 3. FALLBACK
        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | DESCENDANT UPDATES (SAFE + ASYNC-FRIENDLY)
    |--------------------------------------------------------------------------
    */

    public function updateDescendants(): void
    {
        if (! $this->hasHierarchy()) {
            return;
        }

        $this->guardAgainstCycles();

        $this->loadMissing('children');

        foreach ($this->children as $child) {
            $child->generateSlugPath();

            // Avoid event loops + improve performance
            $child->saveQuietly();

            $child->updateDescendants();
        }
    }

    protected function shouldUpdateDescendants(): bool
    {
        return $this->hasHierarchy()
            && $this->wasChanged($this->slugPathRelevantColumns());
    }

    /*
    |--------------------------------------------------------------------------
    | GUARDS
    |--------------------------------------------------------------------------
    */

    protected function guardAgainstCycles(): void
    {
        if (! $this->hasHierarchy()) {
            return;
        }

        $visited = [];
        $current = $this;

        while ($current) {
            $key = $current->getKey();

            if ($key && in_array($key, $visited, true)) {
                throw new RuntimeException('Circular hierarchy detected.');
            }

            $visited[] = $key;
            $current = $current->getParentForPath();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RULES
    |--------------------------------------------------------------------------
    */

    protected function shouldGenerateSlugPath(): bool
    {
        return $this->isDirty($this->slugPathRelevantColumns());
    }

    protected function slugPathRelevantColumns(): array
    {
        return array_values(array_filter([
            $this->cfg('slug'),
            $this->cfg('parent'),
            $this->cfg('context'),
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function getParentForPath(): ?Model
    {
        if (! $this->hasHierarchy()) {
            return null;
        }

        $parentColumn = $this->cfg('parent');

        if (! $this->relationLoaded('parent') && $this->{$parentColumn} === null) {
            return null;
        }

        return $this->parent()
            ->withoutGlobalScopes()
            ->select([
                $this->getKeyName(),
                $this->cfg('slug'),
                $this->cfg('path'),
                $parentColumn,
            ])
            ->first();
    }

    protected function cfg(string $key): mixed
    {
        return $this->resolveSlugPathConfig()[$key] ?? null;
    }
}
