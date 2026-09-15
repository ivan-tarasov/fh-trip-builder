<?php

declare(strict_types=1);

namespace TripBuilder;

/**
 * One queued message, across a redirect.
 *
 * Every mutating admin action bounces to a GET after it runs -- the reason a
 * refresh cannot repeat the last save -- and until now that GET carried no
 * word on whether the thing it is showing actually happened. `set()` before
 * the bounce, `take()` on the page it lands on: read once and gone, so a
 * later refresh of the same page does not show it again (G3.1, #304).
 */
final class Flash
{
    private const string SESSION_KEY = 'admin_flash';

    public static function set(string $message, FlashTone $tone = FlashTone::Success): void
    {
        $_SESSION[self::SESSION_KEY] = ['message' => $message, 'tone' => $tone->value];
    }

    /**
     * @return array{message: string, tone: FlashTone}|null
     */
    public static function take(): ?array
    {
        $flash = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($flash) || !is_string($flash['message'] ?? null)) {
            return null;
        }

        $tone = FlashTone::tryFrom((string) ($flash['tone'] ?? ''));

        return ['message' => $flash['message'], 'tone' => $tone ?? FlashTone::Success];
    }
}
