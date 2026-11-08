<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace Sakura\Vendor\GuzzleHttp;

/**
 * Reads configuration from the process environment.
 *
 * Intentionally separate from Handler\ProxyEnv, which has different
 * lookup semantics.
 *
 * @internal
 */
final class Env
{
    private function __construct()
    {
    }

    public static function get(string $name): ?string
    {
        if (isset($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        if (\PHP_SAPI === 'cli' && ($value = \getenv($name)) !== false && $value !== null) {
            return (string) $value;
        }

        return null;
    }
}
