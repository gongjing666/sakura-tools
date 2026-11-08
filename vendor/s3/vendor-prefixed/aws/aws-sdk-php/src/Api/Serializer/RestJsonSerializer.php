<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Api\Serializer;

use Sakura\Vendor\Aws\Api\Service;
use Sakura\Vendor\Aws\Api\StructureShape;

/**
 * Serializes requests for the REST-JSON protocol.
 * @internal
 */
class RestJsonSerializer extends RestSerializer
{
    /** @var JsonBody */
    private $jsonFormatter;

    /** @var string */
    private $contentType;

    /**
     * @param Service  $api           Service API description
     * @param string   $endpoint      Endpoint to connect to
     * @param JsonBody $jsonFormatter Optional JSON formatter to use
     */
    public function __construct(
        Service $api,
        $endpoint,
        ?JsonBody $jsonFormatter = null
    ) {
        parent::__construct($api, $endpoint);
        $this->contentType = JsonBody::getContentType($api);
        $this->jsonFormatter = $jsonFormatter ?: new JsonBody($api);
    }

    protected function payload(StructureShape $member, array|string $value, array &$opts)
    {
        $opts['headers']['Content-Type'] = $this->contentType;
        $body = $this->jsonFormatter->build($member, $value);
        $opts['headers']['Content-Length'] = (string) strlen($body);
        $opts['body'] = $body;
    }
}
