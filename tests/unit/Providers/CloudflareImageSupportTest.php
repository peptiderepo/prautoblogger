<?php
/**
 * Tests for PRAutoBlogger_Cloudflare_Image_Support — URL building in
 * particular. The v0.8.2 gateway migration added a derivation path from
 * the existing `prautoblogger_ai_gateway_base_url` OpenRouter gateway
 * URL to the corresponding Workers AI URL at the same gateway root.
 *
 * @package PRAutoBlogger\Tests\Providers
 */

namespace PRAutoBlogger\Tests\Providers;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

class CloudflareImageSupportTest extends BaseTestCase {

	private const ACCOUNT_ID = 'a16f4baa82a1074c47f696e7cb6d5995';
	private const MODEL      = '@cf/black-forest-labs/flux-1-schnell';

	/**
	 * With no gateway URL configured, the support class returns the direct
	 * Workers AI API URL — the historical behaviour prior to v0.8.2.
	 */
	public function test_returns_direct_url_when_gateway_not_configured(): void {
		$this->stub_get_option( [
			'prautoblogger_ai_gateway_base_url'  => '',
			'prautoblogger_cf_image_via_gateway' => '1',
		] );

		$support = new \PRAutoBlogger_Cloudflare_Image_Support();
		$url     = $support->build_endpoint_url( self::ACCOUNT_ID, self::MODEL );

		$this->assertStringStartsWith( 'https://api.cloudflare.com/client/v4/accounts/', $url );
		$this->assertStringContainsString( self::ACCOUNT_ID, $url );
		$this->assertStringEndsWith( self::MODEL, $url );
	}

	/**
	 * With the AI Gateway URL configured and the toggle on, image calls
	 * route through `gateway.ai.cloudflare.com/.../workers-ai/{model}` at
	 * the same gateway root as the OpenRouter LLM path.
	 */
	public function test_returns_gateway_url_when_configured_and_toggle_on(): void {
		$this->stub_get_option( [
			'prautoblogger_ai_gateway_base_url'  => 'https://gateway.ai.cloudflare.com/v1/' . self::ACCOUNT_ID . '/peptiderepo-prod/openrouter',
			'prautoblogger_cf_image_via_gateway' => '1',
		] );

		$support = new \PRAutoBlogger_Cloudflare_Image_Support();
		$url     = $support->build_endpoint_url( self::ACCOUNT_ID, self::MODEL );

		$this->assertSame(
			'https://gateway.ai.cloudflare.com/v1/' . self::ACCOUNT_ID . '/peptiderepo-prod/workers-ai/' . self::MODEL,
			$url
		);
	}

	/**
	 * The toggle overrides the gateway URL: operators can fall back to the
	 * direct API if the gateway regresses, without clearing the OpenRouter
	 * gateway setting that the LLM provider depends on.
	 */
	public function test_toggle_off_forces_direct_url_even_with_gateway_configured(): void {
		$this->stub_get_option( [
			'prautoblogger_ai_gateway_base_url'  => 'https://gateway.ai.cloudflare.com/v1/' . self::ACCOUNT_ID . '/peptiderepo-prod/openrouter',
			'prautoblogger_cf_image_via_gateway' => '0',
		] );

		$support = new \PRAutoBlogger_Cloudflare_Image_Support();
		$url     = $support->build_endpoint_url( self::ACCOUNT_ID, self::MODEL );

		$this->assertStringStartsWith( 'https://api.cloudflare.com/client/v4/accounts/', $url );
	}

	/**
	 * A malformed gateway URL (e.g. http, or a totally different host) is
	 * rejected and the direct-API path is used instead. Defensive — a bad
	 * option value shouldn't break image generation.
	 */
	public function test_falls_back_to_direct_when_gateway_url_is_malformed(): void {
		$this->stub_get_option( [
			'prautoblogger_ai_gateway_base_url'  => 'http://evil.example.com/openrouter',
			'prautoblogger_cf_image_via_gateway' => '1',
		] );

		$support = new \PRAutoBlogger_Cloudflare_Image_Support();
		$url     = $support->build_endpoint_url( self::ACCOUNT_ID, self::MODEL );

		$this->assertStringStartsWith( 'https://api.cloudflare.com/client/v4/accounts/', $url );
	}

	/**
	 * The NSFW detector must match on the documented error code without
	 * tripping on other 4xx bodies. (Covered at the provider level too,
	 * but worth pinning at the support class so a tolerance regression
	 * fails early.)
	 */
	public function test_is_nsfw_error_matches_code_3030_only(): void {
		$support = new \PRAutoBlogger_Cloudflare_Image_Support();

		$this->assertTrue(
			$support->is_nsfw_error( '{"errors":[{"code":3030,"message":"NSFW"}]}' )
		);
		$this->assertFalse(
			$support->is_nsfw_error( '{"errors":[{"code":7003,"message":"bad token"}]}' )
		);
		$this->assertFalse(
			$support->is_nsfw_error( 'not json at all' )
		);
		$this->assertFalse(
			$support->is_nsfw_error( '{}' )
		);
	}
}
