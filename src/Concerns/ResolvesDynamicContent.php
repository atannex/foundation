<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

trait ResolvesDynamicContent
{
    /*
    |--------------------------------------------------------------------------
    | Cache (Per Request)
    |--------------------------------------------------------------------------
    */

    protected array $tabCache = [];

    /*
    |--------------------------------------------------------------------------
    | Configuration (Override in Model)
    |--------------------------------------------------------------------------
    */

    protected function getSectionRelation(): string
    {
        return property_exists($this, 'sectionRelation')
            ? $this->sectionRelation
            : 'sections';
    }

    protected function getWidgetRelation(): string
    {
        return property_exists($this, 'widgetRelation')
            ? $this->widgetRelation
            : 'widgets';
    }

    protected function getPivotConfigKey(): string
    {
        return property_exists($this, 'pivotConfigKey')
            ? $this->pivotConfigKey
            : 'config';
    }

    protected function getSectionTabKey(): string
    {
        return property_exists($this, 'sectionTabKey')
            ? $this->sectionTabKey
            : 'section_tab';
    }

    protected function getWidgetTabKey(): string
    {
        return property_exists($this, 'widgetTabKey')
            ? $this->widgetTabKey
            : 'widget_tab';
    }

    /*
    |--------------------------------------------------------------------------
    | Abstract Dependency (MUST IMPLEMENT)
    |--------------------------------------------------------------------------
    */

    /**
     * Must return the service responsible for resolving data (e.g., PostService).
     */
    abstract protected function getResolver(): object;

    /**
     * Mapping definition for tab types.
     */
    abstract protected function getMapping(string $type): ?array;

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    protected function resolveSections(
        object $entity,
        int $sectionLimit = 6,
        int $widgetLimit = 3
    ): void {
        $relation = $this->getSectionRelation();

        if (! method_exists($entity, $relation)) {
            return;
        }

        $sections = $entity->{$relation}()
            ->limit($sectionLimit)
            ->with([
                $this->getWidgetRelation() => function ($query) use ($entity, $widgetLimit) {
                    $query->limit($widgetLimit);
                },
            ])
            ->get();

        foreach ($sections as $section) {
            $this->resolveEntity($section, $this->getSectionTabKey());
        }

        $entity->setRelation($relation, $sections);
    }

    /*
    |--------------------------------------------------------------------------
    | Entity Resolution
    |--------------------------------------------------------------------------
    */

    protected function resolveEntity(object $entity, string $tabKey): void
    {
        $config = $this->extractConfig($entity);

        $this->attachTabs($entity, $config, $tabKey);

        $widgetRelation = $this->getWidgetRelation();

        if (! empty($entity->{$widgetRelation})) {
            foreach ($entity->{$widgetRelation} as $widget) {
                $widgetConfig = $this->extractConfig($widget);
                $this->attachTabs($widget, $widgetConfig, $this->getWidgetTabKey());
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tabs
    |--------------------------------------------------------------------------
    */

    protected function attachTabs(object $entity, array $config, string $tabKey): void
    {
        if (empty($config[$tabKey]) || ! is_array($config[$tabKey])) {
            $entity->setRelation('tabs', collect());
            return;
        }

        $tabs = collect($config[$tabKey])
            ->map(fn($tab) => $this->resolveSingleTab($tab))
            ->values();

        $entity->setRelation('tabs', $tabs);
    }

    protected function resolveSingleTab(array $tab): array
    {
        $mapping = $this->getMapping($tab['type'] ?? null);

        if (! $mapping || empty($mapping['method'])) {
            $tab['content'] = collect();
            return $tab;
        }

        $idKey = $mapping['idKey'] ?? null;

        $tab['content'] = $this->resolveTab($tab, $mapping, $idKey);

        return $tab;
    }

    /*
    |--------------------------------------------------------------------------
    | Core Resolver
    |--------------------------------------------------------------------------
    */

    protected function resolveTab(array $tab, array $mapping, ?string $key = null): mixed
    {
        $params = [
            'limit' => $tab['limit'] ?? null,
            'relation_limit' => $tab['relation_limit'] ?? null,
            'leaf_relation_limit' => $tab['leaf_relation_limit'] ?? null,
            'sort' => $tab['sort'] ?? null,
            'order' => $tab['order'] ?? null,
        ];

        if ($key && isset($tab[$key])) {
            $params[$key] = $this->normalizeIds($tab[$key]);
        }

        $method = $mapping['method'];

        $resolver = $this->getResolver();

        if (! method_exists($resolver, $method)) {
            return collect();
        }

        /*
        |--------------------------------------------------------------------------
        | Memoization
        |--------------------------------------------------------------------------
        */
        $cacheKey = md5(json_encode([$method, $params]));

        if (isset($this->tabCache[$cacheKey])) {
            return $this->tabCache[$cacheKey];
        }

        return $this->tabCache[$cacheKey] =
            $resolver->{$method}($params);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function extractConfig(object $entity): array
    {
        $key = $this->getPivotConfigKey();

        return (array) ($entity->pivot->{$key} ?? []);
    }

    protected function normalizeIds(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [$value];
    }
}
