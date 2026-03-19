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

    protected static function bootHasSlugPath(): void
    {
        static::saving(function (Model $model): void {
            if ($model->shouldGenerateSlugPath()) {
                $model->generateSlugPath();
            }
        });

        static::saved(function (Model $model): void {
            if ($model->wasChanged($model->slugPathRelevantColumns())) {
                DB::afterCommit(fn () => $model->updateDescendants());
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | PUBLIC CONTRACT (SINGLE ENTRY POINT)
    |--------------------------------------------------------------------------
    */

    public function resolveSlugPathConfig(): array
    {
        return [
            'slug' => $this->slugColumn ?? 'slug',
            'path' => $this->slugPathColumn ?? 'slug_path',
            'parent' => $this->parentColumn ?? 'parent_id',
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
    | CORE
    |--------------------------------------------------------------------------
    */

    public function generateSlugPath(): void
    {
        $cfg = $this->resolveSlugPathConfig();

        $slug = $this->{$cfg['slug']} ?? null;

        if (! is_string($slug) || $slug === '') {
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
            if (in_array($current->getKey(), $visited, true)) {
                throw new RuntimeException('Circular hierarchy detected.');
            }

            $visited[] = $current->getKey();
            $current = $current->getParentForPath();
        }
    }

    protected function shouldGenerateSlugPath(): bool
    {
        return $this->isDirty($this->slugPathRelevantColumns());
    }

    protected function slugPathRelevantColumns(): array
    {
        return [
            $this->cfg('slug'),
            $this->cfg('parent'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function getParentForPath(): ?Model
    {
        if ($this->relationLoaded('parent')) {
            return $this->parent;
        }

        $cfg = $this->resolveSlugPathConfig();

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

    protected function cfg(string $key): mixed
    {
        return $this->resolveSlugPathConfig()[$key];
    }
}
