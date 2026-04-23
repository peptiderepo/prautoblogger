<?php
declare(strict_types=1);

/**
 * Async queue for Opik span/trace payloads.
 *
 * Backs the queue with WP options (a single serialized array stored
 * in wp_options). Payloads are enqueued at the end of article
 * generation, dequeued and batch-posted via cron.
 *
 * Max queue depth is 1000 items; older items are expired TTL-based.
 *
 * @see includes/services/opik/class-opik-dispatcher.php — Drains the queue.
 */
class PRAutoBlogger_Opik_Span_Queue {

	/**
	 * @var string WP option key storing the queue.
	 */
	const QUEUE_OPTION_KEY = 'prautoblogger_opik_queue';

	/**
	 * @var int Max queue depth (items).
	 */
	const MAX_QUEUE_DEPTH = 1000;

	/**
	 * @var int Item TTL in seconds (12 hours).
	 */
	const ITEM_TTL_SECONDS = 43200;

	/**
	 * Enqueue a span or trace for async dispatch.
	 *
	 * @param array $payload Span or trace payload.
	 * @param string $type Either 'span' or 'trace'.
	 *
	 * @return bool True if enqueued, false if queue full.
	 */
	public function enqueue( array $payload, string $type = 'span' ): bool {
		if ( ! in_array( $type, array( 'span', 'trace' ), true ) ) {
			$type = 'span';
		}

		$queue = $this->get_queue();

		if ( count( $queue ) >= self::MAX_QUEUE_DEPTH ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Opik queue full (%d items); dropping span', count( $queue ) ),
				'opik'
			);
			return false;
		}

		$item = array(
			'type'      => $type,
			'payload'   => $payload,
			'enqueued'  => time(),
			'attempt'   => 0,
		);

		$queue[] = $item;
		update_option( self::QUEUE_OPTION_KEY, $queue );

		return true;
	}

	/**
	 * Dequeue the next N items from the queue for processing.
	 *
	 * @param int $limit Max items to dequeue.
	 *
	 * @return array Array of items with 'type', 'payload', 'enqueued', 'attempt'.
	 */
	public function dequeue( int $limit = 100 ): array {
		$queue = $this->get_queue();

		if ( empty( $queue ) ) {
			return array();
		}

		// Remove expired items.
		$now   = time();
		$queue = array_filter(
			$queue,
			function ( $item ) use ( $now ) {
				return ( $now - $item['enqueued'] ) < self::ITEM_TTL_SECONDS;
			}
		);

		// Grab the first $limit items.
		$batch = array_splice( $queue, 0, $limit );

		// Save the remaining queue.
		update_option( self::QUEUE_OPTION_KEY, $queue );

		return $batch;
	}

	/**
	 * Re-enqueue items that failed to post (typically after backoff).
	 *
	 * @param array $items Items from a failed batch.
	 */
	public function reenqueue( array $items ): void {
		$queue = $this->get_queue();

		foreach ( $items as $item ) {
			$item['attempt'] = ( $item['attempt'] ?? 0 ) + 1;

			// Drop items that have exceeded max retries (3).
			if ( $item['attempt'] >= 3 ) {
				PRAutoBlogger_Logger::instance()->warning(
					sprintf(
						'Dropping Opik item after %d attempts: %s',
						$item['attempt'],
						$item['type']
					),
					'opik'
				);
				continue;
			}

			$queue[] = $item;
		}

		update_option( self::QUEUE_OPTION_KEY, $queue );
	}

	/**
	 * Get the current queue state.
	 *
	 * @return array
	 */
	public function get_queue(): array {
		$queue = get_option( self::QUEUE_OPTION_KEY, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Get queue depth (item count).
	 *
	 * @return int
	 */
	public function get_queue_depth(): int {
		return count( $this->get_queue() );
	}

	/**
	 * Clear all items from the queue.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::QUEUE_OPTION_KEY );
	}

	/**
	 * Get a human-readable status string for admin display.
	 *
	 * @return string
	 */
	public function get_status(): string {
		$depth = $this->get_queue_depth();
		if ( 0 === $depth ) {
			return 'Queue empty';
		}
		return sprintf( 'Queue: %d item%s pending', $depth, 1 !== $depth ? 's' : '' );
	}
}
