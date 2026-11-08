<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sakura\Vendor\Aws\Auth;

use Sakura\Vendor\Aws\Identity\IdentityInterface;

/**
 * An AuthSchemeResolver object determines which auth scheme will be used for request signing.
 */
interface AuthSchemeResolverInterface
{
    /**
     * Selects an auth scheme for request signing.
     *
     * @param array $authSchemes a priority-ordered list of authentication schemes.
     * @param array $args
     *
     * @return string|null
     */
    public function selectAuthScheme(
        array $authSchemes,
        array $args
    ): ?string;
}
