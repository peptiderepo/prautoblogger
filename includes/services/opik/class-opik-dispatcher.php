<?php
declare(strict_types=1);

/**
 * Opik dispatcher — cron handler for async span/trace dispatch.
 *
 * Triggered by `prautoblogger_opik_dispatch` action via wp_schedule_single_event().
 * Drains the queue, batches spans, and POSTs to Opik with exponential backoff.
 *
 * Handles retries per-batch (not per-item). Failed batches are re-enqueued
 * for the next cron tick.
 *
 * @see includes/services/opik/class-opik-client.php
 * @see includes/services/opik/class-opik-span-queue.php
 */
class PRAutoBlogger_Opik_Dispatcher {

	/**
	 * @var PRAutoBlogger_Opik_Client
	 */
	private PRAutoBlogger_Opik_Client $client;

	/**
	 * @var PRAutoBlogger_Opik_Span_Queue
	 */
	private PRAutoBlogger_Opik_Span_Queue $queue;

	/**
	 * Constructor.
	 *
	 * @param PRAutoBlogger_Opik_Client      $client Client instance.
	 * @param PRAutoBlogger_Opik_Span_Queue  $queue  Queue instance.
	 */
	public function __construct(
		PRAutoBlogger_Opik_Client $client,
		PRAutoBlogger_Opik_Span_Queue $queue
	) {
		$this->client = $client;
		$this->queue  = $queue;
	}

	/**
	 * Dispatch queued spans/traces to Opik.
	 *
	 * Called via wp_schedule_single_event(). Drains the queue in batches
	 * and POSTs to Opik. Failed batches are re-enqueued.
	 *
	 * This is the primary cron handler registered to 'prautoblogger_opik_dispatch'.
	 *
	 * @return void
	 */
	public function dispatch(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$batch = $this->queue->dequeue( 100 );
		if ( empty( $batch ) ) {
			return;
		}

		$spans  = array();
		$traces = array();

		foreach ( $batch as $item ) {
			if ( 'trace' === $item['type'] ) {
				$traces[] = $item['payload'];
			} else {
				$spans[] = $item['payload'];
			}
		}

		$failed_items = array();

		// POST traces first.
		if ( ! empty( $traces ) ) {
			$success = $this->post_traces( $traces );
			if ( ! $success ) {
				$failed_items = array_merge(
					$failed_items,
					array_filter(
						$batch,
						function ( $item ) {
							return 'trace' === $item['type'];
						}
					)
				);
			}
		}

		// Then POST spans.
		if ( ! empty( $spans ) ) {
			$success = $this->post_spans( $spans );
			if ( ! $success ) {
				$failed_items = array_merge(
					$failed_items,
					array_filter(
						$batch,
						function ( $item ) {
							return 'span' === $item['type'];
						}
					)
				);
			}
		}

		// Re-enqueue failed items with backoff.
		if ( ! empty( $failed_items ) ) {
			$this->queue->reenqueue( $failed_items );
		}

		// Log summary.
		PRAutoBlogger_Logger::instance()->info(
			sprintf(
				'Opik dispatch: %d spans, %d traces, %d failed; queue depth now %d',
				count( $spans ),
				count( $traces ),
				count( $failed_items ),
				$this->queue->get_queue_depth()
			),
			'opik'
		);
	}

	/**
	 * POST traces to Opik.
	 *
	 * @param array $traces Array of trace payloads.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function post_traces( array $traces ): bool {
		if ( count( $traces ) === 1 ) {
			// Single trace: use create_trace.
			$response = $this->client->create_trace( $traces[0] );
			return null !== $response;
		}

		// Multiple traces: use batch.
		$response = $this->client->batch_create_traces( $traces );
		return null !== $response;
	}

	/**
	 * POST spans to Opik.
	 *
	 * @param array $spans Array of span payloads.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function post_spans( array $spans ): bool {
		if ( count( $spans ) === 1 ) {
			// Single span: use create_span.
			$response = $this->client->create_span( $spans[0] );
			return null !== $response;
		}

		// Multiple spans: use batch.
		$response = $this->client->batch_create_spans( $spans );
		return null !== $response;
	}

	/**
	 * Check if Opik is enabled via feature flag and has credentials.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( ! get_option( 'prautoblogger_opik_enabled', false ) ) {
			return false;
		}

		// Credentials must be defined in wp-config.php.
		if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) ||
			! defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) ) {
			return false;
		}

		$api_key = PRAUTOBLOGGER_OPIK_API_KEY;
		$workspace = PRAUTOBLOGGER_OPIK_WORKSPACE;

		return ! empty( $api_key ) && ! empty( $workspace );
	}
}
