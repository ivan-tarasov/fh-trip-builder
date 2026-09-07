<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use TripBuilder\Config;
use TripBuilder\Controllers\SearchController;
use TripBuilder\Http\Input;
use TripBuilder\Http\Request;
use TripBuilder\Routes;
use TripBuilder\SearchUrl;
use TripBuilder\Timer;

/**
 * What the app answers for a search URL that is spelled correctly and names
 * nothing.
 *
 * The search route matches on shape alone -- three characters, six digits, a
 * cabin letter -- so a URL can pass the router and still describe a search that
 * cannot be run: a date the calendar does not have, a window wider than the
 * search will run, a return before its departure. Each of those answered 200
 * and a page carrying an inline script, which moved only a visitor who ran
 * JavaScript and whose content policy allowed it. A crawler kept the page.
 *
 * The controller is driven directly rather than through a stand-in, because the
 * bug was never in the 404 itself -- it was in nothing reaching for one. The
 * guard sits ahead of any database work, so this costs a config load.
 */
final class NotFoundAnswerTest extends TestCase
{
    protected function setUp(): void
    {
        // The route table, the query-string field names and the cabin letters
        // all come from config.
        new Config('common');

        // The page footer prints how long the request took, and index.php is
        // what normally starts the clock. Without it, rendering any full page
        // throws instead of answering.
        Timer::start();
    }

    /** @return array<string, array{string}> */
    public static function unrunnableSearches(): array
    {
        return [
            'a date the calendar does not have' => ['/search/YUL999999LHRY1'],
            'a window past SearchUrl::MAX_SPAN' => ['/search/YUL151026x9LHRY1'],
            'a return before its departure' => ['/search/YUL151026LHR011025Y1'],
        ];
    }

    #[DataProvider('unrunnableSearches')]
    public function testAnUnrunnableSearchIsAnsweredAsNotFound(string $path): void
    {
        $answer = $this->answer($path);

        self::assertSame(404, $answer['status'], $path . ' should not be a page');
        self::assertStringContainsString('lost in the sky', $answer['body'], 'the 404 page, not a blank one');

        // The point of the whole change: leaving without JavaScript, and
        // without an inline script a content policy would refuse to run.
        self::assertStringNotContainsString('window.location.replace', $answer['body']);
    }

    #[DataProvider('unrunnableSearches')]
    public function testTheRouterAcceptsThemSoOnlyTheControllerCanTurnThemAway(string $path): void
    {
        // If this ever stops holding, the URL falls to the router's own 404 and
        // the guard in the controller becomes dead code -- worth being told
        // rather than quietly keeping both.
        self::assertSame('Search@index', Routes::resolve($path), $path . ' should reach the search controller');
        self::assertNull(SearchUrl::parse($path), $path . ' should not parse into a search');
    }

    public function testARunnableSearchIsStillASearch(): void
    {
        // The control. Without it, a parser that had begun rejecting everything
        // would satisfy every assertion above.
        $path = '/search/YUL151026x3LHR221026x2Y1';

        self::assertSame('Search@index', Routes::resolve($path));
        self::assertNotNull(SearchUrl::parse($path));
    }

    public function testAFragmentGetsTheStatusAndNoSecondDocument(): void
    {
        // "Load more" appends to a results list that is already on screen, so a
        // whole 404 document would draw an error page inside the list rather
        // than replacing it. The status is the entire answer.
        $answer = $this->answer('/search/YUL999999LHRY1', ['fragment' => '1']);

        self::assertSame(404, $answer['status']);
        self::assertSame('', $answer['body']);
    }

    /**
     * Run the search controller and hand back what it set and what it printed.
     *
     * The status is read before it is put back: reset first, and every
     * assertion would be about a 200.
     *
     * @param array<string, string> $query
     *
     * @return array{status: int|bool, body: string}
     */
    private function answer(string $path, array $query = []): array
    {
        http_response_code(200);

        $controller = new SearchController(
            new Request(new Input($query), new Input(), new Input(), uri: $path),
        );

        ob_start();

        try {
            $controller->index();
            $body = (string) ob_get_clean();

            return ['status' => http_response_code(), 'body' => $body];
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        } finally {
            // Left set, it would leak a 404 into whatever test ran next.
            http_response_code(200);
        }
    }
}
