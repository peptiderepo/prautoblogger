<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Deterministic post-processor that hyperlinks peptide mentions in HTML content.
 *
 * Scans generated article HTML for any peptide name that exists in the PR Core
 * database and wraps every mention in an <a> tag pointing to its /peptides/ page.
 * Skips text that is already inside an existing <a> tag to avoid nested links.
 *
 * Triggered by: PRAutoBlogger_Publisher::create_post() (before wp_insert_post).
 * Dependencies: PR Core plugin (pr_peptide CPT). Gracefully no-ops if PR Core is inactive.
 *
 * @see core/class-publisher.php — Calls inject_links() on post_content.
 * @see peptide-repo-core/includes/cpt/class-pr-core-peptide-cpt.php — Peptide CPT.
 */
class PRAutoBlogger_Peptide_Linker {

	/**
	 * Inject peptide hyperlinks into HTML content.
	 *
	 * Every occurrence of a known peptide name is wrapped in a link to its
	 * database page, except when the text is already inside an <a> tag.
	 *
	 * @param string $html Article HTML content.
	 * @return string HTML with peptide names hyperlinked.
	 */
	public static function inject_links( string $html ): string {
		if ( ! post_type_exists( 'pr_peptide' ) ) {
			return $html;
		}

		$link_map = self::build_link_map();
		if ( empty( $link_map ) ) {
			return $html;
		}

		// Build a single regex alternation of all peptide names, longest first
		// so "BPC-157" matches before a hypothetical "BPC" would.
		$names = array_keys( $link_map );
		usort(
			$names,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		$escaped = array_map(
			static function ( string $name ): string {
				return preg_quote( $name, '/' );
			},
			$names
		);

		// Word-boundary match, case-insensitive.
		$pattern = '/\b(' . implode( '|', $escaped ) . ')\b/iu';

		// Split HTML into segments: inside <a>…</a> tags vs. outside.
		// Only replace in segments that are NOT inside an anchor tag.
		return self::replace_outside_links( $html, $pattern, $link_map );
	}

	/**
	 * Build a map of peptide display names → permalink URLs.
	 *
	 * Includes the canonical title and common aliases (e.g., "BPC-157" and
	 * "BPC 157") so both hyphenated and space-separated forms get linked.
	 *
	 * @return array<string, string> Name → URL pairs, case-preserved keys.
	 */
	private static function build_link_map(): array {
		$peptides = get_posts(
			array(
				'post_type'      => 'pr_peptide',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$map = array();
		foreach ( $peptides as $peptide_id ) {
			$title = get_the_title( $peptide_id );
			$url   = get_permalink( $peptide_id );
			if ( ! $title || ! $url ) {
				continue;
			}

			// Canonical name (e.g., "BPC-157").
			$map[ $title ] = $url;

			// If the name contains hyphens, also match the space-separated
			// variant (e.g., "BPC 157" → same URL as "BPC-157").
			if ( strpos( $title, '-' ) !== false ) {
				$space_variant         = str_replace( '-', ' ', $title );
				$map[ $space_variant ] = $url;
			}
		}

		return $map;
	}

	/**
	 * Replace peptide names with links, but only outside existing <a> tags.
	 *
	 * Splits HTML into chunks by <a …>…</a> boundaries. Replacement only
	 * runs on chunks that are outside anchors, preventing nested <a> tags.
	 *
	 * @param string              $html     Full HTML content.
	 * @param string              $pattern  Regex pattern matching peptide names.
	 * @param array<string,string> $link_map Name → URL map (case-preserved keys).
	 * @return string HTML with replacements applied.
	 */
	private static function replace_outside_links( string $html, string $pattern, array $link_map ): string {
		// Split on anchor tags, keeping the delimiters.
		// This regex captures full <a …>…</a> blocks as array elements.
		$parts = preg_split( '/(<a\s[^>]*>.*?<\/a>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $html;
		}

		// Build a lowercase → URL lookup for case-insensitive matching.
		$lower_map = array();
		foreach ( $link_map as $name => $url ) {
			$lower_map[ mb_strtolower( $name ) ] = $url;
		}

		$result = '';
		foreach ( $parts as $part ) {
			// If this chunk is an anchor tag, pass it through untouched.
			if ( preg_match( '/^<a\s/i', $part ) ) {
				$result .= $part;
				continue;
			}

			// Replace peptide names with hyperlinks.
			$result .= preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( $lower_map ): string {
					$matched_text = $matches[0];
					$key          = mb_strtolower( $matched_text );
					$url          = $lower_map[ $key ] ?? '';
					if ( '' === $url ) {
						return $matched_text;
					}
					return '<a href="' . esc_url( $url ) . '">' . esc_html( $matched_text ) . '</a>';
				},
				$part
			);
		}

		return $result;
	}
}
