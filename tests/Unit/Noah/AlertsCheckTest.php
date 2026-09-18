<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\Noah;

use PHPUnit\Framework\TestCase;
use TripBuilder\Noah\Alerts\Check;

/**
 * Whether a watch already under its threshold should actually be emailed.
 *
 * `qualifies()` is static and pure precisely so this file exists: the
 * decision that stops a watch being emailed once a tick forever is all here,
 * and none of it needs a database or Mailtrap.
 */
final class AlertsCheckTest extends TestCase
{
    public function testANeverNotifiedWatchQualifies(): void
    {
        self::assertTrue(Check::qualifies(400.0, null));
    }

    public function testTheSamePriceAsBeforeDoesNotQualify(): void
    {
        self::assertFalse(Check::qualifies(400.0, 400.0));
    }

    public function testAHigherPriceThanBeforeDoesNotQualify(): void
    {
        self::assertFalse(Check::qualifies(450.0, 400.0));
    }

    public function testAFurtherDropQualifiesAgain(): void
    {
        self::assertTrue(Check::qualifies(350.0, 400.0));
    }
}
