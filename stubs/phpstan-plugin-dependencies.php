<?php
// phpcs:ignoreFile -- PHPStan-only declarations; this file is not loaded by WordPress.
/**
 * PHPStan-only declarations for runtime-loaded dependencies.
 *
 * This file is referenced only by phpstan.neon and is never loaded by WordPress.
 */
namespace {
	if ( ! defined( 'SAKURA_TOOLS_VERSION' ) ) {
		define( 'SAKURA_TOOLS_VERSION', '' );
	}
	if ( ! defined( 'SAKURA_TOOLS_PATH' ) ) {
		define( 'SAKURA_TOOLS_PATH', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'SAKURA_TOOLS_URL' ) ) {
		define( 'SAKURA_TOOLS_URL', '' );
	}
	if ( ! defined( 'SAKURA_TOOLS_INC' ) ) {
		define( 'SAKURA_TOOLS_INC', '' );
	}

	class WP_CLI {
		/**
		 * @param mixed $message
		 * @return never
		 * @phpstan-impure
		 */
		public static function error( $message ) {
			throw new \RuntimeException( (string) $message );
		}

		/** @param mixed $message @phpstan-impure */
		public static function warning( $message ): void {
			echo '';
		}

		/** @param mixed $message @phpstan-impure */
		public static function line( $message ): void {
			echo '';
		}

		/** @param mixed $message @phpstan-impure */
		public static function log( $message ): void {
			echo '';
		}

		/**
		 * @param mixed $question
		 * @param array<string,mixed> $assoc_args
		 */
		public static function confirm( $question, $assoc_args = array() ): void {
		}

		/** @param mixed $message @phpstan-impure */
		public static function success( $message ): void {
			echo '';
		}

		/**
		 * @param string $name
		 * @param callable|class-string $command
		 * @param array<string,mixed> $args
		 * @phpstan-impure
		 */
		public static function add_command( $name, $command, $args = array() ): void {
			echo '';
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string $format
	 * @param array<int,array<string,mixed>> $items
	 * @param array<int,string>|null $fields
	 */
	function format_items( $format, $items, $fields = null ): void {
	}
}

namespace Sakura\Vendor\Aws\S3 {
	class S3Client {
		/** @param array<string,mixed> $config */
		public function __construct( array $config ) {
			unset( $config );
		}

		/** @param array<string,mixed> $args @return array<string,mixed> */
		public function putObject( array $args ): array {
			return $args;
		}

		/** @param array<string,mixed> $args @return array<string,mixed> */
		public function deleteObjects( array $args ): array {
			return $args;
		}

		/** @param array<string,mixed> $args @return array<string,mixed> */
		public function headObject( array $args ): array {
			return $args;
		}

		/** @param array<string,mixed> $args @return array<string,mixed> */
		public function headBucket( array $args ): array {
			return $args;
		}

		/** @param array<string,mixed> $args @return array<string,mixed> */
		public function listObjectsV2( array $args ): array {
			return $args;
		}
	}
}

namespace Sakura\Vendor\Aws\S3\Exception {
	class S3Exception extends \RuntimeException {
		/** @return string */
		public function getAwsErrorCode() {
			return '';
		}

		/** @return int */
		public function getStatusCode() {
			return 0;
		}
	}
}
