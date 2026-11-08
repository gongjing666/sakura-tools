<?php
/**
 * Regression: the cron callback accepts WordPress' empty argument.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Delete_Worker;

final class S3DeleteWorkerTest extends TestCase {

	public function test_cron_empty_argument_does_not_type_error(): void {
		$worker = new Delete_Worker();
		$worker->run( '' );
		$this->assertTrue( true );
	}
}
