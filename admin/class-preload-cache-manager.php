<?php
/**
 * Preload Cache Manager for nginx-helper.
 *
 * Handles cache preloading, status checking, and cache path building.
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 */

/**
 * Class Preload_Cache_Manager
 *
 * Core preload logic, cache path building, and status checking.
 */
class Preload_Cache_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var Preload_Cache_Manager
	 */
	private static $instance = null;

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Standard fastcgi cache key variables.
	 *
	 * @var array
	 */
	private $standard_key_vars = array(
		'$scheme',
		'$request_method',
		'$host',
		'$request_uri',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return Preload_Cache_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->options = get_site_option( 'rt_wp_nginx_helper_options', array() );
	}

	/**
	 * Refresh options from database.
	 */
	public function refresh_options() {
		$this->options = get_site_option( 'rt_wp_nginx_helper_options', array() );
	}

	/**
	 * Get the cache key template.
	 *
	 * Uses plugin settings only. This is a mandatory field.
	 *
	 * @return string
	 */
	public function get_cache_key_template() {
		if ( ! empty( $this->options['fastcgi_cache_key_template'] ) ) {
			return $this->options['fastcgi_cache_key_template'];
		}

		// Default template (user should configure this).
		return '';
	}

	/**
	 * Get the cache path root.
	 *
	 * Uses the RT_WP_NGINX_HELPER_CACHE_PATH constant.
	 *
	 * @return string
	 */
	public function get_cache_path_root() {
		if ( defined( 'RT_WP_NGINX_HELPER_CACHE_PATH' ) ) {
			return RT_WP_NGINX_HELPER_CACHE_PATH;
		}

		// Default path.
		return '/var/run/nginx-cache';
	}

	/**
	 * Check if cache directory is accessible by PHP.
	 *
	 * @return array ['accessible' => bool, 'writable' => bool, 'error' => string|null, 'security_warning' => string|null]
	 */
	public function is_cache_path_accessible() {
		$cache_path = $this->get_cache_path_root();
		
		$result = array(
			'accessible'       => false,
			'writable'         => false,
			'error'            => null,
			'security_warning' => null,
		);

		if ( empty( $cache_path ) ) {
			$result['error'] = __( 'Cache path is not configured.', 'nginx-helper' );
			return $result;
		}

		if ( ! file_exists( $cache_path ) ) {
			$result['error'] = __( 'Directory does not exist.', 'nginx-helper' );
			return $result;
		}

		if ( ! is_dir( $cache_path ) ) {
			$result['error'] = __( 'Path is not a directory.', 'nginx-helper' );
			return $result;
		}

		if ( ! is_readable( $cache_path ) ) {
			$result['error'] = __( 'Directory is not readable by PHP.', 'nginx-helper' );
			return $result;
		}

		$result['accessible'] = true;

		// Check if writable - this is a security concern.
		if ( is_writable( $cache_path ) ) {
			$result['writable'] = true;
			$result['security_warning'] = __( 'Security Risk: The cache directory is writable by PHP. This could allow malicious scripts to inject content into cached pages. The cache directory should only be writable by the nginx process, not by PHP/WordPress.', 'nginx-helper' );
		}

		return $result;
	}

	/**
	 * Parse KEY: header from a FastCGI cache file.
	 *
	 * FastCGI cache files contain a header section with KEY: value.
	 *
	 * @param string $cache_file_path Full path to cache file.
	 * @return string|null The KEY value or null if not found.
	 */
	public function parse_cache_file_key( $cache_file_path ) {
		if ( ! file_exists( $cache_file_path ) || ! is_readable( $cache_file_path ) ) {
			return null;
		}

		// Read the first 4KB which should contain the headers.
		$handle = fopen( $cache_file_path, 'rb' );
		if ( ! $handle ) {
			return null;
		}

		$content = fread( $handle, 4096 );
		fclose( $handle );

		// Look for KEY: header line.
		if ( preg_match( '/^KEY:\s*(.+)$/m', $content, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Get full diagnostics for a URL including actual vs expected KEY.
	 *
	 * @param string $url            The URL to diagnose.
	 * @param array  $variant_values Optional variant values.
	 * @return array Diagnostic data.
	 */
	public function get_url_diagnostics( $url, $variant_values = array() ) {
		$cache_key = $this->build_cache_key( $url, $variant_values );
		$cache_path = $this->build_cache_path( $cache_key );
		$hash = md5( $cache_key );

		$result = array(
			'url'                => $url,
			'variant_values'     => $variant_values,
			'expected_key'       => $cache_key,
			'expected_hash'      => $hash,
			'expected_path'      => $cache_path,
			'file_exists'        => false,
			'actual_key'         => null,
			'key_matches'        => false,
			'file_mtime'         => null,
			'file_size'          => null,
			'reasons_not_cached' => array(),
		);

		if ( ! file_exists( $cache_path ) ) {
			$result['reasons_not_cached'][] = __( 'Cache file does not exist.', 'nginx-helper' );
			return $result;
		}

		$result['file_exists'] = true;
		$result['file_mtime'] = filemtime( $cache_path );
		$result['file_size'] = filesize( $cache_path );

		// Parse the actual KEY from the file.
		$actual_key = $this->parse_cache_file_key( $cache_path );
		$result['actual_key'] = $actual_key;

		if ( null === $actual_key ) {
			$result['reasons_not_cached'][] = __( 'Could not parse KEY from cache file.', 'nginx-helper' );
		} elseif ( $actual_key !== $cache_key ) {
			$result['key_matches'] = false;
			$result['reasons_not_cached'][] = __( 'KEY mismatch: expected key does not match actual key in file.', 'nginx-helper' );
		} else {
			$result['key_matches'] = true;
		}

		return $result;
	}

	/**
	 * Scan cache directory for orphaned files (not matching known URLs).
	 *
	 * @param int $limit Maximum number of files to return.
	 * @return array List of orphaned cache files with parsed KEY values.
	 */
	public function find_orphaned_cache_files( $limit = 100 ) {
		$cache_path = $this->get_cache_path_root();
		$orphaned = array();

		if ( ! is_dir( $cache_path ) || ! is_readable( $cache_path ) ) {
			return $orphaned;
		}

		// Get site domain for filtering.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Get snapshot to compare against.
		$snapshot = $this->get_snapshot();
		$known_paths = array();

		// Build list of known cache paths from snapshot.
		if ( ! empty( $snapshot['pages'] ) ) {
			$variants = $this->get_all_variant_combinations();
			foreach ( $snapshot['pages'] as $relative_url => $page_data ) {
				$full_url = isset( $page_data['full_url'] ) ? $page_data['full_url'] : home_url( $relative_url );
				foreach ( $variants as $variant_values ) {
					$cache_key = $this->build_cache_key( $full_url, $variant_values );
					$path = $this->build_cache_path( $cache_key );
					$known_paths[ $path ] = true;
				}
			}
		}

		// Scan cache directory recursively.
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $cache_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$count = 0;
		foreach ( $iterator as $file ) {
			if ( $count >= $limit ) {
				break;
			}

			$file_path = $file->getPathname();

			// Skip if this is a known path.
			if ( isset( $known_paths[ $file_path ] ) ) {
				continue;
			}

			// Parse the KEY from the file.
			$key = $this->parse_cache_file_key( $file_path );

			// Skip files that don't belong to this site (domain not in key).
			if ( null !== $key && false === strpos( $key, $site_host ) ) {
				continue;
			}

			// Extract URL path from key if possible.
			$url_path = $this->extract_url_from_key( $key );

			$orphaned[] = array(
				'path'      => $file_path,
				'key'       => $key,
				'url_path'  => $url_path,
				'size'      => $file->getSize(),
				'mtime'     => $file->getMTime(),
			);

			$count++;
		}

		return $orphaned;
	}

	/**
	 * Extract URL path from a cache key.
	 *
	 * @param string|null $key The cache key.
	 * @return string|null The extracted URL path or null.
	 */
	public function extract_url_from_key( $key ) {
		if ( null === $key ) {
			return null;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Try to find the host in the key and extract path after it.
		$pos = strpos( $key, $site_host );
		if ( false !== $pos ) {
			$after_host = substr( $key, $pos + strlen( $site_host ) );
			// The path usually starts with / or is empty for homepage.
			if ( empty( $after_host ) ) {
				return '/';
			}
			// Remove any trailing variant suffixes (non-path characters).
			if ( preg_match( '#^(/[^?#]*)#', $after_host, $matches ) ) {
				return $matches[1];
			}
			return $after_host;
		}

		return null;
	}

	/**
	 * Generate sample cache paths for preview display.
	 *
	 * @param string $sample_url Example URL.
	 * @return array All variant paths with keys and hashes.
	 */
	public function generate_sample_paths( $sample_url ) {
		$paths = array();
		$template = $this->get_cache_key_template();

		if ( empty( $template ) ) {
			return $paths;
		}

		$variants = $this->get_all_variant_combinations();
		$force_ssl = ! empty( $this->options['preload_force_ssl'] );

		// Determine schemes to show.
		$schemes = $force_ssl ? array( 'https' ) : array( 'https', 'http' );

		foreach ( $schemes as $scheme ) {
			// Modify the URL to use this scheme.
			$url = preg_replace( '/^https?:/', $scheme . ':', $sample_url );

			foreach ( $variants as $variant_values ) {
				$cache_key = $this->build_cache_key( $url, $variant_values );
				$hash = md5( $cache_key );
				$cache_path = $this->build_cache_path( $cache_key );

				// Generate variant label.
				$variant_key = $this->get_variant_key( $variant_values );
				$label = $variant_key;
				if ( 'default' === $variant_key ) {
					$label = $scheme;
				} else {
					$label = $variant_key . ' (' . $scheme . ')';
				}

				$paths[] = array(
					'variant_label'  => $label,
					'variant_values' => $variant_values,
					'scheme'         => $scheme,
					'key'            => $cache_key,
					'hash'           => $hash,
					'path'           => $cache_path,
				);
			}
		}

		return $paths;
	}

	/**
	 * Parse custom key variables from the cache key template.
	 *
	 * Returns variables that are not in the standard set.
	 *
	 * @return array Array of custom variable names (without $).
	 */
	public function get_custom_key_variables() {
		$template = $this->get_cache_key_template();
		$custom_vars = array();

		// Match all $variable patterns.
		preg_match_all( '/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $template, $matches );

		if ( ! empty( $matches[0] ) ) {
			foreach ( $matches[0] as $var ) {
				if ( ! in_array( $var, $this->standard_key_vars, true ) ) {
					// Remove the $ prefix and store.
					$custom_vars[] = ltrim( $var, '$' );
				}
			}
		}

		return array_unique( $custom_vars );
	}

	/**
	 * Get variant values for a custom variable.
	 *
	 * @param string $var_name Variable name (without $).
	 * @return array Array of possible values.
	 */
	public function get_variant_values( $var_name ) {
		$custom_var_values = array();

		if ( ! empty( $this->options['fastcgi_custom_var_values'] ) ) {
			$custom_var_values = json_decode( $this->options['fastcgi_custom_var_values'], true );
			if ( ! is_array( $custom_var_values ) ) {
				$custom_var_values = array();
			}
		}

		if ( isset( $custom_var_values[ $var_name ] ) && ! empty( $custom_var_values[ $var_name ] ) ) {
			// Parse CSV values.
			$values = array_map( 'trim', explode( ',', $custom_var_values[ $var_name ] ) );
			return array_filter( $values );
		}

		return array();
	}

	/**
	 * Get all variant combinations.
	 *
	 * @return array Array of variant value arrays.
	 */
	public function get_all_variant_combinations() {
		$custom_vars = $this->get_custom_key_variables();
		
		if ( empty( $custom_vars ) ) {
			return array( array() ); // Single empty variant.
		}

		// Check if variant caching is enabled.
		if ( empty( $this->options['preload_cache_variants'] ) ) {
			return array( array() ); // Single empty variant.
		}

		$combinations = array( array() );

		foreach ( $custom_vars as $var_name ) {
			$values = $this->get_variant_values( $var_name );
			
			if ( empty( $values ) ) {
				continue;
			}

			$new_combinations = array();
			foreach ( $combinations as $combo ) {
				foreach ( $values as $value ) {
					$new_combo = $combo;
					$new_combo[ $var_name ] = $value;
					$new_combinations[] = $new_combo;
				}
			}
			$combinations = $new_combinations;
		}

		return $combinations;
	}

	/**
	 * Build the cache key for a URL.
	 *
	 * @param string $url            The URL to build cache key for.
	 * @param array  $variant_values Array of custom variable values (var_name => value).
	 * @return string The cache key.
	 */
	public function build_cache_key( $url, $variant_values = array() ) {
		$template = $this->get_cache_key_template();
		$parsed = wp_parse_url( $url );

		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
		$host = isset( $parsed['host'] ) ? $parsed['host'] : wp_parse_url( home_url(), PHP_URL_HOST );
		$path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		$request_uri = $path . $query;

		// Check for SSL-only mode.
		if ( ! empty( $this->options['preload_force_ssl'] ) ) {
			$scheme = 'https';
		}

		// Build the cache key by replacing variables.
		$cache_key = $template;
		$cache_key = str_replace( '$scheme', $scheme, $cache_key );
		$cache_key = str_replace( '$request_method', 'GET', $cache_key );
		$cache_key = str_replace( '$host', $host, $cache_key );
		$cache_key = str_replace( '$request_uri', $request_uri, $cache_key );

		// Replace custom variables.
		foreach ( $variant_values as $var_name => $value ) {
			$cache_key = str_replace( '$' . $var_name, $value, $cache_key );
		}

		return $cache_key;
	}

	/**
	 * Build the cache file path from a cache key.
	 *
	 * Uses nginx levels=1:2 structure.
	 *
	 * @param string $cache_key The cache key.
	 * @return string The full path to the cache file.
	 */
	public function build_cache_path( $cache_key ) {
		$hash = md5( $cache_key );
		$cache_root = $this->get_cache_path_root();

		// Ensure trailing slash.
		$cache_root = rtrim( $cache_root, '/' ) . '/';

		// nginx levels=1:2 structure.
		// Level 1: last 1 character.
		// Level 2: preceding 2 characters.
		$level1 = substr( $hash, -1 );
		$level2 = substr( $hash, -3, 2 );

		return $cache_root . $level1 . '/' . $level2 . '/' . $hash;
	}

	/**
	 * Check if a cache file exists for a URL.
	 *
	 * @param string $url            The URL to check.
	 * @param array  $variant_values Array of custom variable values.
	 * @return array Array with 'exists' (bool) and 'modtime' (int|null).
	 */
	public function check_cache_file( $url, $variant_values = array() ) {
		$cache_key = $this->build_cache_key( $url, $variant_values );
		$cache_path = $this->build_cache_path( $cache_key );

		$result = array(
			'exists'  => false,
			'modtime' => null,
			'path'    => $cache_path,
			'key'     => $cache_key,
		);

		if ( file_exists( $cache_path ) ) {
			$result['exists'] = true;
			$result['modtime'] = filemtime( $cache_path );
		}

		return $result;
	}

	/**
	 * Get the snapshot file path.
	 *
	 * @return string
	 */
	public function get_snapshot_path() {
		$upload_dir = wp_upload_dir();
		$nginx_helper_dir = $upload_dir['basedir'] . '/nginx-helper';

		// Ensure directory exists.
		if ( ! is_dir( $nginx_helper_dir ) ) {
			wp_mkdir_p( $nginx_helper_dir );
		}

		return $nginx_helper_dir . '/preload-status.json';
	}

	/**
	 * Get the current snapshot data.
	 *
	 * @return array
	 */
	public function get_snapshot() {
		$path = $this->get_snapshot_path();

		if ( ! file_exists( $path ) ) {
			return $this->get_default_snapshot();
		}

		$content = file_get_contents( $path );
		$data = json_decode( $content, true );

		if ( ! is_array( $data ) ) {
			return $this->get_default_snapshot();
		}

		return $data;
	}

	/**
	 * Get default snapshot structure.
	 *
	 * @return array
	 */
	private function get_default_snapshot() {
		return array(
			'last_scan'       => null,
			'preload_status'  => 'idle',
			'current_batch'   => 0,
			'total_batches'   => 0,
			'current_url'     => null,
			'pages'           => array(),
		);
	}

	/**
	 * Save snapshot data.
	 *
	 * @param array $data The snapshot data.
	 * @return bool
	 */
	public function save_snapshot( $data ) {
		$path = $this->get_snapshot_path();
		$content = wp_json_encode( $data, JSON_PRETTY_PRINT );

		return (bool) file_put_contents( $path, $content );
	}

	/**
	 * Get all public pages from sitemap.
	 *
	 * Prioritizes Yoast sitemap, falls back to WordPress native.
	 *
	 * @return array Array of page data with 'url', 'title', and 'source'.
	 */
	public function get_all_pages() {
		$pages = array();

		// Try Yoast sitemap first.
		$yoast_pages = $this->get_yoast_sitemap_pages();
		if ( ! empty( $yoast_pages ) ) {
			// Add source indicator.
			foreach ( $yoast_pages as &$page ) {
				$page['source'] = 'yoast';
			}
			return $yoast_pages;
		}

		// Fall back to WordPress native sitemap.
		$wp_pages = $this->get_wp_sitemap_pages();
		if ( ! empty( $wp_pages ) ) {
			// Add source indicator.
			foreach ( $wp_pages as &$page ) {
				$page['source'] = 'wordpress';
			}
			return $wp_pages;
		}

		// Final fallback: query posts directly.
		$db_pages = $this->get_pages_from_database();
		foreach ( $db_pages as &$page ) {
			$page['source'] = 'database';
		}
		return $db_pages;
	}

	/**
	 * Get pages from Yoast SEO sitemap.
	 *
	 * @return array
	 */
	private function get_yoast_sitemap_pages() {
		$pages = array();

		// Check if Yoast SEO is active.
		if ( ! class_exists( 'WPSEO_Sitemaps' ) && ! defined( 'WPSEO_VERSION' ) ) {
			return $pages;
		}

		$sitemap_url = home_url( '/sitemap_index.xml' );
		$response = wp_remote_get( $sitemap_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return $pages;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $pages;
		}

		// Parse sitemap index to get individual sitemaps.
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );

		if ( false === $xml ) {
			return $pages;
		}

		$sitemap_urls = array();
		foreach ( $xml->sitemap as $sitemap ) {
			$sitemap_urls[] = (string) $sitemap->loc;
		}

		// Parse each sitemap for URLs.
		foreach ( $sitemap_urls as $sitemap_url ) {
			$sitemap_response = wp_remote_get( $sitemap_url, array( 'timeout' => 30 ) );
			if ( is_wp_error( $sitemap_response ) ) {
				continue;
			}

			$sitemap_body = wp_remote_retrieve_body( $sitemap_response );
			$sitemap_xml = simplexml_load_string( $sitemap_body );

			if ( false === $sitemap_xml ) {
				continue;
			}

			foreach ( $sitemap_xml->url as $url_entry ) {
				$url = (string) $url_entry->loc;
				$pages[] = array(
					'url'   => $url,
					'title' => $this->get_page_title_from_url( $url ),
				);
			}
		}

		return $pages;
	}

	/**
	 * Get pages from WordPress native sitemap.
	 *
	 * @return array
	 */
	private function get_wp_sitemap_pages() {
		$pages = array();

		// Check if WordPress sitemaps are available (WP 5.5+).
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return $pages;
		}

		$sitemaps = wp_sitemaps_get_server();
		if ( ! $sitemaps ) {
			return $pages;
		}

		$sitemap_list = $sitemaps->index->get_sitemap_list();

		foreach ( $sitemap_list as $sitemap ) {
			$sitemap_url = $sitemap['loc'];
			$urls = $this->extract_sitemap_urls( $sitemap_url );

			foreach ( $urls as $url ) {
				$pages[] = array(
					'url'   => $url,
					'title' => $this->get_page_title_from_url( $url ),
				);
			}
		}

		return $pages;
	}

	/**
	 * Extract URLs from a sitemap.
	 *
	 * @param string $sitemap_url The sitemap URL.
	 * @return array
	 */
	private function extract_sitemap_urls( $sitemap_url ) {
		$urls = array();
		$response = wp_remote_get( $sitemap_url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return $urls;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $urls;
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );

		if ( false === $xml ) {
			return $urls;
		}

		foreach ( $xml->url as $url_entry ) {
			$urls[] = (string) $url_entry->loc;
		}

		return $urls;
	}

	/**
	 * Get pages directly from database.
	 *
	 * @return array
	 */
	private function get_pages_from_database() {
		$pages = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		
		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$pages[] = array(
					'url'   => get_permalink(),
					'title' => get_the_title(),
				);
			}
			wp_reset_postdata();
		}

		// Add homepage if it's not already included.
		$home_url = home_url( '/' );
		$home_found = false;
		foreach ( $pages as $page ) {
			if ( trailingslashit( $page['url'] ) === trailingslashit( $home_url ) ) {
				$home_found = true;
				break;
			}
		}

		if ( ! $home_found ) {
			array_unshift( $pages, array(
				'url'   => $home_url,
				'title' => get_bloginfo( 'name' ),
			) );
		}

		return $pages;
	}

	/**
	 * Get page title from URL.
	 *
	 * @param string $url The URL.
	 * @return string
	 */
	private function get_page_title_from_url( $url ) {
		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			return get_the_title( $post_id );
		}

		// Check if it's the homepage.
		if ( trailingslashit( $url ) === trailingslashit( home_url( '/' ) ) ) {
			return get_bloginfo( 'name' ) . ' - Home';
		}

		// Use URL path as fallback.
		$parsed = wp_parse_url( $url );
		$path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		return ucwords( str_replace( array( '/', '-', '_' ), ' ', trim( $path, '/' ) ) ) ?: 'Home';
	}

	/**
	 * Sync sitemap pages to the snapshot.
	 *
	 * @return bool
	 */
	public function sync_sitemap_to_snapshot() {
		$pages = $this->get_all_pages();
		$snapshot = $this->get_snapshot();
		$variants = $this->get_all_variant_combinations();

		$existing_pages = isset( $snapshot['pages'] ) ? $snapshot['pages'] : array();
		$new_pages = array();

		foreach ( $pages as $page ) {
			$url = $page['url'];
			$relative_url = $this->get_relative_url( $url );
			$source = isset( $page['source'] ) ? $page['source'] : 'unknown';

			// Preserve existing data if available.
			if ( isset( $existing_pages[ $relative_url ] ) ) {
				$new_pages[ $relative_url ] = $existing_pages[ $relative_url ];
				$new_pages[ $relative_url ]['title'] = $page['title'];
				$new_pages[ $relative_url ]['url'] = $relative_url;
				$new_pages[ $relative_url ]['source'] = $source;
			} else {
				$new_pages[ $relative_url ] = array(
					'title'    => $page['title'],
					'url'      => $relative_url,
					'full_url' => $url,
					'enabled'  => true,
					'source'   => $source,
					'variants' => array(),
				);
			}

			// Update cache status for each variant.
			foreach ( $variants as $variant_values ) {
				$variant_key = $this->get_variant_key( $variant_values );
				$cache_status = $this->check_cache_file( $url, $variant_values );

				$new_pages[ $relative_url ]['variants'][ $variant_key ] = array(
					'cached'           => $cache_status['exists'],
					'cache_date'       => $cache_status['modtime'] ? gmdate( 'c', $cache_status['modtime'] ) : null,
					'status_code'      => isset( $new_pages[ $relative_url ]['variants'][ $variant_key ]['status_code'] ) 
						? $new_pages[ $relative_url ]['variants'][ $variant_key ]['status_code'] 
						: null,
					'response_time_ms' => isset( $new_pages[ $relative_url ]['variants'][ $variant_key ]['response_time_ms'] ) 
						? $new_pages[ $relative_url ]['variants'][ $variant_key ]['response_time_ms'] 
						: null,
					'variant_values'   => $variant_values,
				);
			}
		}

		$snapshot['pages'] = $new_pages;
		$snapshot['last_scan'] = gmdate( 'c' );

		return $this->save_snapshot( $snapshot );
	}

	/**
	 * Get relative URL from full URL.
	 *
	 * @param string $url The full URL.
	 * @return string
	 */
	public function get_relative_url( $url ) {
		$home_url = home_url();
		$relative = str_replace( $home_url, '', $url );
		return $relative ?: '/';
	}

	/**
	 * Get variant key for storage.
	 *
	 * @param array $variant_values The variant values.
	 * @return string
	 */
	public function get_variant_key( $variant_values ) {
		if ( empty( $variant_values ) ) {
			return 'default';
		}

		$parts = array();
		foreach ( $variant_values as $var => $value ) {
			$parts[] = $value;
		}

		return implode( '_', $parts );
	}

	/**
	 * Preload a single page.
	 *
	 * @param string $url            The URL to preload.
	 * @param array  $variant_values The variant values for this request.
	 * @return array Result with status_code, response_time_ms, cached.
	 */
	public function preload_single_page( $url, $variant_values = array() ) {
		$start_time = microtime( true );

		// Build request arguments.
		$args = array(
			'timeout'     => 30,
			'redirection' => 5,
			'sslverify'   => false,
			'headers'     => array(),
		);

		// Set custom user agent.
		$user_agent = ! empty( $this->options['preload_user_agent'] ) 
			? $this->options['preload_user_agent'] 
			: 'NginxHelper-Preloader/1.0';

		// Append variant info to user agent.
		if ( ! empty( $variant_values ) ) {
			$variant_suffix = implode( '-', array_values( $variant_values ) );
			$user_agent .= ' (' . $variant_suffix . ')';
		}

		$args['user-agent'] = $user_agent;

		// Add variant headers.
		foreach ( $variant_values as $var_name => $value ) {
			$header_name = 'X-' . str_replace( '_', '-', ucwords( $var_name, '_' ) );
			$args['headers'][ $header_name ] = $value;
		}

		// Add custom headers from settings.
		if ( ! empty( $this->options['preload_custom_headers'] ) ) {
			$custom_headers = $this->parse_custom_headers( $this->options['preload_custom_headers'] );
			$args['headers'] = array_merge( $args['headers'], $custom_headers );
		}

		// Force SSL if enabled.
		if ( ! empty( $this->options['preload_force_ssl'] ) ) {
			$url = preg_replace( '/^http:/', 'https:', $url );
		}

		// Make the request.
		$response = wp_remote_get( $url, $args );

		$end_time = microtime( true );
		$response_time = round( ( $end_time - $start_time ) * 1000 );

		$result = array(
			'url'              => $url,
			'variant_values'   => $variant_values,
			'status_code'      => 0,
			'response_time_ms' => $response_time,
			'cached'           => false,
			'error'            => null,
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$result['status_code'] = wp_remote_retrieve_response_code( $response );

		// Verify cache file was created (for successful responses).
		if ( 200 === $result['status_code'] ) {
			// Small delay to allow nginx to write the cache file.
			usleep( 100000 ); // 100ms

			$cache_status = $this->check_cache_file( $url, $variant_values );
			$result['cached'] = $cache_status['exists'];
		}

		return $result;
	}

	/**
	 * Parse custom headers from settings.
	 *
	 * @param string $headers_string Headers as key:value per line.
	 * @return array
	 */
	private function parse_custom_headers( $headers_string ) {
		$headers = array();
		$lines = explode( "\n", $headers_string );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) === 2 ) {
				$headers[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		return $headers;
	}

	/**
	 * Preload all pages in batch.
	 *
	 * @param int $batch_size Number of pages per batch.
	 * @return array Progress info.
	 */
	public function preload_all_pages( $batch_size = 10 ) {
		$snapshot = $this->get_snapshot();

		// Initialize if needed.
		if ( 'idle' === $snapshot['preload_status'] || 'completed' === $snapshot['preload_status'] ) {
			$this->sync_sitemap_to_snapshot();
			$snapshot = $this->get_snapshot();

			$total_pages = count( $snapshot['pages'] );
			$variants = $this->get_all_variant_combinations();
			$total_items = $total_pages * count( $variants );

			$snapshot['preload_status'] = 'running';
			$snapshot['current_batch'] = 0;
			$snapshot['total_batches'] = ceil( $total_items / $batch_size );
			$snapshot['total_items'] = $total_items;
			$snapshot['processed_items'] = 0;
			$this->save_snapshot( $snapshot );
		}

		// Check if already completed.
		if ( 'completed' === $snapshot['preload_status'] ) {
			return $this->get_preload_progress();
		}

		$variants = $this->get_all_variant_combinations();
		$processed = 0;
		$batch_processed = 0;

		foreach ( $snapshot['pages'] as $relative_url => $page_data ) {
			if ( empty( $page_data['enabled'] ) ) {
				continue;
			}

			$full_url = isset( $page_data['full_url'] ) ? $page_data['full_url'] : home_url( $relative_url );

			foreach ( $variants as $variant_values ) {
				$variant_key = $this->get_variant_key( $variant_values );

				// Skip if already processed in this run.
				$variant_data = isset( $page_data['variants'][ $variant_key ] ) ? $page_data['variants'][ $variant_key ] : array();
				
				// Check if we should skip based on progress.
				if ( $processed < $snapshot['processed_items'] ) {
					$processed++;
					continue;
				}

				// Update current URL.
				$snapshot['current_url'] = $full_url . ' (' . $variant_key . ')';
				$this->save_snapshot( $snapshot );

				// Preload the page.
				$result = $this->preload_single_page( $full_url, $variant_values );

				// Update snapshot with results.
				$snapshot['pages'][ $relative_url ]['variants'][ $variant_key ] = array(
					'cached'           => $result['cached'],
					'cache_date'       => $result['cached'] ? gmdate( 'c' ) : null,
					'status_code'      => $result['status_code'],
					'response_time_ms' => $result['response_time_ms'],
					'variant_values'   => $variant_values,
					'error'            => $result['error'],
				);

				$snapshot['processed_items']++;
				$batch_processed++;
				$processed++;

				// Save progress.
				$this->save_snapshot( $snapshot );

				// Check if batch complete.
				if ( $batch_processed >= $batch_size ) {
					$snapshot['current_batch']++;
					$this->save_snapshot( $snapshot );
					return $this->get_preload_progress();
				}
			}
		}

		// All done.
		$snapshot['preload_status'] = 'completed';
		$snapshot['current_url'] = null;
		$this->save_snapshot( $snapshot );

		return $this->get_preload_progress();
	}

	/**
	 * Get preload progress.
	 *
	 * @return array
	 */
	public function get_preload_progress() {
		$snapshot = $this->get_snapshot();

		return array(
			'status'          => $snapshot['preload_status'],
			'current_batch'   => $snapshot['current_batch'],
			'total_batches'   => $snapshot['total_batches'],
			'current_url'     => $snapshot['current_url'],
			'processed_items' => isset( $snapshot['processed_items'] ) ? $snapshot['processed_items'] : 0,
			'total_items'     => isset( $snapshot['total_items'] ) ? $snapshot['total_items'] : 0,
		);
	}

	/**
	 * Stop the preload process.
	 *
	 * @return bool
	 */
	public function stop_preload() {
		$snapshot = $this->get_snapshot();
		$snapshot['preload_status'] = 'stopped';
		$snapshot['current_url'] = null;
		return $this->save_snapshot( $snapshot );
	}

	/**
	 * Reset preload status to idle.
	 *
	 * @return bool
	 */
	public function reset_preload() {
		$snapshot = $this->get_snapshot();
		$snapshot['preload_status'] = 'idle';
		$snapshot['current_batch'] = 0;
		$snapshot['total_batches'] = 0;
		$snapshot['current_url'] = null;
		$snapshot['processed_items'] = 0;
		$snapshot['total_items'] = 0;
		return $this->save_snapshot( $snapshot );
	}

	/**
	 * Toggle page enabled status.
	 *
	 * @param string $relative_url The relative URL.
	 * @param bool   $enabled      Whether to enable or disable.
	 * @return bool
	 */
	public function toggle_page( $relative_url, $enabled ) {
		$snapshot = $this->get_snapshot();

		if ( isset( $snapshot['pages'][ $relative_url ] ) ) {
			$snapshot['pages'][ $relative_url ]['enabled'] = (bool) $enabled;
			return $this->save_snapshot( $snapshot );
		}

		return false;
	}

	/**
	 * Reset (re-preload) a single page.
	 *
	 * @param string $relative_url The relative URL.
	 * @return array Results for each variant.
	 */
	public function reset_page( $relative_url ) {
		$snapshot = $this->get_snapshot();
		$results = array();

		if ( ! isset( $snapshot['pages'][ $relative_url ] ) ) {
			return $results;
		}

		$page_data = $snapshot['pages'][ $relative_url ];
		$full_url = isset( $page_data['full_url'] ) ? $page_data['full_url'] : home_url( $relative_url );
		$variants = $this->get_all_variant_combinations();

		foreach ( $variants as $variant_values ) {
			$variant_key = $this->get_variant_key( $variant_values );
			$result = $this->preload_single_page( $full_url, $variant_values );

			$snapshot['pages'][ $relative_url ]['variants'][ $variant_key ] = array(
				'cached'           => $result['cached'],
				'cache_date'       => $result['cached'] ? gmdate( 'c' ) : null,
				'status_code'      => $result['status_code'],
				'response_time_ms' => $result['response_time_ms'],
				'variant_values'   => $variant_values,
				'error'            => $result['error'],
			);

			$results[ $variant_key ] = $result;
		}

		$this->save_snapshot( $snapshot );

		return $results;
	}

	/**
	 * Check if preload is currently running.
	 *
	 * @return bool
	 */
	public function is_preload_running() {
		$snapshot = $this->get_snapshot();
		return 'running' === $snapshot['preload_status'];
	}

	/**
	 * Get preload mode.
	 *
	 * @return string off, cron, or reactive.
	 */
	public function get_preload_mode() {
		return isset( $this->options['preload_mode'] ) ? $this->options['preload_mode'] : 'off';
	}
}

