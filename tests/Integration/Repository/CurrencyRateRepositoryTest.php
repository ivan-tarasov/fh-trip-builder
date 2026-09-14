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
            'DELETE FROM currency_rates WHERE code IN (?, ?)',
            [self::TEST_CODE, 'ZZQ'],
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

    /**
     * A backfill is many days at once, and it is a history when it lands.
     *
     * What B2.1 (#104) was for: one date is a point, and a point is not a
     * chart.
     */
    public function testASpanOfDaysIsWrittenAsASpanOfRows(): void
    {
        $written = $this->repository()->storeMany([
            self::OLDER => [self::TEST_CODE => 2.5],
            self::NEWER => [self::TEST_CODE => 3.5],
        ]);

        self::assertSame(2, $written);
        self::assertSame(
            [self::OLDER => 2.5, self::NEWER => 3.5],
            $this->history(),
        );
    }

    /**
     * Running the same span twice reports the nothing it changed.
     *
     * A backfill is the command somebody runs when they are not sure whether
     * they have run it, so it has to be safe to run again and honest about
     * having done nothing.
     */
    public function testTheSameSpanTwiceChangesNothing(): void
    {
        $days = [self::OLDER => [self::TEST_CODE => 2.5], self::NEWER => [self::TEST_CODE => 3.5]];

        self::assertSame(2, $this->repository()->storeMany($days));
        self::assertSame(0, $this->repository()->storeMany($days));
        self::assertCount(2, $this->history());
    }

    /**
     * A day already held is corrected rather than duplicated.
     */
    public function testARewrittenDayIsCorrected(): void
    {
        $this->repository()->storeMany([self::OLDER => [self::TEST_CODE => 2.5]]);
        $this->repository()->storeMany([self::OLDER => [self::TEST_CODE => 2.75]]);

        self::assertSame([self::OLDER => 2.75], $this->history());
    }

    /**
     * The read the graph is drawn from: one currency, oldest first.
     */
    public function testAHistoryComesBackOldestFirst(): void
    {
        $this->repository()->storeMany([
            self::NEWER => [self::TEST_CODE => 3.5],
            self::OLDER => [self::TEST_CODE => 2.5],
        ]);

        self::assertSame(
            [self::OLDER => 2.5, self::NEWER => 3.5],
            $this->repository()->history(self::TEST_CODE, 90),
        );
    }

    /**
     * Bounded by the most recent days, not the oldest.
     *
     * A chart of the last ninety days that quietly drew the first ninety this
     * table ever held would be wrong in a way nobody would notice.
     */
    public function testAHistoryKeepsTheNewestDays(): void
    {
        $this->repository()->storeMany([
            '1999-01-04' => [self::TEST_CODE => 1.0],
            '1999-01-05' => [self::TEST_CODE => 2.0],
            '1999-01-06' => [self::TEST_CODE => 3.0],
        ]);

        self::assertSame(
            ['1999-01-05' => 2.0, '1999-01-06' => 3.0],
            $this->repository()->history(self::TEST_CODE, 2),
        );
    }

    public function testAHistoryForACurrencyNobodyStoredIsEmpty(): void
    {
        self::assertSame([], $this->repository()->history('ZZQ', 90));
    }

    /** @return array<string, float> rate_date => rate */
    private function history(): array
    {
        $rows = $this->connection()->fetchAll(
            'SELECT rate_date, rate FROM currency_rates WHERE code = ? ORDER BY rate_date',
            [self::TEST_CODE],
        );

        $history = [];

        foreach ($rows as $row) {
            $history[(string) $row['rate_date']] = (float) $row['rate'];
        }

        return $history;
    }

    private function repository(): CurrencyRateRepository
    {
        return new CurrencyRateRepository($this->connection());
    }
}
