<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace Sakura\Vendor\GuzzleHttp\Promise;

/**
 * @internal
 */
trait NonSerializableTrait
{
    public function __serialize(): array
    {
        throw new \LogicException(static::class.' should never be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException(static::class.' should never be unserialized');
    }
}
