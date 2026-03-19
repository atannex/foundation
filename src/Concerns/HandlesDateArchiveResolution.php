<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HandlesDateArchiveResolution
{
    /*
    |--------------------------------------------------------------------------
    | Configuration (Override in Model)
    |--------------------------------------------------------------------------
    */

    /**
     * Return the model used for date-based querying.
     */
    protected function getArchiveModelClass(): string
    {
        if (property_exists($this, 'archiveModel')) {
            return $this->archiveModel;
        }

        throw new \RuntimeException(sprintf(
            'Model [%s] must define $archiveModel to use HandlesDateArchiveResolution.',
            static::class
        ));
    }

    /**
     * Column used for date filtering.
     */
    protected function getDateColumn(): string
    {
        return property_exists($this, 'archiveDateColumn')
            ? $this->archiveDateColumn
            : 'published_at';
    }

    /**
     * Optional scope name (e.g., "published").
     */
    protected function getPublishedScope(): ?string
    {
        return property_exists($this, 'archivePublishedScope')
            ? $this->archivePublishedScope
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Core Resolver
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve archive context from slug.
     *
     * Supported:
     *  - YYYY
     *  - YYYY/MM
     */
    protected function resolveDateArchiveBySlug(string $slug): ?array
    {
        [$year, $month] = $this->extractDateParts($slug);

        if (! $this->isValidYear($year) || ! $this->recordsExist($year)) {
            return null;
        }

        if ($this->isValidMonth($month) && $this->recordsExist($year, $month)) {
            return [
                'type' => 'month',
                'year' => (int) $year,
                'month' => (int) $month,
            ];
        }

        return [
            'type' => 'year',
            'year' => (int) $year,
            'month' => null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Query Builder
    |--------------------------------------------------------------------------
    */

    protected function newArchiveQuery(): Builder
    {
        $modelClass = $this->getArchiveModelClass();

        /** @var Model $model */
        $model = new $modelClass;

        $query = $model->newQuery();

        if ($scope = $this->getPublishedScope()) {
            if (method_exists($query->getModel(), $scope)) {
                $query->{$scope}();
            }
        }

        return $query;
    }

    protected function recordsExist(string $year, ?string $month = null): bool
    {
        $column = $this->getDateColumn();

        $query = $this->newArchiveQuery()
            ->whereYear($column, $year);

        if ($month !== null) {
            $query->whereMonth($column, $month);
        }

        return $query->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function extractDateParts(string $slug): array
    {
        return array_pad(
            explode('/', trim($slug, '/'), 2),
            2,
            null
        );
    }

    protected function isValidYear(?string $year): bool
    {
        if (! is_string($year) || ! preg_match('/^\d{4}$/', $year)) {
            return false;
        }

        $yearInt = (int) $year;
        $current = (int) date('Y');

        return $yearInt >= 1900 && $yearInt <= $current + 1;
    }

    protected function isValidMonth(?string $month): bool
    {
        return is_string($month)
            && preg_match('/^(0[1-9]|1[0-2])$/', $month) === 1;
    }
}
