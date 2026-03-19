<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

use Illuminate\Support\Facades\Storage;

trait HandleFileCleaning
{
    /*
    |--------------------------------------------------------------------------
    | Configuration (Override in Model)
    |--------------------------------------------------------------------------
    */

    protected function getFileDisk(): string
    {
        return property_exists($this, 'fileDisk')
            ? $this->fileDisk
            : 'public';
    }

    protected function getBlockFileMap(): array
    {
        return property_exists($this, 'blockFileMap')
            ? $this->blockFileMap
            : [];
    }

    protected function getBlockTypeKey(): string
    {
        return property_exists($this, 'blockTypeKey')
            ? $this->blockTypeKey
            : 'type';
    }

    protected function getBlockDataKey(): string
    {
        return property_exists($this, 'blockDataKey')
            ? $this->blockDataKey
            : 'data';
    }

    /*
    |--------------------------------------------------------------------------
    | Extraction
    |--------------------------------------------------------------------------
    */

    protected function extractFilePaths(array $content): array
    {
        $files = [];
        $map = $this->getBlockFileMap();

        foreach ($content as $block) {
            $type = $block[$this->getBlockTypeKey()] ?? null;

            if (! $type || empty($map[$type])) {
                continue;
            }

            $data = $block[$this->getBlockDataKey()] ?? [];

            foreach ((array) $map[$type] as $path) {
                $files = array_merge(
                    $files,
                    $this->extractByPath($data, $path)
                );
            }
        }

        return array_values(array_unique(array_filter($files)));
    }

    /**
     * Advanced path extraction with full wildcard support.
     */
    protected function extractByPath(array $data, string $path): array
    {
        // Use Laravel's data_get with wildcard support
        $results = data_get($data, $path);

        if (is_array($results)) {
            return array_filter($results);
        }

        return $results ? [$results] : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Deletion
    |--------------------------------------------------------------------------
    */

    protected function deleteFiles(array $files): void
    {
        $disk = Storage::disk($this->getFileDisk());

        foreach ($files as $file) {
            if ($file && $disk->exists($file)) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Bulk delete (more efficient for large sets).
     */
    protected function deleteFilesBulk(array $files): void
    {
        $files = array_values(array_filter(array_unique($files)));

        if (empty($files)) {
            return;
        }

        Storage::disk($this->getFileDisk())->delete($files);
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup Logic
    |--------------------------------------------------------------------------
    */

    protected function cleanupRemovedFiles(array $original, array $current): void
    {
        $oldFiles = $this->extractFilePaths($original);
        $newFiles = $this->extractFilePaths($current);

        $this->deleteFiles(array_diff($oldFiles, $newFiles));
    }

    /**
     * Optional optimized version using bulk delete.
     */
    protected function cleanupRemovedFilesBulk(array $original, array $current): void
    {
        $oldFiles = $this->extractFilePaths($original);
        $newFiles = $this->extractFilePaths($current);

        $this->deleteFilesBulk(array_diff($oldFiles, $newFiles));
    }

    /*
    |--------------------------------------------------------------------------
    | Hooks (Override if needed)
    |--------------------------------------------------------------------------
    */

    /**
     * Hook before deletion.
     */
    protected function beforeDeletingFiles(array $files): array
    {
        return $files;
    }

    /**
     * Hook after deletion.
     */
    protected function afterDeletingFiles(array $files): void
    {
        // Logging, events, etc.
    }
}
