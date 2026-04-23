<?php
declare(strict_types=1);

/**
 * Reddit RSS/Atom feed parser — parses Atom XML into normalized post arrays.
 *
 * Handles parsing of Reddit's Atom/RSS feeds and mapping them to the standard
 * Reddit post data format (same shape as .json responses). This parser is
 * self-contained: it takes raw XML and produces normalized arrays.
 *
 * Fields unavailable in RSS feeds (score, num_comments, upvote_ratio) are set
 * to sensible defaults so the rest of the pipeline can treat RSS and .json data uniformly.
 *
 * Usage: Called by PRAutoBlogger_Reddit_JSON_Client::fetch_posts_via_rss()
 *        to parse the response body.
 *
 * Dependencies: wp_strip_all_tags(), PRAutoBlogger_Logger, built-in SimpleXML.
 *
 * @see providers/class-reddit-json-client.php — Main client that calls this parser.
 * @see ARCHITECTURE.md                        — External API integrations table.
 */
class PRAutoBlogger_Reddit_RSS_Parser {

	/**
	 * Parse a Reddit Atom feed into post-data arrays matching .json format.
	 *
	 * Maps Atom entry fields to the standard Reddit post fields so the rest
	 * of the pipeline can consume RSS data without changes. Fields unavailable
	 * in RSS (score, num_comments, upvote_ratio) are set to reasonable defaults.
	 *
	 * @param string $xml       Raw Atom XML string.
	 * @param string $subreddit The subreddit name for context (logging only).
	 *
	 * @return array<int, array<string, mixed>> Parsed post data.
	 */
	public function parse( string $xml, string $subreddit ): array {
		// Suppress XML errors for malformed feeds.
		$prev_errors = libxml_use_internal_errors( true );
		$feed        = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev_errors );

		if ( false === $feed ) {
			PRAutoBlogger_Logger::instance()->error( 'Failed to parse Reddit RSS XML.', 'reddit' );
			return array();
		}

		// Register the Atom namespace.
		$feed->registerXPathNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
		$entries = $feed->xpath( '//atom:entry' );

		if ( empty( $entries ) ) {
			return array();
		}

		$posts = array();
		foreach ( $entries as $entry ) {
			$post = $this->parse_entry( $entry, $subreddit );
			if ( null !== $post ) {
				$posts[] = $post;
			}
		}

		PRAutoBlogger_Logger::instance()->info(
			sprintf( 'Parsed %d posts from Reddit RSS for r/%s.', count( $posts ), $subreddit ),
			'reddit'
		);

		return $posts;
	}

	/**
	 * Parse a single Atom entry element into a normalized post array.
	 *
	 * @param \SimpleXMLElement $entry An Atom entry element.
	 * @param string            $subreddit The subreddit name (for context/logging).
	 *
	 * @return array<string, mixed>|null The parsed post data, or null if parsing fails.
	 */
	private function parse_entry( $entry, string $subreddit ): ?array {
		$title   = (string) $entry->title;
		$link    = '';
		$content = '';

		// Get the HTML link (type="text/html").
		foreach ( $entry->link as $link_el ) {
			if ( 'text/html' === (string) $link_el['type'] || 'alternate' === (string) $link_el['rel'] ) {
				$link = (string) $link_el['href'];
				break;
			}
		}

		// Content is in <content> tag as HTML.
		if ( isset( $entry->content ) ) {
			$content = wp_strip_all_tags( (string) $entry->content );
		}

		// Extract post ID from the entry id (format: /r/subreddit/comments/ID/...).
		$entry_id = (string) $entry->id;
		$post_id  = '';
		if ( preg_match( '#/comments/([a-z0-9]+)#', $entry_id, $m ) ) {
			$post_id = $m[1];
		}

		// Extract author name.
		$author = '[deleted]';
		if ( isset( $entry->author->name ) ) {
			$author = str_replace( '/u/', '', (string) $entry->author->name );
		}

		// Parse published date to Unix timestamp.
		$published   = (string) ( $entry->published ?? $entry->updated ?? '' );
		$created_utc = '' !== $published ? (int) strtotime( $published ) : time();

		// Extract permalink (relative path) from full URL.
		$permalink = '';
		if ( '' !== $link ) {
			$parsed    = wp_parse_url( $link );
			$permalink = $parsed['path'] ?? '';
		}

		return array(
			'id'                  => $post_id,
			'title'               => $title,
			'selftext'            => $content,
			'author'              => $author,
			'score'               => 1,     // Not available in RSS.
			'num_comments'        => 0,     // Not available in RSS.
			'permalink'           => $permalink,
			'created_utc'         => $created_utc,
			'is_self'             => true,
			'link_flair_text'     => null,
			'upvote_ratio'        => null,
			'is_original_content' => false,
			'data_source'         => 'reddit_rss',
		);
	}
}
