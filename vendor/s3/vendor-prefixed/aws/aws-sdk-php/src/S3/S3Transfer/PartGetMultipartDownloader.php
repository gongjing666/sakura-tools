<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sakura\Vendor\Aws\S3\S3Transfer;

use Sakura\Vendor\Aws\CommandInterface;
use Sakura\Vendor\Aws\Result;
use Sakura\Vendor\Aws\ResultInterface;

/**
 * Multipart downloader using the part get approach.
 */
final class PartGetMultipartDownloader extends AbstractMultipartDownloader
{
    /**
     * @inheritDoc
     */
    protected function getFetchCommandArgs(): array
    {
        $nextCommandArgs = $this->downloadRequestArgs;
        $nextCommandArgs['PartNumber'] = $this->currentPartNo;

        return $nextCommandArgs;
    }

    /**
     * @inheritDoc
     *
     * @param Result $result
     *
     * @return void
     */
    protected function computeObjectDimensions(ResultInterface $result): void
    {
        if (!empty($result['PartsCount'])) {
            $this->objectPartsCount = $result['PartsCount'];
        } else {
            $this->objectPartsCount = 1;
        }

        $this->objectSizeInBytes = self::computeObjectSizeFromContentRange(
            $result['ContentRange'] ?? ""
        );
    }
}
