<?php

declare(strict_types=1);

namespace TripBuilder\View;

use Exception;
use TripBuilder\Cdn;
use TripBuilder\Config;
use TripBuilder\Helper;
use TripBuilder\Party;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders Twig templates from frontend/template with HTML auto-escaping.
 *
 * Templates are escaped by default and support inheritance/includes/loops, so
 * view assembly lives in the templates rather than in the controllers.
 */
final readonly class TwigRenderer
{
    private Environment $twig;

    private LayoutData $layout;

    public function __construct()
    {
        $loader = new FilesystemLoader(Helper::getRootDir() . '/frontend/template');

        $this->twig = new Environment($loader, [
            'cache' => Helper::getRootDir() . '/cache/twig',
            'auto_reload' => true,
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);

        $this->layout = new LayoutData();

        // Helpers the templates need (CDN asset URLs and config lookups)...
        $this->twig->addFunction(new TwigFunction('cdn', Cdn::getUrl(...)));
        $this->twig->addFunction(new TwigFunction('config', Config::get(...)));
        // A date that carries its year only when that year is not this one, so
        // a trip crossing New Year cannot print two dates eleven months apart
        // as though they were days.
        $this->twig->addFunction(new TwigFunction('date_label', Helper::dateLabel(...)));
        $this->twig->addFunction(new TwigFunction('gmt_offset', Helper::gmtOffset(...)));
        $this->twig->addFunction(new TwigFunction('duration', Helper::hoursAndMinutes(...)));
        // What each passenger type pays, so the calendar can price a party in
        // the browser without a second definition of it living there.
        $this->twig->addFunction(new TwigFunction('party_shares', Party::shares(...)));

        // ...and the dynamic header/footer data (see LayoutData).
        // A global, so it reaches the passenger partial through the `only`
        // includes that otherwise cut the context off. There is one definition
        // of how many seats a booking may hold and it is Party's.
        $this->twig->addGlobal('max_seats', Party::MAX_SEATS);

        $this->twig->addFunction(new TwigFunction('asset', $this->layout->asset(...)));
        // Given the trail the partial is about to draw, so the two agree.
        $this->twig->addFunction(new TwigFunction('breadcrumb_jsonld', Breadcrumbs::structuredData(...)));
        $this->twig->addFunction(new TwigFunction('current_page', $this->layout->currentPage(...)));
        $this->twig->addFunction(new TwigFunction('canonical_path', $this->layout->canonicalPath(...)));
        $this->twig->addFunction(new TwigFunction('indexable', $this->layout->indexable(...)));
        $this->twig->addFunction(new TwigFunction('in_section', $this->layout->inSection(...)));
        $this->twig->addFunction(new TwigFunction('csrf_token', $this->layout->csrfToken(...)));
        $this->twig->addFunction(new TwigFunction('git_info', $this->layout->gitInfo(...)));
        $this->twig->addFunction(new TwigFunction('git_repo', $this->layout->gitRepo(...)));
        $this->twig->addFunction(new TwigFunction('copyright_years', $this->layout->copyrightYears(...)));
        $this->twig->addFunction(new TwigFunction('subscribe_notice', $this->layout->subscribeNotice(...)));
        $this->twig->addFunction(new TwigFunction('most_searched_cities', $this->layout->mostSearchedCities(...)));
        $this->twig->addFunction(new TwigFunction('footer_links', $this->layout->footerLinks(...)));
    }

    /**
     * Render a template fragment (no base layout).
     *
     * @param array<string, mixed> $context
     *
     * @throws LoaderError|RuntimeError|SyntaxError
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
     *
     * @throws Exception|LoaderError|RuntimeError|SyntaxError
     */
    public function renderPage(string $template, array $context = []): string
    {
        // `+`, not array_merge: a controller that passes its own breadcrumbs
        // wins, which is how a page named by its data (a booking, by its
        // reference) overrides the trail derived from the path.
        return $this->render(
            $template,
            $context + ['breadcrumbs' => $this->layout->breadcrumbs()] + $this->layout->stats(),
        );
    }
}
