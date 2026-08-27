<?php

declare(strict_types=1);

namespace TripBuilder;

use Exception;

class Templater
{
    private const TEMPLATES_DIRECTORY = 'frontend/template';
    private const TAG_OPEN  = '{{';
    private const TAG_CLOSE = '}}';

    private string $path = '';
    private string $filename = '';
    private string $templateContent = '';
    private array $placeholders = [];
    private string $content = '';

    /**
     * @param string|null $path
     * @param string|null $filename
     * @throws Exception
     */
    public function __construct(?string $path = null, ?string $filename = null)
    {
        if ($path !== null && $filename !== null) {
            $this->setPath($path)->setFilename($filename)->set();
        }
    }

    /**
     * @return static
     * @throws Exception
     */
    public function set(): static
    {
        $file = sprintf(
            '%s/%s/%s/%s.tpl',
            Helper::getRootDir(),
            self::TEMPLATES_DIRECTORY,
            $this->path,
            $this->filename,
        );

        if (! file_exists($file)) {
            throw new Exception("Template file not found: $file");
        }

        $this->templateContent = file_get_contents($file);

        return $this;
    }

    public function save(): static
    {
        $replacements = [];

        foreach ($this->placeholders as $key => $value) {
            $placeholder = sprintf(
                '%s%s%s',
                self::TAG_OPEN,
                $key,
                self::TAG_CLOSE,
            );

            $replacements[$placeholder] = $value;
        }

        $this->templateContent = preg_replace(
            '/' . preg_quote(self::TAG_OPEN) . '\s*(.*?)\s*' . preg_quote(self::TAG_CLOSE) . '/',
            self::TAG_OPEN . '$1' . self::TAG_CLOSE,
            $this->templateContent,
        );

        $this->content .= strtr($this->templateContent, $replacements);

        // Placeholders are consumed by save(): every template block must set
        // all values it needs, instead of silently inheriting stale ones.
        $this->placeholders = [];

        return $this;
    }

    public function render(): string
    {
        $content = $this->content;

        $this->content = '';

        return $content;
    }

    public function setPlaceholder(string $key, mixed $value): static
    {
        $this->placeholders[$key] = $value;
        return $this;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;
        return $this;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }
}
