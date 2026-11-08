<?php
/**
 * Short-lived, signed token for the fast search endpoint.
 *
 * The sync table page normally queries through admin-ajax.php, which boots the
 * whole admin stack (~150 ms on this site). The fast endpoint boots WordPress
 * with SHORTINIT (~2 ms) and therefore cannot rely on nonces or capability
 * checks in the usual way. A short-lived HMAC token binds the request to one
 * user and one profile; the endpoint additionally checks the administrator
 * role before serving data.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

/**
 * Signed search token helper.
 */
final class Search_Token {

	/**
	 * Token format version.
	 */
	public const VERSION = 'v1';

	/**
	 * Default lifetime in seconds.
	 */
	public const TTL = 600;

	/**
	 * Signing secret derived from the site salts.
	 *
	 * The fast endpoint derives the same value without loading the plugin, so
	 * this must stay in sync with fast-search.php.
	 */
	public static function secret(): string {
		$auth  = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$salt  = defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '';
		return hash( 'sha256', 'sakura-sync-search|' . $auth . '|' . $salt );
	}

	/**
	 * Signature over user, expiry and profile.
	 *
	 * @param int    $user_id    User id.
	 * @param int    $expires    Unix expiry.
	 * @param string $profile_id Active profile.
	 */
	public static function sign( int $user_id, int $expires, string $profile_id ): string {
		return hash_hmac( 'sha256', self::VERSION . '|' . $user_id . '|' . $expires . '|' . $profile_id, self::secret() );
	}

	/**
	 * Build the token parts passed to the browser.
	 *
	 * @param int    $user_id    User id.
	 * @param string $profile_id Active profile.
	 * @param int    $ttl        Lifetime in seconds (clamped to 60–3600).
	 * @return array{u:int,e:int,p:string,s:string}
	 */
	public static function create( int $user_id, string $profile_id, int $ttl = self::TTL ): array {
		$ttl     = max( 60, min( 3600, $ttl ) );
		$expires = time() + $ttl;
		return array(
			'u' => $user_id,
			'e' => $expires,
			'p' => $profile_id,
			's' => self::sign( $user_id, $expires, $profile_id ),
		);
	}

	/**
	 * Verify token parts (used by tests and defensive callers).
	 *
	 * @param int    $user_id    User id.
	 * @param int    $expires    Unix expiry.
	 * @param string $profile_id Profile.
	 * @param string $signature  Hex signature.
	 */
	public static function verify( int $user_id, int $expires, string $profile_id, string $signature ): bool {
		if ( $user_id <= 0 || $expires < time() || '' === trim( $profile_id ) ) {
			return false;
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}
		return hash_equals( self::sign( $user_id, $expires, $profile_id ), $signature );
	}
}
