<?php
declare(strict_types=1);

/**
 * Injects frontend typography and table styles for PRAutoBlogger-generated posts.
 *
 * What: Outputs inline CSS on all single post pages, applying
 *       user-configured font family, font size, and table border
 *       styles from the Display admin settings.
 * Who calls it: PRAutoBlogger::register_frontend_hooks() hooks this
 *               into wp_head.
 * Dependencies: Reads wp_options for typography settings.
 *
 * @see class-prautoblogger.php       — Registers the wp_head hook.
 * @see class-settings-fields.php     — Defines the Display settings section.
 * @see admin/class-admin-page.php    — Renders the settings form.
 */
class PRAutoBlogger_Article_Typography {

	/** Option key for article font family. */
	private const OPT_FONT_FAMILY = 'prautoblogger_article_font_family';

	/** Option key for article body font size in pixels. */
	private const OPT_FONT_SIZE = 'prautoblogger_article_font_size';

	/** Option key for table border toggle. */
	private const OPT_TABLE_BORDERS = 'prautoblogger_table_borders';

	/**
	 * Output inline CSS in <head> for single post pages.
	 *
	 * Applies to all posts — typography settings are site-wide.
	 * The CSS targets .entry-content (PR Theme's article body class)
	 * and overrides the theme's CSS custom properties so the cascade
	 * works naturally with headings and sub-elements.
	 *
	 * Side effects: echoes a <style> block.
	 *
	 * @return void
	 */
	public function on_wp_head(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$css = $this->build_css();
		if ( '' === $css ) {
			return;
		}

		printf(
			"\n<style id=\"prautoblogger-typography\">\n%s\n</style>\n",
			$css // Already escaped — contains only sanitized option values.
		);
	}

	/**
	 * Build the CSS string from current settings.
	 *
	 * Reads each setting at call time (never cached) so changes
	 * take effect immediately without cache invalidation.
	 *
	 * @return string CSS rules, or empty string if all defaults.
	 */
	private function build_css(): string {
		$rules = [];

		$font_family = $this->get_font_family_stack();
		if ( '' !== $font_family ) {
			$rules[] = sprintf(
				".entry-content,\n.entry-content p,\n.entry-content li {\n\tfont-family: %s;\n}",
				$font_family
			);
		}

		$font_size = absint( get_option( self::OPT_FONT_SIZE, 0 ) );
		if ( $font_size > 0 ) {
			$rules[] = sprintf(
				".entry-content,\n.entry-content p,\n.entry-content li {\n\tfont-size: %dpx;\n\tline-height: 1.7;\n}",
				$font_size
			);
		}

		// Restore bullet points — the theme's global reset strips
		// list-style from all ul/ol, which removes bullets from article
		// content. This re-applies them inside .entry-content only.
		$rules[] = implode( "\n", [
			'.entry-content ul {',
			"\tlist-style: disc;",
			"\tpadding-left: 1.5em;",
			"\tmargin: 1em 0;",
			'}',
			'.entry-content ol {',
			"\tlist-style: decimal;",
			"\tpadding-left: 1.5em;",
			"\tmargin: 1em 0;",
			'}',
			'.entry-content li {',
			"\tmargin-bottom: 0.4em;",
			'}',
		] );

		$table_borders = get_option( self::OPT_TABLE_BORDERS, '1' );
		if ( '1' === $table_borders ) {
			// Uses theme CSS custom properties for dark mode compatibility.
			// --color-border-default, --color-bg-secondary, --color-bg-primary,
			// --color-text-primary are set by PR Theme for both light and dark.
			$rules[] = implode( "\n", [
				'.entry-content .prab-table-wrap {',
				"\toverflow-x: auto;",
				"\t-webkit-overflow-scrolling: touch;",
				"\tmargin: 1.5em 0;",
				'}',
				'.entry-content table {',
				"\tborder-collapse: collapse;",
				"\twidth: 100%;",
				"\tmin-width: 480px;",
				'}',
				'.entry-content th,',
				'.entry-content td {',
				"\tborder: 1px solid var(--color-border-default, #d1d5db);",
				"\tpadding: 0.6em 1em;",
				"\ttext-align: left;",
				"\tcolor: var(--color-text-primary, #111827);",
				'}',
				'.entry-content thead th {',
				"\tbackground: var(--color-bg-secondary, #f3f4f6);",
				"\tfont-weight: 600;",
				'}',
				'.entry-content tbody tr:nth-child(even) {',
				"\tbackground: var(--color-bg-secondary, #f9fafb);",
				'}',
				'@media (max-width: 600px) {',
				"\t.entry-content th,",
				"\t.entry-content td {",
				"\t\tpadding: 0.4em 0.6em;",
				"\t\tfont-size: 14px;",
				"\t}",
				'}',
			] );
		}

		return implode( "\n\n", $rules );
	}

	/**
	 * Map the stored font-family key to a full CSS font stack.
	 *
	 * Returns empty string for 'default' (let the theme decide).
	 *
	 * @return string CSS font-family value, or empty for theme default.
	 */
	private function get_font_family_stack(): string {
		$key = sanitize_text_field( get_option( self::OPT_FONT_FAMILY, 'default' ) );

		$stacks = [
			'default'    => '',
			'inter'      => '"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
			'georgia'    => '"Georgia", "Times New Roman", Times, serif',
			'merriweather' => '"Merriweather", Georgia, "Times New Roman", serif',
			'lora'       => '"Lora", Georgia, "Times New Roman", serif',
			'open_sans'  => '"Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
			'roboto'     => '"Roboto", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
			'system'     => '-apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", sans-serif',
		];

		return $stacks[ $key ] ?? '';
	}

	/**
	 * Get available font choices for the settings UI.
	 *
	 * @return array<string, string> Map of key => display label.
	 */
	public static function get_font_choices(): array {
		return [
			'default'      => __( 'Theme Default (Inter)', 'prautoblogger' ),
			'inter'        => __( 'Inter (Sans-serif)', 'prautoblogger' ),
			'georgia'      => __( 'Georgia (Serif)', 'prautoblogger' ),
			'merriweather' => __( 'Merriweather (Serif)', 'prautoblogger' ),
			'lora'         => __( 'Lora (Serif)', 'prautoblogger' ),
			'open_sans'    => __( 'Open Sans (Sans-serif)', 'prautoblogger' ),
			'roboto'       => __( 'Roboto (Sans-serif)', 'prautoblogger' ),
			'system'       => __( 'System Default', 'prautoblogger' ),
		];
	}

	/**
	 * Enqueue Google Fonts stylesheet when a web font is selected.
	 *
	 * Only loads on singular post pages that use a Google-hosted font.
	 *
	 * Side effects: enqueues a remote stylesheet.
	 *
	 * @return void
	 */
	public function on_enqueue_fonts(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$font = sanitize_text_field( get_option( self::OPT_FONT_FAMILY, 'default' ) );

		// Map font keys to Google Fonts URLs. Inter is already loaded by the theme.
		$google_fonts = [
			'merriweather' => 'https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&display=swap',
			'lora'         => 'https://fonts.googleapis.com/css2?family=Lora:wght@400;700&display=swap',
			'open_sans'    => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap',
			'roboto'       => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap',
		];

		if ( isset( $google_fonts[ $font ] ) ) {
			wp_enqueue_style(
				'prautoblogger-google-font',
				$google_fonts[ $font ],
				[],
				null // No version for external CDN URLs.
			);
		}
	}

	/**
	 * Wrap bare <table> elements in a scrollable container for mobile.
	 *
	 * Tables wider than the viewport break mobile layouts. This wraps
	 * each <table> not already inside a wrapper in a
	 * <div class="prab-table-wrap"> that enables horizontal scrolling.
	 *
	 * Only runs on singular post pages.
	 *
	 * Side effects: modifies post HTML content via the_content filter.
	 *
	 * @param string $content Post HTML content.
	 * @return string Modified content with tables wrapped.
	 */
	public function on_wrap_tables( string $content ): string {
		if ( ! is_singular( 'post' ) ) {
			return $content;
		}

		if ( '1' !== get_option( self::OPT_TABLE_BORDERS, '1' ) ) {
			return $content;
		}

		// Wrap <table> elements not already inside a .prab-table-wrap.
		return (string) preg_replace(
			'/(?<!prab-table-wrap">)\s*(<table[\s>])/i',
			'<div class="prab-table-wrap">$1',
			(string) preg_replace(
				'/<\/table>\s*/i',
				'</table></div>',
				$content
			)
		);
	}
}
