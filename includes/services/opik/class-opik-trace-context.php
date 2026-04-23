<?php
declare(strict_types=1);

/**
 * Per-request trace context singleton.
 *
 * Holds the current trace ID and manages the span stack for a single
 * article generation. Initialized at the top of the pipeline,
 * torn down at the end.
 *
 * Single-threaded per-request model: one trace per article generation,
 * child spans per LLM call.
 *
 * @see includes/services/opik/class-opik-client.php
 * @see includes/services/opik/class-opik-span-queue.php
 */
class PRAutoBlogger_Opik_Trace_Context {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @var string Trace ID (UUID).
	 */
	private string $trace_id = '';

	/**
	 * @var float Trace start time (microtime).
	 */
	private float $trace_start = 0.0;

	/**
	 * @var array Stack of active spans; indexed by span_id.
	 */
	private array $span_stack = array();

	/**
	 * Get or create the current request's trace context.
	 *
	 * @return self
	 */
	public static function current(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize a new trace for this request.
	 *
	 * Generates a UUID and records the start time. Called once at the
	 * top of the article generation pipeline.
	 *
	 * @return string The trace ID (UUID).
	 */
	public function init_trace(): string {
		$this->trace_id    = self::generate_uuid7();
		$this->trace_start = microtime( true );
		return $this->trace_id;
	}

	/**
	 * Get the current trace ID.
	 *
	 * @return string
	 */
	public function get_trace_id(): string {
		return $this->trace_id;
	}

	/**
	 * Start a span within the current trace.
	 *
	 * @param array{
	 *     name: string,
	 *     type: string,
	 *     model?: string,
	 *     provider?: string,
	 *     input?: array,
	 *     parent_span_id?: string
	 * } $span_data Span metadata.
	 *
	 * @return string Generated span ID (UUID).
	 */
	public function start_span( array $span_data ): string {
		$span_id = self::generate_uuid7();

		$span = array(
			'id'             => $span_id,
			'trace_id'       => $this->trace_id,
			'name'           => $span_data['name'] ?? 'unknown',
			'type'           => $span_data['type'] ?? 'general',
			'start_time'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'start_time_ms'  => microtime( true ),
			'end_time'       => null,
			'end_time_ms'    => null,
			'input'          => $span_data['input'] ?? array(),
			'output'         => array(),
			'model'          => $span_data['model'] ?? null,
			'provider'       => $span_data['provider'] ?? null,
			'parent_span_id' => $span_data['parent_span_id'] ?? null,
			'usage'          => array(),
		);

		$this->span_stack[ $span_id ] = $span;
		return $span_id;
	}

	/**
	 * End a span and record its output.
	 *
	 * @param string $span_id Span ID from start_span().
	 * @param array{
	 *     output?: array,
	 *     usage?: array
	 * } $span_data Final span data to merge.
	 */
	public function end_span( string $span_id, array $span_data = array() ): void {
		if ( ! isset( $this->span_stack[ $span_id ] ) ) {
			PRAutoBlogger_Logger::instance()->warning(
				sprintf( 'Attempt to end unknown span %s', $span_id ),
				'opik'
			);
			return;
		}

		$span = &$this->span_stack[ $span_id ];
		$span['end_time']   = gmdate( 'Y-m-d\TH:i:s\Z' );
		$span['end_time_ms'] = microtime( true );
		$span['output']      = $span_data['output'] ?? array();
		$span['usage']       = $span_data['usage'] ?? array();
	}

	/**
	 * Get a completed span from the stack.
	 *
	 * @param string $span_id
	 *
	 * @return array|null
	 */
	public function get_span( string $span_id ): ?array {
		return $this->span_stack[ $span_id ] ?? null;
	}

	/**
	 * Get all spans collected in this trace.
	 *
	 * @return array
	 */
	public function get_spans(): array {
		return $this->span_stack;
	}

	/**
	 * Finalize and return the trace object with all spans.
	 *
	 * @return array{
	 *     id: string,
	 *     name: string,
	 *     project_name: string,
	 *     start_time: string,
	 *     end_time: string,
	 *     input: array,
	 *     output: array,
	 *     metadata: array,
	 *     spans: array
	 * }
	 */
	public function finalize_trace(): array {
		$trace_end = microtime( true );

		return array(
			'id'           => $this->trace_id,
			'name'         => 'article_generation',
			'project_name' => get_option( 'prautoblogger_opik_project_name', 'prautoblogger' ),
			'start_time'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) $this->trace_start ),
			'end_time'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) $trace_end ),
			'input'        => array( 'pipeline' => 'article_generation' ),
			'output'       => array( 'spans_count' => count( $this->span_stack ) ),
			'metadata'     => array( 'source' => 'prautoblogger' ),
			'spans'        => $this->span_stack,
		);
	}

	/**
	 * Tear down the context (clear it for the next request).
	 *
	 * Called at the end of the article generation pipeline.
	 */
	public static function teardown(): void {
		self::$instance = null;
	}

	/**
	 * Generate a UUID version 7 (time-ordered, random).
	 *
	 * Opik requires v7 UUIDs for trace and span IDs. WordPress only provides
	 * wp_generate_uuid4() so we implement v7 here.
	 *
	 * UUID v7 structure (128 bits):
	 *   - Bits  0-47 : Unix timestamp in milliseconds (big-endian)
	 *   - Bits 48-51 : Version nibble (0x7)
	 *   - Bits 52-63 : rand_a (12 random bits)
	 *   - Bits 64-65 : Variant (0b10)
	 *   - Bits 66-127: rand_b (62 random bits)
	 *
	 * @return string UUID v7 in standard 8-4-4-4-12 hex format.
	 */
	private static function generate_uuid7(): string {
		$ms     = (int) ( microtime( true ) * 1000 );
		$ts_hex = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT ); // 48-bit ts -> 12 hex
		$rand   = bin2hex( random_bytes( 10 ) );                    // 80 random bits -> 20 hex

		// Group 3: version nibble '7' + 12 random bits (3 hex chars).
		$g3 = '7' . substr( $rand, 0, 3 );

		// Group 4: variant byte (top 2 bits = 10) + 2 random hex chars.
		$var_byte = ( hexdec( substr( $rand, 4, 2 ) ) & 0x3f ) | 0x80;
		$g4       = sprintf( '%02x', $var_byte ) . substr( $rand, 6, 2 );

		// Group 5: 12 remaining random hex chars.
		$g5 = substr( $rand, 8, 12 );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $ts_hex, 0, 8 ), // time_high (32 bits)
			substr( $ts_hex, 8, 4 ), // time_low  (16 bits)
			$g3,
			$g4,
			$g5
		);
	}
}
