<?php

namespace TripBuilder;

class Templater
{
    const TEMPLATES_DIRECTORY = 'frontend/template';

    const TAG_OPEN  = '{{',
          TAG_CLOSE = '}}';

    private string $path;

    private string $filename;

    private string $templateContent;

    private array $placeholders = [];

    private string $content = '';

    /**
     * @param string|null $path
     * @param string|null $filename
     * @throws \Exception
     */
    public function __construct(?string $path = null, ?string $filename = null) {
        if ($path !== null && $filename !== null) {
            $this->setPath($path)->setFilename($filename)->set();
        }
    }

    /**
     * @return static
     * @throws \Exception
     */
    public function set(): static
    {
        $file = sprintf(
            '%s/%s/%s/%s.tpl',
            Helper::getRootDir(),
            self::TEMPLATES_DIRECTORY,
            $this->path,
            $this->filename
        );

        if (! file_exists($file)) {
            throw new \Exception("Template file not found: " . $file);
        }

        $this->templateContent = file_get_contents($file);

        return $this;
    }

    /**
     * @return $this
     */
    public function save(): static
    {
        $replacements = [];

        foreach ($this->placeholders as $key => $value) {
            $placeholder = sprintf(
                '%s%s%s',
                self::TAG_OPEN,
                $key,
                self::TAG_CLOSE
            );

            $replacements[$placeholder] = $value;
        }

        $this->templateContent = preg_replace(
            '/' . preg_quote(self::TAG_OPEN) . '\s*(.*?)\s*' . preg_quote(self::TAG_CLOSE) . '/',
            self::TAG_OPEN . '$1' . self::TAG_CLOSE,
            $this->templateContent
        );

        $this->content .= strtr($this->templateContent, $replacements);

        return $this;
    }

    /**
     * @return string
     */
    public function render(): string
    {
        $content = $this->content;

        $this->content = '';

        return $content;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function setPlaceholder(string $key, mixed $value): static
    {
        $this->placeholders[$key] = $value;

        return $this;
    }

    /**
     * @param $path
     * @return $this
     */
    public function setPath($path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * @param $filename
     * @return $this
     */
    public function setFilename($filename): static
    {
        $this->filename = $filename;

        return $this;
    }

}
