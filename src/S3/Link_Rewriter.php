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
	 * Attachment URL path prefix; null keeps the historical host-only mode.
	 *
	 * @var string|null
	 */
	private ?string $path_prefix = null;

	/**
	 * Constructor.
	 *
	 * @param string      $from        Old host or URL.
	 * @param string      $to          New host or URL.
	 * @param string|null $path_prefix Attachment URL path prefix, or null for host-only mode.
	 * @throws \InvalidArgumentException When either host is missing, malformed or identical.
	 */
	public function __construct( string $from, string $to, ?string $path_prefix = null ) {
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

		if ( null !== $path_prefix ) {
			$this->path_prefix = self::normalize_path_prefix( $path_prefix );
		}
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
	 * Normalized attachment path prefix, or null for the host-only mode.
	 */
	public function path_prefix(): ?string {
		return $this->path_prefix;
	}

	/**
	 * Whether this rewriter restricts matches to one attachment URL prefix.
	 */
	public function is_scoped(): bool {
		return null !== $this->path_prefix;
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
	 * With a path prefix this is URL-scoped: only attachment URLs are changed.
	 * Without one it keeps the historical host-only behaviour.
	 *
	 * @param string $value Plain text.
	 * @return string
	 */
	public function rewrite_string( string $value ): string {
		if ( null === $this->path_prefix ) {
			return $this->rewrite_legacy_string( $value );
		}

		return $this->rewrite_scoped_string( $value );
	}

	/**
	 * Whether a value contains at least one attachment URL on the old host.
	 *
	 * Serialized payloads are scanned as text: their nested strings still
	 * contain the same URL characters, and the rewrite walk handles them one by
	 * one. JSON values with escaped slashes are normalized for the path check.
	 *
	 * @param string $value Raw value.
	 */
	public function has_attachment_url( string $value ): bool {
		if ( null === $this->path_prefix ) {
			return $this->contains_host( $value );
		}

		foreach ( $this->scoped_patterns() as $pattern ) {
			if ( ! preg_match_all( $pattern, $value, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				if ( $this->path_matches( $this->normalize_rest( (string) ( $match['rest'] ?? '' ) ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Normalize an object-key or public URL path prefix.
	 *
	 * A full URL is accepted for callers that only have upload_url_path; its
	 * path component is used. The returned value has no leading or trailing
	 * slash, and "." (the root marker) becomes an empty prefix.
	 *
	 * @param string $prefix Raw prefix.
	 */
	public static function normalize_path_prefix( string $prefix ): string {
		$prefix = trim( $prefix );

		if ( '' === $prefix || '.' === $prefix ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $prefix ) ) {
			$parsed = parse_url( $prefix );
			$prefix = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		}

		$prefix = str_replace( '\\', '/', $prefix );
		$prefix = (string) preg_replace( '#/+#', '/', $prefix );

		return trim( $prefix, '/' );
	}

	/**
	 * Historical host-only replacement, kept byte-for-byte for old callers.
	 *
	 * @param string $value Plain text.
	 */
	private function rewrite_legacy_string( string $value ): string {
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
	 * URL-scoped replacement: only URLs under the configured path prefix.
	 *
	 * Two passes are intentional. The first consumes scheme-qualified URLs so
	 * the bare-host pass cannot see a host that sits after "//"; the second
	 * handles bare hosts such as "bf.example.com/wp-content/uploads/a.jpg"
	 * that appear in options and serialized strings.
	 *
	 * @param string $value Plain text.
	 */
	private function rewrite_scoped_string( string $value ): string {
		$patterns = $this->scoped_patterns();

		$value = (string) preg_replace_callback(
			$patterns[0],
			fn( array $parts ): string => $this->rewrite_full_url( $parts ),
			$value
		);

		return (string) preg_replace_callback(
			$patterns[1],
			fn( array $parts ): string => $this->rewrite_bare_host( $parts ),
			$value
		);
	}

	/**
	 * Regexes for scheme-qualified and bare-host attachment URLs.
	 *
	 * The slash pairs accept an optional backslash so JSON values written as
	 * "https:\/\/host\/path" are found without a separate decoder. The rest of
	 * the URL is captured raw because the replacement must preserve escaping.
	 *
	 * @return array{0:string,1:string}
	 */
	private function scoped_patterns(): array {
		$host = preg_quote( $this->from, '#' );
		$tail = '(?![a-z0-9\-]|\.[a-z0-9])';
		$rest = '(?<rest>[^\s"\'<>]*)';

		return array(
			'#(?<prefix>(?:https?:)?(?:\\\\?/){2})' . $host . $tail . $rest . '#i',
			'#(?<![a-z0-9.:/\-])' . $host . $tail . $rest . '#i',
		);
	}

	/**
	 * Rewrite one scheme-qualified URL when its path is in scope.
	 *
	 * @param array<string,string> $parts Regex match parts.
	 */
	private function rewrite_full_url( array $parts ): string {
		$rest = $this->normalize_rest( (string) ( $parts['rest'] ?? '' ) );

		if ( ! $this->path_matches( $rest ) ) {
			return (string) ( $parts[0] ?? '' );
		}

		$prefix = (string) ( $parts['prefix'] ?? '' );
		$value  = $prefix . $this->to . (string) ( $parts['rest'] ?? '' );

		if ( 0 === stripos( $prefix, 'http:' ) ) {
			$value = (string) preg_replace( '#^http:#i', 'https:', $value, 1 );
		}

		return $value;
	}

	/**
	 * Rewrite one bare-host URL when its path is in scope.
	 *
	 * @param array<string,string> $parts Regex match parts.
	 */
	private function rewrite_bare_host( array $parts ): string {
		$rest = $this->normalize_rest( (string) ( $parts['rest'] ?? '' ) );

		if ( ! $this->path_matches( $rest ) ) {
			return (string) ( $parts[0] ?? '' );
		}

		return $this->to . (string) ( $parts['rest'] ?? '' );
	}

	/**
	 * Undo JSON slash escaping only for the path check.
	 *
	 * @param string $rest Captured URL tail.
	 */
	private function normalize_rest( string $rest ): string {
		return str_replace( '\\/', '/', $rest );
	}

	/**
	 * Whether a URL tail starts with the configured attachment directory.
	 *
	 * A trailing slash is required so "wp-content/uploads-old" and a bare
	 * directory URL never count as attachment files. An empty prefix means the
	 * site explicitly stores objects at the bucket root.
	 *
	 * @param string $rest URL tail, with escaped slashes already normalized.
	 */
	private function path_matches( string $rest ): bool {
		if ( '' === $rest || '/' !== $rest[0] ) {
			return false;
		}

		$prefix = (string) $this->path_prefix;

		if ( '' === $prefix ) {
			return true;
		}

		$needle = '/' . $prefix . '/';

		if ( 0 === strpos( $rest, $needle ) ) {
			return true;
		}

		return 0 === strpos( rawurldecode( $rest ), $needle );
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
