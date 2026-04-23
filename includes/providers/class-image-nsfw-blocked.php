<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Exception raised when an image provider rejects a prompt as NSFW.
 *
 * Image providers (Runware, OpenRouter) return provider-specific error
 * shapes when their content filters block a prompt. We catch those shapes
 * in each provider and raise this typed exception so the image pipeline
 * can distinguish a content-filter rejection from a generic 4xx and
 * retry once with a sanitized fallback prompt.
 *
 * Triggered by: image providers on NSFW-filter rejection.
 * Dependencies: None.
 *
 * @see providers/class-runware-image-provider.php     — May raise this.
 * @see providers/class-open-router-image-provider.php — May raise this.
 * @see core/class-image-nsfw-retry.php                — Catches + rebuilds prompt.
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
