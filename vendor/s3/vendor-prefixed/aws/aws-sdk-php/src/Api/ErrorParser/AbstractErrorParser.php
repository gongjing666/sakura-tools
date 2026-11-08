<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */
namespace Sakura\Vendor\Aws\Api\ErrorParser;

use Sakura\Vendor\Aws\Api\Parser\MetadataParserTrait;
use Sakura\Vendor\Aws\Api\Parser\PayloadParserTrait;
use Sakura\Vendor\Aws\Api\Service;
use Sakura\Vendor\Aws\Api\StructureShape;
use Sakura\Vendor\Aws\CommandInterface;
use Sakura\Vendor\Psr\Http\Message\ResponseInterface;

abstract class AbstractErrorParser
{
    use MetadataParserTrait;
    use PayloadParserTrait;

    /**
     * @var Service
     */
    protected $api;

    /**
     * @param Service $api
     */
    public function __construct(?Service $api = null)
    {
        $this->api = $api;
    }

    abstract protected function payload(
        ResponseInterface $response,
        StructureShape $member
    );

    protected function populateShape(
        array &$data,
        ResponseInterface $response,
        ?CommandInterface $command = null
    ) {
        $data['body'] = [];

        if (!empty($command) && !empty($this->api)) {

            // If modeled error code is indicated, check for known error shape
            if (!empty($data['code'])) {

                $errors = $this->api->getOperation($command->getName())->getErrors();
                foreach ($errors as $error) {

                    // If error code matches a known error shape, populate the body
                    if ($this->errorCodeMatches($data, $error)) {
                        $data['body'] = $this->payload(
                            $response,
                            $error
                        );
                        $data['error_shape'] = $error;

                        foreach ($error->getMembers() as $name => $member) {
                            switch ($member['location']) {
                                case 'header':
                                    $this->extractHeader($name, $member, $response, $data['body']);
                                    break;
                                case 'headers':
                                    $this->extractHeaders($name, $member, $response, $data['body']);
                                    break;
                                case 'statusCode':
                                    $this->extractStatus($name, $response, $data['body']);
                                    break;
                            }
                        }

                        break;
                    }
                }
            }
        }

        return $data;
    }

    private function errorCodeMatches(array $data, $error): bool
    {
        return $data['code'] == $error['name']
            || (isset($error['error']['code']) && $data['code'] === $error['error']['code']);
    }
}
