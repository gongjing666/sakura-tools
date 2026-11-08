<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace Sakura\Vendor\GuzzleHttp\Handler;

/**
 * @internal
 */
final class Clock
{
    private function __construct()
    {
    }

    /**
     * Returns the current monotonic clock reading in seconds.
     */
    public static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    public static function sleep(int $microseconds): void
    {
        while ($microseconds > 0) {
            $slice = \min($microseconds, 0xFFFFFFFF);
            \usleep($slice);
            $microseconds -= $slice;
        }
    }
}
