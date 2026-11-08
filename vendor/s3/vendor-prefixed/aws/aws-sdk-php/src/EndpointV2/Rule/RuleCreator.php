<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sakura\Vendor\Aws\EndpointV2\Rule;

use Sakura\Vendor\Aws\Exception\UnresolvedEndpointException;

class RuleCreator
{
    public static function create($type, $definition)
    {
        switch ($type) {
            case 'endpoint':
                return new EndpointRule($definition);
            case 'error':
                return new ErrorRule($definition);
            case 'tree':
                return new TreeRule($definition);
            default:
                throw new UnresolvedEndpointException(
                    'Unknown rule type ' . $type .
                    ' must be of type `endpoint`, `tree` or `error`'
                );
        }
    }
}

