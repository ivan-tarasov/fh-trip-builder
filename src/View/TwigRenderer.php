<?php

declare(strict_types=1);

namespace TripBuilder\View;

use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders Twig templates from frontend/template with HTML auto-escaping.
 *
 * Replaces the hand-rolled Templater: templates are escaped by default and
 * support inheritance/includes/loops, so view assembly leaves the controllers.
 */
final class TwigRenderer
{
    private readonly Environment $twig;

    public function __construct()
    {
        $loader = new FilesystemLoader(Helper::getRootDir() . '/frontend/template');

        $this->twig = new Environment($loader, [
            'cache' => Helper::getRootDir() . '/var/cache/twig',
            'auto_reload' => true,
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);

        // Helpers the templates need (CDN asset URLs and config lookups).
        $this->twig->addFunction(new TwigFunction('cdn', Cdn::getUrl(...)));
        $this->twig->addFunction(new TwigFunction('config', Config::get(...)));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
