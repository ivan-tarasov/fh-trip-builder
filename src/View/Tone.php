<?php

declare(strict_types=1);

namespace TripBuilder\View;

/**
 * How a reading should look, which is not the same as what it says.
 *
 * Four and not three: `Quiet` is the one that earns its place. A panel whose
 * every tile is green or red has nothing left to say when something is
 * genuinely wrong, and most of what a dashboard reports is neither good nor
 * bad -- it is simply the number (A3.6, #231).
 *
 * Colour never carries a state on its own here. Every tile prints the state in
 * words as well, so the tone is emphasis rather than information.
 */
enum Tone: string
{
    /** Working, and recently. */
    case Good = 'good';

    /** Working, and older than it should be. */
    case Warn = 'warn';

    /** Not working, or never ran. */
    case Bad = 'bad';

    /** A fact with no opinion attached. */
    case Quiet = 'quiet';
}
