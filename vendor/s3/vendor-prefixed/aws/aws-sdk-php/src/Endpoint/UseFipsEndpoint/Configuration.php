<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Endpoint\UseFipsEndpoint;

use Sakura\Vendor\Aws;
use Sakura\Vendor\Aws\Endpoint\UseFipsEndpoint\Exception\ConfigurationException;

class Configuration implements ConfigurationInterface
{
    private $useFipsEndpoint;

    public function __construct($useFipsEndpoint)
    {
        $this->useFipsEndpoint = \Sakura\Vendor\Aws\boolean_value($useFipsEndpoint);
        if (is_null($this->useFipsEndpoint)) {
            throw new ConfigurationException("'use_fips_endpoint' config option"
                . " must be a boolean value.");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isUseFipsEndpoint()
    {
        return $this->useFipsEndpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        return [
            'use_fips_endpoint' => $this->isUseFipsEndpoint(),
        ];
    }
}
