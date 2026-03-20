<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Atannex\Foundation\Contracts\ValueResolver;
use Atannex\Foundation\Supports\HasAttributeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait CanGenerateSlugPath
{
    use HasAttributeGenerator;

    /*
    |--------------------------------------------------------------------------
    | BOOT
    |--------------------------------------------------------------------------
    */

    protected static function bootCanGenerateSlugPath(): void
    {
        static::saving(fn(Model $m) => $m->runGenerator());

        static::saved(function (Model $m): void {
            if ($m->shouldUpdateDescendants()) {
                DB::afterCommit(fn() => $m->updateDescendants());
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATOR IMPLEMENTATION
    |--------------------------------------------------------------------------
    */

    protected function generatorConfig(): array
    {
        return [
            'slug' => $this->slugColumn ?? 'slug',
            'path' => $this->slugPathColumn ?? 'slug_path',
            'parent' => $this->parentColumn ?? null,
            'context' => $this->slugPathContext ?? null,
            'separator' => $this->slugPathSeparator ?? '/',
        ];
    }

    protected function shouldGenerate(): bool
    {
        return $this->isDirty(array_filter([
            $this->slugColumn ?? 'slug',
            $this->parentColumn ?? null,
            $this->slugPathContext ?? null,
        ]));
    }

    protected function generateValue(array $cfg): string
    {
        $slug = $this->{$cfg['slug']} ?? null;

        if (! $slug) {
            throw new RuntimeException('Slug required for path.');
        }

        $base = $this->resolveBasePath($cfg);

        return $base
            ? trim($base . $cfg['separator'] . $slug, $cfg['separator'])
            : $slug;
    }

    protected function assignValue(mixed $value, array $cfg): void
    {
        $this->{$cfg['path']} = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | BASE PATH
    |--------------------------------------------------------------------------
    */

    protected function resolveBasePath(array $cfg): string
    {
        // hierarchy
        if (! empty($cfg['parent'])) {
            $parent = $this->parent()->first();

            if ($parent) {
                return $parent->{$cfg['path']}
                    ?? $parent->{$cfg['slug']}
                    ?? '';
            }
        }

        // context
        if (! empty($cfg['context'])) {
            $resolver = app(ValueResolver::class);

            $context = $resolver->resolve($this, $cfg['context']);

            if (! empty($context)) {
                return (string) $context;
            }
        }

        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | DESCENDANTS
    |--------------------------------------------------------------------------
    */

    protected function shouldUpdateDescendants(): bool
    {
        return ! empty($this->parentColumn)
            && $this->wasChanged([
                $this->slugColumn ?? 'slug',
                $this->slugPathColumn ?? 'slug_path',
            ]);
    }

    public function updateDescendants(): void
    {
        if (empty($this->parentColumn)) {
            return;
        }

        foreach ($this->children as $child) {
            $child->runGenerator();
            $child->saveQuietly();
            $child->updateDescendants();
        }
    }
}
