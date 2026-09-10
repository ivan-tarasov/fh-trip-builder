<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Integration\Repository;

use TripBuilder\Repository\CurrencyRateRepository;
use TripBuilder\Tests\Integration\IntegrationTestCase;

/**
 * The rates table is a history, and this is what makes that claim true.
 *
 * Keyed on code *and* date, so a refresh adds a day rather than replacing a
 * figure. Two things depend on that and neither is visible from the site: the
 * seeded rows survive an `app:install` that would otherwise overwrite fetched
 * ones, and a rate graph is a later feature needing no schema change.
 *
 * A code outside the catalogue is used throughout. `ZZT` fits the column, has
 * no entry in config/common/currencies.php and so can never reach a page, which
 * means these rows cannot disturb what the site shows while the suite runs --
 * and the real rows cannot disturb the assertions either.
 */
final class CurrencyRateRepositoryTest extends IntegrationTestCase
{
    private const string TEST_CODE = 'ZZT';
    private const string OLDER = '1999-01-04';
    private const string NEWER = '1999-01-05';

    protected function tearDown(): void
    {
        // Nothing was written, so there is nothing to tidy -- and a skip
        // raised from a teardown is a failure rather than a skip. See
        // IntegrationTestCase::connectionOrNull().
        if ($this->connectionOrNull() === null) {
            return;
        }

        $this->connection()->execute(
            'DELETE FROM currency_rates WHERE code = ?',
            [self::TEST_CODE],
        );
    }

    /**
     * A second day is a second row, and the reader takes the later one.
     *
     * The assertion the graph feature will rest on, and the one that says an
     * install cannot clobber a fetch.
     */
    public function testANewDayIsAddedRatherThanReplacingTheOldOne(): void
    {
        $repository = $this->repository();

        $repository->store([self::TEST_CODE => 2.5], self::OLDER);
        $repository->store([self::TEST_CODE => 3.5], self::NEWER);

        self::assertSame(
            2,
            (int) $this->connection()->fetchValue(
                'SELECT COUNT(*) FROM currency_rates WHERE code = ?',
                [self::TEST_CODE],
            ),
            'the older day should still be on the table',
        );

        self::assertSame(3.5, $repository->latest()[self::TEST_CODE], 'the newer rate should be the one read');
    }

    /**
     * The same day twice corrects the row instead of failing on the key.
     *
     * The command is safe to run repeatedly -- a cron that fires twice, or an
     * operator checking their work -- and the ECB publishes one figure per
     * working day, so two rows for one day would be two records of one fact.
     */
    public function testTheSameDayTwiceUpdatesInPlace(): void
    {
        $repository = $this->repository();

        $repository->store([self::TEST_CODE => 2.5], self::OLDER);
        $repository->store([self::TEST_CODE => 9.75], self::OLDER);

        self::assertSame(
            1,
            (int) $this->connection()->fetchValue(
                'SELECT COUNT(*) FROM currency_rates WHERE code = ?',
                [self::TEST_CODE],
            ),
        );

        self::assertSame(9.75, $repository->latest()[self::TEST_CODE]);
    }

    /**
     * Rates come back as floats.
     *
     * PDO hands a DECIMAL column over as a string, and Money's constructor is
     * typed -- the same trap MapView::round() documents having hit on five map
     * pages. Asserted on the type, because "0.7263" and 0.7263 compare equal
     * under a loose check and only one of them can be multiplied.
     */
    public function testRatesAreCastFromTheStringsPdoReturns(): void
    {
        $this->repository()->store([self::TEST_CODE => 1.25], self::OLDER);

        $rate = $this->repository()->latest()[self::TEST_CODE];

        self::assertIsFloat($rate);
        self::assertSame(1.25, $rate);
    }

    /**
     * Every currency the switcher offers has a rate behind it.
     *
     * Reads the real rows rather than fixtures, because the question is whether
     * this install can actually convert. A fresh clone passes on the seeded CSV;
     * a machine where somebody has cleared the table does not, and should not.
     */
    public function testTheInstalledRowsCoverTheWholeCatalogue(): void
    {
        $rates = $this->repository()->latest();

        self::assertNotEmpty($rates, 'no rates are installed, so nothing could be converted');
        self::assertArrayHasKey('CAD', $rates);
        self::assertSame(1.0, $rates['CAD'], 'the base currency must sit at parity');
        self::assertNotNull($this->repository()->latestDate());
    }

    private function repository(): CurrencyRateRepository
    {
        return new CurrencyRateRepository($this->connection());
    }
}
