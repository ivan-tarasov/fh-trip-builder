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

    private readonly LayoutData $layout;

    public function __construct()
    {
        $loader = new FilesystemLoader(Helper::getRootDir() . '/frontend/template');

        $this->twig = new Environment($loader, [
            'cache' => Helper::getRootDir() . '/var/cache/twig',
            'auto_reload' => true,
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);

        $this->layout = new LayoutData();

        // Helpers the templates need (CDN asset URLs and config lookups)...
        $this->twig->addFunction(new TwigFunction('cdn', Cdn::getUrl(...)));
        $this->twig->addFunction(new TwigFunction('config', Config::get(...)));

        // ...and the dynamic header/footer data (see LayoutData).
        $this->twig->addFunction(new TwigFunction('current_page', $this->layout->currentPage(...)));
        $this->twig->addFunction(new TwigFunction('csrf_token', $this->layout->csrfToken(...)));
        $this->twig->addFunction(new TwigFunction('git_info', $this->layout->gitInfo(...)));
        $this->twig->addFunction(new TwigFunction('git_repo', $this->layout->gitRepo(...)));
        $this->twig->addFunction(new TwigFunction('copyright_years', $this->layout->copyrightYears(...)));
    }

    /**
     * Render a template fragment (no base layout).
     *
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    /**
     * Render a full page: merges the request-scoped layout stats so templates
     * extending layout.html.twig get a populated header/footer.
     *
     * @param array<string, mixed> $context
     */
    public function renderPage(string $template, array $context = []): string
    {
        return $this->render($template, $context + $this->layout->stats());
    }
}
