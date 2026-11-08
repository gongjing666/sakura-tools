<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sakura\Vendor\Aws\Handler\GuzzleV6;

trigger_error(sprintf(
    'Using the "%s" class is deprecated, use "%s" instead.',
    __NAMESPACE__ . '\GuzzleHandler',
    \Sakura\Vendor\Aws\Handler\Guzzle\GuzzleHandler::class
), E_USER_DEPRECATED);

class_alias(\Sakura\Vendor\Aws\Handler\Guzzle\GuzzleHandler::class, __NAMESPACE__ . '\GuzzleHandler');
