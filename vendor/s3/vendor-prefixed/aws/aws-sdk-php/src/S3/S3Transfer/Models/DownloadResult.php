<?php
/**
 * @license Apache-2.0
 *
 * Modified by __root__ on 24-October-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace Sakura\Vendor\Aws\S3\S3Transfer\Models;

use Sakura\Vendor\Aws\Result;

final class DownloadResult extends Result
{
    private readonly mixed $downloadDataResult;

    /**
     * @param mixed $downloadDataResult
     * @param array $data
     */
    public function __construct(
        mixed $downloadDataResult,
        array $data = []
    ) {
        parent::__construct($data);
        $this->downloadDataResult = $downloadDataResult;
    }

    /**
     * @return mixed
     */
    public function getDownloadDataResult(): mixed
    {
        return $this->downloadDataResult;
    }
}
