<?php

declare(strict_types=1);

namespace App\Core;

final class Translator
{
    /** @var array<string,array<string,string>> */
    private array $catalogues = [];

    public function __construct(private readonly string $resourceDirectory)
    {
    }

    /** @param array<string,scalar> $parameters */
    public function get(string $key, string $language = 'fa', array $parameters = []): string
    {
        $language = $language === 'en' ? 'en' : 'fa';
        if (!isset($this->catalogues[$language])) {
            $file = rtrim($this->resourceDirectory, '/') . '/' . $language . '/messages.php';
            $this->catalogues[$language] = is_file($file) ? require $file : [];
        }
        $value = $this->catalogues[$language][$key] ?? $this->catalogues['fa'][$key] ?? $key;
        foreach ($parameters as $name => $parameter) {
            $value = str_replace(':' . $name, (string) $parameter, $value);
        }
        return $value;
    }
}

