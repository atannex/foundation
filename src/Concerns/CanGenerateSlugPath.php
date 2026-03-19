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
            if ($model->wasChanged($model->slugPathRelevantColumns())) {
                DB::afterCommit(fn() => $model->updateDescendants());
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

            // hierarchy (tree models)
            'parent' => $this->parentColumn ?? 'parent_id',

            // context fallback (e.g. category.slug_path)
            'context' => $this->slugPathContext ?? null,

            'separator' => $this->slugPathSeparator ?? '/',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->cfg('parent'));
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->cfg('parent'));
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

        if (!is_string($slug) || $slug === '') {
            throw new RuntimeException('Slug is required for slug path generation.');
        }

        if (! $this->{$cfg['parent']}) {
            $this->{$cfg['path']} = $slug;
            return;
        }

        $parent = $this->getParentForPath();

        if (! $parent) {
            $this->{$cfg['path']} = $slug;
            return;
        }

        $base = $parent->{$cfg['path']} ?? $parent->{$cfg['slug']};

        $this->{$cfg['path']} = trim(
            $base . $cfg['separator'] . $slug,
            $cfg['separator']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BASE PATH RESOLUTION (IMPORTANT LOGIC)
    |--------------------------------------------------------------------------
    */

    protected function resolveBasePath(array $cfg): string
    {
        /*
        |-----------------------------------------
        | 1. PARENT (HIERARCHICAL MODELS)
        |-----------------------------------------
        */

        if (!empty($cfg['parent'])) {
            $parent = $this->getParentForPath();

            if ($parent) {
                return $parent->{$cfg['path']}
                    ?? $parent->{$cfg['slug']}
                    ?? '';
            }
        }

        /*
        |-----------------------------------------
        | 2. CONTEXT (RELATIONAL MODELS)
        |-----------------------------------------
        | Example: category.slug_path
        */

        if (!empty($cfg['context'])) {
            $contextValue = $this->resolveDotValue($cfg['context']);

            if (!empty($contextValue)) {
                return $contextValue;
            }
        }

        /*
        |-----------------------------------------
        | 3. FALLBACK (STANDALONE)
        |-----------------------------------------
        */

        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | DESCENDANT UPDATES
    |--------------------------------------------------------------------------
    */

    public function updateDescendants(): void
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
    | GUARDS
    |--------------------------------------------------------------------------
    */

    protected function guardAgainstCycles(): void
    {
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
        return array_filter([
            $this->cfg('slug'),
            $this->cfg('parent'),
            $this->slugPathContext ?? null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function getParentForPath(): ?Model
    {
        $cfg = $this->resolveSlugPathConfig();

        if (!$this->relationLoaded('parent') && $this->{$cfg['parent']} === null) {
            return null;
        }

        return $this->parent()
            ->withoutGlobalScopes()
            ->select([
                $this->getKeyName(),
                $cfg['slug'],
                $cfg['path'],
                $cfg['parent'],
            ])
            ->first();
    }

    protected function resolveDotValue(string $path): string
    {
        $segments = explode('.', $path);

        $value = $this;

        foreach ($segments as $segment) {
            if (is_object($value)) {
                $value = $value->{$segment} ?? null;
            } else {
                return '';
            }

            if ($value === null) {
                return '';
            }
        }

        return (string) $value;
    }

    protected function cfg(string $key): mixed
    {
        return $this->resolveSlugPathConfig()[$key] ?? null;
    }
}
