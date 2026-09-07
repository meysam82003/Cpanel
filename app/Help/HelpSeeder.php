<?php

declare(strict_types=1);

namespace App\Help;

use App\Core\Database;

final class HelpSeeder
{
    public function __construct(private readonly Database $database)
    {
    }

    public function seed(string $sourceFile): int
    {
        $topics = require $sourceFile;
        $count = 0;
        foreach ($topics as $topic) {
            $related = json_encode($topic['related'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $search = implode(' ', [$topic['slug'], $topic['category'], $topic['title_fa'], $topic['title_en'], $topic['body_fa'], $topic['body_en']]);
            $this->database->execute(
                'INSERT INTO help_topics (slug, category, title_fa, title_en, body_fa, body_en, warning_level, related_topics, version, search_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?) '
                . 'ON DUPLICATE KEY UPDATE category = VALUES(category), title_fa = VALUES(title_fa), title_en = VALUES(title_en), body_fa = VALUES(body_fa), body_en = VALUES(body_en), warning_level = VALUES(warning_level), related_topics = VALUES(related_topics), version = version + IF(body_fa <> VALUES(body_fa) OR body_en <> VALUES(body_en), 1, 0), search_text = VALUES(search_text)',
                [$topic['slug'], $topic['category'], $topic['title_fa'], $topic['title_en'], $topic['body_fa'], $topic['body_en'], $topic['warning_level'], $related, $search]
            );
            $count++;
        }
        return $count;
    }
}

