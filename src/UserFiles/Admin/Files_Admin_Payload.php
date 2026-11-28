<?php
/**
 * Bootstrap payload for the three file-space list islands.
 *
 * The REST controllers already own pagination, quotas, review and audit
 * semantics. This class only describes the page chrome, card metadata, the
 * capability matrix and the translated strings the React views reuse.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Capabilities;
use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\Payload_Contract;
use Sakura\ImageOptimizer\Admin\Shell;
use Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Shared boot document for users, queue and log.
 */
final class Files_Admin_Payload {

	/**
	 * Pages this payload serves.
	 *
	 * @var array<int, string>
	 */
	private const PAGES = array( 'files-queue', 'files-log' );

	/**
	 * Build one page document.
	 *
	 * @param string $page Page id.
	 * @return array<string,mixed>
	 */
	public function build( string $page ): array {
		$page = in_array( $page, self::PAGES, true ) ? $page : 'files-queue';
		$args = $this->page_args( $page );
		$data    = ( new Page() )->page_data( $page );
		$strings = isset( $data['strings'] ) && is_array( $data['strings'] ) ? $data['strings'] : array();

		if ( 'files-queue' === $page ) {
			$users = ( new Page() )->page_data( 'files-users' );
			$strings = array_merge( isset( $users['strings'] ) && is_array( $users['strings'] ) ? $users['strings'] : array(), $strings );
			$data['defaultQuotaMb'] = (int) ( $users['defaultQuotaMb'] ?? 0 );
		}

		$strings['refresh'] = __( '刷新', 'sakura-imgopt' );
		$strings['userColumn'] = __( '用户', 'sakura-imgopt' );
		$strings['usageColumn'] = __( '容量使用', 'sakura-imgopt' );
		$strings['filesColumn'] = __( '文件', 'sakura-imgopt' );
		$strings['quotaColumn'] = __( '单独容量（MB）', 'sakura-imgopt' );
		$strings['sanctionColumn'] = __( '处罚', 'sakura-imgopt' );
		$strings['roleColumn'] = __( '角色', 'sakura-imgopt' );
		$strings['capsLimited'] = __( '仅本人附件', 'sakura-imgopt' );
		$strings['pickTitle'] = __( '选择已批准的申请', 'sakura-imgopt' );
		$strings['pickHint'] = __( '批准只代表可以进入执行；勾选后才算进入下一步。', 'sakura-imgopt' );
		$strings['previewStepTitle'] = __( '核对删除范围', 'sakura-imgopt' );
		$strings['previewStepHint'] = __( '预览会生成范围哈希；下一步确认时会再比对一次。', 'sakura-imgopt' );
		$strings['confirmStepTitle'] = __( '确认执行', 'sakura-imgopt' );
		$strings['confirmStepHint'] = __( '输入「删除」两个字，批次建立后由后台任务逐项清理。', 'sakura-imgopt' );
		$strings['phrasePlaceholder'] = __( '输入“删除”', 'sakura-imgopt' );
		$strings['deleteHint'] = __( '执行中可以停止，但已经开始的对象无法撤回。', 'sakura-imgopt' );
		$strings['saveCaps'] = __( '保存权限', 'sakura-imgopt' );
		$strings['capsNote'] = __( '「删」还需 WordPress 的 delete_post 权限；「户 / 志 / 同」需单独授予；管理员始终开启；⚠ 表示实际值与配置值不一致。', 'sakura-imgopt' );
		$strings['reasonLabel'] = __( '理由', 'sakura-imgopt' );
		$strings['searchUser'] = __( '搜索用户', 'sakura-imgopt' );
		$strings['filterLabel'] = __( '筛选当前页', 'sakura-imgopt' );
		$strings['capsDriftOn'] = __( '配置里没有，但实际已生效：可能被其它插件或过滤器授予。', 'sakura-imgopt' );
		$strings['capsDriftOff'] = __( '配置里有，但实际未生效：可能被其它插件或过滤器拒绝。', 'sakura-imgopt' );
		$strings['capsLocked'] = __( '管理员直删不受此页控制，始终开启。', 'sakura-imgopt' );
		$strings['capsFixed'] = __( '（固定开启）', 'sakura-imgopt' );
		$strings['capsFailed'] = __( '权限矩阵保存失败，请稍后重试。', 'sakura-imgopt' );
		$strings['detail'] = __( '详情', 'sakura-imgopt' );
		$strings['reviewDetail'] = __( '审核详情', 'sakura-imgopt' );
		$strings['closeDetail'] = __( '关闭详情', 'sakura-imgopt' );
		$strings['attachments'] = __( '附件', 'sakura-imgopt' );
		$strings['noAttachments'] = __( '没有可预览的附件。', 'sakura-imgopt' );
		$strings['openGallery'] = __( '查看大图', 'sakura-imgopt' );
		$strings['applicant'] = __( '申请人', 'sakura-imgopt' );
		$strings['userContext'] = __( '用户与容量', 'sakura-imgopt' );
		$strings['userContextFailed'] = __( '未取到该用户的容量信息。', 'sakura-imgopt' );
		$strings['usage'] = __( '容量使用', 'sakura-imgopt' );
		$strings['addToExecute'] = __( '加入执行', 'sakura-imgopt' );
		$strings['alreadySelected'] = __( '已在执行列表', 'sakura-imgopt' );
		$strings['capsCollapse'] = __( '权限矩阵（低频）', 'sakura-imgopt' );
		$strings['capsCollapseHint'] = __( '展开后修改谁能申请、审核、直接删除与处罚。', 'sakura-imgopt' );
		$strings['capsLegendTitle'] = __( '权限含义', 'sakura-imgopt' );
		$strings['drawerTitle'] = __( '审核详情', 'sakura-imgopt' );
		$strings['attachmentsCount'] = __( '{count} 个附件', 'sakura-imgopt' );
		$strings['attachmentsEmpty'] = __( '没有可预览的附件。', 'sakura-imgopt' );
		$strings['applicantContext'] = __( '申请人', 'sakura-imgopt' );
		$strings['applicantMissing'] = __( '未取到该用户的容量信息。', 'sakura-imgopt' );
		$strings['quota'] = __( '容量', 'sakura-imgopt' );
		$strings['used'] = __( '已用', 'sakura-imgopt' );
		$strings['reserved'] = __( '预留', 'sakura-imgopt' );
		$strings['files'] = __( '文件', 'sakura-imgopt' );
		$strings['folders'] = __( '文件夹', 'sakura-imgopt' );
		$strings['pending'] = __( '待审申请', 'sakura-imgopt' );
		$strings['addedToExecute'] = __( '已加入执行列表', 'sakura-imgopt' );
		$strings['capsSummary'] = __( '权限矩阵（低频）', 'sakura-imgopt' );
		$strings['viewUsersJump'] = __( '跳到用户容量卡', 'sakura-imgopt' );
		$strings['perPage'] = __( '每页', 'sakura-imgopt' );
		$strings['prevPage'] = __( '上一页', 'sakura-imgopt' );
		$strings['nextPage'] = __( '下一页', 'sakura-imgopt' );

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0',
			'page'    => $args,
			'head'    => UI\Page::head_data( $args ),
			'chrome'  => Shell::header_data( 'files', substr( $page, 6 ) ),
			'cards'   => $this->cards( $page ),
			'strings' => $strings,
			'rest'    => Payload_Contract::rest_for( rest_url( 'sufm/v1/' ), $page ),
			'data'    => array(
				'defaultQuotaMb' => (int) ( $data['defaultQuotaMb'] ?? 0 ),
				'queueStatuses'  => $this->queue_statuses(),
				'logReasons'     => $this->log_reasons(),
				'permissions'    => 'files-queue' === $page ? array(
					'execute'  => Capabilities::can_execute(),
					'users'    => Capabilities::can_manage_users(),
					'audit'    => Capabilities::can_view_audit(),
					'sync'     => Capabilities::can_manage_sync(),
					'sanction' => current_user_can( Capabilities::SANCTION_USERS ),
				) : array(),
			),
		);
	}

	/**
	 * Page arguments, including the initial status card.
	 *
	 * @param string $page Page id.
	 * @return array<string,mixed>
	 */
	private function page_args( string $page ): array {
		$data  = ( new Page() )->page_data( $page );
		$ledes = array(
			'files-queue' => __( '审核删除申请、查看附件并直接处罚违规用户；批准不等于删除，勾选已批准的范围并确认后才会真正执行。', 'sakura-imgopt' ),
			'files-log'   => __( '只记录动作与结果，不记录文件路径、对象键或凭证。', 'sakura-imgopt' ),
		);
		$statuses     = array(
			'files-users' => array(
				'label' => __( '容量策略', 'sakura-imgopt' ),
				'value' => __( '按用户配额', 'sakura-imgopt' ),
				'sub'   => sprintf(
					/* translators: %d: default quota in MB */
					__( '默认 %d MB', 'sakura-imgopt' ),
					(int) ( $data['defaultQuotaMb'] ?? 0 )
				),
				'tone'  => 'neutral',
			),
			'files-queue' => array(
				'label' => __( '审核队列', 'sakura-imgopt' ),
				'value' => '—',
				'unit'  => __( '份待审核申请', 'sakura-imgopt' ),
				'sub'   => __( '已批准待执行 — · 执行中 —', 'sakura-imgopt' ),
				'tone'  => 'neutral',
			),
			'files-log'   => array(
				'label' => __( '审计', 'sakura-imgopt' ),
				'value' => __( '操作记录', 'sakura-imgopt' ),
				'sub'   => __( '只记录动作与结果', 'sakura-imgopt' ),
				'tone'  => 'neutral',
			),
		);

		return array(
			'id'      => $page,
			'module'  => 'files',
			'tab'     => substr( $page, 6 ),
			'eyebrow' => 'USER FILE SPACE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
			'title'   => 'files-queue' === $page ? __( '审核与处置', 'sakura-imgopt' ) : ( 'files-log' === $page ? __( '最近操作', 'sakura-imgopt' ) : __( '文件管理', 'sakura-imgopt' ) ),
			'lede'    => $ledes[ $page ],
			'status'  => $statuses[ $page ],
			'actions' => array(
				array(
					'label'   => __( '打开媒体库', 'sakura-imgopt' ),
					'url'     => admin_url( 'upload.php' ),
					'variant' => 'ghost',
				),
				array(
					'label'   => __( '返回插件首页', 'sakura-imgopt' ),
					'url'     => admin_url( 'admin.php?page=sakura-tools' ),
					'variant' => 'quiet',
				),
			),
		);
	}

	/**
	 * Card metadata, using Layout as the single placement source.
	 *
	 * @param string $page Page id.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( string $page ): array {
		if ( 'files-log' === $page ) {
			return array(
				array(
					'id'          => 'log',
					'kicker'      => 'AUDIT',
					'title'       => __( '最近操作', 'sakura-imgopt' ),
					'description' => __( '只记录动作与结果，不记录文件路径、对象键或凭证。', 'sakura-imgopt' ),
					'placement'   => Layout::card( $page, 'log' ) ?? array( 1, 1, 12 ),
				),
			);
		}

		$cards = array(
			array(
				'id'          => 'queue',
				'kicker'      => 'REVIEW',
				'title'       => __( '删除申请队列', 'sakura-imgopt' ),
				'description' => __( '点“详情”查看附件、用户容量并直接处罚；批准只改变申请状态，驳回或撤销会立即解除对象锁。', 'sakura-imgopt' ),
				'wide'        => true,
				'placement'   => Layout::card( $page, 'queue' ) ?? array( 1, 1, 12 ),
			),
			array(
				'id'          => 'execute',
				'kicker'      => 'EXECUTE',
				'title'       => __( '待执行删除', 'sakura-imgopt' ),
				'description' => __( '只能选择已批准的申请；确认前会再次核对范围哈希。', 'sakura-imgopt' ),
				'placement'   => Layout::card( $page, 'execute' ) ?? array( 2, 1, 12 ),
			),
			array(
				'id'          => 'users',
				'kicker'      => 'QUOTA',
				'title'       => __( '用户容量', 'sakura-imgopt' ),
				'description' => __( '留空表示使用默认容量；已用容量包含文件空间上传与已纳入账本的媒体库文件。', 'sakura-imgopt' ),
				'placement'   => Layout::card( $page, 'users' ) ?? array( 3, 1, 12 ),
			),
			array(
				'id'          => 'capability-matrix',
				'kicker'      => 'ACCESS',
				'title'       => __( '权限设置', 'sakura-imgopt' ),
				'description' => __( '决定谁能直接删除、谁只能提交申请。默认折叠，只改本插件的能力。', 'sakura-imgopt' ),
				'placement'   => Layout::card( $page, 'capability-matrix' ) ?? array( 4, 1, 12 ),
				'caps'        => $this->caps_matrix(),
			),
		);

		$hidden = array();

		if ( ! current_user_can( 'manage_options' ) ) {
			$hidden[] = 'capability-matrix';
		}

		// The users card reads the whole-site user list and edits quotas, so a
		// reviewer without MANAGE_USERS must not see a card whose first request
		// would be rejected. Same rule for the matrix above: hide, do not half-
		// render a control the server will deny.
		if ( ! current_user_can( Capabilities::MANAGE_USERS ) ) {
			$hidden[] = 'users';
		}

		if ( $hidden ) {
			$cards = array_values(
				array_filter(
					$cards,
					static function ( $card ) use ( $hidden ) {
						return ! in_array( (string) $card['id'], $hidden, true );
					}
				)
			);
		}

		return $cards;
	}

	/**
	 * Status filter options for the queue.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function queue_statuses(): array {
		return array(
			array( 'value' => '', 'label' => __( '全部状态', 'sakura-imgopt' ) ),
			array( 'value' => 'pending_review', 'label' => __( '待审核', 'sakura-imgopt' ) ),
			array( 'value' => 'approved', 'label' => __( '已批准', 'sakura-imgopt' ) ),
			array( 'value' => 'needs_review', 'label' => __( '需进一步核实', 'sakura-imgopt' ) ),
			array( 'value' => 'completed', 'label' => __( '已完成', 'sakura-imgopt' ) ),
			array( 'value' => 'rejected', 'label' => __( '已驳回', 'sakura-imgopt' ) ),
		);
	}

	/**
	 * Closed reason list for the audit filter.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function log_reasons(): array {
		return array(
			array( 'value' => '', 'label' => __( '全部理由', 'sakura-imgopt' ) ),
			array( 'value' => 'operator', 'label' => __( '管理员直删', 'sakura-imgopt' ) ),
			array( 'value' => 'moderation', 'label' => __( '违规下架', 'sakura-imgopt' ) ),
			array( 'value' => 'request', 'label' => __( '用户申请', 'sakura-imgopt' ) ),
			array( 'value' => 'cleanup', 'label' => __( '清理', 'sakura-imgopt' ) ),
		);
	}

	/**
	 * Capability matrix plus the native admin-post form markers.
	 *
	 * @return array<string,mixed>
	 */
	public function caps_matrix(): array {
		$caps  = Capability_Matrix::caps();
		$roles = Capability_Matrix::roles();
		$out   = array();

		foreach ( $roles as $role ) {
			$cells = array();

			foreach ( $caps as $cap => $meta ) {
				$cells[] = array(
					'key'      => (string) $cap,
					'locked'   => Capability_Matrix::locked( $role, $cap ),
					'stored'   => Capability_Matrix::configured( $role, $cap ),
					'actual'   => Capability_Matrix::effective( $role, $cap ),
					'limited'  => Capability_Matrix::limited( $role ) && Capabilities::DELETE_FILES === $cap,
				);
			}

			$out[] = array(
				'role'    => (string) $role,
				'english' => ucwords( (string) preg_replace( '/[_-]+/', ' ', (string) $role ) ),
				'label'   => Capability_Matrix::role_label( (string) $role ),
				'limited' => Capability_Matrix::limited( (string) $role ),
				'cells'   => $cells,
			);
		}

		$columns = array();
		foreach ( $caps as $cap => $meta ) {
			$columns[] = array(
				'key'   => (string) $cap,
				'abbr'  => (string) $meta['abbr'],
				'label' => (string) $meta['label'],
				'hint'  => (string) $meta['hint'],
			);
		}

		return array(
			'columns' => $columns,
			'roles'   => $out,
			'legend'  => implode( ' · ', array_map(
				static function ( array $meta ): string {
					return $meta['abbr'] . ' = ' . $meta['label'];
				},
				$columns
			) ),
		);
	}

}
