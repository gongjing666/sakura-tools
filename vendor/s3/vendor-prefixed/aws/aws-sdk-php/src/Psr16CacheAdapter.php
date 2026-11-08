<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws;

use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

class Psr16CacheAdapter implements CacheInterface
{
    /** @var SimpleCacheInterface */
    private $cache;

    public function __construct(SimpleCacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function get($key)
    {
        return $this->cache->get($key);
    }

    public function set($key, $value, $ttl = 0)
    {
        $this->cache->set($key, $value, $ttl);
    }

    public function remove($key)
    {
        $this->cache->delete($key);
    }
}
