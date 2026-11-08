<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Api\Exception;

use Sakura\Vendor\Aws\HasMonitoringEventsTrait;
use Sakura\Vendor\Aws\MonitoringEventsInterface;

class RpcV2CborException extends \RuntimeException implements
    MonitoringEventsInterface
{
    use HasMonitoringEventsTrait;
}
