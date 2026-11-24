<?php
namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A save the server refused, carrying the error code the page reports back.
 *
 * Settings_Saver::apply() is shared by the admin-post handler (which turns this
 * into a ?saved=0&error=... redirect) and the REST controller (which turns it
 * into a JSON error). Throwing keeps the refusal in one place instead of
 * returning sentinel values that callers can forget to check.
 */
final class Save_Refused extends \RuntimeException {

	/** Error code, matching the keys S3_Storage_Page::notice() translates. */
	private string $reason;

	/** Extra redirect arguments, such as the fields a locked save tried to change. */
	/** @var array<string,string> */
	private array $args;

	/**
	 * @param string                $reason Error code (bucket, endpoint, locked, ...).
	 * @param array<string,string>  $args   Extra query arguments for the redirect.
	 */
	public function __construct( string $reason, array $args = array() ) {
		parent::__construct( 'save refused: ' . $reason );
		$this->reason = $reason;
		$this->args   = $args;
	}

	/** Machine-readable reason, never a user-facing sentence. */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Extra redirect arguments that belong with this refusal.
	 *
	 * @return array<string,string>
	 */
	public function args(): array {
		return $this->args;
	}
}
