<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Api\Parser;

use Sakura\Vendor\Aws\Api\Service;
use Sakura\Vendor\Aws\Api\StructureShape;
use Sakura\Vendor\Aws\CommandInterface;
use Sakura\Vendor\Aws\ResultInterface;
use Sakura\Vendor\GuzzleHttp\Psr7\CachingStream;
use Sakura\Vendor\Psr\Http\Message\ResponseInterface;
use Sakura\Vendor\Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
abstract class AbstractParser
{
    /** @var \Sakura\Vendor\Aws\Api\Service Representation of the service API*/
    protected $api;

    /** @var callable */
    protected $parser;

    /**
     * @param Service $api Service description.
     */
    public function __construct(Service $api)
    {
        $this->api = $api;
    }

    /**
     * @param CommandInterface  $command  Command that was executed.
     * @param ResponseInterface $response Response that was received.
     *
     * @return ResultInterface
     */
    abstract public function __invoke(
        CommandInterface $command,
        ResponseInterface $response
    );

    abstract public function parseMemberFromStream(
        StreamInterface $stream,
        StructureShape $member,
        $response
    );

    public static function getBodyContents(ResponseInterface $response): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }

    public static function getResponseWithCachingStream(
        ResponseInterface $response
    ): ResponseInterface
    {
        if (!$response->getBody()->isSeekable()) {
            return $response->withBody(
                new CachingStream($response->getBody())
            );
        }

        return $response;
    }
}
