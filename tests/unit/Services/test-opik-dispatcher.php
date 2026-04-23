<?php
declare(strict_types=1);

/**
 * Unit tests for PRAutoBlogger_Opik_Dispatcher.
 *
 * @see includes/services/opik/class-opik-dispatcher.php
 */
namespace PRAutoBlogger\Tests\Services;

use PRAutoBlogger\Tests\BaseTestCase;
use Brain\Monkey\Functions;

/**
 * @group opik
 * @group services
 */
class TestOpikDispatcher extends BaseTestCase {

	private array $option_store = array();

	protected function setUp(): void {
		parent::setUp();

		// Mock WP options.
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
	 * Test dispatcher skips if Opik is not enabled.
	 */
	public function test_dispatch_skips_if_disabled(): void {
		$client = $this->create_mock_client();
		$queue  = $this->create_mock_queue();

		// Queue has items but feature is disabled.
		$this->option_store['prautoblogger_opik_enabled'] = false;

		$dispatcher = new \PRAutoBlogger_Opik_Dispatcher( $client, $queue );
		$dispatcher->dispatch();

		// Client should not have been called.
		$this->assertTrue( true );
	}

	/**
	 * Test dispatch() batches and posts spans.
	 */
	public function test_dispatch_posts_spans(): void {
		// Enable Opik and set credentials.
		$this->option_store['prautoblogger_opik_enabled'] = true;

		// Mock constants.
		if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) ) {
			define( 'PRAUTOBLOGGER_OPIK_API_KEY', 'test-key' );
		}
		if ( ! defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) ) {
			define( 'PRAUTOBLOGGER_OPIK_WORKSPACE', 'test-workspace' );
		}

		$posted_spans = null;

		$client = $this->getMockBuilder( \PRAutoBlogger_Opik_Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'batch_create_spans', 'batch_create_traces' ) )
			->getMock();

		$client->expects( $this->once() )
			->method( 'batch_create_spans' )
			->willReturnCallback( function( $spans ) use ( &$posted_spans ) {
				$posted_spans = $spans;
				return array( 'inserted' => count( $spans ) );
			} );

		$queue = $this->getMockBuilder( \PRAutoBlogger_Opik_Span_Queue::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'dequeue', 'get_queue_depth' ) )
			->getMock();

		$queue->expects( $this->once() )
			->method( 'dequeue' )
			->with( 100 )
			->willReturn(
				array(
					array(
						'type'     => 'span',
						'payload'  => array( 'id' => 'span-1', 'name' => 'test' ),
						'attempt'  => 0,
						'enqueued' => time(),
					),
				)
			);

		$queue->method( 'get_queue_depth' )->willReturn( 0 );

		$dispatcher = new \PRAutoBlogger_Opik_Dispatcher( $client, $queue );
		$dispatcher->dispatch();

		$this->assertNotNull( $posted_spans );
		$this->assertCount( 1, $posted_spans );
	}

	/**
	 * Test dispatcher handles empty queue gracefully.
	 */
	public function test_dispatch_handles_empty_queue(): void {
		$this->option_store['prautoblogger_opik_enabled'] = true;

		if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) ) {
			define( 'PRAUTOBLOGGER_OPIK_API_KEY', 'test-key' );
		}
		if ( ! defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) ) {
			define( 'PRAUTOBLOGGER_OPIK_WORKSPACE', 'test-workspace' );
		}

		$client = $this->create_mock_client();
		$queue  = $this->create_mock_queue( array() );

		$dispatcher = new \PRAutoBlogger_Opik_Dispatcher( $client, $queue );
		$dispatcher->dispatch();

		// Should not error.
		$this->assertTrue( true );
	}

	/**
	 * Test dispatcher separates spans and traces.
	 */
	public function test_dispatch_separates_types(): void {
		$this->option_store['prautoblogger_opik_enabled'] = true;

		if ( ! defined( 'PRAUTOBLOGGER_OPIK_API_KEY' ) ) {
			define( 'PRAUTOBLOGGER_OPIK_API_KEY', 'test-key' );
		}
		if ( ! defined( 'PRAUTOBLOGGER_OPIK_WORKSPACE' ) ) {
			define( 'PRAUTOBLOGGER_OPIK_WORKSPACE', 'test-workspace' );
		}

		$posted_traces = null;
		$posted_spans  = null;

		$client = $this->getMockBuilder( \PRAutoBlogger_Opik_Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'batch_create_traces', 'batch_create_spans' ) )
			->getMock();

		$client->method( 'batch_create_traces' )->willReturnCallback(
			function( $traces ) use ( &$posted_traces ) {
				$posted_traces = $traces;
				return array( 'inserted' => count( $traces ) );
			}
		);

		$client->method( 'batch_create_spans' )->willReturnCallback(
			function( $spans ) use ( &$posted_spans ) {
				$posted_spans = $spans;
				return array( 'inserted' => count( $spans ) );
			}
		);

		$queue = $this->getMockBuilder( \PRAutoBlogger_Opik_Span_Queue::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'dequeue', 'get_queue_depth' ) )
			->getMock();

		$queue->method( 'dequeue' )->willReturn(
			array(
				array(
					'type'     => 'trace',
					'payload'  => array( 'id' => 'trace-1' ),
					'attempt'  => 0,
					'enqueued' => time(),
				),
				array(
					'type'     => 'span',
					'payload'  => array( 'id' => 'span-1' ),
					'attempt'  => 0,
					'enqueued' => time(),
				),
			)
		);

		$queue->method( 'get_queue_depth' )->willReturn( 0 );

		$dispatcher = new \PRAutoBlogger_Opik_Dispatcher( $client, $queue );
		$dispatcher->dispatch();

		$this->assertNotNull( $posted_traces );
		$this->assertCount( 1, $posted_traces );
		$this->assertNotNull( $posted_spans );
		$this->assertCount( 1, $posted_spans );
	}

	/**
	 * Helper to create a mock client.
	 */
	private function create_mock_client() {
		$client = $this->getMockBuilder( \PRAutoBlogger_Opik_Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'batch_create_spans', 'batch_create_traces' ) )
			->getMock();

		$client->method( 'batch_create_spans' )->willReturn( array() );
		$client->method( 'batch_create_traces' )->willReturn( array() );

		return $client;
	}

	/**
	 * Helper to create a mock queue.
	 */
	private function create_mock_queue( array $items = array() ) {
		$queue = $this->getMockBuilder( \PRAutoBlogger_Opik_Span_Queue::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'dequeue', 'get_queue_depth' ) )
			->getMock();

		$queue->method( 'dequeue' )->willReturn( $items );
		$queue->method( 'get_queue_depth' )->willReturn( count( $items ) );

		return $queue;
	}
}
