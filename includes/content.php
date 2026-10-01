<?php
/**
 * Front-end markup for the [youtube_channel_videos] shortcode.
 *
 * This file is include()'d from YCV_YouTube_Channel_Videos::render_shortcode(),
 * so it runs inside that method's scope and expects these variables to already
 * be set by the caller:
 *
 * @var array  $videos           The current page's slice of video arrays: id, title, description, published, thumbnail, url.
 * @var int    $columns          Number of grid columns (1–6).
 * @var bool   $show_desc        Whether to print a trimmed description under each title.
 * @var string $pagination_html  Pre-built <ul class="page-numbers"> markup from paginate_links(), or '' if only one page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}
?>
<div id="ycv-grid" style="--ycv-columns: <?php echo esc_attr( $columns ); ?>;">
	<?php foreach ( $videos as $video ) : ?>
		<a class="ycv-card" href="<?php echo esc_url( $video['url'] ); ?>" target="_blank" rel="noopener">
			<span class="ycv-thumb-wrap">
				<img class="ycv-thumb" src="<?php echo esc_url( $video['thumbnail'] ); ?>" alt="<?php echo esc_attr( $video['title'] ); ?>" loading="lazy" />
				<span class="ycv-play">&#9658;</span>
			</span>
			<span class="ycv-title"><?php echo esc_html( $video['title'] ); ?></span>
			<?php if ( $show_desc && ! empty( $video['description'] ) ) : ?>
				<span class="ycv-desc"><?php echo esc_html( wp_trim_words( $video['description'], 20 ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</div>
<?php if ( ! empty( $pagination_html ) ) : ?>
	<nav class="ycv-pagination" aria-label="<?php esc_attr_e( 'Video gallery pagination', 'ycv' ); ?>">
		<?php echo $pagination_html; // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped output from paginate_links(). ?>
	</nav>
<?php endif; ?>
