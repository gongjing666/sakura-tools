<?php
/**
 * Link_Rewriter tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Link_Rewriter;

/**
 * Host rewriting, including PHP serialization repair.
 */
final class LinkRewriterTest extends TestCase {

	/**
	 * Build a rewriter between the two production hosts.
	 */
	private function rewriter(): Link_Rewriter {
		return new Link_Rewriter( 'bf.nboke.com', 'doge.nboke.com' );
	}

	/**
	 * Host normalization accepts URLs and protocol-relative input.
	 */
	public function test_normalize_host_strips_scheme_and_path(): void {
		$this->assertSame( 'bf.nboke.com', Link_Rewriter::normalize_host( 'https://BF.Nboke.com/wp-content/uploads' ) );
		$this->assertSame( 'bf.nboke.com', Link_Rewriter::normalize_host( '//bf.nboke.com/image.jpg' ) );
		$this->assertSame( 'bf.nboke.com', Link_Rewriter::normalize_host( 'bf.nboke.com/' ) );
		$this->assertSame( 'bf.nboke.com', Link_Rewriter::normalize_host( '  bf.nboke.com  ' ) );
	}

	/**
	 * Normalization refuses values that are not domain names.
	 */
	public function test_normalize_host_rejects_invalid_input(): void {
		$this->assertSame( '', Link_Rewriter::normalize_host( '' ) );
		$this->assertSame( '', Link_Rewriter::normalize_host( 'localhost' ) );
		$this->assertSame( '', Link_Rewriter::normalize_host( 'not a host' ) );
		$this->assertSame( '', Link_Rewriter::normalize_host( 'http://' ) );
		$this->assertSame( '', Link_Rewriter::normalize_host( '-bad.example.com' ) );
	}

	/**
	 * The constructor refuses identical hosts after normalization.
	 */
	public function test_constructor_rejects_identical_hosts(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Link_Rewriter( 'https://bf.nboke.com', 'bf.nboke.com' );
	}

	/**
	 * The constructor refuses unparseable hosts.
	 */
	public function test_constructor_rejects_invalid_hosts(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Link_Rewriter( 'bf.nboke.com', 'not a host' );
	}

	/**
	 * Exposes the normalized host pair.
	 */
	public function test_exposes_normalized_hosts(): void {
		$rewriter = new Link_Rewriter( 'https://BF.nboke.com/uploads', 'https://doge.nboke.com' );

		$this->assertSame( 'bf.nboke.com', $rewriter->from_host() );
		$this->assertSame( 'doge.nboke.com', $rewriter->to_host() );
	}

	/**
	 * Rewrites the three URL shapes.
	 */
	public function test_rewrites_url_shapes(): void {
		$rewriter = $this->rewriter();

		$this->assertSame(
			'<img src="https://doge.nboke.com/wp-content/uploads/a.jpg">',
			$rewriter->rewrite_string( '<img src="https://bf.nboke.com/wp-content/uploads/a.jpg">' )
		);

		$this->assertSame(
			'https://doge.nboke.com/a.jpg',
			$rewriter->rewrite_string( 'http://bf.nboke.com/a.jpg' )
		);

		$this->assertSame(
			'src="//doge.nboke.com/a.jpg"',
			$rewriter->rewrite_string( 'src="//bf.nboke.com/a.jpg"' )
		);
	}

	/**
	 * Rewrites a bare host, as stored in options such as oss_host.
	 */
	public function test_rewrites_bare_host(): void {
		$rewriter = $this->rewriter();

		$this->assertSame( 'doge.nboke.com', $rewriter->rewrite_string( 'bf.nboke.com' ) );
		$this->assertSame(
			'{"host":"doge.nboke.com","scheme":"https"}',
			$rewriter->rewrite_string( '{"host":"bf.nboke.com","scheme":"https"}' )
		);
		$this->assertSame(
			'https://doge.nboke.com:443/a.jpg',
			$rewriter->rewrite_string( 'https://bf.nboke.com:443/a.jpg' )
		);
	}

	/**
	 * Host boundaries keep look-alike domains untouched.
	 */
	public function test_keeps_lookalike_hosts(): void {
		$rewriter = $this->rewriter();

		foreach ( array(
			'https://mybf.nboke.com/a.jpg',
			'https://bf.nboke.com.cn/a.jpg',
			'https://bf.nboke.com.evil.test/a.jpg',
			'https://xbf.nboke.com/a.jpg',
			'https://bf.nboke.co/a.jpg',
		) as $value ) {
			$this->assertSame( $value, $rewriter->rewrite_string( $value ) );
		}
	}

	/**
	 * URL schemes and hosts are matched case-insensitively.
	 */
	public function test_rewrites_case_insensitive_urls(): void {
		$this->assertSame(
			'https://doge.nboke.com/A.jpg',
			$this->rewriter()->rewrite_string( 'HTTPS://BF.Nboke.COM/A.jpg' )
		);
	}

	/**
	 * Non-HTTP schemes keep their scheme and only swap the host.
	 */
	public function test_rewrites_non_http_scheme(): void {
		$this->assertSame(
			'ftp://doge.nboke.com/a.jpg',
			$this->rewriter()->rewrite_string( 'ftp://bf.nboke.com/a.jpg' )
		);
	}

	/**
	 * Values without the old host pass through unchanged.
	 */
	public function test_ignores_unrelated_values(): void {
		$rewriter = $this->rewriter();

		$this->assertSame( 'https://www.nboke.com/a.jpg', $rewriter->rewrite_string( 'https://www.nboke.com/a.jpg' ) );
		$this->assertFalse( $rewriter->contains_host( 'https://www.nboke.com/a.jpg' ) );
		$this->assertTrue( $rewriter->contains_host( 'https://bf.nboke.com/a.jpg' ) );
	}

	/**
	 * Plain text is rewritten and reported as non-serialized.
	 */
	public function test_rewrites_plain_text_value(): void {
		$result = $this->rewriter()->rewrite_value( 'see https://bf.nboke.com/a.jpg' );

		$this->assertTrue( $result['safe'] );
		$this->assertFalse( $result['serialized'] );
		$this->assertSame( 'see https://doge.nboke.com/a.jpg', $result['value'] );
	}

	/**
	 * Serialized arrays keep working after the host length changes.
	 */
	public function test_repairs_serialized_array_lengths(): void {
		$original = serialize(
			array(
				'url'  => 'https://bf.nboke.com/a.jpg',
				'host' => 'bf.nboke.com',
			)
		);

		$result = $this->rewriter()->rewrite_value( $original );

		$this->assertTrue( $result['safe'] );
		$this->assertTrue( $result['serialized'] );

		$decoded = unserialize( $result['value'] );

		$this->assertSame(
			array(
				'url'  => 'https://doge.nboke.com/a.jpg',
				'host' => 'doge.nboke.com',
			),
			$decoded
		);
	}

	/**
	 * A serialized string nested inside a serialized array is rewritten too.
	 */
	public function test_rewrites_nested_serialized_payload(): void {
		$inner    = serialize( array( 'cover_image' => 'https://bf.nboke.com/cover.jpg' ) );
		$original = serialize( array( 'nested' => $inner ) );

		$result = $this->rewriter()->rewrite_value( $original );

		$this->assertTrue( $result['safe'] );
		$this->assertTrue( $result['serialized'] );

		$outer = unserialize( $result['value'] );
		$this->assertIsArray( $outer );

		$decoded = unserialize( $outer['nested'] );
		$this->assertSame( array( 'cover_image' => 'https://doge.nboke.com/cover.jpg' ), $decoded );
	}

	/**
	 * Theme options nest deeper than four levels; the walk follows them.
	 */
	public function test_walks_deeply_nested_arrays(): void {
		$value = 'https://bf.nboke.com/deep.jpg';

		for ( $i = 0; $i < 6; $i++ ) {
			$value = array( 'row' => array( $value ) );
		}

		$result = $this->rewriter()->rewrite_value( serialize( $value ) );

		$this->assertTrue( $result['safe'] );

		$decoded = unserialize( $result['value'] );

		for ( $i = 0; $i < 6; $i++ ) {
			$decoded = $decoded['row'][0];
		}

		$this->assertSame( 'https://doge.nboke.com/deep.jpg', $decoded );
	}

	/**
	 * Serialized objects are reported as unsafe and left untouched.
	 */
	public function test_serialized_object_is_unsafe(): void {
		$object      = new \stdClass();
		$object->url = 'https://bf.nboke.com/a.jpg';
		$original    = serialize( $object );

		$result = $this->rewriter()->rewrite_value( $original );

		$this->assertFalse( $result['safe'] );
		$this->assertTrue( $result['serialized'] );
		$this->assertSame( $original, $result['value'] );
	}

	/**
	 * An object nested inside a serialized array also blocks the write.
	 */
	public function test_nested_object_is_unsafe(): void {
		$object      = new \stdClass();
		$object->url = 'https://bf.nboke.com/a.jpg';
		$original    = serialize(
			array(
				'ok'  => 'https://bf.nboke.com/b.jpg',
				'bad' => $object,
			)
		);

		$result = $this->rewriter()->rewrite_value( $original );

		$this->assertFalse( $result['safe'] );
		$this->assertSame( $original, $result['value'] );
	}

	/**
	 * Values that merely look serialized fall back to plain text.
	 */
	public function test_broken_serialized_prefix_is_treated_as_text(): void {
		$original = 'a:1:{s:3:"url";s:24:"https://bf.nboke.com/a.jpg"';

		$result = $this->rewriter()->rewrite_value( $original );

		$this->assertTrue( $result['safe'] );
		$this->assertFalse( $result['serialized'] );
		$this->assertSame( 'a:1:{s:3:"url";s:24:"https://doge.nboke.com/a.jpg"', $result['value'] );
	}

	/**
	 * The serialized boolean false survives a rewrite.
	 */
	public function test_serialized_false_is_preserved(): void {
		$result = $this->rewriter()->rewrite_value( 'b:0;' );

		$this->assertTrue( $result['safe'] );
		$this->assertTrue( $result['serialized'] );
		$this->assertSame( 'b:0;', $result['value'] );
	}

	/**
	 * looks_serialized only accepts complete payloads.
	 */
	public function test_looks_serialized(): void {
		$this->assertTrue( Link_Rewriter::looks_serialized( serialize( array( 'a' => 1 ) ) ) );
		$this->assertTrue( Link_Rewriter::looks_serialized( 'b:0;' ) );
		$this->assertTrue( Link_Rewriter::looks_serialized( 'N;' ) );
		$this->assertFalse( Link_Rewriter::looks_serialized( 'a:1:{broken' ) );
		$this->assertFalse( Link_Rewriter::looks_serialized( '{"a":1}' ) );
		$this->assertFalse( Link_Rewriter::looks_serialized( '' ) );
	}

	/**
	 * A serialized array holding an integer and a boolean round-trips unchanged.
	 */
	public function test_serialized_scalars_round_trip(): void {
		$original = serialize(
			array(
				'width'  => 100,
				'active' => true,
				'absent' => null,
			)
		);

		$result = $this->rewriter()->rewrite_value( $original );

		$this->assertTrue( $result['safe'] );
		$this->assertSame( $original, $result['value'] );
	}

	/**
	 * Scoped rewriter restricted to the uploads directory.
	 */
	private function scoped(): Link_Rewriter {
		return new Link_Rewriter( 'bf.nboke.com', 'doge.nboke.com', 'wp-content/uploads' );
	}

	/**
	 * Only attachment URLs under the prefix are rewritten.
	 */
	public function test_scoped_rewrites_only_attachment_urls(): void {
		$rewriter = $this->scoped();

		$this->assertSame(
			'<img src="https://doge.nboke.com/wp-content/uploads/a.jpg">',
			$rewriter->rewrite_string( '<img src="https://bf.nboke.com/wp-content/uploads/a.jpg">' )
		);
		$this->assertSame(
			'https://doge.nboke.com/wp-content/uploads/a.jpg?x=1&y=2',
			$rewriter->rewrite_string( 'https://bf.nboke.com/wp-content/uploads/a.jpg?x=1&y=2' )
		);
		$this->assertSame(
			'https://doge.nboke.com/wp-content/uploads/a.jpg',
			$rewriter->rewrite_string( 'http://bf.nboke.com/wp-content/uploads/a.jpg' )
		);
		$this->assertSame(
			'//doge.nboke.com/wp-content/uploads/a.jpg',
			$rewriter->rewrite_string( '//bf.nboke.com/wp-content/uploads/a.jpg' )
		);
	}

	/**
	 * Other paths, prefix lookalikes and other hosts stay untouched.
	 */
	public function test_scoped_keeps_other_paths_and_lookalikes(): void {
		$rewriter = $this->scoped();

		foreach ( array(
			'https://bf.nboke.com/other/a.jpg',
			'https://bf.nboke.com/wp-content/uploads-old/a.jpg',
			'https://bf.nboke.com/wp-content/a.jpg',
			'https://bf.nboke.com.evil.test/wp-content/uploads/a.jpg',
			'https://mybf.nboke.com/wp-content/uploads/a.jpg',
			'https://bf.nboke.com/WP-CONTENT/uploads/a.jpg',
			'https://www.nboke.com/wp-content/uploads/a.jpg',
		) as $value ) {
			$this->assertSame( $value, $rewriter->rewrite_string( $value ) );
			$this->assertFalse( $rewriter->has_attachment_url( $value ) );
		}
	}

	/**
	 * A bare host with the attachment path is still a URL in options data.
	 */
	public function test_scoped_handles_bare_host(): void {
		$this->assertSame(
			'doge.nboke.com/wp-content/uploads/a.jpg',
			$this->scoped()->rewrite_string( 'bf.nboke.com/wp-content/uploads/a.jpg' )
		);
		$this->assertSame( 'bf.nboke.com', $this->scoped()->rewrite_string( 'bf.nboke.com' ) );
	}

	/**
	 * A JSON value with escaped slashes is rewritten without losing escaping.
	 */
	public function test_scoped_handles_escaped_json_slashes(): void {
		$input = '{"url":"https:\/\/bf.nboke.com\/wp-content\/uploads\/a.jpg","api":"https:\/\/bf.nboke.com\/api\/x"}';
		$want  = '{"url":"https:\/\/doge.nboke.com\/wp-content\/uploads\/a.jpg","api":"https:\/\/bf.nboke.com\/api\/x"}';

		$this->assertSame( $want, $this->scoped()->rewrite_string( $input ) );
		$this->assertTrue( $this->scoped()->has_attachment_url( $input ) );
	}

	/**
	 * Serialized values keep their lengths and leave non-attachment strings alone.
	 */
	public function test_scoped_repairs_serialized_lengths(): void {
		$original = serialize(
			array(
				'image' => 'https://bf.nboke.com/wp-content/uploads/a.jpg',
				'api'   => 'https://bf.nboke.com/api/v1',
				'host'  => 'bf.nboke.com',
			)
		);
		$result = $this->scoped()->rewrite_value( $original );

		$this->assertTrue( $result['safe'] );
		$this->assertTrue( $result['serialized'] );

		$data = unserialize( $result['value'] );
		$this->assertSame( 'https://doge.nboke.com/wp-content/uploads/a.jpg', $data['image'] );
		$this->assertSame( 'https://bf.nboke.com/api/v1', $data['api'] );
		$this->assertSame( 'bf.nboke.com', $data['host'] );
	}

	/**
	 * A root-level bucket accepts any path but still requires one.
	 */
	public function test_scoped_root_prefix_requires_a_path(): void {
		$rewriter = new Link_Rewriter( 'bf.nboke.com', 'doge.nboke.com', '' );

		$this->assertSame( 'https://doge.nboke.com/a.jpg', $rewriter->rewrite_string( 'https://bf.nboke.com/a.jpg' ) );
		$this->assertSame( 'bf.nboke.com', $rewriter->rewrite_string( 'bf.nboke.com' ) );
	}

	/**
	 * An encoded prefix is accepted for matching, while the raw URL is preserved.
	 */
	public function test_scoped_matches_encoded_prefix(): void {
		$rewriter = $this->scoped();

		$this->assertTrue( $rewriter->has_attachment_url( 'https://bf.nboke.com/%77p-content/uploads/a.jpg' ) );
		$this->assertSame(
			'https://doge.nboke.com/%77p-content/uploads/a.jpg',
			$rewriter->rewrite_string( 'https://bf.nboke.com/%77p-content/uploads/a.jpg' )
		);
	}
}
