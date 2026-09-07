<?php

declare(strict_types=1);

namespace App\Help;

use App\Core\AppException;
use App\Core\Database;

final class HelpService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,mixed> */
    public function topic(string $slug, string $language): array
    {
        $row = $this->database->one('SELECT * FROM help_topics WHERE slug = ?', [$slug]);
        if ($row === null) {
            throw new AppException('Help topic was not found.', 404, 'help_topic_not_found');
        }
        $topic = $this->localize($row, $language);
        $relatedSlugs = array_values(array_filter(
            $topic['related_topics'],
            static fn (mixed $related): bool => is_string($related) && preg_match('/^[a-z0-9][a-z0-9.-]{1,99}$/', $related) === 1
        ));
        $topic['related'] = [];
        if ($relatedSlugs === []) {
            return $topic;
        }

        $placeholders = implode(',', array_fill(0, count($relatedSlugs), '?'));
        $rows = $this->database->all("SELECT * FROM help_topics WHERE slug IN ({$placeholders})", $relatedSlugs);
        $localized = [];
        foreach ($rows as $relatedRow) {
            $item = $this->localize($relatedRow, $language);
            $localized[(string) $item['slug']] = [
                'slug' => (string) $item['slug'],
                'title' => (string) $item['title'],
                'warning_level' => (string) $item['warning_level'],
            ];
        }
        foreach ($relatedSlugs as $relatedSlug) {
            if (isset($localized[$relatedSlug])) {
                $topic['related'][] = $localized[$relatedSlug];
            }
        }
        return $topic;
    }

    /** @return list<array<string,mixed>> */
    public function search(string $query, string $language, ?string $category = null, int $limit = 20): array
    {
        $query = trim(mb_substr($query, 0, 100));
        $limit = max(1, min(50, $limit));
        if ($query === '') {
            $rows = $category === null
                ? $this->database->all('SELECT * FROM help_topics ORDER BY category, slug LIMIT ' . $limit)
                : $this->database->all('SELECT * FROM help_topics WHERE category = ? ORDER BY slug LIMIT ' . $limit, [$category]);
        } else {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $sql = 'SELECT * FROM help_topics WHERE (search_text LIKE ? ESCAPE \'\\\\\' OR slug LIKE ? ESCAPE \'\\\\\')';
            $params = [$like, $like];
            if ($category !== null) {
                $sql .= ' AND category = ?';
                $params[] = $category;
            }
            $rows = $this->database->all($sql . ' ORDER BY category, slug LIMIT ' . $limit, $params);
        }
        return array_map(fn (array $row): array => $this->localize($row, $language), $rows);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function localize(array $row, string $language): array
    {
        $language = $language === 'en' ? 'en' : 'fa';
        return [
            'id' => (int) $row['id'],
            'slug' => $row['slug'],
            'category' => $row['category'],
            'title' => $row['title_' . $language],
            'body' => $row['body_' . $language],
            'warning_level' => $row['warning_level'],
            'related_topics' => json_decode((string) ($row['related_topics'] ?? '[]'), true) ?: [],
            'version' => (int) $row['version'],
        ];
    }
}
