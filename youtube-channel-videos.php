<?php
/**
 * Plugin Name: YouTube Channel Videos
 * Plugin URI:  https://syednomanali.vercel.app/
 * Description: Fetches all videos from a YouTube channel (by @handle or Channel ID) and displays them via a shortcode. Example: [youtube_channel_videos channel="@example"]
 * Version:     1.2.0
 * Author:      Syed Noman Ali
 * License:     GPL v2 or later
 * Text Domain: ycv
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'YCV_PLUGIN_FILE', __FILE__ );
define( 'YCV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YCV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

class YCV_YouTube_Channel_Videos {

	const OPTION_KEY = 'ycv_settings';
	const VERSION    = '1.2.0';
	const API_BASE   = 'https://www.googleapis.com/youtube/v3';

	private static $instance = null;

	/**
	 * Bumped once per shortcode instance rendered on a page, so that two
	 * [youtube_channel_videos] shortcodes on the same page paginate with
	 * independent query vars (ycv_page, ycv_page_2, ...) instead of clashing.
	 */
	private static $instance_count = 0;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( 'youtube_channel_videos', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );

		// Admin action: manual "clear cache" button handler.
		add_action( 'admin_post_ycv_clear_cache', array( $this, 'handle_clear_cache' ) );
	}

	/* -----------------------------------------------------------
	 * Settings
	 * --------------------------------------------------------- */

	public function get_settings() {
		$defaults = array(
			'api_key'         => '',
			'default_channel' => '',
			'cache_hours'     => 6,
			'default_count'   => 0,
			'default_columns' => 4,
			'default_per_page'=> 12,
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	public function register_settings_page() {
		add_options_page(
			'YouTube Channel Videos',
			'YouTube Channel Videos',
			'manage_options',
			'ycv-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'ycv_settings_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$clean = array();
		$clean['api_key']         = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '';
		$clean['default_channel'] = isset( $input['default_channel'] ) ? sanitize_text_field( trim( $input['default_channel'] ) ) : '';
		$clean['cache_hours']     = isset( $input['cache_hours'] ) ? max( 0, intval( $input['cache_hours'] ) ) : 6;
		$clean['default_count']   = isset( $input['default_count'] ) ? max( 0, intval( $input['default_count'] ) ) : 12;
		$clean['default_columns'] = isset( $input['default_columns'] ) ? max( 1, min( 6, intval( $input['default_columns'] ) ) ) : 4;
		$clean['default_per_page']= isset( $input['default_per_page'] ) ? max( 1, intval( $input['default_per_page'] ) ) : 12;
		return $clean;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = $this->get_settings();
		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ycv_clear_cache' ),
			'ycv_clear_cache'
		);
		?>
		<div class="wrap">
			<h1>YouTube Channel Videos — Settings</h1>

			<?php if ( isset( $_GET['ycv_cache_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Cache cleared.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ycv_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ycv_api_key">YouTube Data API v3 Key</label></th>
						<td>
							<input type="text" id="ycv_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
								value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description">
								Create a key in the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>
								with the <strong>YouTube Data API v3</strong> enabled.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ycv_default_channel">Default Channel</label></th>
						<td>
							<input type="text" id="ycv_default_channel" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_channel]"
								value="<?php echo esc_attr( $s['default_channel'] ); ?>" class="regular-text"
								placeholder="@example or UCxxxxxxxxxxxxxxxxxxxxxx" />
							<p class="description">Used when the shortcode is called without a <code>channel</code> attribute.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ycv_cache_hours">Cache Duration (hours)</label></th>
						<td>
							<input type="number" min="0" id="ycv_cache_hours" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_hours]"
								value="<?php echo esc_attr( $s['cache_hours'] ); ?>" class="small-text" />
							<p class="description">How long fetched video lists are cached (transients). 0 disables caching — not recommended, burns API quota fast.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ycv_default_count">Default Video Count (total)</label></th>
						<td>
							<input type="number" min="0" id="ycv_default_count" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_count]"
								value="<?php echo esc_attr( $s['default_count'] ); ?>" class="small-text" />
							<p class="description">0 = show every fetched video, spread across pages. A number here caps the total pool before it gets paginated.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ycv_default_per_page">Default Videos Per Page</label></th>
						<td>
							<input type="number" min="1" id="ycv_default_per_page" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_per_page]"
								value="<?php echo esc_attr( $s['default_per_page'] ); ?>" class="small-text" />
							<p class="description">How many videos appear on each pagination page.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ycv_default_columns">Default Grid Columns</label></th>
						<td>
							<input type="number" min="1" max="6" id="ycv_default_columns" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_columns]"
								value="<?php echo esc_attr( $s['default_columns'] ); ?>" class="small-text" />
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr />
			<h2>Cache</h2>
			<p><a href="<?php echo esc_url( $clear_url ); ?>" class="button">Clear Cached Videos</a></p>

			<hr />
			<h2>Shortcode Usage</h2>
			<p>Basic:</p>
			<pre>[youtube_channel_videos channel="@example"]</pre>
			<p>All attributes:</p>
			<pre>[youtube_channel_videos channel="@example" count="0" per_page="12" columns="4" order="date" show_desc="no"]</pre>
			<table class="widefat striped" style="max-width:700px">
				<thead><tr><th>Attribute</th><th>Default</th><th>Notes</th></tr></thead>
				<tbody>
					<tr><td><code>channel</code></td><td>setting above</td><td>@handle (e.g. <code>@example</code>) or a raw Channel ID (<code>UC...</code>)</td></tr>
					<tr><td><code>count</code></td><td><?php echo esc_html( $s['default_count'] ); ?></td><td>Total videos in the pool before pagination. 0 = every video fetched</td></tr>
					<tr><td><code>per_page</code></td><td><?php echo esc_html( $s['default_per_page'] ); ?></td><td>Videos shown per pagination page</td></tr>
					<tr><td><code>columns</code></td><td><?php echo esc_html( $s['default_columns'] ); ?></td><td>1–6</td></tr>
					<tr><td><code>order</code></td><td>date</td><td><code>date</code> (newest first) or <code>oldest</code></td></tr>
					<tr><td><code>show_desc</code></td><td>no</td><td><code>yes</code> to show a short description under each title</td></tr>
				</tbody>
			</table>
			<p class="description">Pagination reloads the page with a <code>?ycv_page=N</code> query var (namespaced per shortcode instance if you use more than one on a page), so it works with no JavaScript and reuses the same cached video list.</p>
		</div>
		<?php
	}

	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ycv_clear_cache' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ycv\_%' OR option_name LIKE '\_transient\_timeout\_ycv\_%'"
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=ycv-settings&ycv_cache_cleared=1' ) );
		exit;
	}

	/* -----------------------------------------------------------
	 * YouTube API
	 * --------------------------------------------------------- */

	/**
	 * Resolve a channel identifier (@handle or UC... ID) to its uploads playlist ID.
	 * Cached for 30 days since this almost never changes.
	 */
	private function get_uploads_playlist_id( $channel, $api_key ) {
		$cache_key = 'ycv_uploads_' . md5( $channel );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$channel = trim( $channel );
		$args    = array(
			'part' => 'contentDetails',
			'key'  => $api_key,
		);

		if ( preg_match( '/^UC[A-Za-z0-9_-]{10,}$/', $channel ) ) {
			$args['id'] = $channel;
		} else {
			// Treat as a handle, with or without the leading @.
			$handle             = ltrim( $channel, '@' );
			$args['forHandle']  = '@' . $handle;
		}

		$url  = self::API_BASE . '/channels?' . http_build_query( $args );
		$body = $this->api_get( $url );

		if ( is_wp_error( $body ) || empty( $body['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ) ) {
			return is_wp_error( $body ) ? $body : new WP_Error( 'ycv_no_channel', 'Could not find a YouTube channel for "' . esc_html( $channel ) . '".' );
		}

		$uploads_id = $body['items'][0]['contentDetails']['relatedPlaylists']['uploads'];

		// Uploads playlist ID is effectively permanent for a channel.
		set_transient( $cache_key, $uploads_id, 30 * DAY_IN_SECONDS );

		return $uploads_id;
	}

	/**
	 * Fetch every video from the channel's uploads playlist, paginating through
	 * playlistItems until YouTube stops returning a nextPageToken.
	 */
	private function get_all_videos( $channel, $api_key, $cache_hours ) {
		$cache_key = 'ycv_videos_' . md5( $channel );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$uploads_id = $this->get_uploads_playlist_id( $channel, $api_key );
		if ( is_wp_error( $uploads_id ) ) {
			return $uploads_id;
		}

		$videos     = array();
		$page_token = '';
		$safety_cap = 50; // 50 pages * 50 items = up to 2500 videos; raise if a channel genuinely has more.

		do {
			$args = array(
				'part'       => 'snippet',
				'playlistId' => $uploads_id,
				'maxResults' => 50,
				'key'        => $api_key,
			);
			if ( $page_token ) {
				$args['pageToken'] = $page_token;
			}

			$url  = self::API_BASE . '/playlistItems?' . http_build_query( $args );
			$body = $this->api_get( $url );

			if ( is_wp_error( $body ) ) {
				// Return whatever we already collected rather than failing outright,
				// but only if we have something; otherwise bubble up the error.
				if ( ! empty( $videos ) ) {
					break;
				}
				return $body;
			}

			foreach ( (array) $body['items'] as $item ) {
				$snippet = $item['snippet'];
				// Skip private/deleted entries, which YouTube returns with a placeholder title.
				if ( empty( $snippet['resourceId']['videoId'] ) || $snippet['title'] === 'Private video' || $snippet['title'] === 'Deleted video' ) {
					continue;
				}
				$video_id  = $snippet['resourceId']['videoId'];
				$thumbs    = $snippet['thumbnails'];
				$thumb_url = isset( $thumbs['maxres'] ) ? $thumbs['maxres']['url']
					: ( isset( $thumbs['high'] ) ? $thumbs['high']['url']
					: ( isset( $thumbs['medium'] ) ? $thumbs['medium']['url'] : $thumbs['default']['url'] ) );

				$videos[] = array(
					'id'          => $video_id,
					'title'       => $snippet['title'],
					'description' => $snippet['description'],
					'published'   => $snippet['publishedAt'],
					'thumbnail'   => $thumb_url,
					'url'         => 'https://www.youtube.com/watch?v=' . $video_id,
				);
			}

			$page_token = isset( $body['nextPageToken'] ) ? $body['nextPageToken'] : '';
			$safety_cap--;

		} while ( $page_token && $safety_cap > 0 );

		if ( $cache_hours > 0 ) {
			set_transient( $cache_key, $videos, $cache_hours * HOUR_IN_SECONDS );
		}

		return $videos;
	}

	private function api_get( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'YouTube API request failed (HTTP ' . $code . ').';
			return new WP_Error( 'ycv_api_error', $message );
		}

		return $body;
	}

	/* -----------------------------------------------------------
	 * Shortcode + rendering
	 * --------------------------------------------------------- */

	public function render_shortcode( $atts ) {
		$s = $this->get_settings();

		$atts = shortcode_atts(
			array(
				'channel'   => $s['default_channel'],
				'count'     => $s['default_count'],
				'columns'   => $s['default_columns'],
				'order'     => 'date',
				'show_desc' => 'no',
				'per_page'  => $s['default_per_page'],
			),
			$atts,
			'youtube_channel_videos'
		);

		if ( empty( $s['api_key'] ) ) {
			return $this->admin_notice( 'YouTube Channel Videos: no API key set. Add one under Settings → YouTube Channel Videos.' );
		}
		if ( empty( $atts['channel'] ) ) {
			return $this->admin_notice( 'YouTube Channel Videos: no channel specified. Add a channel="@handle" attribute or set a default channel in Settings.' );
		}

		$videos = $this->get_all_videos( $atts['channel'], $s['api_key'], intval( $s['cache_hours'] ) );

		if ( is_wp_error( $videos ) ) {
			return $this->admin_notice( 'YouTube Channel Videos: ' . $videos->get_error_message() );
		}
		if ( empty( $videos ) ) {
			return '<p class="ycv-empty">No videos found.</p>';
		}

		if ( strtolower( $atts['order'] ) === 'oldest' ) {
			$videos = array_reverse( $videos );
		}

		$count = intval( $atts['count'] );
		if ( $count > 0 ) {
			$videos = array_slice( $videos, 0, $count );
		}

		$columns   = max( 1, min( 6, intval( $atts['columns'] ) ) );
		$show_desc = strtolower( $atts['show_desc'] ) === 'yes';

		/* ---------- Pagination ---------- */
		self::$instance_count++;
		$page_param = 'ycv_page' . ( self::$instance_count > 1 ? '_' . self::$instance_count : '' );

		$per_page    = max( 1, intval( $atts['per_page'] ) );
		$total_items = count( $videos );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

		$current_page = isset( $_GET[ $page_param ] ) ? intval( $_GET[ $page_param ] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- plain pagination read, no state change.
		$current_page = max( 1, min( $total_pages, $current_page ) );

		$offset = ( $current_page - 1 ) * $per_page;
		$videos = array_slice( $videos, $offset, $per_page ); // Overwritten on purpose: content.php only ever sees the current page's slice.

		$pagination_html = $this->get_pagination_html( $page_param, $current_page, $total_pages );

		// $videos, $columns, $show_desc and $pagination_html are picked up by
		// includes/content.php since an include() shares the including method's
		// local scope.
		ob_start();
		include YCV_PLUGIN_DIR . 'includes/content.php';
		return ob_get_clean();
	}

	/**
	 * Build accessible pagination markup (a <ul class="page-numbers"> list) for
	 * the current shortcode instance, using WordPress's own paginate_links().
	 * Returns '' when there is nothing to paginate.
	 */
	private function get_pagination_html( $page_param, $current_page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return '';
		}

		$current_url = home_url( add_query_arg( null, null ) );
		$base_url    = add_query_arg( $page_param, '%#%', $current_url );

		return paginate_links(
			array(
				'base'      => $base_url,
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
				'type'      => 'list',
			)
		);
	}

	private function admin_notice( $message ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return ''; // Fail silently for regular visitors.
		}
		return '<p style="color:#b32d2e;border:1px solid #b32d2e;padding:8px 12px;border-radius:4px;">' . esc_html( $message ) . '</p>';
	}

	/* -----------------------------------------------------------
	 * Assets
	 * --------------------------------------------------------- */

	public function maybe_enqueue_assets() {
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'youtube_channel_videos' ) ) {
			wp_enqueue_style(
				'ycv-style',
				YCV_PLUGIN_URL . 'assets/style.css',
				array(),
				self::VERSION
			);
		}
	}
}

YCV_YouTube_Channel_Videos::instance();
