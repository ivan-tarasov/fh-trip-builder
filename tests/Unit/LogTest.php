<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TripBuilder\Log;

/**
 * What a log line looks like, checked by reading one.
 *
 * The whole value of this class is that two lines can be tied together, so the
 * test that matters is that they carry the same id — asserting the method
 * exists would prove nothing.
 */
final class LogTest extends TestCase
{
    private string $file;

    private string $previous;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'log-') ?: '';
        $this->previous = (string) ini_get('error_log');

        ini_set('error_log', $this->file);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previous);

        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function written(): string
    {
        return (string) file_get_contents($this->file);
    }

    public function testAnErrorCarriesTheRequestId(): void
    {
        $id = Log::begin();

        Log::error('Airside page failed: nope');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $id);
        self::assertStringContainsString('[' . $id . '] Airside page failed: nope', $this->written());
    }

    /**
     * Two errors in one request share an id; that is the point of having one.
     */
    public function testEveryLineFromOneRequestSharesAnId(): void
    {
        $id = Log::begin();

        Log::error('first');
        Log::error('second');

        self::assertSame(2, substr_count($this->written(), '[' . $id . ']'));
    }

    public function testTwoRequestsDoNotShareAnId(): void
    {
        self::assertNotSame(Log::begin(), Log::begin());
    }

    /**
     * The line the front controller registers, written on demand here.
     */
    public function testTheRequestLineCarriesTheIdTheErrorsDid(): void
    {
        $id = Log::begin();

        Log::error('Airside page failed: nope');
        Log::finish('GET', '/airside');

        self::assertStringContainsString('[' . $id . '] GET /airside', $this->written());
        self::assertSame(2, substr_count($this->written(), '[' . $id . ']'));
    }

    /**
     * On the command line there is no request, so there is no id to print —
     * `noah` writes plain lines rather than empty brackets.
     */
    public function testACommandLineErrorHasNoBrackets(): void
    {
        $log = new ReflectionProperty(Log::class, 'id');
        $log->setValue(null, '');

        Log::error('airside:import failed');

        self::assertStringContainsString('airside:import failed', $this->written());
        self::assertStringNotContainsString('[] ', $this->written());
    }
}
