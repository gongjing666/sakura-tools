<?php
declare( strict_types=1 );
namespace {
	if ( ! function_exists( 'maybe_unserialize' ) ) { function maybe_unserialize( $value ) { if ( ! is_string( $value ) ) { return $value; } $decoded = @unserialize( $value, array( 'allowed_classes' => false ) ); return false !== $decoded || 'b:0;' === $value ? $decoded : $value; } }
	if ( ! function_exists( 'path_is_absolute' ) ) { function path_is_absolute( $path ) { return str_starts_with( $path, '/' ) || (bool) preg_match( '/^[A-Za-z]:[\\\\\/]/', $path ); } }
}
namespace Sakura\ImageOptimizer\Tests {
	use Nboke\UserFiles\Services\Media_Mover;
	use Nboke\UserFiles\Services\Adoption_Manifest;
	use PHPUnit\Framework\TestCase;
	require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Media_Mover.php';
	require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Adoption_Manifest.php';

	final class UserFilesMediaMoveTest extends TestCase {
		private string $dir;
		private $previous_db;
		protected function setUp(): void {
			$this->previous_db = $GLOBALS['wpdb'] ?? null;
			$this->dir = sys_get_temp_dir() . '/sufm-adoption-' . bin2hex( random_bytes( 8 ) );
			mkdir( $this->dir );
			$GLOBALS['sakura_test_upload_dir'] = $this->dir;
			$GLOBALS['wpdb'] = new class {
				public string $postmeta = 'test_postmeta'; public string $last_error = ''; public array $meta = array();
				public function prepare( $sql, ...$args ) { return $sql; }
				public function get_results( $sql ) { return array_map( static fn( $k, $v ) => (object) array( 'meta_key' => $k, 'meta_value' => is_array( $v ) ? serialize( $v ) : $v ), array_keys( $this->meta ), array_values( $this->meta ) ); }
			};
		}
		protected function tearDown(): void {
			foreach ( array( 'original.txt', 'thumb.txt', 'link.txt' ) as $file ) { if ( file_exists( $this->dir . '/' . $file ) || is_link( $this->dir . '/' . $file ) ) { unlink( $this->dir . '/' . $file ); } }
			rmdir( $this->dir );
			unset( $GLOBALS['sakura_test_upload_dir'] );
			$GLOBALS['wpdb'] = $this->previous_db;
		}
		public function test_batch_parser_rejects_mixed_invalid_ids_instead_of_silently_moving_subset(): void {
			foreach ( array( array( 1, 'oops' ), array( 1, 0 ), array( 1, -2 ), array( 1, array( 2 ) ), array( 1, true ), array( 1, 1.5 ), array(), range( 1, 101 ), '1,2' ) as $input ) { $this->assertInstanceOf( \WP_Error::class, Media_Mover::ids( $input ) ); }
			$this->assertSame( array( 1, 7, 10 ), Media_Mover::ids( array( '10', 1, 7, '1' ) ) );
		}
		public function test_manifest_counts_deduplicated_real_artifacts_and_hashes_actual_bytes(): void {
			file_put_contents( $this->dir . '/original.txt', 'hello' ); file_put_contents( $this->dir . '/thumb.txt', 'xy' );
			$GLOBALS['wpdb']->meta = array( '_wp_attached_file' => 'original.txt', '_wp_attachment_metadata' => array( 'file' => 'original.txt', 'sizes' => array( 'a' => array( 'file' => 'thumb.txt' ), 'b' => array( 'file' => 'thumb.txt' ) ) ) );
			$manifest = ( new Adoption_Manifest() )->build( 1 );
			$this->assertIsArray( $manifest ); $this->assertCount( 2, $manifest );
			$this->assertSame( 7, array_sum( array_column( $manifest, 'logical_bytes' ) ) );
			$this->assertContains( hash( 'sha256', 'hello' ), array_column( $manifest, 'checksum' ) );
		}
		public function test_missing_derived_file_refuses_adoption_without_changing_original(): void {
			file_put_contents( $this->dir . '/original.txt', 'hello' );
			$GLOBALS['wpdb']->meta = array( '_wp_attached_file' => 'original.txt', '_wp_attachment_metadata' => array( 'sizes' => array( 'a' => array( 'file' => 'missing.txt' ) ) ) );
			$this->assertInstanceOf( \WP_Error::class, ( new Adoption_Manifest() )->build( 1 ) );
			$this->assertSame( 'hello', file_get_contents( $this->dir . '/original.txt' ) );
		}
		public function test_foreign_meta_and_offload_markers_never_veto_adoption(): void {
			file_put_contents( $this->dir . '/original.txt', 'hello' );
			// A foreign key (optimiser, offloader, view counter) never describes a new
			// file, so it must not block first adoption — that silently disabled
			// adoption on every site running one of those plugins.
			$GLOBALS['wpdb']->meta = array( '_wp_attached_file' => 'original.txt', '_sio_s3_objects' => array(), 'sakura_imgopt_log' => array( 'ok' => 1 ), 'views' => 12 );
			$manifest = ( new Adoption_Manifest() )->build( 1 );
			$this->assertIsArray( $manifest ); $this->assertCount( 1, $manifest ); $this->assertSame( 5, $manifest[0]['logical_bytes'] );
		}
		public function test_symlinked_attachment_still_refuses_adoption(): void {
			file_put_contents( $this->dir . '/original.txt', 'hello' );
			symlink( $this->dir . '/original.txt', $this->dir . '/link.txt' );
			$GLOBALS['wpdb']->meta = array( '_wp_attached_file' => 'link.txt' );
			$this->assertInstanceOf( \WP_Error::class, ( new Adoption_Manifest() )->build( 1 ) );
		}
	}
}
