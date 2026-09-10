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

    private function read(string $path): string
    {
        return (string) file_get_contents(Helper::getRootDir() . '/' . $path);
    }
}
