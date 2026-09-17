<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TripBuilder\Routes;

final class RoutesTest extends TestCase
{
    public function testAnExactRouteStillResolves(): void
    {
        self::assertSame('My@bookings', Routes::resolve('/my/bookings'));
        self::assertSame('Checkout@confirmation', Routes::resolve('/checkout/confirmation'));
    }

    /**
     * The hub is an exact route and the articles are a pattern.
     *
     * The pattern is the loose half, and it is loose deliberately: what makes a
     * slug real is being a row in `articles`, which is
     * HelpController's question and not the router's. So the router says yes to
     * a word here and the page still answers 404.
     */
    public function testHelpHasAHubAndAPatternForItsArticles(): void
    {
        self::assertSame('Help@index', Routes::resolve('/help'));
        self::assertSame('Help@show', Routes::resolve('/help/baggage'));
        self::assertSame('Help@show', Routes::resolve('/help/ticket-not-received'));
        // Capitals reach the controller so that it can redirect them.
        self::assertSame('Help@show', Routes::resolve('/help/Baggage'));
    }

    /**
     * Airside allows digits in a slug where help does not.
     *
     * A help article is a subject; a post is a piece of writing, and
     * "three-ways-to-pick-a-seat" is a title somebody will write. The pattern,
     * `AirsideController::slug()` and the check `airside:import` makes on a
     * file name are three copies of this rule, so a slug that stops matching
     * one of them stops being reachable.
     */
    public function testAirsideHasAHubAndAPatternThatTakesDigits(): void
    {
        self::assertSame('Airside@index', Routes::resolve('/airside'));
        self::assertSame('Airside@show', Routes::resolve('/airside/picking-a-seat'));
        self::assertSame('Airside@show', Routes::resolve('/airside/three-ways-to-pick-a-seat'));

        // Matched and then 301'd to lower case by the controller, the way help
        // handles a capital.
        self::assertSame('Airside@show', Routes::resolve('/airside/Picking-A-Seat'));

        // Not a post: a slash below the section is not a route here, and
        // neither is an empty slug.
        self::assertNull(Routes::resolve('/airside/picking/a-seat'));
        self::assertNull(Routes::resolve('/airside/'));
    }

    /**
     * Each family votes at its own endpoint.
     *
     * Two endpoints and not one taking a "which table" parameter: the
     * allow-list is the difference between them, and an allow-list the caller
     * chooses is not one.
     */
    public function testEachFamilyHasItsOwnVoteEndpoint(): void
    {
        self::assertSame('Ajax@articleVote', Routes::resolve('/ajax/article-vote'));
        self::assertSame('Ajax@postVote', Routes::resolve('/ajax/post-vote'));
    }

    /**
     * A tag's page sits under the section, and is lower case only.
     *
     * Unlike a post slug, which the controller 301s from capitals because a
     * reader may well have typed one: a tag slug is derived from its name by
     * `airside:import`, which lower-cases it, so there is no other spelling in
     * existence to redirect from.
     */
    public function testATagHasItsOwnPageBeneathTheSection(): void
    {
        self::assertSame('Airside@tag', Routes::resolve('/airside/tag/security'));
        self::assertSame('Airside@tag', Routes::resolve('/airside/tag/hand-luggage'));

        self::assertNull(Routes::resolve('/airside/tag/Security'));
        self::assertNull(Routes::resolve('/airside/tag/a/b'));

        // The bare prefix is not a listing. It matches the post pattern, and
        // answers the way any other unknown slug does.
        self::assertSame('Airside@show', Routes::resolve('/airside/tag'));
    }

    public function testABookingIsAddressedByItsIdInThePath(): void
    {
        self::assertSame('My@booking', Routes::resolve('/my/bookings/100001'));
        self::assertSame('My@calendar', Routes::resolve('/my/bookings/100001/calendar'));
    }

    public function testASearchIsAddressedByItsOwnPath(): void
    {
        self::assertSame('Search@index', Routes::resolve('/search/YUL160926LHRY1'));
        self::assertSame('Search@index', Routes::resolve('/search/YUL160926LHR300926W421'));
        // The digit-bearing airport code, which [A-Z]{3} would reject.
        self::assertSame('Search@index', Routes::resolve('/search/A39160926A39W1'));
        // The query-string form still answers; it is what redirects here.
        self::assertSame('Search@index', Routes::resolve('/search'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notRoutes(): array
    {
        return [
            ['/search/YUL160926LHR'],
            ['/search/YUL160926LHRY'],
            ['/search/yul160926lhry1'],
            ['/search/YUL160926LHRY1234'],
            ['/my/bookings/abc'],
            ['/my/bookings/'],
            ['/my/bookings/12/'],
            ['/my/bookings/12/calendar/extra'],
            ['/my/bookings/12/cancel'],
            ['/nope'],
            // The help pattern is anchored, and takes no digits and no second
            // segment.
            ['/help/'],
            ['/help/baggage/extra'],
            ['/help/baggage2'],
        ];
    }

    #[DataProvider('notRoutes')]
    public function testAnythingElseIsNotARoute(string $url): void
    {
        // The patterns are anchored and digits-only on purpose: a router this
        // small should refuse everything it was not asked to serve.
        self::assertNull(Routes::resolve($url));
    }

    public function testOnlyTheCalendarWritesItsOwnBytes(): void
    {
        // It hangs off a page controller, so the per-controller list cannot
        // cover it -- and a header and footer glued to a download corrupts it.
        self::assertTrue(Routes::emitsOwnPayload('/my/bookings/100001/calendar'));
        self::assertFalse(Routes::emitsOwnPayload('/my/bookings/100001'));
        self::assertFalse(Routes::emitsOwnPayload('/my/bookings'));
        self::assertNotContains('My', Routes::EXCLUDE_HEADER_FOOTER);
    }

    /**
     * The panel is reachable and is not advertised.
     *
     * The password is what keeps a stranger out; this is what keeps the
     * address out of the sitemap and out of an index, which robots.txt is
     * built from as well. Not a security measure -- there is simply no reason
     * to publish it (A3.2, #100).
     */
    public function testTheAdminPanelIsRoutedAndPrivate(): void
    {
        self::assertSame('Admin@index', Routes::resolve('/admin'));
        self::assertSame('Admin@content', Routes::resolve('/admin/content'));
        self::assertSame('Admin@bookings', Routes::resolve('/admin/bookings'));
        self::assertSame('Admin@login', Routes::resolve('/admin/login'));
        self::assertSame('Admin@logout', Routes::resolve('/admin/logout'));

        foreach (['/admin', '/admin/content', '/admin/bookings', '/admin/login', '/admin/logout'] as $path) {
            self::assertFalse(Routes::isPublic($path), $path . ' is being advertised');
        }

        // A page, not a payload: it renders through the layout like the rest.
        self::assertNotContains('Admin', Routes::EXCLUDE_HEADER_FOOTER);
        self::assertFalse(Routes::emitsOwnPayload('/admin'));
    }

    /**
     * The editors, with a slug and without.
     *
     * Without one they create and with one they edit, and the same address
     * takes the POST that saves -- so one pattern has to answer to both or
     * half the panel 404s (A3.3, #101).
     */
    public function testTheEditorsAnswerWithAndWithoutASlug(): void
    {
        self::assertSame('Admin@article', Routes::resolve('/admin/article'));
        self::assertSame('Admin@article', Routes::resolve('/admin/article/searching-for-flights'));
        self::assertSame('Admin@category', Routes::resolve('/admin/category'));
        self::assertSame('Admin@category', Routes::resolve('/admin/category/before-you-book'));

        // A slug is the shape the help pages use, because it is the same slug.
        self::assertNull(Routes::resolve('/admin/article/Not A Slug'));
        self::assertNull(Routes::resolve('/admin/article/one/two'));
    }

    /**
     * A booking is addressed by id and not by reference.
     *
     * A reference is the code a traveller quotes and it is unique -- but it is
     * empty on a row written before checkout finished, and those are exactly
     * the bookings an operator most wants to look at (A3.8, #233).
     */
    public function testABookingIsAddressedByItsId(): void
    {
        self::assertSame('Admin@booking', Routes::resolve('/admin/bookings/100001'));
        self::assertNull(Routes::resolve('/admin/bookings/3AU6CE'));
        self::assertNull(Routes::resolve('/admin/bookings/'));
    }

    /**
     * The preview answers a fragment, and a header and footer wrapped around
     * one would be spliced into the page it is previewing.
     */
    public function testThePreviewWritesItsOwnBytes(): void
    {
        self::assertSame('Admin@preview', Routes::resolve('/admin/preview'));
        self::assertTrue(Routes::emitsOwnPayload('/admin/preview'));
    }

    /**
     * The schedule's own editor, addressed by id rather than by slug -- a
     * job has no name of its own to be one (G19, #377).
     */
    public function testTheScheduleEditorAnswersWithAndWithoutAnId(): void
    {
        self::assertSame('Admin@schedule', Routes::resolve('/admin/schedule'));
        self::assertSame('Admin@scheduleJob', Routes::resolve('/admin/schedule/job'));
        self::assertSame('Admin@scheduleJob', Routes::resolve('/admin/schedule/job/42'));
        self::assertSame('Admin@scheduleHistory', Routes::resolve('/admin/schedule/history'));

        self::assertNull(Routes::resolve('/admin/schedule/job/abc'));
        self::assertNull(Routes::resolve('/admin/schedule/job/42/extra'));
    }

    public function testEveryDynamicRouteIsAValidPatternNamingAnAction(): void
    {
        foreach (Routes::DYNAMIC_ROUTES as $pattern => $route) {
            // false, not 0: a malformed pattern would make resolve() warn and
            // fall through to a 404 on a route that is supposed to work.
            self::assertNotFalse(@preg_match($pattern, '/probe'), 'invalid pattern: ' . $pattern);
            self::assertStringContainsString('@', $route, $pattern . ' names no action');
        }
    }
}
