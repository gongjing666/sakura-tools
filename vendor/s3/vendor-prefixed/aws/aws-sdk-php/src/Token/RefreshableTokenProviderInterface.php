<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Token;

/**
 * Provides access to an AWS token used for accessing AWS services
 *
 */
interface RefreshableTokenProviderInterface
{
    /**
     * Attempts to refresh this token object
     *
     * @return Token | Exception
     */
    public function refresh();

    /**
     * Check if a refresh should be attempted
     *
     * @return boolean
     */
    public function shouldAttemptRefresh();
}
