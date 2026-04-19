<?php
declare(strict_types=1);

/**
 * Semantic deduplication using embedding cosine similarity.
 *
 * Embeds candidate topics and recent post titles in a single batch API call,
 * then compares via cosine similarity. Falls back to keyword-overlap matching
 * if the embedding API is unavailable or fails.
 *
 * Triggered by: PRAutoBlogger_Idea_Scorer::score_and_rank().
 * Dependencies: OpenRouter_Embedding_Provider, WP_Query.
 *
 * @see core/class-idea-scorer.php                         — Consumer.
 * @see providers/class-open-router-embedding-provider.php — Embedding API.
 */
class PRAutoBlogger_Semantic_Dedup {

	/**
	 * Cosine similarity threshold for "too similar". MiniLM embeddings treat
	 * ~0.7+ as semantically equivalent for short texts. We use 0.72 to avoid
	 * over-aggressive blocking while still catching rephrasings.
	 */
	private const SIMILARITY_THRESHOLD = 0.72;

	/** @var float[][] Cached embeddings for recent post titles, keyed by index. */
	private array $title_embeddings = [];

	/** @var string[] Recent post titles. */
	private array $recent_titles = [];

	/** @var float[][] Cached embeddings for accepted ideas, accumulated per-batch. */
	private array $accepted_embeddings = [];

	/** @var bool Whether embeddings are available (false after API failure). */
	private bool $embeddings_available = true;

	/** @var PRAutoBlogger_OpenRouter_Embedding_Provider|null Reused across calls. */
	private ?PRAutoBlogger_OpenRouter_Embedding_Provider $provider = null;

	/**
	 * Initialize by fetching recent titles and pre-computing their embeddings.
	 *
	 * Called once at the start of scoring. If the embedding API fails,
	 * sets a flag so all subsequent checks fall back to keyword matching.
	 *
	 * Side effects: HTTP request to OpenRouter embedding API, WP_Query.
	 */
	public function initialize(): void {
		$this->recent_titles = $this->fetch_recent_post_titles();
		if ( empty( $this->recent_titles ) ) {
			return;
		}

		try {
			$this->provider         = new PRAutoBlogger_OpenRouter_Embedding_Provider();
			$this->title_embeddings = $this->provider->get_embeddings( $this->recent_titles );
		} catch ( \Throwable $e ) {
			PRAutoBlogger_Logger::instance()->warning(
				'Semantic dedup unavailable, falling back to keyword matching: ' . $e->getMessage(),
				'scorer'
			);
			$this->embeddings_available = false;
		}
	}

	/**
	 * Check if a topic is too similar to any already-accepted idea in this batch.
	 *
	 * @param string   $topic            Candidate topic text.
	 * @param string[] $topic_keywords   Pre-extracted keywords (for fallback).
	 * @param array    $keyword_sets     Accepted keyword sets (for fallback).
	 *
	 * @return bool True if the topic should be skipped.
	 */
	public function is_batch_duplicate( string $topic, array $topic_keywords, array $keyword_sets ): bool {
		if ( ! $this->embeddings_available || empty( $this->accepted_embeddings ) ) {
			return $this->keyword_overlaps_any( $topic_keywords, $keyword_sets );
		}

		$embedding = $this->embed_single( $topic );
		if ( null === $embedding ) {
			return $this->keyword_overlaps_any( $topic_keywords, $keyword_sets );
		}

		foreach ( $this->accepted_embeddings as $accepted ) {
			$sim = PRAutoBlogger_OpenRouter_Embedding_Provider::cosine_similarity( $embedding, $accepted );
			if ( $sim >= self::SIMILARITY_THRESHOLD ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a topic is too similar to any recently published post.
	 *
	 * @param string   $topic          Candidate topic text.
	 * @param string[] $topic_keywords Pre-extracted keywords (for fallback).
	 *
	 * @return bool True if a recent post is semantically too similar.
	 */
	public function is_recent_duplicate( string $topic, array $topic_keywords ): bool {
		if ( ! $this->embeddings_available || empty( $this->title_embeddings ) ) {
			return $this->keyword_matches_recent( $topic_keywords );
		}

		$embedding = $this->embed_single( $topic );
		if ( null === $embedding ) {
			return $this->keyword_matches_recent( $topic_keywords );
		}

		foreach ( $this->title_embeddings as $i => $title_emb ) {
			$sim = PRAutoBlogger_OpenRouter_Embedding_Provider::cosine_similarity( $embedding, $title_emb );
			if ( $sim >= self::SIMILARITY_THRESHOLD ) {
				PRAutoBlogger_Logger::instance()->info(
					sprintf(
						'Semantic match (%.2f): "%s" ≈ "%s"',
						$sim,
						substr( $topic, 0, 60 ),
						substr( $this->recent_titles[ $i ] ?? '', 0, 60 )
					),
					'scorer'
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Record an accepted idea's embedding for intra-batch dedup.
	 *
	 * @param string $topic The accepted topic text.
	 */
	public function record_accepted( string $topic ): void {
		if ( ! $this->embeddings_available ) {
			return;
		}
		$embedding = $this->embed_single( $topic );
		if ( null !== $embedding ) {
			$this->accepted_embeddings[] = $embedding;
		}
	}

	// ── Private helpers ─────────────────────────────────────────────────

	/**
	 * Embed a single text, with error handling.
	 *
	 * @param string $text Text to embed.
	 * @return float[]|null Embedding vector, or null on failure.
	 */
	private function embed_single( string $text ): ?array {
		try {
			if ( null === $this->provider ) {
				$this->provider = new PRAutoBlogger_OpenRouter_Embedding_Provider();
			}
			$result = $this->provider->get_embeddings( [ $text ] );
			return $result[0] ?? null;
		} catch ( \Throwable $e ) {
			// Degrade gracefully — one failed embed shouldn't kill the pipeline.
			$this->embeddings_available = false;
			PRAutoBlogger_Logger::instance()->warning(
				'Embedding failed, disabling semantic dedup for this run: ' . $e->getMessage(),
				'scorer'
			);
			return null;
		}
	}

	/**
	 * Keyword-overlap fallback for intra-batch dedup.
	 *
	 * @param string[] $keywords     Keywords to check.
	 * @param array    $keyword_sets List of previously accepted keyword arrays.
	 * @return bool
	 */
	private function keyword_overlaps_any( array $keywords, array $keyword_sets ): bool {
		if ( count( $keywords ) < 2 ) {
			return false;
		}
		foreach ( $keyword_sets as $existing ) {
			$overlap = count( array_intersect( $keywords, $existing ) );
			if ( $overlap / count( $keywords ) >= 0.6 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keyword-overlap fallback for recent-post dedup.
	 *
	 * @param string[] $topic_keywords Pre-extracted keywords.
	 * @return bool
	 */
	private function keyword_matches_recent( array $topic_keywords ): bool {
		if ( count( $topic_keywords ) < 2 ) {
			return false;
		}
		foreach ( $this->recent_titles as $title ) {
			$title_keywords = $this->extract_meaningful_keywords( $title );
			$overlap        = count( array_intersect( $topic_keywords, $title_keywords ) );
			if ( $overlap / count( $topic_keywords ) >= 0.6 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch titles of generated posts from the last 30 days.
	 *
	 * Widened from 7 days (the old keyword window) because semantic similarity
	 * catches rephrasings that keywords miss — a wider net is safe here.
	 *
	 * @return string[]
	 */
	private function fetch_recent_post_titles(): array {
		$query = new \WP_Query( [
			'post_type'      => 'post',
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'meta_key'       => '_prautoblogger_generated',
			'meta_value'     => '1',
			'date_query'     => [ [ 'after' => '30 days ago' ] ],
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$titles = [];
		foreach ( $query->posts as $post_id ) {
			$titles[] = get_the_title( $post_id );
		}
		return $titles;
	}

	/**
	 * Extract meaningful keywords from text (fallback dedup).
	 *
	 * @param string $text Input text.
	 * @return string[] Unique lowercase keywords.
	 */
	private function extract_meaningful_keywords( string $text ): array {
		static $stopwords = [
			'a', 'an', 'the', 'and', 'or', 'but', 'is', 'are', 'was', 'were',
			'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as',
			'it', 'its', 'this', 'that', 'your', 'you', 'how', 'what', 'when',
			'why', 'where', 'which', 'who', 'do', 'does', 'did', 'not', 'no',
			'can', 'will', 'should', 'could', 'would', 'may', 'might', 'have',
			'has', 'had', 'be', 'been', 'being', 'about', 'into', 'through',
			'during', 'before', 'after', 'above', 'below', 'between', 'same',
			'up', 'down', 'out', 'off', 'over', 'under', 'again', 'then',
			'here', 'there', 'all', 'each', 'every', 'both', 'few', 'more',
			'most', 'other', 'some', 'such', 'than', 'too', 'very', 'just',
			'also', 'only', 'own', 'so', 'if', 'while', 'because', 'until',
			'vs', 'versus', 'guide', 'complete', 'ultimate', 'best', 'top',
			'new', 'first', 'need', 'know', 'everything',
		];

		$text  = strtolower( $text );
		$words = preg_split( '/[^a-z0-9-]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_filter( $words, static function ( string $w ) use ( $stopwords ): bool {
			return strlen( $w ) >= 2 && ! in_array( $w, $stopwords, true );
		} );

		return array_values( array_unique( $words ) );
	}
}
