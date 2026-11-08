<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace Sakura\Vendor\GuzzleHttp\Exception;

use Sakura\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use Sakura\Vendor\Psr\Http\Message\RequestInterface;

/**
 * Base exception for transfer failures without a response.
 */
class NetworkException extends TransferException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        RequestInterface $request,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $request, 0, $previous);
    }
}
