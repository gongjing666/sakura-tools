<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace Sakura\Vendor\GuzzleHttp\Promise;

/**
 * Interface used with classes that return a promise.
 *
 * @template TValue = mixed
 * @template TReason = mixed
 */
interface PromisorInterface
{
    /**
     * Returns a promise.
     *
     * @return PromiseInterface<TValue, TReason>
     */
    public function promise(): PromiseInterface;
}
