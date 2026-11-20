<?php
/**
 * Value object returned by the deletion kernel.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Per-target outcome of one {@see Deletion::delete()} call.
 *
 * The kernel never throws for a normal refusal: a denied capability, a row that
 * is already gone and an attachment that is being deleted right now are
 * results, because every caller has to render them differently. Only an
 * unexpected storage failure produces an error object, and it is stored on the
 * item so the caller can decide whether to retry.
 */
final class Deletion_Result {

	/** The target is gone: attachment, local files, ledger row and charge. */
	public const DELETED = 'deleted';

	/** There was nothing left to remove (already purged, or never known). */
	public const MISSING = 'missing';

	/** The caller may not remove this target; nothing was written. */
	public const DENIED = 'denied';

	/** Part of the work failed (usually the remote object); retry is pending. */
	public const PARTIAL = 'partial';

	/** Another worker claimed the row and is still deleting it. */
	public const IN_PROGRESS = 'in_progress';

	/**
	 * Per-target rows.
	 *
	 * @var array<int,array{target:string,status:string,file_id:int,attachment_id:int,error:string}>
	 */
	private array $items = array();

	/**
	 * Record one target's outcome.
	 *
	 * @param string    $target        Original target (attachment id, file id or object key).
	 * @param string    $status        One of the status constants.
	 * @param int       $file_id       Ledger row id, 0 when unknown.
	 * @param int       $attachment_id Attachment id, 0 when unknown.
	 * @param string    $error         Short error code or empty.
	 * @return void
	 */
	public function add( string $target, string $status, int $file_id = 0, int $attachment_id = 0, string $error = '' ): void {
		$this->items[] = array(
			'target'        => $target,
			'status'        => $status,
			'file_id'       => $file_id,
			'attachment_id' => $attachment_id,
			'error'         => $error,
		);
	}

	/**
	 * Record a failed target from a WP_Error.
	 *
	 * @param string    $target        Target.
	 * @param int       $file_id       Ledger row id.
	 * @param int       $attachment_id Attachment id.
	 * @param \WP_Error $error         Failure.
	 * @return void
	 */
	public function add_error( string $target, int $file_id, int $attachment_id, WP_Error $error ): void {
		$this->add( $target, self::PARTIAL, $file_id, $attachment_id, (string) $error->get_error_code() );
	}

	/**
	 * All per-target rows.
	 *
	 * @return array<int,array{target:string,status:string,file_id:int,attachment_id:int,error:string}>
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Counts per status.
	 *
	 * @return array{deleted:int,missing:int,denied:int,partial:int,in_progress:int,total:int}
	 */
	public function counts(): array {
		$counts = array(
			'deleted'     => 0,
			'missing'     => 0,
			'denied'      => 0,
			'partial'     => 0,
			'in_progress' => 0,
			'total'       => count( $this->items ),
		);

		foreach ( $this->items as $item ) {
			if ( isset( $counts[ $item['status'] ] ) ) {
				++$counts[ $item['status'] ];
			}
		}

		return $counts;
	}

	/**
	 * How many targets were actually removed.
	 *
	 * @return int
	 */
	public function deleted(): int {
		return $this->counts()['deleted'];
	}

	/**
	 * Whether every target reached a final, successful state.
	 *
	 * @return bool
	 */
	public function succeeded(): bool {
		$counts = $this->counts();

		return $counts['total'] > 0 && 0 === $counts['denied'] + $counts['partial'] + $counts['in_progress'];
	}

	/**
	 * Whether at least one target was refused.
	 *
	 * @return bool
	 */
	public function denied(): bool {
		$counts = $this->counts();

		return $counts['denied'] > 0 && 0 === $counts['deleted'] + $counts['missing'];
	}

	/**
	 * Whether at least one target left work for a retry.
	 *
	 * @return bool
	 */
	public function partial(): bool {
		return $this->counts()['partial'] > 0;
	}

	/**
	 * Status of the first (usually only) target, for single-target callers.
	 *
	 * @return string One of the status constants, empty when nothing was recorded.
	 */
	public function first_status(): string {
		return $this->items[0]['status'] ?? '';
	}

	/**
	 * First error code, or empty.
	 *
	 * @return string
	 */
	public function first_error(): string {
		foreach ( $this->items as $item ) {
			if ( '' !== $item['error'] ) {
				return $item['error'];
			}
		}

		return '';
	}

	/**
	 * Merge another result into this one (used when a caller splits a batch).
	 *
	 * @param Deletion_Result $other Result to append.
	 * @return void
	 */
	public function merge( Deletion_Result $other ): void {
		foreach ( $other->items() as $item ) {
			$this->items[] = $item;
		}
	}

	/**
	 * Structured payload for a JSON response or an audit row.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'counts' => $this->counts(),
			'items'  => $this->items,
		);
	}

	/**
	 * Human sentence for an operator-facing notice.
	 *
	 * @return string
	 */
	public function message(): string {
		$counts = $this->counts();

		if ( 1 === $counts['total'] ) {
			$item = $this->items[0];

			switch ( $item['status'] ) {
				case self::DELETED:
					return __( '文件已删除，附件、本地文件、云端对象与容量记录均已同步。', 'sakura-imgopt' );
				case self::MISSING:
					return __( '该文件已经不存在，无需再次删除。', 'sakura-imgopt' );
				case self::DENIED:
					return __( '你没有直接删除该文件的权限。请提交删除申请，等待管理员批准执行；管理员可直接在媒体库删除。', 'sakura-imgopt' );
				case self::IN_PROGRESS:
					return __( '该文件正在删除中，请稍后刷新查看结果。', 'sakura-imgopt' );
				case self::PARTIAL:
					return '' !== $item['error']
						? sprintf(
							/* translators: %s: error code. */
							__( '文件已从媒体库移除，但云端对象尚未确认删除（%s），已交给后台队列重试。', 'sakura-imgopt' ),
							$item['error']
						)
						: __( '文件已从媒体库移除，但云端对象尚未确认删除，已交给后台队列重试。', 'sakura-imgopt' );
			}
		}

		if ( $counts['deleted'] > 0 && 0 === $counts['denied'] + $counts['partial'] + $counts['in_progress'] ) {
			return sprintf(
				/* translators: %d: number of deleted files. */
				__( '已删除 %d 个文件。', 'sakura-imgopt' ),
				$counts['deleted']
			);
		}

		return sprintf(
			/* translators: 1: deleted, 2: missing, 3: denied, 4: partial. */
			__( '删除完成：成功 %1$d，已不存在 %2$d，无权限 %3$d，待重试 %4$d。', 'sakura-imgopt' ),
			$counts['deleted'],
			$counts['missing'],
			$counts['denied'],
			$counts['partial']
		);
	}
}
