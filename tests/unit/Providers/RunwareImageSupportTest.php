<?php
/**
 * Tests for PRAutoBlogger_Runware_Image_Support.
 *
 * Covers response parsing (URL extraction), dimension snapping to FLUX
 * constraints, seed normalization, and API-key lookup.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class RunwareImageSupportTest extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();

		if ( ! class_exists( 'PRAutoBlogger_Encryption' ) ) {
			eval( '
				class PRAutoBlogger_Encryption {
					public static function is_encrypted( $v ) { return 0 === strpos( $v, "enc:" ); }
					public static function decrypt( $v ) { return "rw-test-key-123"; }
					public static function encrypt( $v ) { return "enc:" . $v; }
				}
			' );
		}
	}

	/** A well-formed imageInference entry returns its imageURL. */
	public function test_extract_image_url_valid_response(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$raw     = wp_json_encode( [
			'data' => [
				[
					'taskType' => 'imageInference',
					'imageURL' => 'https://im.runware.ai/image/abc123.png',
				],
			],
		] );
		$this->assertSame(
			'https://im.runware.ai/image/abc123.png',
			$support->extract_image_url( $raw )
		);
	}

	/** An errors[] array in the response causes a RuntimeException. */
	public function test_extract_image_url_error_response_throws(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$raw     = wp_json_encode( [
			'errors' => [
				[ 'message' => 'invalid api key' ],
			],
		] );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'invalid api key' );
		$support->extract_image_url( $raw );
	}

	/** Malformed JSON throws RuntimeException. */
	public function test_extract_image_url_invalid_json_throws(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->expectException( \RuntimeException::class );
		$support->extract_image_url( 'not json at all' );
	}

	/** Data array present but no imageInference entry throws. */
	public function test_extract_image_url_no_inference_entry_throws(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$raw     = wp_json_encode( [
			'data' => [
				[ 'taskType' => 'authentication' ],
			],
		] );
		$this->expectException( \RuntimeException::class );
		$support->extract_image_url( $raw );
	}

	/** imageInference present but empty imageURL throws. */
	public function test_extract_image_url_empty_url_throws(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$raw     = wp_json_encode( [
			'data' => [
				[ 'taskType' => 'imageInference', 'imageURL' => '' ],
			],
		] );
		$this->expectException( \RuntimeException::class );
		$support->extract_image_url( $raw );
	}

	/** snap_dimension rounds to nearest multiple of 64. */
	public function test_snap_dimension_rounds_to_64(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->assertSame( 1216, $support->snap_dimension( 1200 ) );
		$this->assertSame( 640, $support->snap_dimension( 632 ) );
		$this->assertSame( 1024, $support->snap_dimension( 1024 ) );
		$this->assertSame( 1024, $support->snap_dimension( 1050 ) );
	}

	/** snap_dimension clamps to minimum 512. */
	public function test_snap_dimension_clamps_min(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->assertSame( 512, $support->snap_dimension( 64 ) );
		$this->assertSame( 512, $support->snap_dimension( 200 ) );
	}

	/** snap_dimension clamps to maximum 2048. */
	public function test_snap_dimension_clamps_max(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->assertSame( 2048, $support->snap_dimension( 4096 ) );
		$this->assertSame( 2048, $support->snap_dimension( 2500 ) );
	}

	/** A valid positive seed passes through unchanged. */
	public function test_normalize_seed_positive_passthrough(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->assertSame( 42, $support->normalize_seed( 42 ) );
		$this->assertSame( 1234567, $support->normalize_seed( 1234567 ) );
	}

	/** Null seed derives a positive integer. */
	public function test_normalize_seed_null_derives_positive(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$seed    = $support->normalize_seed( null );
		$this->assertGreaterThan( 0, $seed );
	}

	/** Zero seed is replaced with a derived positive integer. */
	public function test_normalize_seed_zero_replaced(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$seed    = $support->normalize_seed( 0 );
		$this->assertGreaterThan( 0, $seed );
	}

	/** Negative seed is replaced with a derived positive integer. */
	public function test_normalize_seed_negative_replaced(): void {
		$support = new \PRAutoBlogger_Runware_Image_Support();
		$seed    = $support->normalize_seed( -5 );
		$this->assertGreaterThan( 0, $seed );
	}

	/** Missing API key option throws RuntimeException. */
	public function test_get_api_key_missing_throws(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			return $default;
		} );

		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->expectException( \RuntimeException::class );
		$support->get_api_key();
	}

	/** Encrypted key is decrypted on read. */
	public function test_get_api_key_decrypts_encrypted(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'prautoblogger_runware_api_key' === $key ) {
				return 'enc:stored-ciphertext';
			}
			return $default;
		} );

		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->assertSame( 'rw-test-key-123', $support->get_api_key() );
	}

	/** A plaintext key (pre-migration) passes through unchanged. */
	public function test_get_api_key_plaintext_passthrough(): void {
		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
			if ( 'prautoblogger_runware_api_key' === $key ) {
				return 'rw-plain-key';
			}
			return $default;
		} );

		$support = new \PRAutoBlogger_Runware_Image_Support();
		$this->assertSame( 'rw-plain-key', $support->get_api_key() );
	}
}
