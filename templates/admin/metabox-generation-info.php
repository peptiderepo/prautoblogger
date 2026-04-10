<?php
/**
 * Post metabox template showing AutoBlogger generation metadata.
 *
 * @see admin/class-post-metabox.php — Renders this template.
 *
 * @var WP_Post $post The current post.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$meta = [
	'topic'          => get_post_meta( $post->ID, '_autoblogger_topic', true ),
	'type'           => get_post_meta( $post->ID, '_autoblogger_article_type', true ),
	'model'          => get_post_meta( $post->ID, '_autoblogger_model_used', true ),
	'pipeline'       => get_post_meta( $post->ID, '_autoblogger_pipeline_mode', true ),
	'verdict'        => get_post_meta( $post->ID, '_autoblogger_editor_verdict', true ),
	'editor_notes'   => get_post_meta( $post->ID, '_autoblogger_editor_notes', true ),
	'quality_score'  => get_post_meta( $post->ID, '_autoblogger_quality_score', true ),
	'seo_score'      => get_post_meta( $post->ID, '_autoblogger_seo_score', true ),
	'generated_at'   => get_post_meta( $post->ID, '_autoblogger_generated_at', true ),
	'keywords'       => get_post_meta( $post->ID, '_autoblogger_target_keywords', true ),
];
?>
<div class="autoblogger-metabox">
	<p>
		<strong><?php esc_html_e( 'Generated:', 'autoblogger' ); ?></strong>
		<?php echo esc_html( $meta['generated_at'] ?: __( 'Unknown', 'autoblogger' ) ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Topic:', 'autoblogger' ); ?></strong>
		<?php echo esc_html( $meta['topic'] ?: '—' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Type:', 'autoblogger' ); ?></strong>
		<?php echo esc_html( ucfirst( $meta['type'] ?: 'article' ) ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Model:', 'autoblogger' ); ?></strong>
		<?php echo esc_html( $meta['model'] ?: '—' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Pipeline:', 'autoblogger' ); ?></strong>
		<?php echo esc_html( 'multi_step' === $meta['pipeline'] ? __( 'Multi-step', 'autoblogger' ) : __( 'Single-pass', 'autoblogger' ) ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Editor Verdict:', 'autoblogger' ); ?></strong>
		<span class="autoblogger-verdict-<?php echo esc_attr( $meta['verdict'] ?: 'unknown' ); ?>">
			<?php echo esc_html( ucfirst( $meta['verdict'] ?: __( 'Unknown', 'autoblogger' ) ) ); ?>
		</span>
	</p>
	<?php if ( $meta['quality_score'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Quality:', 'autoblogger' ); ?></strong>
			<?php echo esc_html( number_format( (float) $meta['quality_score'] * 100, 0 ) ); ?>%
			&nbsp;|&nbsp;
			<strong><?php esc_html_e( 'SEO:', 'autoblogger' ); ?></strong>
			<?php echo esc_html( number_format( (float) $meta['seo_score'] * 100, 0 ) ); ?>%
		</p>
	<?php endif; ?>
	<?php if ( $meta['editor_notes'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Editor Notes:', 'autoblogger' ); ?></strong><br>
			<?php echo esc_html( $meta['editor_notes'] ); ?>
		</p>
	<?php endif; ?>
	<?php if ( $meta['keywords'] ) : ?>
		<p>
			<strong><?php esc_html_e( 'Keywords:', 'autoblogger' ); ?></strong>
			<?php echo esc_html( implode( ', ', json_decode( $meta['keywords'], true ) ?: [] ) ); ?>
		</p>
	<?php endif; ?>
</div>
