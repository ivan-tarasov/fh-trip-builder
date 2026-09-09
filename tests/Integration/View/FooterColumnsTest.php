<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\View;

use DOMDocument;
use DOMElement;
use DOMXPath;
use TripBuilder\Config;
use TripBuilder\Routes;
use TripBuilder\Tests\Integration\IntegrationTestCase;
use TripBuilder\View\TwigRenderer;

/**
 * The shape of the footer's link grid.
 *
 * An integration test and not a unit one, which is the whole reason this was
 * missing. Five of the six columns are drawn from the database, and a column
 * with no rows takes itself off the page -- so without a database the footer
 * renders one column and there is nothing to compare. The suite that renders
 * the footer fastest is exactly the suite that cannot see this.
 *
 * Which cuts both ways, and cost this test a round of CI: a column can be
 * absent because something broke, or because the signal behind it has honestly
 * never been written to. `search` is empty on a freshly installed database --
 * searches are recorded by use, not by seeding -- so the routes column is
 * missing there by design. Asserting all six are present was asserting that
 * somebody had used the site.
 */
final class FooterColumnsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        new Config('common');
        $_SESSION = [];
    }

    /**
     * The columns are all the same length as each other.
     *
     * Evening them up was the point of the work that built this band, and the
     * grid gives no clue when one drifts: a short column just leaves white
     * space beneath it that reads as breathing room.
     *
     * This does not claim it would have caught the commit that made them
     * uneven, because it would not -- before that commit the counted columns
     * ran to seven rows and Help & tips to six, so this test would have been
     * failing then and passing after. It pins the state that is wanted from
     * here: level, at whatever length.
     *
     * Length rather than a number, so moving all of them together stays a
     * cheap change and moving one alone does not.
     *
     * On an installed database with no searches recorded, the Directions
     * column has nothing to show and correctly takes itself off the page, so
     * this compares the five that draw. That is not a gap to close here --
     * FooterRenderTest::testADataDrivenColumnFollowsItsData is what checks a
     * column's presence against its own source.
     */
    public function testTheLinkColumnsAreAllTheSameLength(): void
    {
        $lengths = $this->columnLengths();

        self::assertGreaterThan(1, count($lengths), 'there should be a grid of columns to compare');
        self::assertCount(
            1,
            array_unique($lengths),
            'these columns are different lengths: ' . (string) json_encode($lengths),
        );
    }

    /**
     * Rows per column, keyed by heading.
     *
     * @return array<string, int>
     */
    private function columnLengths(): array
    {
        // Touched so the test skips, rather than fails, where no database is
        // reachable -- the render below reaches for its own connection.
        $this->connection();

        Routes::setCurrentPage('/');

        $html = new TwigRenderer()->render('partials/footer.html.twig', [
            'execution_time' => '0.001',
            'database_requests' => 1,
            'flights_count' => '~1,000',
        ]);

        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

        // Direct children only. The Navigation and Repository columns below the
        // grid draw through the same partial and carry the same class, but they
        // are not part of it and are not meant to match its length.
        $columns = new DOMXPath($document)->query(
            '//*[contains(concat(" ", @class, " "), " footer__columns ")]'
            . '/*[contains(concat(" ", @class, " "), " footer__column ")]',
        );

        self::assertNotFalse($columns);

        $lengths = [];

        foreach ($columns as $column) {
            if (!$column instanceof DOMElement) {
                continue;
            }

            $heading = $column->getElementsByTagName('h2')->item(0);
            $lengths[trim($heading->textContent ?? '?')] = $column->getElementsByTagName('li')->length;
        }

        return $lengths;
    }
}
