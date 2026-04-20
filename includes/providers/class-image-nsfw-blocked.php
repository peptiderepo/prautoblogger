<?php
declare(strict_types=1);

/**
 * Exception raised when an image provider rejects a prompt as NSFW.
 *
 * Cloudflare Workers AI returns HTTP 400 with an `errors[].code === 3030`
 * body when its content filter blocks a prompt. We catch that shape in
 * the provider and raise this typed exception so the image pipeline can
 * distinguish a content-filter rejection from a generic 4xx and retry
 * once with a sanitized fallback prompt.
 *
 * Triggered by: PRAutoBlogger_Cloudflare_Image_Provider on NSFW 4xx.
 * Dependencies: None.
 *
 * @see providers/class-cloudflare-image-provider.php — Raises this.
 * @see core/class-image-nsfw-retry.php               — Catches + rebuilds prompt.
 * @see https://developers.cloudflare.com/workers-ai/models/flux-1-schnell/ — Upstream error codes.
 */
class PRAutoBlogger_Image_NSFW_Blocked extends \RuntimeException {

	/**
	 * The raw upstream response body, kept verbatim for log forensics.
	 *
	 * @var string
	 */
	private string $upstream_body;

	/**
	 * @param string          $message       Human-readable summary.
	 * @param string          $upstream_body Raw response body from the provider.
	 * @param \Throwable|null $previous      Optional previous exception.
	 */
	public function __construct( string $message, string $upstream_body = '', ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->upstream_body = $upstream_body;
	}

	/**
	 * @return string Raw upstream body (may be empty).
	 */
	public function get_upstream_body(): string {
		return $this->upstream_body;
	}
}
