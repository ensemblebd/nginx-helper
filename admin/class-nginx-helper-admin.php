<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://rtcamp.com/nginx-helper/
 * @since      2.0.0
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 */

use EasyCache\Cloudflare_Client;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 * @author     rtCamp
 */
class Nginx_Helper_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    2.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    2.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Various settings tabs.
	 *
	 * @since    2.0.0
	 * @access   private
	 * @var      string    $settings_tabs    Various settings tabs.
	 */
	private $settings_tabs;

	/**
	 * Purge options.
	 *
	 * @since    2.0.0
	 * @access   public
	 * @var      string[]    $options    Purge options.
	 */
	public $options;

	/**
	 * Purge options.
	 *
	 * @since    2.0.0
	 * @access   public
	 * @var      string[] $options Cloudflare options.
	 */
	public $cf_options;

	/**
	 * WP-CLI Command.
	 *
	 * @since    2.0.0
	 * @access   public
	 * @var      string    $options    WP-CLI Command.
	 */
	const WP_CLI_COMMAND = 'nginx-helper';

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2.0.0
	 * @param      string $plugin_name       The name of this plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->options    = $this->nginx_helper_settings();
		$this->cf_options = $this->get_cloudflare_settings();
	}

	/**
	 * Initialize the settings tab.
	 * Required since i18n is used in the settings tab which can be invoked only after init hook since WordPress 6.7
	 */
	public function initialize_setting_tab() {

		/**
		 * Define settings tabs
		 */
		$this->settings_tabs = apply_filters(
				'rt_nginx_helper_settings_tabs',
				array(
						'general'    => array(
								'menu_title' => __( 'General', 'nginx-helper' ),
								'menu_slug'  => 'general',
						),
						'support'    => array(
								'menu_title' => __( 'Support', 'nginx-helper' ),
								'menu_slug'  => 'support',
						),
						'cloudflare' => array(
								'menu_title' => __( 'Cloudflare', 'nginx-helper' ),
								'menu_slug'  => 'cloudflare',
						),
						'preload' => array(
							'menu_title' => __( 'Preload', 'nginx-helper' ),
							'menu_slug'  => 'preload',
						),
				)
		);
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    2.0.0
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_styles( $hook ) {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Nginx_Helper_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Nginx_Helper_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		// Handle both regular and network admin pages.
		$valid_hooks = array( 'settings_page_nginx', 'settings_page_nginx-network' );
		if ( ! in_array( $hook, $valid_hooks, true ) ) {
			return;
		}

		wp_enqueue_style( $this->plugin_name . '-icons', plugin_dir_url( __FILE__ ) . 'icons/css/nginx-fontello.css', array(), $this->version, 'all' );
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/nginx-helper-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    2.0.0
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( $hook ) {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Nginx_Helper_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Nginx_Helper_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		// Handle both regular and network admin pages.
		$valid_hooks = array( 'settings_page_nginx', 'settings_page_nginx-network' );
		if ( ! in_array( $hook, $valid_hooks, true ) ) {
			return;
		}

		// Enqueue jQuery UI Tabs and Dialog (bundled with WordPress).
		wp_enqueue_script( 'jquery-ui-tabs' );
		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		// Enqueue List.js for table search/filter/pagination.
		wp_enqueue_script(
			'list-js',
			plugin_dir_url( __FILE__ ) . 'js/vendor/list.min.js',
			array(),
			'2.3.1',
			true
		);

		$rand_hash = rand( 1000, 9999 );
		// Load main script in footer (true) to ensure all dependencies are ready.
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/nginx-helper-admin.js', array( 'jquery', 'jquery-ui-tabs', 'jquery-ui-dialog', 'list-js' ), $this->version . $rand_hash, true );

		$do_localize = array(
			'purge_confirm_string' => esc_html__( 'Purging entire cache is not recommended. Would you like to continue?', 'nginx-helper' ),
			'preload_nonce'        => wp_create_nonce( 'nginx_helper_preload' ),
			'ajax_url'             => admin_url( 'admin-ajax.php' ),
			'i18n'                 => array(
				'loading'          => esc_html__( 'Loading...', 'nginx-helper' ),
				'error'            => esc_html__( 'An error occurred.', 'nginx-helper' ),
				'confirm_stop'     => esc_html__( 'Are you sure you want to stop the preload?', 'nginx-helper' ),
				'rescan_complete'  => esc_html__( 'Rescan complete.', 'nginx-helper' ),
				'preload_started'  => esc_html__( 'Preload started.', 'nginx-helper' ),
				'preload_stopped'  => esc_html__( 'Preload stopped.', 'nginx-helper' ),
				'preload_complete' => esc_html__( 'Preload complete!', 'nginx-helper' ),
			),
		);
		wp_localize_script( $this->plugin_name, 'nginx_helper', $do_localize );

	}

	/**
	 * Add admin menu.
	 *
	 * @since    2.0.0
	 */
	public function nginx_helper_admin_menu() {

		if ( is_multisite() ) {

			add_submenu_page(
				'settings.php',
				__( 'Nginx Helper', 'nginx-helper' ),
				__( 'Nginx Helper', 'nginx-helper' ),
				'manage_options',
				'nginx',
				array( &$this, 'nginx_helper_setting_page' )
			);

		} else {

			add_submenu_page(
				'options-general.php',
				__( 'Nginx Helper', 'nginx-helper' ),
				__( 'Nginx Helper', 'nginx-helper' ),
				'manage_options',
				'nginx',
				array( &$this, 'nginx_helper_setting_page' )
			);

		}

	}

	/**
	 * Function to add toolbar purge link.
	 *
	 * @param object $wp_admin_bar Admin bar object.
	 */
	public function nginx_helper_toolbar_purge_link( $wp_admin_bar ) {

		if ( ! current_user_can( 'Nginx Helper | Purge cache' ) ) {
			return;
		}

		if ( is_admin() ) {
			$nginx_helper_urls = 'all';
			$link_title        = __( 'Purge Cache', 'nginx-helper' );
		} else {
			$nginx_helper_urls = 'current-url';
			$link_title        = __( 'Purge Current Page', 'nginx-helper' );
		}

		$purge_url = add_query_arg(
			array(
				'nginx_helper_action'  => 'purge',
				'nginx_helper_urls'    => $nginx_helper_urls,
				'nginx_helper_dismiss' => get_transient( 'rt_wp_nginx_helper_suggest_purge_notice' ),
			)
		);

		$nonced_url = wp_nonce_url( $purge_url, 'nginx_helper-purge_all' );

		$wp_admin_bar->add_menu(
			array(
				'id'    => 'nginx-helper-purge-all',
				'title' => $link_title,
				'href'  => $nonced_url,
				'meta'  => array( 'title' => $link_title ),
			)
		);

	}

	/**
	 * Display settings.
	 *
	 * @global $string $pagenow Contain current admin page.
	 *
	 * @since    2.0.0
	 */
	public function nginx_helper_setting_page() {
		include plugin_dir_path( __FILE__ ) . 'partials/nginx-helper-admin-display.php';
	}

	/**
	 * Default settings.
	 *
	 * @since    2.0.0
	 * @return array
	 */
	public function nginx_helper_default_settings() {

		return array(
			'enable_purge'                     => 0,
			'cache_method'                     => 'enable_fastcgi',
			'purge_method'                     => 'get_request',
			'enable_map'                       => 0,
			'enable_log'                       => 0,
			'log_level'                        => 'INFO',
			'log_filesize'                     => '5',
			'enable_stamp'                     => 0,
			'purge_homepage_on_edit'           => 1,
			'purge_homepage_on_del'            => 1,
			'purge_archive_on_edit'            => 1,
			'purge_archive_on_del'             => 1,
			'purge_archive_on_new_comment'     => 0,
			'purge_archive_on_deleted_comment' => 0,
			'purge_page_on_mod'                => 1,
			'purge_page_on_new_comment'        => 1,
			'purge_page_on_deleted_comment'    => 1,
			'purge_feeds'                      => 1,
			'redis_hostname'                   => '127.0.0.1',
			'redis_port'                       => '6379',
			'redis_prefix'                      => 'nginx-cache:',
			'redis_unix_socket'                => '',
			'redis_database'                   => 0,
			'redis_username'                   => '',
			'redis_password'                   => '',
			'purge_url'                        => '',
			'redis_enabled_by_constant'        => 0,
			'purge_amp_urls'                   => 1,
			'redis_socket_enabled_by_constant' => 0,
			'redis_acl_enabled_by_constant'    => 0,
			'preload_cache'                    => 0,
			'is_cache_preloaded'               => 0,
			'roles_with_purge_cap'             => array(),
			'purge_woo_products'               => 0,
			// Preload tab settings.
			'preload_mode'                     => 'off',
			'preload_cron_schedule'            => '0 3 * * *',
			'preload_user_agent'               => 'NginxHelper-Preloader/1.0',
			'preload_custom_headers'           => '',
			'preload_cache_variants'           => 1,
			'preload_force_ssl'                => 1,
			// FastCGI Configuration.
			'fastcgi_cache_key_template'       => '',
			'fastcgi_cache_path_root'          => '',
			'fastcgi_custom_var_values'        => '{}',
		);

	}

	public function store_default_options() {
		$options = get_site_option( 'rt_wp_nginx_helper_options', array() );
		$default_settings = $this->nginx_helper_default_settings();

		$removable_default_settings = array(
			'redis_port',
			'redis_prefix',
			'redis_hostname',
			'redis_database',
			'redis_unix_socket'
		);

		// Remove all the keys that are not to be stored by default.
		foreach ( $removable_default_settings as $removable_key ) {
			unset( $default_settings[ $removable_key ] );
		}

		$diffed_options = wp_parse_args( $options, $default_settings );

		add_site_option( 'rt_wp_nginx_helper_options', $diffed_options );

		$this->store_cloudflare_settings();
	}

	/**
	 * Gets the default settings for cloudflare.
	 *
	 * @return array An array of settings.
	 */
	public function get_cloudflare_default_settings() {
		return array(
			'api_token'                     => '',
			'zone_id'                       => '',
			'default_cache_ttl'             => 604800,
			'api_token_enabled_by_constant' => false,
		);
	}

	/**
	 * Gets the current cloudflare settings.
	 *
	 * @return array The current settings.
	 */
	public function get_cloudflare_settings() {
		$default_settings = $this->get_cloudflare_default_settings();

		$stored_options = get_site_option( 'easycache_cf_settings', array() );

		if ( defined( 'EASYCACHE_CLOUDFLARE_API_TOKEN' ) && !empty( EASYCACHE_CLOUDFLARE_API_TOKEN ) ) {
			$stored_options['api_token']                     = EASYCACHE_CLOUDFLARE_API_TOKEN;
			$stored_options['api_token_enabled_by_constant'] = true;
		}

		$diff_options = wp_parse_args( $stored_options, $default_settings );

		$diff_options['is_enabled'] = ! empty( $diff_options['api_token'] ) && ! empty( $diff_options['zone_id'] );

		return $diff_options;
	}

	/**
	 * Stores the cloudflare settings.
	 *
	 * @return array The current settings.
	 */
	public function store_cloudflare_settings() {
		$default_settings = $this->get_cloudflare_default_settings();

		$stored_options = get_site_option( 'easycache_cf_settings', array() );

		$diff_options = wp_parse_args( $stored_options, $default_settings );

		add_site_option( 'easycache_cf_settings', $diff_options );
	}

	/**
	 * Get settings.
	 *
	 * @since    2.0.0
	 */
	public function nginx_helper_settings() {

		$options = get_site_option(
			'rt_wp_nginx_helper_options',
			array(
				'redis_hostname' => '127.0.0.1',
				'redis_port'     => '6379',
				'redis_prefix'   => 'nginx-cache:',
				'redis_database' => 0,
			)
		);

		$data = wp_parse_args(
			$options,
			$this->nginx_helper_default_settings()
		);

		$is_redis_enabled = (
			defined( 'RT_WP_NGINX_HELPER_REDIS_HOSTNAME' ) &&
			defined( 'RT_WP_NGINX_HELPER_REDIS_PORT' ) &&
			defined( 'RT_WP_NGINX_HELPER_REDIS_PREFIX' )
		);

		$data['redis_acl_enabled_by_constant']    = defined('RT_WP_NGINX_HELPER_REDIS_USERNAME') && defined('RT_WP_NGINX_HELPER_REDIS_PASSWORD');
		$data['redis_socket_enabled_by_constant'] = defined('RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET');
		$data['redis_unix_socket']                = $data['redis_socket_enabled_by_constant'] ? RT_WP_NGINX_HELPER_REDIS_UNIX_SOCKET : $data['redis_unix_socket'];
		$data['redis_username']                   = $data['redis_acl_enabled_by_constant'] ? RT_WP_NGINX_HELPER_REDIS_USERNAME : $data['redis_username'];
		$data['redis_password']                   = $data['redis_acl_enabled_by_constant'] ? RT_WP_NGINX_HELPER_REDIS_PASSWORD : $data['redis_password'];

		if ( ! $is_redis_enabled ) {
			return $data;
		}

		$data['redis_enabled_by_constant']        = $is_redis_enabled;
		$data['enable_purge']                     = $is_redis_enabled;
		$data['cache_method']                     = 'enable_redis';
		$data['redis_hostname']                   = RT_WP_NGINX_HELPER_REDIS_HOSTNAME;
		$data['redis_port']                       = RT_WP_NGINX_HELPER_REDIS_PORT;
		$data['redis_prefix']                     = RT_WP_NGINX_HELPER_REDIS_PREFIX;
		$data['redis_database']                   = defined('RT_WP_NGINX_HELPER_REDIS_DATABASE') ? RT_WP_NGINX_HELPER_REDIS_DATABASE : 0;

		return $data;

	}

	/**
	 * Nginx helper setting link function.
	 *
	 * @param array $links links.
	 *
	 * @return mixed
	 */
	public function nginx_helper_settings_link( $links ) {

		if ( is_network_admin() ) {
			$setting_page = 'settings.php';
		} else {
			$setting_page = 'options-general.php';
		}

		$settings_link = '<a href="' . network_admin_url( $setting_page . '?page=nginx' ) . '">' . __( 'Settings', 'nginx-helper' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;

	}

	/**
	 * Check if the nginx log is enabled.
	 *
	 * @since 2.2.4
	 * @return    boolean
	 */
	public function is_nginx_log_enabled() {

		$options = get_site_option( 'rt_wp_nginx_helper_options', array() );

		if ( ! empty( $options['enable_log'] ) && 1 === (int) $options['enable_log'] ) {
			return true;
		}

		if ( defined( 'NGINX_HELPER_LOG' ) && true === NGINX_HELPER_LOG ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieve the asset path.
	 *
	 * @since     2.0.0
	 * @return    string    asset path of the plugin.
	 */
	public function functional_asset_path() {

		$log_path = WP_CONTENT_DIR . '/uploads/nginx-helper/';

		return apply_filters( 'nginx_asset_path', $log_path );

	}

	/**
	 * Retrieve the asset url.
	 *
	 * @since     2.0.0
	 * @return    string    asset url of the plugin.
	 */
	public function functional_asset_url() {

		$log_url = WP_CONTENT_URL . '/uploads/nginx-helper/';

		return apply_filters( 'nginx_asset_url', $log_url );

	}

	/**
	 * Get latest news.
	 *
	 * @since     2.0.0
	 */
	public function nginx_helper_get_feeds() {

		// Get RSS Feed(s).
		require_once ABSPATH . WPINC . '/feed.php';

		$maxitems  = 0;
		$rss_items = array();

		// Get a SimplePie feed object from the specified feed source.
		$rss = fetch_feed( 'https://rtcamp.com/blog/feed/' );

		if ( ! is_wp_error( $rss ) ) { // Checks that the object is created correctly.

			// Figure out how many total items there are, but limit it to 5.
			$maxitems = $rss->get_item_quantity( 5 );
			// Build an array of all the items, starting with element 0 (first element).
			$rss_items = $rss->get_items( 0, $maxitems );

		}
		?>
		<ul role="list">
			<?php
			if ( 0 === $maxitems ) {
				echo '<li role="listitem">' . esc_html_e( 'No items', 'nginx-helper' ) . '.</li>';
			} else {

				// Loop through each feed item and display each item as a hyperlink.
				foreach ( $rss_items as $item ) {
					?>
						<li role="listitem">
							<?php
								printf(
									'<a href="%s" title="%s">%s</a>',
									esc_url( $item->get_permalink() ),
									esc_attr(
										sprintf(
											/* translators: %s: date/time the feed item as been posted */
											__( 'Posted %s', 'nginx-helper' ),
											$item->get_date( 'j F Y | g:i a' )
										)
									),
									esc_html( $item->get_title() )
								);
							?>
						</li>
					<?php
				}
			}
			?>
		</ul>
		<?php
		die();

	}

	/**
	 * Add time stamps in html.
	 */
	public function add_timestamps() {

		global $pagenow;

		if ( is_admin() || 1 !== (int) $this->options['enable_purge'] || 1 !== (int) $this->options['enable_stamp'] ) {
			return;
		}

		if ( ! empty( $pagenow ) && 'wp-login.php' === $pagenow ) {
			return;
		}

		foreach ( headers_list() as $header ) {
			list( $key, $value ) = explode( ':', $header, 2 );
			$key                 = strtolower( $key );
			if ( 'content-type' === $key && strpos( trim( $value ), 'text/html' ) !== 0 ) {
				return;
			}
			if ( 'content-type' === $key ) {
				break;
			}
		}

		/**
		 * Don't add timestamp if run from ajax, cron or wpcli.
		 */
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$timestamps = "\n<!--" .
			'Cached using Nginx-Helper on ' . current_time( 'mysql' ) . '. ' .
			'It took ' . get_num_queries() . ' queries executed in ' . timer_stop() . ' seconds.' .
			"-->\n" .
			'<!--Visit http://wordpress.org/extend/plugins/nginx-helper/faq/ for more details-->';

		echo wp_kses( $timestamps, array() );

	}

	/**
	 * Get map
	 *
	 * @global object $wpdb
	 *
	 * @return string
	 */
	public function get_map() {

		if ( ! $this->options['enable_map'] ) {
			return;
		}

		if ( is_multisite() ) {

			global $wpdb;

			$rt_all_blogs = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT blog_id, domain, path FROM ' . $wpdb->blogs . " WHERE site_id = %d AND archived = '0' AND mature = '0' AND spam = '0' AND deleted = '0'",
					$wpdb->siteid
				)
			);

			$wpdb->dmtable = $wpdb->base_prefix . 'domain_mapping';

			$rt_domain_map_sites = '';

			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->dmtable}'" ) === $wpdb->dmtable ) { // phpcs:ignore
				$rt_domain_map_sites = $wpdb->get_results( "SELECT blog_id, domain FROM {$wpdb->dmtable} ORDER BY id DESC" );
			}

			$rt_nginx_map       = '';
			$rt_nginx_map_array = array();

			if ( $rt_all_blogs ) {

				foreach ( $rt_all_blogs as $blog ) {

					if ( true === SUBDOMAIN_INSTALL ) {
						$rt_nginx_map_array[ $blog->domain ] = $blog->blog_id;
					} else {

						if ( 1 !== $blog->blog_id ) {
							$rt_nginx_map_array[ $blog->path ] = $blog->blog_id;
						}
					}
				}
			}

			if ( $rt_domain_map_sites ) {

				foreach ( $rt_domain_map_sites as $site ) {
					$rt_nginx_map_array[ $site->domain ] = $site->blog_id;
				}
			}

			foreach ( $rt_nginx_map_array as $domain => $domain_id ) {
				$rt_nginx_map .= "\t" . $domain . "\t" . $domain_id . ";\n";
			}

			return $rt_nginx_map;

		}

	}

	/**
	 * Update map
	 */
	public function update_map() {

		if ( is_multisite() ) {

			$rt_nginx_map = $this->get_map();

			$fp = fopen( $this->functional_asset_path() . 'map.conf', 'w+' );
			if ( $fp ) {
				fwrite( $fp, $rt_nginx_map );
				fclose( $fp );
			}
		}

	}

	/**
	 * Purge url when post status is changed.
	 *
	 * @global string $blog_id Blog id.
	 * @global object $nginx_purger Nginx purger variable.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $post Post object.
	 */
	public function set_future_post_option_on_future_status( $new_status, $old_status, $post ) {

		global $blog_id, $nginx_purger;

		$exclude_post_types = apply_filters( 'rt_nginx_helper_exclude_post_types', array( 'nav_menu_item' ) );

		if ( in_array( $post->post_type, $exclude_post_types, true ) ) {
			return;
		}

		if ( ! $this->options['enable_purge'] || $this->is_import_request() ) {
			return;
		}

		$purge_status = array( 'publish', 'future' );

		if ( in_array( $old_status, $purge_status, true ) || in_array( $new_status, $purge_status, true ) ) {

			$nginx_purger->log( 'Purge post on transition post STATUS from ' . $old_status . ' to ' . $new_status );
			$nginx_purger->purge_post( $post->ID );

		}

		if (
			'future' === $new_status && $post && 'future' === $post->post_status &&
			(
				( 'post' === $post->post_type || 'page' === $post->post_type ) ||
				(
					isset( $this->options['custom_post_types_recognized'] ) &&
					in_array( $post->post_type, $this->options['custom_post_types_recognized'], true )
				)
			)
		) {

			$nginx_purger->log( 'Set/update future_posts option ( post id = ' . $post->ID . ' and blog id = ' . $blog_id . ' )' );
			$this->options['future_posts'][ $blog_id ][ $post->ID ] = strtotime( $post->post_date_gmt ) + 60;
			update_site_option( 'rt_wp_nginx_helper_options', $this->options );

		}

	}

	/**
	 * Unset future post option on delete
	 *
	 * @global string $blog_id Blog id.
	 * @global object $nginx_purger Nginx helper object.
	 *
	 * @param int $post_id Post id.
	 */
	public function unset_future_post_option_on_delete( $post_id ) {

		global $blog_id, $nginx_purger;

		if (
			! $this->options['enable_purge'] ||
			empty( $this->options['future_posts'] ) ||
			empty( $this->options['future_posts'][ $blog_id ] ) ||
			isset( $this->options['future_posts'][ $blog_id ][ $post_id ] ) ||
			wp_is_post_revision( $post_id )
		) {
			return;
		}

		$nginx_purger->log( 'Unset future_posts option ( post id = ' . $post_id . ' and blog id = ' . $blog_id . ' )' );

		unset( $this->options['future_posts'][ $blog_id ][ $post_id ] );

		if ( ! count( $this->options['future_posts'][ $blog_id ] ) ) {
			unset( $this->options['future_posts'][ $blog_id ] );
		}

		update_site_option( 'rt_wp_nginx_helper_options', $this->options );
	}

	/**
	 * Update map when new blog added in multisite.
	 *
	 * @global object $nginx_purger Nginx purger class object.
	 *
	 * @param string $blog_id blog id.
	 */
	public function update_new_blog_options( $blog_id ) {

		global $nginx_purger;

		$nginx_purger->log( "New site added ( id $blog_id )" );
		$this->update_map();
		$nginx_purger->log( "New site added to nginx map ( id $blog_id )" );
		$helper_options = $this->nginx_helper_default_settings();
		update_blog_option( $blog_id, 'rt_wp_nginx_helper_options', $helper_options );
		$nginx_purger->log( "Default options updated for the new blog ( id $blog_id )" );

	}

	/**
	 * Purge all urls.
	 * Purge current page cache when purging is requested from front
	 * and all urls when requested from admin dashboard.
	 *
	 * @global object $nginx_purger
	 */
	public function purge_all() {

		if ( $this->is_import_request() ) {
			return;
		}

		global $nginx_purger, $wp;

		$method = null;
		if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$method = wp_strip_all_tags( $_SERVER['REQUEST_METHOD'] );
		}

		$action = '';
		if ( 'POST' === $method ) {
			if ( isset( $_POST['nginx_helper_action'] ) ) {
				$action = wp_strip_all_tags( $_POST['nginx_helper_action'] );
			}
		} else {
			if ( isset( $_GET['nginx_helper_action'] ) ) {
				$action = wp_strip_all_tags( $_GET['nginx_helper_action'] );
			}
		}

		if ( empty( $action ) ) {
			return;
		}

		if ( ! current_user_can( 'Nginx Helper | Purge cache' ) ) {
			wp_die( 'Sorry, you do not have the necessary privileges to edit these options.' );
		}

		if ( 'done' === $action ) {

			add_action( 'admin_notices', array( &$this, 'display_notices' ) );
			add_action( 'network_admin_notices', array( &$this, 'display_notices' ) );
			return;

		}

		check_admin_referer( 'nginx_helper-purge_all' );

		$current_url = user_trailingslashit( home_url( $wp->request ) );

		if ( ! is_admin() ) {
			$action       = 'purge_current_page';
			$redirect_url = $current_url;
		} else {
			$redirect_url = add_query_arg( array( 'nginx_helper_action' => 'done' ) );
		}

		switch ( $action ) {
			case 'purge':
				$nginx_purger->purge_all();
				break;
			case 'purge_current_page':
				$nginx_purger->purge_url( $current_url );
				break;
		}

		if ( 'purge' === $action ) {

			/**
			 * Fire an action after the entire cache has been purged whatever caching type is used.
			 *
			 * @since 2.2.2
			 */
			do_action( 'rt_nginx_helper_after_purge_all' );

		}

		if( $this->cf_options['is_enabled'] ) {
			Cloudflare_Client::purgeEverything();
		}

		wp_redirect( esc_url_raw( $redirect_url ) );
		exit();

	}

	/**
	 * Dispay plugin notices.
	 */
	public function display_notices() {
		echo '<div class="updated"><p>' . esc_html__( 'Purge initiated', 'nginx-helper' ) . '</p></div>';
	}

	/**
	 * Preloads the cache for the website.
	 *
	 * @return void
	 */
	public function preload_cache() {
		$is_cache_preloaded    = $this->options['is_cache_preloaded'];
		$preload_cache_enabled = $this->options['preload_cache'];

		if ( $preload_cache_enabled && false === boolval( $is_cache_preloaded ) ) {
			$this->options['is_cache_preloaded'] = true;

			update_site_option( 'rt_wp_nginx_helper_options', $this->options );
			$this->preload_cache_from_sitemap();
		}
	}

	/**
	 * This function preloads the cache from sitemap url.
	 *
	 * @return void
	 */
	private function preload_cache_from_sitemap() {

		$sitemap_urls = $this->get_index_sitemap_urls();
		$all_urls     = array();

		foreach ( $sitemap_urls as $sitemap_url ) {
			$urls     = $this->extract_sitemap_urls( $sitemap_url );
			$all_urls = array_merge( $all_urls, $urls );
		}

		$args = array(
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => false,
		);

		foreach ( $all_urls as $url ) {
			wp_remote_get( esc_url_raw( $url ), $args );
		}

	}

	/**
	 * Fetches all the sitemap urls for the site.
	 *
	 * @return array
	 */
	private function get_index_sitemap_urls() {
		$sitemaps = wp_sitemaps_get_server()->index->get_sitemap_list();
		$urls     = array();
		foreach ( $sitemaps as $sitemap ) {
			$urls[] = $sitemap['loc'];
		}
		return $urls;
	}

	/**
	 * Parse sitemap content and extract all URLs.
	 *
	 * @param string $sitemap_url The URL of the sitemap.
	 * @return array|WP_Error An array of URLs or WP_Error on failure.
	 */
	private function extract_sitemap_urls( $sitemap_url ) {
		$response = wp_remote_get( $sitemap_url );

		$urls = array();

		if ( is_wp_error( $response ) ) {
			return $urls;
		}

		$sitemap_content = wp_remote_retrieve_body( $response );

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $sitemap_content );

		if ( false === $xml ) {
			return new WP_Error( 'sitemap_parse_error', esc_html__( 'Failed to parse the sitemap XML', 'nginx-helper' ) );
		}

		$urls = array();

		if ( false === $xml ) {
			return $urls;
		}

		foreach ( $xml->url as $url ) {
			$urls[] = (string) $url->loc;
		}

		return $urls;
	}

	/**
	* Determines if the current request is for importing Posts/ WordPress content.
	*
	* @return bool True if the request is for importing, false otherwise.
	*/
	public function is_import_request() {
		$import_query_var   = sanitize_text_field( wp_unslash( $_GET['import'] ?? '' ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is already in the admin dashboard.
		$has_import_started = did_action( 'import_start' );

		return ( defined( 'WP_IMPORTING' ) && true === WP_IMPORTING )
			|| 0 !== $has_import_started
			|| ! empty( $import_query_var );
	}

	/**
	 * Sync purge capability with selected roles.
	 */
	public function nginx_helper_update_role_caps() {
		$purge_cap = 'Nginx Helper | Purge cache';

		// Get all available roles.
		$all_roles    = wp_roles()->get_names();
		$site_options = get_site_option( 'rt_wp_nginx_helper_options', array() );

		// Roles selected in settings.
		$selected_roles = isset( $site_options['roles_with_purge_cap'] ) && is_array( $site_options['roles_with_purge_cap'] )
			? $site_options['roles_with_purge_cap']
			: array();

		foreach ( $all_roles as $role_key => $role_name ) {
			$role = get_role( $role_key );

			if ( ! $role || 'administrator' === $role_key ) {
				continue;
			}

			// If role is NOT selected, remove cap and continue.
			if ( ! isset( $selected_roles[ $role_key ] ) ) {
				$role->remove_cap( $purge_cap );
				continue;
			}

			// If selected, make sure cap is added.
			$role->add_cap( $purge_cap );
		}
	}

	/**
	 * Automatically purges Nginx cache on any WordPress core, plugin, or theme update if enabled.
	 *
	 * @param WP_Upgrader $upgrader_object WP_Upgrader instance.
	 * @param array       $options Array of bulk item update data.
	 */
	public function nginx_helper_auto_purge_on_any_update( $upgrader_object, $options ) {

		if ( ! isset( $options['action'], $options['type'] )
			|| 'update' !== $options['action']
			|| ! in_array( $options['type'], array( 'core', 'plugin', 'theme' ), true ) ) {
			return;
		}
		if ( ! defined( 'NGINX_HELPER_AUTO_PURGE_ON_ANY_UPDATE' ) || ! NGINX_HELPER_AUTO_PURGE_ON_ANY_UPDATE ) {
			set_transient( 'rt_wp_nginx_helper_suggest_purge_notice', true, HOUR_IN_SECONDS );
			return;
		}
		global $nginx_purger;

		$nginx_purger->purge_all();
	}

	/**
	 * Displays an admin notice suggesting the user to purge cache after a WordPress update.
	 */
	public function suggest_purge_after_update() {

		if ( ! get_transient( 'rt_wp_nginx_helper_suggest_purge_notice' ) ) {
			return;
		}

		$setting_page  = is_network_admin() ? 'settings.php' : 'options-general.php';
		$settings_link = network_admin_url( $setting_page . '?page=nginx' );
		$dismiss_url   = wp_nonce_url( add_query_arg( 'nginx_helper_dismiss', 'true' ), 'nginx_helper_dismiss_notice' );
		?>
		<div class="notice notice-info">
			<p>
				<?php
				esc_html_e( 'A WordPress update was detected. It is recommended to purge the cache to ensure your site displays the latest changes.', 'nginx-helper' );
				?>
				<a href="<?php echo esc_url( $settings_link ); ?>"><?php esc_html_e( 'Go & Purge Cache', 'nginx-helper' ); ?></a>
				|
				<a href="<?php echo esc_url( $dismiss_url ); ?>">
				<?php esc_html_e( 'Dismiss', 'nginx-helper' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismisses the "suggest purge" admin notice when the user clicks the dismiss link.
	 */
	public function dismiss_suggest_purge_after_update() {

		if ( ! isset( $_GET['nginx_helper_dismiss'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}

		$dismiss          = sanitize_text_field( wp_unslash( $_GET['nginx_helper_dismiss'] ) );
		$nonce            = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		// Verify the correct nonce depending on whether this is a purge+dismiss or dismiss-only request.
		$has_purge_params = isset( $_GET['nginx_helper_action'], $_GET['nginx_helper_urls'] );
		$nonce_verified   = $has_purge_params ? wp_verify_nonce( $nonce, 'nginx_helper-purge_all' ) : wp_verify_nonce( $nonce, 'nginx_helper_dismiss_notice' );

		if ( $dismiss && $nonce_verified ) {

			delete_transient( 'rt_wp_nginx_helper_suggest_purge_notice' );
			wp_safe_redirect( remove_query_arg( array( 'nginx_helper_dismiss', '_wpnonce' ) ) );
			exit;
		}
	}

	/**
	 * Initialize WooCommerce hooks if enabled.
	 *
	 * @since 2.3.5
	 */
	public function init_woocommerce_hooks() {
		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) || empty( $this->options['purge_woo_products'] ) ) {
			return;
		}

		add_action( 'woocommerce_reduce_order_stock', array( $this, 'purge_product_cache_on_purchase' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'purge_product_cache_on_update' ), 10, 1 );
	}

	/**
	 * Purge product cache when order stock is reduced (purchase).
	 *
	 * @since  2.3.5
	 * @global object $nginx_purger Nginx purger object.
	 * @param  object $order Order object.
	 */
	public function purge_product_cache_on_purchase( $order ) {

		global $nginx_purger;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->options['enable_purge'] ) {
			return;
		}

		$nginx_purger->log( 'WooCommerce order stock reduction - purging product caches' );

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$product_id = $product->get_id();
			$nginx_purger->log( 'Purging cache for product ID: ' . $product_id . ' due to purchase' );

			$product_url = get_permalink( $product_id );

			if ( $product_url ) {
				$nginx_purger->purge_url( $product_url );
			}
		}
	}

	/**
	 * Purge product cache when a product is updated via REST API.
	 *
	 * @since 2.3.5
	 * @global object $nginx_purger Nginx purger object.
	 * @param int $product_id Product ID.
	 */
	public function purge_product_cache_on_update( $product_id ) {
		global $nginx_purger;

		if ( empty( $nginx_purger ) ) {
			return;
		}

		if ( ! $this->options['enable_purge'] ) {
			return;
		}

		$nginx_purger->log( 'WooCommerce product update - purging cache for product ID: ' . $product_id );

		$product_url = get_permalink( $product_id );

		if ( $product_url ) {
			$nginx_purger->purge_url( $product_url );
		}
	}

	/**
	 * Handles the cache rule update on Cloudflare tab.
	 *
	 * @return void
	 */
	public function handle_cf_cache_rule_update() {
		$nonce = isset( $_POST['easycache_add_cache_rule_nonce'] ) ? wp_unslash( $_POST['easycache_add_cache_rule_nonce'] ) : '';

		if ( wp_verify_nonce( $nonce, 'easycache_add_cache_rule_nonce' ) ) {

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$result = EasyCache\Cloudflare_Client::setupCacheRule();

			set_transient( 'ec_page_rule_save_state_admin_notice', $result, 60 );
		}
	}

	/**
	 * Display admin notices for cloudflare page save rules.
	 */
	public function cf_page_rule_save_display_admin_notices() {
		if ( $result = get_transient( 'ec_page_rule_save_state_admin_notice' ) ) {
			$class   = 'notice';
			$message = '';

			switch ( $result ) {
				case 'created':
					$class   .= ' notice-success';
					$message = __( 'The Cloudflare Cache Rule was created successfully.', 'nginx-helper' );
					break;
				case 'exists':
					$class   .= ' notice-info';
					$message = __( 'The Cache Rule already exists. No action was taken.', 'nginx-helper' );
					break;
				default:
					$class   .= ' notice-error';
					$message = __( 'Failed to create the Cache Rule. Please check that your API Token has Cache Rules Read/Write permissions.', 'nginx-helper' );
					break;
			}

			printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			delete_transient( 'ec_page_rule_save_state_admin_notice' );
		}
	}

	/**
	 * Register a toolbar button to purge the cache for the current page.
	 *
	 * @param object $wp_admin_bar Instance of WP_Admin_Bar.
	 */
	public static function add_cloudflare_admin_bar_purge( $wp_admin_bar ) {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! empty( $_GET['message'] ) && 'ec-cleared-url-cache' === $_GET['message'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$title = esc_html__( 'URL Cache Cleared', 'easycache' );
		} else {
			$title = esc_html__( 'Clear URL Cache', 'easycache' );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';
		$wp_admin_bar->add_menu( [
			'parent' => '',
			'id'     => 'clear-page-cache',
			'title'  => $title,
			'meta'   => [
				'title' => __( 'Purge the current URL from Cloudflare cache.', 'easycache' ),
			],
			'href'   => wp_nonce_url( admin_url( 'admin-ajax.php?action=ec_clear_url_cache&path=' . rawurlencode( home_url( $request_uri ) ) ), 'ec-clear-url-cache' ),
		] );
	}

	/**
	 * Handle an admin-ajax request to clear the URL cache for Cloudflare.
	 *
	 * @return void
	 */
	public static function handle_cloudflare_clear_cache_ajax() {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( $_GET['_wpnonce'] ) : '';
		if ( empty( $nonce )
			 || ! wp_verify_nonce( $nonce, 'ec-clear-url-cache' )
			 || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( "You shouldn't be doing this.", 'easycache' ) );
		}

		$path = isset( $_GET['path'] ) ? esc_url_raw( $_GET['path'] ) : '';
		if ( empty( $path ) ) {
			wp_die( esc_html__( 'No path provided.', 'easycache' ) );
		}

		$ret = Cloudflare_Client::purgeByUrls( [ $path ] );
		if ( ! $ret ) {
			wp_die( esc_html__( 'Failed to clear URL cache.', 'easycache' ) );
		}

		wp_safe_redirect( add_query_arg( 'message', 'ec-cleared-url-cache', $path ) );
		exit;
	}


	
	/**
	 * Get the Preload Cache Manager instance.
	 *
	 * @return Preload_Cache_Manager
	 */
	public function get_preload_manager() {
		if ( ! class_exists( 'Preload_Cache_Manager' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-preload-cache-manager.php';
		}
		return Preload_Cache_Manager::get_instance();
	}
	
	/**
	 * AJAX handler: Start preload.
	 */
	public function ajax_preload_start() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$manager->reset_preload();
		
		// Check if WP Cron is disabled.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			// Use async processing.
			$this->trigger_async_preload();
		} else {
			// Schedule immediate cron event.
			wp_schedule_single_event( time(), 'nginx_helper_preload_batch' );
		}
		
		// Start first batch immediately.
		$progress = $manager->preload_all_pages( 5 );
		
		wp_send_json_success( $progress );
	}
	
	/**
	 * AJAX handler: Stop preload.
	 */
	public function ajax_preload_stop() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$manager->stop_preload();
		
		// Clear any scheduled batch events.
		$timestamp = wp_next_scheduled( 'nginx_helper_preload_batch' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'nginx_helper_preload_batch' );
		}
		
		wp_send_json_success( array( 'status' => 'stopped' ) );
	}
	
	/**
	 * AJAX handler: Get preload progress.
	 */
	public function ajax_preload_progress() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$progress = $manager->get_preload_progress();
		
		wp_send_json_success( $progress );
	}
	
	/**
	 * AJAX handler: Preload single page.
	 */
	public function ajax_preload_single() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$results = $manager->reset_page( $url );
		
		wp_send_json_success( $results );
	}
	
	/**
	 * AJAX handler: Toggle page enabled status.
	 */
	public function ajax_toggle_page() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) ? (bool) $_POST['enabled'] : true;
		
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$result = $manager->toggle_page( $url, $enabled );
		
		if ( $result ) {
			wp_send_json_success( array( 'enabled' => $enabled ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update page status.', 'nginx-helper' ) ) );
		}
	}
	
	/**
	 * AJAX handler: Rescan cache status.
	 */
	public function ajax_rescan_cache() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$result = $manager->sync_sitemap_to_snapshot();
		
		if ( $result ) {
			$snapshot = $manager->get_snapshot();
			wp_send_json_success( array(
				'last_scan' => $snapshot['last_scan'],
				'page_count' => count( $snapshot['pages'] ),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to rescan cache status.', 'nginx-helper' ) ) );
		}
	}
	
	/**
	 * AJAX handler: Continue preload batch.
	 */
	public function ajax_preload_continue() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		
		if ( ! $manager->is_preload_running() ) {
			wp_send_json_success( array( 'status' => 'completed' ) );
			return;
		}
		
		$progress = $manager->preload_all_pages( 5 );
		
		wp_send_json_success( $progress );
	}
	
	/**
	 * Trigger async preload request.
	 */
	private function trigger_async_preload() {
		$url = admin_url( 'admin-ajax.php' );
		
		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'body'      => array(
				'action' => 'nginx_helper_preload_continue',
				'nonce'  => wp_create_nonce( 'nginx_helper_preload' ),
			),
		);
		
		wp_remote_post( $url, $args );
	}
	
	/**
	 * Handle preload batch via WP Cron.
	 */
	public function handle_preload_batch() {
		$manager = $this->get_preload_manager();
		
		if ( ! $manager->is_preload_running() ) {
			return;
		}
		
		$progress = $manager->preload_all_pages( 10 );
		
		// Schedule next batch if not complete.
		if ( 'running' === $progress['status'] ) {
			wp_schedule_single_event( time() + 1, 'nginx_helper_preload_batch' );
		}
	}
	
	/**
	 * Handle scheduled cron preload.
	 */
	public function handle_cron_preload() {
		$manager = $this->get_preload_manager();
		
		// Only run if mode is cron.
		if ( 'cron' !== $manager->get_preload_mode() ) {
			return;
		}
		
		// Reset and start fresh.
		$manager->reset_preload();
		
		// Process all pages in batches.
		do {
			$progress = $manager->preload_all_pages( 20 );
		} while ( 'running' === $progress['status'] );
	}
	
	/**
	 * Preload single page on post publish/edit (reactive mode).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function preload_on_post_change( $post_id, $post ) {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		// Only published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		
		$manager = $this->get_preload_manager();
		
		// Only in reactive mode.
		if ( 'reactive' !== $manager->get_preload_mode() ) {
			return;
		}
		
		$url = get_permalink( $post_id );
		$relative_url = $manager->get_relative_url( $url );
		
		// Preload all variants for this page.
		$manager->reset_page( $relative_url );
	}
	
	/**
	 * Preload all pages after full cache purge (reactive mode).
	 */
	public function preload_after_purge_all() {
		$manager = $this->get_preload_manager();
		
		// Only in reactive mode.
		if ( 'reactive' !== $manager->get_preload_mode() ) {
			return;
		}
		
		// Reset and start preload.
		$manager->reset_preload();
		
		// Check if WP Cron is disabled.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			// Use async processing.
			$this->trigger_async_preload();
		} else {
			// Schedule immediate cron event.
			wp_schedule_single_event( time(), 'nginx_helper_preload_batch' );
		}
	}
	
	/**
	 * Display admin notice when preload is running.
	 */
	public function display_preload_notice() {
		$manager = $this->get_preload_manager();
		
		if ( ! $manager->is_preload_running() ) {
			return;
		}
		
		$progress = $manager->get_preload_progress();
		$settings_url = admin_url( 'options-general.php?page=nginx&tab=preload' );
		
		if ( is_multisite() ) {
			$settings_url = network_admin_url( 'settings.php?page=nginx&tab=preload' );
		}
		
		?>
		<div class="notice notice-info nginx-preload-admin-notice">
			<p>
				<strong><?php esc_html_e( 'Nginx Helper:', 'nginx-helper' ); ?></strong>
				<?php
				printf(
					/* translators: 1: processed items, 2: total items */
					esc_html__( 'Cache preload in progress (%1$d / %2$d items).', 'nginx-helper' ),
					intval( $progress['processed_items'] ),
					intval( $progress['total_items'] )
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'View Status', 'nginx-helper' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Get URL diagnostics.
	 */
	public function ajax_get_url_diagnostics() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$full_url = home_url( $url );
		$variants = $manager->get_all_variant_combinations();
		$force_ssl = ! empty( $this->options['preload_force_ssl'] );
		
		$diagnostics = array();
		$schemes = $force_ssl ? array( 'https' ) : array( 'https', 'http' );
		
		foreach ( $schemes as $scheme ) {
			$scheme_url = preg_replace( '/^https?:/', $scheme . ':', $full_url );
			
			foreach ( $variants as $variant_values ) {
				$diag = $manager->get_url_diagnostics( $scheme_url, $variant_values );
				$variant_key = $manager->get_variant_key( $variant_values );
				
				$diagnostics[] = array(
					'variant_label'      => 'default' === $variant_key ? $scheme : $variant_key . ' (' . $scheme . ')',
					'variant_key'        => $variant_key,
					'scheme'             => $scheme,
					'url'                => $diag['url'],
					'expected_key'       => $diag['expected_key'],
					'expected_hash'      => $diag['expected_hash'],
					'expected_path'      => $diag['expected_path'],
					'file_exists'        => $diag['file_exists'],
					'actual_key'         => $diag['actual_key'],
					'key_matches'        => $diag['key_matches'],
					'file_mtime'         => $diag['file_mtime'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $diag['file_mtime'] ) : null,
					'file_size'          => $diag['file_size'] ? size_format( $diag['file_size'] ) : null,
					'reasons_not_cached' => $diag['reasons_not_cached'],
					'cached'             => $diag['file_exists'] && $diag['key_matches'],
				);
			}
		}
		
		// Build HTML for the dialog.
		ob_start();
		foreach ( $diagnostics as $diag ) :
		?>
		<div class="nginx-diagnostics-variant">
			<h4><?php echo esc_html( $diag['variant_label'] ); ?></h4>
			
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'Expected Key:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value"><?php echo esc_html( $diag['expected_key'] ); ?></span>
			</div>
			
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'Actual Key:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value">
					<?php if ( $diag['actual_key'] ) : ?>
						<?php echo esc_html( $diag['actual_key'] ); ?>
						<?php if ( $diag['key_matches'] ) : ?>
							<span class="dashicons dashicons-yes-alt nginx-key-match"></span>
						<?php else : ?>
							<span class="dashicons dashicons-warning nginx-key-mismatch"></span>
						<?php endif; ?>
					<?php else : ?>
						<em><?php esc_html_e( '(file not found)', 'nginx-helper' ); ?></em>
					<?php endif; ?>
				</span>
			</div>
			
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'Cache File:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value"><?php echo esc_html( $diag['expected_path'] ); ?></span>
			</div>
			
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'File Exists:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value">
					<?php if ( $diag['file_exists'] ) : ?>
						<?php esc_html_e( 'Yes', 'nginx-helper' ); ?> <span class="dashicons dashicons-yes-alt nginx-status-ok"></span>
					<?php else : ?>
						<?php esc_html_e( 'No', 'nginx-helper' ); ?> <span class="dashicons dashicons-dismiss nginx-status-error"></span>
					<?php endif; ?>
				</span>
			</div>
			
			<?php if ( $diag['file_exists'] ) : ?>
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'File Size:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value"><?php echo esc_html( $diag['file_size'] ); ?></span>
			</div>
			
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'Last Modified:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value"><?php echo esc_html( $diag['file_mtime'] ); ?></span>
			</div>
			<?php endif; ?>
			
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'Status:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value">
					<?php if ( $diag['cached'] ) : ?>
						<span class="nginx-diagnostics-status cached"><?php esc_html_e( 'CACHED', 'nginx-helper' ); ?></span>
					<?php else : ?>
						<span class="nginx-diagnostics-status not-cached"><?php esc_html_e( 'NOT CACHED', 'nginx-helper' ); ?></span>
					<?php endif; ?>
				</span>
			</div>
			
			<?php if ( ! empty( $diag['reasons_not_cached'] ) ) : ?>
			<div class="nginx-diagnostics-row">
				<span class="nginx-diagnostics-label"><?php esc_html_e( 'Reason:', 'nginx-helper' ); ?></span>
				<span class="nginx-diagnostics-value">
					<?php echo esc_html( implode( ' ', $diag['reasons_not_cached'] ) ); ?>
				</span>
			</div>
			<?php endif; ?>
		</div>
		<?php
		endforeach;
		$html = ob_get_clean();
		
		wp_send_json_success( array(
			'diagnostics' => $diagnostics,
			'html'        => $html,
		) );
	}

	/**
	 * AJAX handler: Scan for orphaned cache files.
	 */
	public function ajax_scan_orphaned_files() {
		check_ajax_referer( 'nginx_helper_preload', 'nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nginx-helper' ) ) );
		}
		
		$manager = $this->get_preload_manager();
		$orphaned = $manager->find_orphaned_cache_files( 200 );
		$cache_root = $manager->get_cache_path_root();
		
		// Format the data for display.
		$formatted = array();
		foreach ( $orphaned as $file ) {
			// Get relative path from cache root.
			$relative_path = $file['path'];
			if ( ! empty( $cache_root ) && 0 === strpos( $file['path'], $cache_root ) ) {
				$relative_path = substr( $file['path'], strlen( $cache_root ) );
				if ( 0 === strpos( $relative_path, '/' ) || 0 === strpos( $relative_path, '\\' ) ) {
					$relative_path = substr( $relative_path, 1 );
				}
			}
			
			// Get URL path from key.
			$url_path = isset( $file['url_path'] ) ? $file['url_path'] : null;
			if ( null === $url_path && ! empty( $file['key'] ) ) {
				$url_path = $manager->extract_url_from_key( $file['key'] );
			}
			
			$formatted[] = array(
				'key'           => $file['key'] ? $file['key'] : __( '(could not parse)', 'nginx-helper' ),
				'path'          => $file['path'],
				'relative_path' => $relative_path,
				'url_path'      => $url_path ? $url_path : __( '(unknown)', 'nginx-helper' ),
				'size'          => size_format( $file['size'] ),
				'size_raw'      => $file['size'],
				'date'          => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file['mtime'] ),
				'date_raw'      => $file['mtime'],
			);
		}
		
		wp_send_json_success( array(
			'orphaned' => $formatted,
			'count'    => count( $formatted ),
		) );
	}
	
}
