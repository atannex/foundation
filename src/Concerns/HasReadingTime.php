<?php

declare(strict_types=1);

namespace Atannex\Foundation\Concerns;

trait HasReadingTime
{
    /*
    |--------------------------------------------------------------------------
    | Configuration (Override in Model)
    |--------------------------------------------------------------------------
    */

    protected function getReadingContent(): mixed
    {
        return property_exists($this, 'readingContent')
            ? $this->{$this->readingContent}
            : ($this->content ?? null);
    }

    protected function getReadingKeys(): array
    {
        return property_exists($this, 'readingKeys')
            ? $this->readingKeys
            : ['value', 'title', 'heading', 'paragraph', 'quote'];
    }

    protected function getWordsPerMinute(): int
    {
        return property_exists($this, 'wordsPerMinute')
            ? $this->wordsPerMinute
            : 200;
    }

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    */

    public function readingTime(): int
    {
        $text = $this->extractText($this->getReadingContent());

        $wordCount = $this->countWords($text);

        return max(1, (int) ceil($wordCount / $this->getWordsPerMinute()));
    }

    /*
    |--------------------------------------------------------------------------
    | Extraction
    |--------------------------------------------------------------------------
    */

    protected function extractText(mixed $content): string
    {
        if (is_string($content)) {
            return $this->normalizeText($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $text = '';
        $keys = $this->getReadingKeys();

        foreach ($content as $item) {
            if (is_array($item)) {
                foreach ($keys as $key) {
                    if (! empty($item[$key]) && is_string($item[$key])) {
                        $text .= ' ' . $item[$key];
                    }
                }

                // recursive extraction
                $text .= ' ' . $this->extractText($item);
            }
        }

        return $text;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function normalizeText(string $text): string
    {
        // Strip HTML if present
        $text = strip_tags($text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    protected function countWords(string $text): int
    {
        return str_word_count($text);
    }

    /*
    |--------------------------------------------------------------------------
    | Optional Hooks
    |--------------------------------------------------------------------------
    */

    /**
     * Override to customize final calculation (e.g., add buffer time).
     */
    protected function adjustReadingTime(int $minutes): int
    {
        return $minutes;
    }
}
