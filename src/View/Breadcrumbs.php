<?php

declare(strict_types=1);

namespace TripBuilder\View;

use TripBuilder\Config;

/**
 * The trail of pages above the one being viewed.
 *
 * Ancestors are read off the URL rather than declared, so `/my/bookings/100001`
 * finds `/my/bookings` on its own and a page added underneath an existing one
 * needs no wiring. `/my` falls out of that walk because it is a path segment
 * rather than a page -- what makes a segment a page is having a name in
 * config/common/breadcrumbs.php, and that map is the only thing to edit.
 *
 * A page with no name and no named ancestor gets no trail at all. That is how
 * the booking funnel stays clear of one: `/search` and `/checkout` show a step
 * indicator instead, and two rows of progress on one page compete.
 *
 * Pure -- a string in, an array out. No request, no session, no database.
 */
final class Breadcrumbs
{
    /**
     * @return list<array{label: string, url: string|null, current: bool}>
     */
    public static function trail(string $path, ?string $currentLabel = null): array
    {
        $path = self::normalise($path);

        // Home is the root of every trail and so never shows one of its own.
        if ($path === '/') {
            return [];
        }

        /** @var array<string, string> $pages */
        $pages = Config::get('breadcrumbs.pages', []);
        $segments = explode('/', trim($path, '/'));

        $ancestors = [];
        $walk = '';

        foreach ($segments as $segment) {
            $walk .= '/' . $segment;

            if ($walk === $path) {
                break;
            }

            if (isset($pages[$walk])) {
                $ancestors[] = ['label' => $pages[$walk], 'url' => $walk, 'current' => false];
            }
        }

        $named = isset($pages[$path]);

        if (!$named && $ancestors === []) {
            return [];
        }

        // A page the map does not name is named by its caller -- a booking by
        // its reference. The last segment is the honest fallback when nobody
        // said otherwise: on /my/bookings/100001 it is the id, which is at
        // least true.
        $label = $currentLabel ?? ($pages[$path] ?? end($segments));

        return [
            ['label' => (string) Config::get('breadcrumbs.home', 'Home'), 'url' => '/', 'current' => false],
            ...$ancestors,
            ['label' => $label, 'url' => null, 'current' => true],
        ];
    }

    /**
     * The same trail as schema.org BreadcrumbList JSON-LD, or null when a page
     * shows none.
     *
     * Takes the rendered trail rather than a path, so the structured data and
     * the visible crumbs can never describe different things -- a controller
     * that supplies its own trail, as the booking page and the 404 do, is
     * described by what it supplied.
     *
     * URLs stay relative, exactly as the links above them are. The app knows no
     * canonical host, and resolving them against the page is well defined --
     * inventing an absolute base would be a second source of truth that nothing
     * else in the app could keep correct.
     *
     * @param list<array{label: string, url: string|null, current: bool}> $trail
     */
    public static function structuredData(array $trail): ?string
    {
        if ($trail === []) {
            return null;
        }

        $items = [];

        foreach ($trail as $index => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'],
            ];

            // The current page carries no `item`: schema.org reads its absence
            // as "this one", and a crumb pointing at the page it sits on says
            // nothing.
            if ($crumb['url'] !== null) {
                $item['item'] = $crumb['url'];
            }

            $items[] = $item;
        }

        // JSON_HEX_TAG matters more than it looks: a label reaches this from the
        // URL on a 404 and from a booking reference elsewhere, and an unescaped
        // `<` would let one close the <script> it is written into.
        $json = json_encode(
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $items,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );

        return $json === false ? null : $json;
    }

    /**
     * Whether $path is the page being viewed or an ancestor of it.
     *
     * The nav highlights a section, not a page: on /my/bookings/100001 the
     * "My bookings" item should light up, and exact string equality is why it
     * did not. The ancestry this class already walks for the trail is the same
     * question, so it is answered here rather than a second way in the header.
     */
    public static function covers(string $path, string $current): bool
    {
        $path = self::normalise($path);
        $current = self::normalise($current);

        if ($path === $current) {
            return true;
        }

        // The separator matters: without it /airport would cover /airports.
        return $path !== '/' && str_starts_with($current, $path . '/');
    }

    /**
     * Request::path() already strips the trailing slash, but this is also
     * called with hand-written paths in tests and from controllers.
     */
    private static function normalise(string $path): string
    {
        $path = '/' . trim(trim($path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
