<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Retry;

/**
 * Provides access to retry configuration
 */
interface ConfigurationInterface
{
    /**
     * Returns the retry mode. Available modes include 'legacy', 'standard', and
     * 'adapative'.
     *
     * @return string
     */
    public function getMode();

    /**
     * Returns the maximum number of attempts that will be used for a request
     *
     * @return string
     */
    public function getMaxAttempts();

    /**
     * Returns the configuration as an associative array
     *
     * @return array
     */
    public function toArray();
}
