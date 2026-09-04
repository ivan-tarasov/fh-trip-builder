<?php

declare(strict_types=1);

namespace TripBuilder\Controllers;

use Exception;
use TripBuilder\Helper;
use TripBuilder\View\Readme;
use TripBuilder\View\TwigRenderer;

class AboutController extends AbstractController
{
    /**
     * The project's own README, rendered as a page.
     *
     * Nothing about the project is written twice: this is the same file a
     * developer reads on GitHub, so the page cannot drift from it. See
     * View\Readme for which part of the file is shown and why.
     *
     * @throws Exception|\Twig\Error\Error
     */
    public function index(): void
    {
        $readme = new Readme();
        $html = $readme->html();

        if ($html === null) {
            // The README is part of the repository, so this means a broken
            // deploy rather than missing content. Say so instead of rendering
            // an empty page that looks finished.
            http_response_code(500);

            echo new TwigRenderer()->renderPage('about/unavailable.html.twig', [
                'repo_url' => Helper::getGitRepo(),
            ]);

            return;
        }

        echo new TwigRenderer()->renderPage('about/view.html.twig', [
            'readme_html' => $html,
            'updated_at' => $readme->updatedAt(),
            'repo_url' => Helper::getGitRepo(),
        ]);
    }
}
