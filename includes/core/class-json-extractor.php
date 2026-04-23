<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Extracts valid JSON from potentially dirty LLM response strings.
 *
 * Many models ignore the `response_format: json_object` instruction and wrap
 * their output in markdown fences, add preamble text, or append trailing
 * commentary. This class strips all of that and returns clean decoded data.
 *
 * Triggered by: Any class that parses JSON from an LLM response.
 * Dependencies: None (pure utility).
 *
 * @see core/class-content-analyzer.php — Uses this for analysis JSON parsing.
 * @see core/class-chief-editor.php     — Uses this for editorial review JSON parsing.
 */
class PRAutoBlogger_Json_Extractor {

	/**
	 * Decode JSON from an LLM response, tolerating markdown fences and preamble.
	 *
	 * Attempts, in order:
	 * 1. Direct json_decode (model returned clean JSON).
	 * 2. Strip ```json ... ``` fences, then decode.
	 * 3. Find the first `{` and last `}` and decode that substring.
	 *
	 * @param string $raw The raw LLM response content.
	 * @return array<string, mixed>|null Decoded associative array, or null on failure.
	 */
	public static function decode( string $raw ): ?array {
		$trimmed = trim( $raw );

		// 1. Clean JSON — best case.
		$decoded = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// 2. Markdown-fenced JSON (```json ... ``` or ``` ... ```).
		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $trimmed, $matches ) ) {
			$decoded = json_decode( trim( $matches[1] ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// 3. Brute-force: find outermost { ... } substring.
		$first_brace = strpos( $trimmed, '{' );
		$last_brace  = strrpos( $trimmed, '}' );
		if ( false !== $first_brace && false !== $last_brace && $last_brace > $first_brace ) {
			$candidate = substr( $trimmed, $first_brace, $last_brace - $first_brace + 1 );
			$decoded   = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}
}
