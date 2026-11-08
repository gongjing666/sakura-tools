<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace Sakura\Vendor\GuzzleHttp\Psr7;

use Sakura\Vendor\Psr\Http\Message\StreamInterface;

/**
 * Stream decorator that records read failures and returns an empty string.
 *
 * InflateStream reads through it so that a failure part way through a filtered
 * fread() cannot discard bytes that were already decoded.
 *
 * @internal
 */
final class ReadFailureCapturingStream implements StreamInterface
{
    use StreamDecoratorTrait;
    use NonSerializableStreamTrait;

    private StreamInterface $stream;

    private ?\Exception $readFailure = null;

    private bool $capturing = true;

    public function read(int $length): string
    {
        if (!$this->capturing) {
            return $this->stream->read($length);
        }

        try {
            return $this->stream->read($length);
        } catch (\Exception $e) {
            $this->readFailure = $e;

            return '';
        }
    }

    public function stopCapturing(): void
    {
        $this->capturing = false;
        $this->readFailure = null;
    }

    public function hasReadFailure(): bool
    {
        return $this->readFailure !== null;
    }

    public function takeReadFailure(): ?\Exception
    {
        $failure = $this->readFailure;
        $this->readFailure = null;

        return $failure;
    }
}
