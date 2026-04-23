<?php
declare(strict_types=1);

/**
 * Unit tests for PRAutoBlogger_Opik_Span_Queue.
 *
 * @see includes/services/opik/class-opik-span-queue.php
 */
namespace PRAutoBlogger\Tests\Services;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

/**
 * @group opik
 * @group services
 */
class TestOpikSpanQueue extends BaseTestCase {

	private array $option_store = array();

	protected function setUp(): void {
		parent::setUp();

		// Mock WP options functions.
		Functions\when( 'get_option' )->alias(
			function( $option, $default = false ) {
				return $this->option_store[ $option ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			function( $option, $value ) {
				$this->option_store[ $option ] = $value;
				return true;
			}
		);

		Functions\when( 'delete_option' )->alias(
			function( $option ) {
				unset( $this->option_store[ $option ] );
				return true;
			}
		);
	}

	/**
	 * Test enqueue() adds item to queue.
	 */
	public function test_enqueue_adds_item(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$payload = array(
			'id'       => 'span-1',
			'name'     => 'test',
			'trace_id' => 'trace-1',
		);

		$success = $queue->enqueue( $payload, 'span' );

		$this->assertTrue( $success );
		$this->assertEquals( 1, $queue->get_queue_depth() );
	}

	/**
	 * Test enqueue() respects max depth limit.
	 */
	public function test_enqueue_respects_max_depth(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		// Fill queue to max.
		for ( $i = 0; $i < 1000; ++$i ) {
			$success = $queue->enqueue(
				array( 'id' => "span-$i", 'name' => 'test' ),
				'span'
			);
			if ( ! $success ) {
				// Queue full.
				$this->assertEquals( 1000, $queue->get_queue_depth() );
				return;
			}
		}

		// If we got here, queue filled successfully.
		$this->assertEquals( 1000, $queue->get_queue_depth() );

		// Try to add one more — should fail.
		$success = $queue->enqueue(
			array( 'id' => 'overflow', 'name' => 'test' ),
			'span'
		);
		$this->assertFalse( $success );
	}

	/**
	 * Test dequeue() returns items in order.
	 */
	public function test_dequeue_returns_items(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$queue->enqueue( array( 'id' => 'span-1' ), 'span' );
		$queue->enqueue( array( 'id' => 'span-2' ), 'span' );
		$queue->enqueue( array( 'id' => 'span-3' ), 'span' );

		$batch = $queue->dequeue( 10 );

		$this->assertCount( 3, $batch );
		$this->assertEquals( 'span-1', $batch[0]['payload']['id'] );
		$this->assertEquals( 'span-2', $batch[1]['payload']['id'] );
		$this->assertEquals( 'span-3', $batch[2]['payload']['id'] );
	}

	/**
	 * Test dequeue() respects limit parameter.
	 */
	public function test_dequeue_respects_limit(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		for ( $i = 1; $i <= 50; ++$i ) {
			$queue->enqueue( array( 'id' => "span-$i" ), 'span' );
		}

		$batch = $queue->dequeue( 10 );

		$this->assertCount( 10, $batch );
		$this->assertEquals( 40, $queue->get_queue_depth() );
	}

	/**
	 * Test reenqueue() increments attempt counter.
	 */
	public function test_reenqueue_increments_attempt(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$items = array(
			array(
				'type'     => 'span',
				'payload'  => array( 'id' => 'span-1' ),
				'attempt'  => 0,
				'enqueued' => time(),
			),
		);

		$queue->reenqueue( $items );

		$batch = $queue->dequeue( 10 );
		$this->assertEquals( 1, $batch[0]['attempt'] );
	}

	/**
	 * Test reenqueue() drops items after max retries.
	 */
	public function test_reenqueue_drops_after_max_attempts(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$items = array(
			array(
				'type'     => 'span',
				'payload'  => array( 'id' => 'span-1' ),
				'attempt'  => 3,  // Already at max.
				'enqueued' => time(),
			),
		);

		$queue->reenqueue( $items );

		// Item should be dropped, queue should be empty.
		$this->assertEquals( 0, $queue->get_queue_depth() );
	}

	/**
	 * Test dequeue() removes expired items (TTL > 12 hours).
	 */
	public function test_dequeue_removes_expired_items(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$now = time();

		// Add a fresh item.
		$fresh = array(
			'type'     => 'span',
			'payload'  => array( 'id' => 'fresh' ),
			'enqueued' => $now,
		);

		// Add an expired item (13 hours old).
		$expired = array(
			'type'     => 'span',
			'payload'  => array( 'id' => 'expired' ),
			'enqueued' => $now - 46800,  // 13 hours in seconds.
		);

		$this->option_store['prautoblogger_opik_queue'] = array( $fresh, $expired );

		$batch = $queue->dequeue( 10 );

		// Only fresh item should be returned.
		$this->assertCount( 1, $batch );
		$this->assertEquals( 'fresh', $batch[0]['payload']['id'] );
	}

	/**
	 * Test get_queue_depth() returns item count.
	 */
	public function test_get_queue_depth(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$this->assertEquals( 0, $queue->get_queue_depth() );

		$queue->enqueue( array( 'id' => 'span-1' ), 'span' );
		$this->assertEquals( 1, $queue->get_queue_depth() );

		$queue->enqueue( array( 'id' => 'span-2' ), 'span' );
		$this->assertEquals( 2, $queue->get_queue_depth() );
	}

	/**
	 * Test clear() empties the queue.
	 */
	public function test_clear_empties_queue(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$queue->enqueue( array( 'id' => 'span-1' ), 'span' );
		$queue->enqueue( array( 'id' => 'span-2' ), 'span' );

		$this->assertEquals( 2, $queue->get_queue_depth() );

		$queue->clear();

		$this->assertEquals( 0, $queue->get_queue_depth() );
	}

	/**
	 * Test enqueue() with both span and trace types.
	 */
	public function test_enqueue_handles_trace_type(): void {
		$queue = new \PRAutoBlogger_Opik_Span_Queue();

		$queue->enqueue( array( 'id' => 'trace-1' ), 'trace' );
		$queue->enqueue( array( 'id' => 'span-1' ), 'span' );

		$batch = $queue->dequeue( 10 );

		$this->assertCount( 2, $batch );
		$this->assertEquals( 'trace', $batch[0]['type'] );
		$this->assertEquals( 'span', $batch[1]['type'] );
	}
}
