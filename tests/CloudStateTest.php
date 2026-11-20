<?php
/**
 * 媒体库「云端状态」列的判定规则。
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Media\Cloud_State;
use PHPUnit\Framework\TestCase;

/**
 * 列的每个状态都来自三份记录的组合：附件上的对象键、桶的确认结果、
 * 远端删除队列。把规则钉在这里，渲染层就只剩输出，且「待上传」不会
 * 被误用来表示「索引回答不了」。
 */
final class CloudStateTest extends TestCase {

	/**
	 * 队列里的死任务优先级最高：对象可能还在桶里，也可能还在计费。
	 */
	public function test_a_dead_delete_job_outranks_an_existing_object(): void {
		$state = Cloud_State::classify(
			array( 'a.webp' ),
			array( 'a.webp' => true ),
			array(
				'state'      => 'dead',
				'count'      => 2,
				'last_error' => 'AccessDenied',
			)
		);

		$this->assertSame( Cloud_State::FAILED, $state['state'] );
		$this->assertSame( 'AccessDenied', $state['error'] );
		$this->assertSame( 2, $state['count'] );
	}

	/**
	 * 排队中或执行中的删除任务说明对象正在离开，不必再喊「待上传」。
	 */
	public function test_a_queued_or_running_job_reads_as_deleting(): void {
		$queued = Cloud_State::classify( array( 'a.webp' ), array(), array( 'state' => 'queued', 'count' => 1 ) );
		$running = Cloud_State::classify( array( 'a.webp' ), array(), array( 'state' => 'running', 'count' => 3 ) );

		$this->assertSame( Cloud_State::DELETING, $queued['state'] );
		$this->assertSame( Cloud_State::DELETING, $running['state'] );
		$this->assertSame( 3, $running['count'] );
	}

	/**
	 * 从来没有对象键的附件只是本地文件，不是同步失败。
	 */
	public function test_no_recorded_objects_means_local_only(): void {
		$state = Cloud_State::classify( array(), array(), array( 'state' => 'queued', 'count' => 1 ) );

		$this->assertSame( Cloud_State::DELETING, $state['state'] );

		$clean = Cloud_State::classify( array( '  ', '' ), array() );

		$this->assertSame( Cloud_State::LOCAL, $clean['state'] );
		$this->assertSame( 0, $clean['total'] );
	}

	/**
	 * 索引不可用时必须说「未知」，而不是把对象说成没上传。
	 */
	public function test_an_unavailable_index_reads_as_unknown(): void {
		$state = Cloud_State::classify( array( 'a.webp' ), array( 'a.webp' => true ), null, false );

		$this->assertSame( Cloud_State::UNKNOWN, $state['state'] );
		$this->assertSame( 1, $state['total'] );
		$this->assertSame( 0, $state['confirmed'] );
	}

	/**
	 * 全部确认、部分确认、全未确认对应三个不同状态。
	 */
	public function test_confirmation_counts_split_three_ways(): void {
		$all = Cloud_State::classify( array( 'a.webp', 'b.webp' ), array( 'a.webp' => true, 'b.webp' => true ) );
		$some = Cloud_State::classify( array( 'a.webp', 'b.webp' ), array( 'a.webp' => true ) );
		$none = Cloud_State::classify( array( 'a.webp', 'b.webp' ), array() );

		$this->assertSame( Cloud_State::SYNCED, $all['state'] );
		$this->assertSame( 2, $all['confirmed'] );

		$this->assertSame( Cloud_State::PARTIAL, $some['state'] );
		$this->assertSame( 1, $some['confirmed'] );
		$this->assertSame( 2, $some['total'] );

		$this->assertSame( Cloud_State::PENDING, $none['state'] );
		$this->assertSame( 0, $none['confirmed'] );
	}

	/**
	 * 同一对象被记录两次只能算一个，否则计数会比实际对象多。
	 */
	public function test_duplicate_object_keys_count_once(): void {
		$state = Cloud_State::classify(
			array( 'a.webp', ' a.webp ' ),
			array( 'a.webp' => true )
		);

		$this->assertSame( 1, $state['total'] );
		$this->assertSame( Cloud_State::SYNCED, $state['state'] );
	}
}
