<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Helper;

/**
 * An airport shown under its own city is indented and drops its second line.
 *
 * That takes three files agreeing: the template has to emit which city a place
 * is in, the combobox has to read it and mark the row, and the stylesheet has
 * to indent what it marked. Any one of them can be removed without the other
 * two failing -- the list simply stops nesting, which nothing else here would
 * notice.
 *
 * There is no JavaScript test harness in this project, so this is asserted
 * against the sources, the way PromisesTest scans templates and
 * FooterRenderTest reads the stylesheet. It pins that the three still talk to
 * each other; whether the indent looks right is a question for a screenshot.
 */
final class PlacePickerGroupingTest extends TestCase
{
    public function testTheTemplateSaysWhichCityEachPlaceIsIn(): void
    {
        self::assertStringContainsString(
            'data-in-city="{{ row.city_code }}"',
            $this->read('frontend/template/search/form.html.twig'),
            'without this the combobox cannot tell a child row from a standalone one',
        );
    }

    public function testTheComboboxReadsItAndMarksTheRow(): void
    {
        $js = $this->read('frontend/js/global.js');

        self::assertStringContainsString(
            'dataset.inCity',
            $js,
            'the grouping is keyed on the city code, not on the displayed name',
        );

        self::assertStringContainsString(
            "li.classList.add('combo__option--child')",
            $js,
            'nothing marks an airport as being under its city',
        );
    }

    public function testTheStylesheetIndentsWhatWasMarked(): void
    {
        self::assertMatchesRegularExpression(
            '/\.combo__option--child \{[^}]*padding-left:/',
            $this->read('frontend/css/main.css'),
            'the class is added and styles nothing, so the list does not nest',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The nearby block
    |--------------------------------------------------------------------------
    */

    public function testTheTemplateNamesTheAnchorTheBlockWasBuiltFor(): void
    {
        $form = $this->read('frontend/template/search/form.html.twig');

        self::assertStringContainsString('data-nearby="{{ near|join(\',\') }}"', $form, 'the codes');
        self::assertStringContainsString('data-nearby-for="{{ place.value }}"', $form, 'and what they were measured from');
    }

    /**
     * And the block hides itself once the field holds something else.
     *
     * The rows are computed with the page, so picking another place and
     * reopening would otherwise offer the neighbours of the old one. A block
     * that disappears is honest; one that quietly describes the wrong airport
     * is not, and nothing on screen would say which had happened.
     */
    public function testTheComboboxShowsItOnlyWhileTheAnchorIsStillChosen(): void
    {
        self::assertStringContainsString(
            'chosenNow.value === anchored',
            $this->read('frontend/js/global.js'),
            'without this the block outlives the value it was built for',
        );
    }

    /**
     * The block and the list share one row builder.
     *
     * Two builders is how the nesting rules drift: the block would keep
     * indenting after the list stopped, or the other way about, and each looks
     * right on its own.
     */
    public function testBothBlocksRenderThroughTheSameRowBuilder(): void
    {
        $js = $this->read('frontend/js/global.js');

        self::assertSame(
            2,
            substr_count($js, 'rowFor(option,'),
            'the nearby block and the list should be the only two callers, and both should be there',
        );

        self::assertSame(
            1,
            substr_count($js, 'const rowFor = function'),
            'one builder',
        );
    }

    /**
     * The block asks for the radius `nearby()` argues for.
     *
     * 300km because that is a drive somebody would make, and past it "the
     * answers stop being drives" -- a widened radius would fill the block for
     * the 102 airports it is supposed to drop for, with advice nobody would
     * take. Pinned because it is a decision with a reason, and a constant is
     * the easiest thing in the file to nudge.
     */
    public function testTheBlockKeepsTheRadiusItWasArguedFor(): void
    {
        // On the repository, not on a controller: the homepage and the
        // results page both draw this block, and two copies of a radius is
        // how the two pages start disagreeing about what "nearby" means.
        $repository = $this->read('src/Repository/AirportRepository.php');

        self::assertStringContainsString('NEARBY_KM = 300', $repository);
        self::assertStringContainsString('NEARBY_CITIES = 4', $repository);

        // A tighter question than the block's, and a separate decision:
        // 300km is a drive to another airport, 100km is a claim about where
        // somebody is. Measured on a real request -- 13.9km to Montreal,
        // 164km to Ottawa -- so 100 fills the right city and refuses the
        // wrong one. Widening it silently is how a filled field starts being
        // wrong for people it used to leave alone.
        self::assertStringContainsString('HERE_KM = 100', $repository);
    }

    /**
     * The list is ranked by city, not row by row.
     *
     * Row by row split a city in half. `par` drew "Paris", then Orly -- which
     * matched on its name -- then Paro International in Bhutan, and only then
     * Charles De Gaulle, which had matched on its city line and so sorted a
     * band later. A group takes its best member's band, which keeps a city
     * whole and still answers "lon" with London first.
     *
     * Asserted on the source because there is no JavaScript harness here. It
     * pins the decision rather than the behaviour: what the list looks like is
     * a question for a screenshot.
     */
    public function testTheListIsRankedByCityAndNotRowByRow(): void
    {
        $js = $this->read('frontend/js/global.js');

        self::assertStringContainsString(
            'group.band = Math.min(group.band, band)',
            $js,
            'a city should take its best match\'s band, or its rows scatter across bands',
        );

        self::assertStringContainsString(
            '.sort((a, b) => a.band - b.band)',
            $js,
            'and the sort should be over groups rather than rows',
        );
    }

    /**
     * A city gets a heading on two conditions, and both are load bearing.
     *
     * The row must not already name its city -- "San Diego International
     * Airport" under a heading reading San Diego says it twice -- and a city
     * must have been typed. Dropping the second condition put a heading
     * reading "Rio De Janeiro" at the top of a search for `san`, over Santos
     * Dumont, which had matched on its own name; and it turned `trudeau` into
     * a headed group when somebody searching for an airport wants the airport
     * with its city beside it.
     */
    public function testACityIsHeadedOnlyWhenItWasTypedAndTheRowDoesNotNameIt(): void
    {
        $js = $this->read('frontend/js/global.js');

        self::assertStringContainsString('!namesItsCity(o)', $js, 'the row already saying it');
        self::assertStringContainsString('rank(o) === 2', $js, 'and a city having been typed');
    }

    /**
     * The accent comparison is one definition, shared.
     *
     * Eight of the 248 airports carry an accent their city column does not --
     * `Cancún International` in `Cancun` -- so a plain `includes` answers no
     * where a reader would say yes. The checkout's autofill wants the same
     * fold to build an address from a name, and two copies of
     * `normalize('NFD')` is how the two would drift.
     */
    public function testTheAccentFoldIsSharedRatherThanCopied(): void
    {
        $js = $this->read('frontend/js/global.js');

        self::assertSame(1, substr_count($js, 'const folded ='), 'one definition');
        self::assertSame(0, substr_count($js, "normalize('NFD')") - 1, 'and only that one strips accents');
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(Helper::getRootDir() . '/' . $path);
    }
}
