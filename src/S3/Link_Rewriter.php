<?php
/**
 * Serialization-aware host rewriter.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites one attachment host to another inside plain text, serialized arrays
 * and nested serialized strings, repairing PHP serialization length prefixes.
 *
 * A URL host appears in three shapes in a WordPress database: as part of a full
 * URL, as a protocol-relative URL, or as a bare host in an option such as
 * "oss_host". The bare replacement is guarded by host boundaries so that
 * "bf.example.com.evil.test" or "xbf.example.com" are left alone.
 *
 * Serialized values are decoded with allowed_classes=false. Anything containing
 * an object is reported as unsafe instead of being rebuilt, because re-encoding
 * an incomplete class would either fatal or silently drop data.
 */
final class Link_Rewriter {

	/**
	 * How deep a decoded value is walked.
	 *
	 * Theme options legitimately nest five levels (slider rows, gallery items),
	 * so the cap is generous; it only exists to stop pathological payloads from
	 * exhausting the stack.
	 */
	public const MAX_DEPTH = 16;

	/**
	 * Old host.
	 *
	 * @var string
	 */
	private string $from;

	/**
	 * New host.
	 *
	 * @var string
	 */
	private string $to;

	/**
	 * Constructor.
	 *
	 * @param string $from Old host or URL.
	 * @param string $to   New host or URL.
	 * @throws \InvalidArgumentException When either host is missing, malformed or identical.
	 */
	public function __construct( string $from, string $to ) {
		$from = self::normalize_host( $from );
		$to   = self::normalize_host( $to );

		if ( '' === $from || '' === $to ) {
			throw new \InvalidArgumentException( 'Both hosts must be valid domain names.' );
		}

		if ( $from === $to ) {
			throw new \InvalidArgumentException( 'Source and target hosts are identical.' );
		}

		$this->from = $from;
		$this->to   = $to;
	}

	/**
	 * Old host.
	 */
	public function from_host(): string {
		return $this->from;
	}

	/**
	 * New host.
	 */
	public function to_host(): string {
		return $this->to;
	}

	/**
	 * Normalize a host, URL or protocol-relative URL to a bare lowercase host.
	 *
	 * @param string $host Raw input.
	 * @return string Empty string when the input is not a usable host.
	 */
	public static function normalize_host( string $host ): string {
		$host = trim( $host );

		if ( '' === $host ) {
			return '';
		}

		if ( false !== strpos( $host, '://' ) ) {
			$parsed = parse_url( $host );
			$host   = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		} elseif ( 0 === strpos( $host, '//' ) ) {
			$host  = substr( $host, 2 );
			$slash = strpos( $host, '/' );
			if ( false !== $slash ) {
				$host = substr( $host, 0, $slash );
			}
		}

		$slash = strpos( $host, '/' );
		if ( false !== $slash ) {
			$host = substr( $host, 0, $slash );
		}

		$host = strtolower( rtrim( $host, './' ) );

		if ( false === strpos( $host, '.' ) ) {
			return '';
		}

		if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Whether a value contains the old host at all.
	 *
	 * @param string $value Value.
	 */
	public function contains_host( string $value ): bool {
		return false !== strpos( $value, $this->from );
	}

	/**
	 * Replace the old host inside a plain string.
	 *
	 * @param string $value Plain text.
	 * @return string
	 */
	public function rewrite_string( string $value ): string {
		if ( false === stripos( $value, $this->from ) ) {
			return $value;
		}

		$host = preg_quote( $this->from, '/' );
		// The lookahead refuses another domain label, so "bf.nboke.com.cn" and
		// "bf.nboke.com.evil.test" are never treated as the old host.
		$tail = '(?![a-z0-9\-]|\.[a-z0-9])';

		// Full URLs keep their path and casing; plain http is upgraded to https
		// because the retired host usually no longer answers on port 80.
		$value = (string) preg_replace( '#https://' . $host . $tail . '#i', 'https://' . $this->to, $value );
		$value = (string) preg_replace( '#http://' . $host . $tail . '#i', 'https://' . $this->to, $value );
		$value = (string) preg_replace( '#//' . $host . $tail . '#i', '//' . $this->to, $value );

		// A bare host: not glued to another label on either side.
		$pattern = '/(?<![a-z0-9.\-])' . $host . $tail . '/i';

		$rewritten = preg_replace( $pattern, $this->to, $value );

		return is_string( $rewritten ) ? $rewritten : $value;
	}

	/**
	 * Rewrite a database value, preserving PHP serialization.
	 *
	 * @param string $value Raw column value.
	 * @return array{value:string,safe:bool,serialized:bool} Rewritten value, whether it may be written back, and whether it was serialized.
	 */
	public function rewrite_value( string $value ): array {
		if ( ! self::looks_serialized( $value ) ) {
			return array(
				'value'      => $this->rewrite_string( $value ),
				'safe'       => true,
				'serialized' => false,
			);
		}

		$trimmed = trim( $value );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed serialized value is a normal input here; the false return is checked.
		$data = @unserialize( $trimmed, array( 'allowed_classes' => false ) );

		if ( false === $data && 'b:0;' !== $trimmed ) {
			return array(
				'value'      => $this->rewrite_string( $value ),
				'safe'       => true,
				'serialized' => false,
			);
		}

		try {
			$data = $this->walk( $data, 0 );
		} catch ( \RuntimeException $exception ) {
			unset( $exception );

			return array(
				'value'      => $value,
				'safe'       => false,
				'serialized' => true,
			);
		}

		return array(
			'value'      => serialize( $data ),
			'safe'       => true,
			'serialized' => true,
		);
	}

	/**
	 * Whether a string is a complete serialized PHP value.
	 *
	 * The cheap prefix check keeps article HTML and JSON from being handed to
	 * unserialize(); the actual decode is the final word.
	 *
	 * @param string $value Raw value.
	 */
	public static function looks_serialized( string $value ): bool {
		$value = trim( $value );

		if ( '' === $value ) {
			return false;
		}

		if ( 'b:0;' === $value || 'N;' === $value ) {
			return true;
		}

		if ( ! preg_match( '/^(?:[aOsidb]:|C:)/', $value ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid payloads are expected; the false return is the signal.
		$data = @unserialize( $value, array( 'allowed_classes' => false ) );

		return false !== $data || 'b:0;' === $value;
	}

	/**
	 * Walk an unserialized value, rewriting every nested string.
	 *
	 * @param mixed $data  Unserialized value.
	 * @param int   $depth Recursion depth.
	 * @return mixed
	 * @throws \RuntimeException When the payload nests too deeply or contains an object.
	 */
	private function walk( $data, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \RuntimeException( 'Serialized payload nests too deeply.' );
		}

		if ( is_string( $data ) ) {
			if ( self::looks_serialized( $data ) ) {
				$inner = $this->rewrite_value( $data );
				if ( ! $inner['safe'] ) {
					throw new \RuntimeException( 'Nested serialized payload contains an object.' );
				}

				return $inner['value'];
			}

			return $this->rewrite_string( $data );
		}

		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $item ) {
				$out[ $key ] = $this->walk( $item, $depth + 1 );
			}

			return $out;
		}

		if ( is_object( $data ) || is_resource( $data ) ) {
			throw new \RuntimeException( 'Serialized payload contains an object.' );
		}

		return $data;
	}
}
