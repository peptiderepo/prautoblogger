<?php
declare(strict_types=1);

/**
 * Registers the [prautoblogger_posts] shortcode and its supporting REST API endpoint.
 *
 * This is PRAutoBlogger's first public-facing frontend feature. It renders a React-powered
 * card grid of generated posts, fetching data asynchronously from a lightweight REST
 * endpoint to keep the initial page load fast.
 *
 * Triggered by: Main orchestrator (class-prautoblogger.php) on `init` and `rest_api_init`.
 * Dependencies: wp.element (WordPress-bundled React), posts-widget.js, posts-widget.css.
 *
 * @see class-prautoblogger.php — Hook registration for this class.
 * @see assets/js/posts-widget.js — React component that renders the cards.
 * @see assets/css/posts-widget.css — Frontend styles matching the Peptide Repo theme.
 * @see ARCHITECTURE.md — Data flow for frontend widget.
 */
class PRAutoBlogger_Posts_Widget {

	/**
	 * REST API namespace for the widget endpoint.
	 */
	private const REST_NAMESPACE = 'prautoblogger/v1';

	/**
	 * REST API route for fetching posts.
	 */
	private const REST_ROUTE = '/posts';

	/**
	 * Maximum posts allowed per request (hard cap for safety).
	 */
	private const MAX_POSTS_PER_REQUEST = 12;

	/**
	 * Register the [prautoblogger_posts] shortcode.
	 *
	 * Called on `init` by the main orchestrator.
	 *
	 * Side effects: registers a WordPress shortcode.
	 *
	 * @return void
	 */
	public function on_register_shortcode(): void {
		add_shortcode( 'prautoblogger_posts', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register the REST API endpoint for fetching posts.
	 *
	 * Called on `rest_api_init` by the main orchestrator.
	 *
	 * Side effects: registers a WordPress REST route.
	 *
	 * @return void
	 */
	public function on_register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_rest_request' ),
				'permission_callback' => function () {
					/**
					 * Filter whether the posts widget REST endpoint is publicly accessible.
					 *
					 * By default, this is true — the endpoint only serves published posts.
					 * Return false to require authentication (e.g., for private/staging sites).
					 *
					 * @param bool $is_public Whether the endpoint is publicly accessible.
					 */
					return (bool) apply_filters( 'prautoblogger_rest_posts_public', true );
				},
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 6,
						'minimum'           => 1,
						'maximum'           => self::MAX_POSTS_PER_REQUEST,
						'sanitize_callback' => 'absint',
					),
					'category' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Render the shortcode output.
	 *
	 * Outputs a mount-point div and enqueues the React script + CSS.
	 * The React component hydrates the div on the client side.
	 *
	 * Shortcode attributes:
	 *   count    — Number of posts to display (default 6, max 12).
	 *   category — Filter by category slug (default empty = all).
	 *   title    — Widget heading (default "Latest Research & Insights").
	 *   subtitle — Widget subheading.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 *
	 * @return string HTML mount-point div.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'count'    => '6',
				'category' => '',
				'title'    => __( 'Latest Research & Insights', 'prautoblogger' ),
				'subtitle' => __( 'Evidence-based articles on peptides, protocols, and emerging research', 'prautoblogger' ),
			),
			$atts,
			'prautoblogger_posts'
		);

		$count = min( absint( $atts['count'] ), self::MAX_POSTS_PER_REQUEST );
		if ( $count < 1 ) {
			$count = 6;
		}

		$this->enqueue_frontend_assets( $count, $atts );

		return '<div id="prab-posts-root"></div>';
	}

	/**
	 * Enqueue frontend CSS and JS for the widget.
	 *
	 * Uses wp.element (WordPress-bundled React) as a dependency — no build step needed.
	 * Passes configuration to JS via wp_localize_script.
	 *
	 * Side effects: enqueues styles and scripts.
	 *
	 * @param int                     $count Number of posts to fetch.
	 * @param array<string, string>   $atts  Shortcode attributes.
	 *
	 * @return void
	 */
	private function enqueue_frontend_assets( int $count, array $atts ): void {
		$version = defined( 'PRAUTOBLOGGER_VERSION' ) ? PRAUTOBLOGGER_VERSION : '0.1.0';

		wp_enqueue_style(
			'prautoblogger-posts-widget',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/css/posts-widget.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'prautoblogger-posts-widget',
			PRAUTOBLOGGER_PLUGIN_URL . 'assets/js/posts-widget.js',
			array( 'wp-element' ), // WordPress-bundled React.
			$version,
			true // Load in footer for performance.
		);

		wp_localize_script(
			'prautoblogger-posts-widget',
			'prabPostsWidget',
			array(
				'restUrl'    => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
				'count'      => $count,
				'category'   => sanitize_text_field( $atts['category'] ),
				'title'      => sanitize_text_field( $atts['title'] ),
				'subtitle'   => sanitize_text_field( $atts['subtitle'] ),
				'archiveUrl' => esc_url( get_post_type_archive_link( 'post' ) ?: '' ),
			)
		);
	}

	/**
	 * Handle the REST API request for posts.
	 *
	 * Returns a slim JSON array of published posts generated by PRAutoBlogger.
	 * Each post includes only the fields the frontend needs — no unnecessary data.
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response JSON response with post data.
	 */
	public function handle_rest_request( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = min( absint( $request->get_param( 'per_page' ) ), self::MAX_POSTS_PER_REQUEST );
		if ( $per_page < 1 ) {
			$per_page = 6;
		}
		$category = sanitize_text_field( $request->get_param( 'category' ) ?? '' );

		$query_args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => '_prautoblogger_generated',
					'value' => '1',
				),
			),
		);

		// Optional category filter.
		if ( ! empty( $category ) ) {
			$query_args['category_name'] = $category;
		}

		$query = new \WP_Query( $query_args );
		$posts = array();

		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post_for_response( $post );
		}

		$response = new \WP_REST_Response( $posts, 200 );

		// Cache for 5 minutes — posts don't change frequently.
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Format a single post for the REST API response.
	 *
	 * Returns only the fields the React component needs to render a card.
	 *
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return array<string, mixed> Formatted post data.
	 */
	private function format_post_for_response( \WP_Post $post ): array {
		$categories     = get_the_category( $post->ID );
		$category_name  = ! empty( $categories ) ? $categories[0]->name : '';
		$category_slug  = ! empty( $categories ) ? $categories[0]->slug : '';
		$featured_image = get_the_post_thumbnail_url( $post->ID, 'medium_large' );
		$word_count     = str_word_count( wp_strip_all_tags( $post->post_content ) );

		// Decode HTML entities in title and excerpt so React's textContent
		// rendering shows proper characters (e.g., &#8217; → ').
		// WordPress functions like get_the_title() return HTML-encoded strings,
		// but since our React component sets text via createElement (not innerHTML),
		// raw entities would display literally.
		$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$excerpt = html_entity_decode( get_the_excerpt( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return array(
			'id'             => $post->ID,
			'title'          => $title,
			'excerpt'        => $excerpt,
			'url'            => get_permalink( $post ),
			'date'           => get_the_date( 'c', $post ),
			'category_name'  => $category_name,
			'category_slug'  => $category_slug,
			'featured_image' => $featured_image ?: '',
			'word_count'     => $word_count,
		);
	}
}
