<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sakura\Vendor\Symfony\Polyfill\Php82;

/**
 * @internal
 */
trait NoDynamicProperties
{
    public function __set(string $name, $value): void
    {
        throw new \Error('Cannot create dynamic property '.self::class.'::$'.$name);
    }
}
