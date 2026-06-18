<?php
/**
 * Display preload options of the plugin.
 *
 * This file is used to markup the preload tab in admin settings.
 *
 * @since      2.4.0
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin/partials
 */

global $nginx_helper_admin;

// Include the Preload Cache Manager.
if ( ! class_exists( 'Preload_Cache_Manager' ) ) {
	require_once plugin_dir_path( dirname( __FILE__ ) ) . 'class-preload-cache-manager.php';
}

$preload_manager = Preload_Cache_Manager::get_instance();

// Handle form submission.
$preload_settings_saved = false;
if ( isset( $_POST['preload_settings_save'] ) && wp_verify_nonce( $_POST['preload_settings_nonce'], 'preload-settings-nonce' ) ) {
	
	$nginx_settings = get_site_option( 'rt_wp_nginx_helper_options', array() );
	
	// Save preload mode.
	$nginx_settings['preload_mode'] = isset( $_POST['preload_mode'] ) ? sanitize_text_field( $_POST['preload_mode'] ) : 'off';
	
	// Save cron schedule.
	$nginx_settings['preload_cron_schedule'] = isset( $_POST['preload_cron_schedule'] ) ? sanitize_text_field( $_POST['preload_cron_schedule'] ) : '0 3 * * *';
	
	// Save user agent.
	$nginx_settings['preload_user_agent'] = isset( $_POST['preload_user_agent'] ) ? sanitize_text_field( $_POST['preload_user_agent'] ) : 'NginxHelper-Preloader/1.0';
	
	// Save custom headers.
	$nginx_settings['preload_custom_headers'] = isset( $_POST['preload_custom_headers'] ) ? sanitize_textarea_field( $_POST['preload_custom_headers'] ) : '';
	
	// Save toggles.
	$nginx_settings['preload_cache_variants'] = isset( $_POST['preload_cache_variants'] ) ? 1 : 0;
	$nginx_settings['preload_force_ssl'] = isset( $_POST['preload_force_ssl'] ) ? 1 : 0;
	
	// Save FastCGI configuration - Cache Key Template is mandatory.
	$nginx_settings['fastcgi_cache_key_template'] = isset( $_POST['fastcgi_cache_key_template'] ) ? sanitize_text_field( $_POST['fastcgi_cache_key_template'] ) : '';
	
	// Save custom variable values as JSON.
	$custom_var_values = array();
	if ( isset( $_POST['fastcgi_custom_var'] ) && is_array( $_POST['fastcgi_custom_var'] ) ) {
		foreach ( $_POST['fastcgi_custom_var'] as $var_name => $values ) {
			$custom_var_values[ sanitize_key( $var_name ) ] = sanitize_text_field( $values );
		}
	}
	$nginx_settings['fastcgi_custom_var_values'] = wp_json_encode( $custom_var_values );
	
	update_site_option( 'rt_wp_nginx_helper_options', $nginx_settings );
	$preload_settings_saved = true;
	
	// Refresh manager options.
	$preload_manager->refresh_options();
	
	// Handle cron schedule changes.
	$timestamp = wp_next_scheduled( 'nginx_helper_preload_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'nginx_helper_preload_cron' );
	}
	
	if ( 'cron' === $nginx_settings['preload_mode'] ) {
		// Schedule new cron event.
		if ( ! wp_next_scheduled( 'nginx_helper_preload_cron' ) ) {
			wp_schedule_event( time(), 'nginx_helper_preload_interval', 'nginx_helper_preload_cron' );
		}
	}
}

// Get current settings.
$nginx_helper_settings = $nginx_helper_admin->nginx_helper_settings();

// Get cache key template info.
$cache_key_template = $preload_manager->get_cache_key_template();
$cache_path_root = $preload_manager->get_cache_path_root();
$custom_vars = $preload_manager->get_custom_key_variables();

// Check cache path accessibility.
$cache_path_status = $preload_manager->is_cache_path_accessible();

// Get custom var values.
$custom_var_values = array();
if ( ! empty( $nginx_helper_settings['fastcgi_custom_var_values'] ) ) {
	$custom_var_values = json_decode( $nginx_helper_settings['fastcgi_custom_var_values'], true );
	if ( ! is_array( $custom_var_values ) ) {
		$custom_var_values = array();
	}
}

// Get snapshot data.
$snapshot = $preload_manager->get_snapshot();
$preload_progress = $preload_manager->get_preload_progress();

// Get variant combinations for table headers.
$variants = $preload_manager->get_all_variant_combinations();

// Generate sample cache paths.
$sample_url = home_url( '/example/page' );
$sample_paths = $preload_manager->generate_sample_paths( $sample_url );

// Check if cache key template is configured.
$cache_key_configured = ! empty( $nginx_helper_settings['fastcgi_cache_key_template'] );
?>

<?php if ( $preload_settings_saved ) : ?>
<div class="updated"><p><?php esc_html_e( 'Preload settings saved.', 'nginx-helper' ); ?></p></div>
<?php endif; ?>

<?php if ( ! empty( $custom_vars ) && empty( $nginx_helper_settings['preload_cache_variants'] ) ) : ?>
<div class="notice notice-error">
	<p>
		<strong><?php esc_html_e( 'Cache Variant Warning:', 'nginx-helper' ); ?></strong>
		<?php
		$var_labels = array_map(
			function ( $v ) {
				return '<code>$' . esc_html( $v ) . '</code>';
			},
			$custom_vars
		);
		printf(
			/* translators: %s: comma-separated list of detected custom variable names with $ prefix */
			esc_html__( 'Your cache key template contains custom variable(s): %s. The "Cache all variants" option is currently unchecked. Cache key previews, diagnostics, and status checks cannot substitute these variables and will show incorrect literal values. Check the "Cache all variants" option and configure possible values for each variable below.', 'nginx-helper' ),
			implode( ', ', $var_labels ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		?>
	</p>
</div>
<?php endif; ?>

<!-- Preload Progress Banner -->
<div id="nginx-preload-progress-banner" class="notice notice-info nginx-preload-progress-banner" style="<?php echo 'running' !== $preload_progress['status'] ? 'display:none;' : ''; ?>">
	<p>
		<strong><?php esc_html_e( 'Preload in Progress:', 'nginx-helper' ); ?></strong>
		<span id="preload-progress-text">
			<?php
			printf(
				/* translators: 1: processed items, 2: total items */
				esc_html__( '%1$d / %2$d items processed', 'nginx-helper' ),
				intval( $preload_progress['processed_items'] ),
				intval( $preload_progress['total_items'] )
			);
			?>
		</span>
		<span id="preload-current-url">
			<?php if ( $preload_progress['current_url'] ) : ?>
				- <?php echo esc_html( $preload_progress['current_url'] ); ?>
			<?php endif; ?>
		</span>
	</p>
	<div class="nginx-preload-progress-bar">
		<div id="nginx-preload-progress-fill" class="nginx-preload-progress-fill" style="width: <?php echo esc_attr( $preload_progress['total_items'] > 0 ? ( $preload_progress['processed_items'] / $preload_progress['total_items'] * 100 ) : 0 ); ?>%;"></div>
	</div>
</div>

<!-- Preload Settings Form -->
<form id="preload_settings_form" method="post" action="" class="clearfix">
	
	<!-- Preload Mode Section -->
	<div class="postbox">
		<div class="inside">
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label for="preload_mode"><?php esc_html_e( 'Mode', 'nginx-helper' ); ?></label>
					</th>
					<td>
						<select id="preload_mode" name="preload_mode">
							<option value="off" <?php selected( $nginx_helper_settings['preload_mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'nginx-helper' ); ?></option>
							<option value="cron" <?php selected( $nginx_helper_settings['preload_mode'], 'cron' ); ?>><?php esc_html_e( 'Cron', 'nginx-helper' ); ?></option>
							<option value="reactive" <?php selected( $nginx_helper_settings['preload_mode'], 'reactive' ); ?>><?php esc_html_e( 'Reactive', 'nginx-helper' ); ?></option>
						</select>
						<p class="description preload-mode-help" id="preload_mode_help_off" style="<?php echo 'off' !== $nginx_helper_settings['preload_mode'] ? 'display:none;' : ''; ?>"><?php esc_html_e( 'No preloading will occur.', 'nginx-helper' ); ?></p>
						<p class="description preload-mode-help" id="preload_mode_help_cron" style="<?php echo 'cron' !== $nginx_helper_settings['preload_mode'] ? 'display:none;' : ''; ?>"><?php esc_html_e( 'Will preload all pages on an interval in bulk.', 'nginx-helper' ); ?></p>
						<p class="description preload-mode-help" id="preload_mode_help_reactive" style="<?php echo 'reactive' !== $nginx_helper_settings['preload_mode'] ? 'display:none;' : ''; ?>"><?php esc_html_e( 'Will preload single pages when edited, and all pages when Purge-Cache is executed for entire site.', 'nginx-helper' ); ?></p>
					</td>
				</tr>
				<tr valign="top" id="cron_schedule_row" style="<?php echo 'cron' !== $nginx_helper_settings['preload_mode'] ? 'display:none;' : ''; ?>">
					<th scope="row">
						<label for="preload_cron_schedule"><?php esc_html_e( 'Cron Schedule', 'nginx-helper' ); ?></label>
					</th>
					<td>
						<input type="text" id="preload_cron_schedule" name="preload_cron_schedule" value="<?php echo esc_attr( $nginx_helper_settings['preload_cron_schedule'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Standard cron format (e.g., "0 3 * * *" for daily at 3 AM).', 'nginx-helper' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
	</div>
	
	<!-- Preload Options Section -->
	<div class="postbox">
		<h3 class="hndle">
			<span><?php esc_html_e( 'Preload Options', 'nginx-helper' ); ?></span>
		</h3>
		<div class="inside">
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label for="preload_user_agent"><?php esc_html_e( 'User Agent', 'nginx-helper' ); ?></label>
					</th>
					<td>
						<input type="text" id="preload_user_agent" name="preload_user_agent" value="<?php echo esc_attr( $nginx_helper_settings['preload_user_agent'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Custom User Agent string for preload requests.', 'nginx-helper' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="preload_custom_headers"><?php esc_html_e( 'Custom Headers', 'nginx-helper' ); ?></label>
					</th>
					<td>
						<textarea id="preload_custom_headers" name="preload_custom_headers" rows="4" class="large-text code"><?php echo esc_textarea( $nginx_helper_settings['preload_custom_headers'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional custom headers for preload requests (one per line, format: Header-Name: value).', 'nginx-helper' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<td colspan="2">
						<label>
							<input type="checkbox" name="preload_cache_variants" value="1" <?php checked( $nginx_helper_settings['preload_cache_variants'], 1 ); ?> />
							<?php esc_html_e( 'Cache all variants (based on customizations to fastcgi key pattern, such as device_type)', 'nginx-helper' ); ?>
						</label>
					</td>
				</tr>
				<tr valign="top">
					<td colspan="2">
						<label>
							<input type="checkbox" id="preload_force_ssl" name="preload_force_ssl" value="1" <?php checked( $nginx_helper_settings['preload_force_ssl'], 1 ); ?> />
							<?php esc_html_e( 'Force SSL only (no HTTP caching)', 'nginx-helper' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
	</div>
	
	<!-- FastCGI Configuration Section -->
	<div class="postbox">
		<h3 class="hndle">
			<span><?php esc_html_e( 'FastCGI Cache Configuration', 'nginx-helper' ); ?></span>
		</h3>
		<div class="inside">
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label for="fastcgi_cache_key_template"><?php esc_html_e( 'Cache Key Template', 'nginx-helper' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<input type="text" id="fastcgi_cache_key_template" name="fastcgi_cache_key_template" value="<?php echo esc_attr( $nginx_helper_settings['fastcgi_cache_key_template'] ); ?>" class="regular-text" required />
						<p class="description"><?php esc_html_e( 'Your nginx fastcgi_cache_key pattern (e.g., $scheme$request_method$host$request_uri). This is required for preload to function.', 'nginx-helper' ); ?></p>
						<?php if ( ! $cache_key_configured ) : ?>
							<p class="nginx-path-warning">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'Cache Key Template is required. Please configure it to enable preload functionality.', 'nginx-helper' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label><?php esc_html_e( 'Cache Path Root', 'nginx-helper' ); ?></label>
					</th>
					<td>
						<code><?php echo esc_html( $cache_path_root ); ?></code>
						<p class="description">
							<?php
							printf(
								/* translators: %s: constant name */
								esc_html__( 'Configured via %s constant.', 'nginx-helper' ),
								'<code>RT_WP_NGINX_HELPER_CACHE_PATH</code>'
							);
							?>
						</p>
						<?php if ( $cache_path_status['accessible'] ) : ?>
							<p class="nginx-path-accessible">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Cache directory is accessible by PHP.', 'nginx-helper' ); ?>
							</p>
						<?php if ( ! empty( $cache_path_status['security_warning'] ) && 'unlink_files' !== ( $nginx_helper_settings['purge_method'] ?? '' ) ) : ?>
						<p class="nginx-path-security-warning">
							<span class="dashicons dashicons-shield-alt"></span>
							<strong><?php esc_html_e( 'Security Warning:', 'nginx-helper' ); ?></strong>
							<?php echo esc_html( $cache_path_status['security_warning'] ); ?>
						</p>
						<?php endif; ?>
						<?php else : ?>
							<p class="nginx-path-warning">
								<span class="dashicons dashicons-warning"></span>
								<?php
								printf(
									/* translators: %s: error message */
									esc_html__( 'Cache directory is NOT accessible: %s', 'nginx-helper' ),
									esc_html( $cache_path_status['error'] )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $custom_vars ) ) : ?>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Detected Custom Variables', 'nginx-helper' ); ?></th>
					<td>
					<?php foreach ( $custom_vars as $var_name ) : ?>
					<div class="nginx-custom-var-row" style="margin-bottom: 10px;">
						<code>$<?php echo esc_html( $var_name ); ?></code>
						<br />
						<label>
							<?php esc_html_e( 'Possible values (CSV):', 'nginx-helper' ); ?>
							<input type="text" name="fastcgi_custom_var[<?php echo esc_attr( $var_name ); ?>]" value="<?php echo esc_attr( isset( $custom_var_values[ $var_name ] ) ? $custom_var_values[ $var_name ] : '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., mobile,desktop', 'nginx-helper' ); ?>" />
						</label>
						<?php if ( empty( $custom_var_values[ $var_name ] ) ) : ?>
						<p class="nginx-path-warning">
							<span class="dashicons dashicons-warning"></span>
							<?php esc_html_e( 'No values configured — cache keys containing this variable cannot be resolved. Sample preview and cache status will be inaccurate until values are saved.', 'nginx-helper' ); ?>
						</p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'These non-standard variables were detected in your cache key template. Enter comma-separated possible values for each.', 'nginx-helper' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
			</table>
			
			<!-- Sample Cache Path Preview -->
			<?php if ( $cache_key_configured && ! empty( $sample_paths ) ) : ?>
			<div class="nginx-cache-path-preview">
				<h4><?php esc_html_e( 'Sample Cache Paths Preview', 'nginx-helper' ); ?></h4>
				<p class="description"><?php printf( esc_html__( 'For URL: %s', 'nginx-helper' ), '<code>' . esc_html( $sample_url ) . '</code>' ); ?></p>
				<?php foreach ( $sample_paths as $path_info ) : ?>
				<div class="variant-block">
					<strong><?php echo esc_html( $path_info['variant_label'] ); ?></strong><br />
					<span class="label"><?php esc_html_e( 'Key:', 'nginx-helper' ); ?></span> <code><?php echo esc_html( $path_info['key'] ); ?></code><br />
					<span class="label"><?php esc_html_e( 'Hash:', 'nginx-helper' ); ?></span> <code><?php echo esc_html( $path_info['hash'] ); ?></code><br />
					<span class="label"><?php esc_html_e( 'Path:', 'nginx-helper' ); ?></span> <code><?php echo esc_html( $path_info['path'] ); ?></code>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
	
	<input type="hidden" name="preload_settings_nonce" value="<?php echo esc_attr( wp_create_nonce( 'preload-settings-nonce' ) ); ?>" />
	<?php submit_button( __( 'Save Preload Settings', 'nginx-helper' ), 'primary large', 'preload_settings_save', true ); ?>
	
</form>

<!-- Preload Actions Section -->
<div class="postbox">
	<h3 class="hndle">
		<span><?php esc_html_e( 'Preload Actions', 'nginx-helper' ); ?></span>
	</h3>
	<div class="inside">
		<p>
			<button type="button" id="nginx-preload-rescan" class="button" <?php echo ! $cache_key_configured ? 'disabled' : ''; ?>><?php esc_html_e( 'Rescan Cache Status', 'nginx-helper' ); ?></button>
			<button type="button" id="nginx-preload-start" class="button button-primary" <?php echo ( 'running' === $preload_progress['status'] || ! $cache_key_configured ) ? 'disabled' : ''; ?>><?php esc_html_e( 'Start Preload', 'nginx-helper' ); ?></button>
			<button type="button" id="nginx-preload-stop" class="button" <?php echo 'running' !== $preload_progress['status'] ? 'disabled' : ''; ?>><?php esc_html_e( 'Stop Preload', 'nginx-helper' ); ?></button>
			<span id="nginx-preload-status-message" class="nginx-status-message"></span>
		</p>
		<?php if ( ! empty( $snapshot['last_scan'] ) ) : ?>
		<p class="description" id="nginx-last-scan-info">
			<?php
			printf(
				/* translators: %s: last scan date */
				esc_html__( 'Last scan: %s', 'nginx-helper' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $snapshot['last_scan'] ) ) )
			);
			?>
		</p>
		<?php endif; ?>
		<?php if ( ! $cache_key_configured ) : ?>
		<p class="description" style="color: #dc3232;">
			<?php esc_html_e( 'Please configure the Cache Key Template above before using preload actions.', 'nginx-helper' ); ?>
		</p>
		<?php endif; ?>
	</div>
</div>

<!-- Pages Cache Status with jQuery UI Tabs -->
<div class="postbox">
	<h3 class="hndle">
		<span><?php esc_html_e( 'Cache Status', 'nginx-helper' ); ?></span>
	</h3>
	<div class="inside">
		<div id="nginx-preload-tabs">
			<ul>
				<li><a href="#tab-discovered"><?php esc_html_e( 'Discovered URLs', 'nginx-helper' ); ?> (<span id="discovered-count"><?php echo count( $snapshot['pages'] ); ?></span>)</a></li>
				<li><a href="#tab-orphaned"><?php esc_html_e( 'Orphaned Cache Files', 'nginx-helper' ); ?> (<span id="orphaned-count">0</span>)</a></li>
			</ul>
			
			<!-- Tab 1: Discovered URLs -->
			<div id="tab-discovered">
				<?php if ( empty( $snapshot['pages'] ) ) : ?>
					<p><?php esc_html_e( 'No pages found. Click "Rescan Cache Status" to scan your sitemap.', 'nginx-helper' ); ?></p>
				<?php else : 
					// Pre-calculate counts for filter buttons.
					$count_all = count( $snapshot['pages'] );
					$count_cached = 0;
					$count_partial = 0;
					$count_not_cached = 0;
					foreach ( $snapshot['pages'] as $page_data_count ) {
						$all_c = true;
						$any_c = false;
						if ( ! empty( $page_data_count['variants'] ) ) {
							foreach ( $page_data_count['variants'] as $v_data ) {
								if ( ! empty( $v_data['cached'] ) ) {
									$any_c = true;
								} else {
									$all_c = false;
								}
							}
						} else {
							$all_c = false;
						}
						if ( $all_c ) {
							$count_cached++;
						} elseif ( $any_c ) {
							$count_partial++;
						} else {
							$count_not_cached++;
						}
					}
				?>
				<div id="discovered-urls-list">
					<!-- Search and Filter Controls -->
					<div class="nginx-table-controls">
						<input class="search" placeholder="<?php esc_attr_e( 'Search URLs...', 'nginx-helper' ); ?>" />
						<div class="nginx-table-filters">
							<button type="button" class="filter-btn active" data-filter=""><?php printf( esc_html__( 'All (%d)', 'nginx-helper' ), $count_all ); ?></button>
							<button type="button" class="filter-btn" data-filter="cached"><?php printf( esc_html__( 'Cached (%d)', 'nginx-helper' ), $count_cached ); ?></button>
							<button type="button" class="filter-btn" data-filter="partial"><?php printf( esc_html__( 'Partial (%d)', 'nginx-helper' ), $count_partial ); ?></button>
							<button type="button" class="filter-btn" data-filter="not-cached"><?php printf( esc_html__( 'Not Cached (%d)', 'nginx-helper' ), $count_not_cached ); ?></button>
						</div>
						<div class="nginx-date-filters">
							<span class="filter-label"><?php esc_html_e( 'Modified:', 'nginx-helper' ); ?></span>
							<button type="button" class="date-filter-btn active" data-days=""><?php esc_html_e( 'All', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="1"><?php esc_html_e( '1 day', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="7"><?php esc_html_e( '1 week', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="30"><?php esc_html_e( '1 month', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="90"><?php esc_html_e( '3 months', 'nginx-helper' ); ?></button>
						</div>
					</div>
					
					<!-- Table -->
					<table class="wp-list-table widefat fixed striped nginx-preload-pages-table">
						<thead>
							<tr>
								<th class="column-title sort" data-sort="title"><?php esc_html_e( 'Page Title', 'nginx-helper' ); ?></th>
								<th class="column-url sort" data-sort="url"><?php esc_html_e( 'URL', 'nginx-helper' ); ?></th>
								<th class="column-source"><?php esc_html_e( 'Source', 'nginx-helper' ); ?></th>
								<th class="column-status"><?php esc_html_e( 'Status', 'nginx-helper' ); ?></th>
								<th class="column-cache-date sort" data-sort="cache-date"><?php esc_html_e( 'Last Cached', 'nginx-helper' ); ?></th>
								<th class="column-enabled"><?php esc_html_e( 'Enabled', 'nginx-helper' ); ?></th>
								<th class="column-actions"><?php esc_html_e( 'Actions', 'nginx-helper' ); ?></th>
							</tr>
						</thead>
						<tbody class="list">
							<?php foreach ( $snapshot['pages'] as $relative_url => $page_data ) : 
								// Determine cache status and find latest cache date.
								$all_cached = true;
								$any_cached = false;
								$latest_cache_date = '';
								$latest_cache_timestamp = 0;
								if ( ! empty( $page_data['variants'] ) ) {
									foreach ( $page_data['variants'] as $variant_data ) {
										if ( ! empty( $variant_data['cached'] ) ) {
											$any_cached = true;
											if ( ! empty( $variant_data['cache_date'] ) ) {
												$ts = strtotime( $variant_data['cache_date'] );
												if ( $ts > $latest_cache_timestamp ) {
													$latest_cache_timestamp = $ts;
													$latest_cache_date = $variant_data['cache_date'];
												}
											}
										} else {
											$all_cached = false;
										}
									}
								} else {
									$all_cached = false;
								}
								
								if ( $all_cached ) {
									$status_class = 'cached';
									$status_icon = 'dashicons-yes-alt nginx-status-ok';
									$status_title = __( 'All variants cached', 'nginx-helper' );
								} elseif ( $any_cached ) {
									$status_class = 'partial';
									$status_icon = 'dashicons-warning nginx-status-warning';
									$status_title = __( 'Partially cached', 'nginx-helper' );
								} else {
									$status_class = 'not-cached';
									$status_icon = 'dashicons-dismiss nginx-status-error';
									$status_title = __( 'Not cached', 'nginx-helper' );
								}
								
								// Get source.
								$source = isset( $page_data['source'] ) ? $page_data['source'] : 'unknown';
								$source_label = 'yoast' === $source ? __( 'Yoast', 'nginx-helper' ) : ( 'wordpress' === $source ? __( 'WordPress', 'nginx-helper' ) : ( 'database' === $source ? __( 'DB Query', 'nginx-helper' ) : __( 'Unknown', 'nginx-helper' ) ) );
							?>
							<tr data-url="<?php echo esc_attr( $relative_url ); ?>" data-cache-timestamp="<?php echo esc_attr( $latest_cache_timestamp ); ?>">
								<td class="title"><?php echo esc_html( $page_data['title'] ); ?></td>
								<td class="url">
									<a href="<?php echo esc_url( home_url( $relative_url ) ); ?>" target="_blank">
										<?php echo esc_html( $relative_url ); ?>
										<span class="dashicons dashicons-external"></span>
									</a>
								</td>
								<td class="source">
									<span class="nginx-source-badge nginx-source-<?php echo esc_attr( $source ); ?>"><?php echo esc_html( $source_label ); ?></span>
								</td>
								<td class="status <?php echo esc_attr( $status_class ); ?>">
									<span class="dashicons <?php echo esc_attr( $status_icon ); ?>" title="<?php echo esc_attr( $status_title ); ?>"></span>
									<span class="status-text" style="display:none;"><?php echo esc_html( $status_class ); ?></span>
								</td>
								<td class="cache-date" data-timestamp="<?php echo esc_attr( $latest_cache_timestamp ); ?>">
									<?php if ( ! empty( $latest_cache_date ) ) : ?>
										<?php echo esc_html( wp_date( 'M j, Y H:i', strtotime( $latest_cache_date ) ) ); ?>
									<?php else : ?>
										<span class="nginx-variant-not-cached"><?php esc_html_e( 'Never', 'nginx-helper' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="enabled">
									<input type="checkbox" class="nginx-page-enabled" data-url="<?php echo esc_attr( $relative_url ); ?>" <?php checked( ! empty( $page_data['enabled'] ) ); ?> />
								</td>
								<td class="column-actions">
									<button type="button" class="button button-small nginx-diagnostics-btn" data-url="<?php echo esc_attr( $relative_url ); ?>" title="<?php esc_attr_e( 'View diagnostics', 'nginx-helper' ); ?>">
										<span class="dashicons dashicons-info"></span>
									</button>
									<button type="button" class="button button-small nginx-reset-page" data-url="<?php echo esc_attr( $relative_url ); ?>" title="<?php esc_attr_e( 'Re-cache this page by running preloader for it.', 'nginx-helper' ); ?>"><?php esc_html_e( 'Reset', 'nginx-helper' ); ?></button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					
					<!-- Pagination -->
					<ul class="pagination"></ul>
				</div>
				<?php endif; ?>
			</div>
			
			<!-- Tab 2: Orphaned Cache Files -->
			<div id="tab-orphaned">
				<div id="orphaned-files-list" data-cache-root="<?php echo esc_attr( $cache_path_root ); ?>">
					<p class="description"><?php esc_html_e( 'These are cache files found on disk that do not match any discovered URL. This may indicate stale cache entries or URLs not in your sitemap.', 'nginx-helper' ); ?></p>
					
					<!-- Search and Filter Controls -->
					<div class="nginx-table-controls">
						<input class="search" placeholder="<?php esc_attr_e( 'Search by URL path...', 'nginx-helper' ); ?>" />
						<button type="button" id="nginx-scan-orphans" class="button"><?php esc_html_e( 'Scan for Orphaned Files', 'nginx-helper' ); ?></button>
						<div class="nginx-date-filters orphan-date-filters">
							<span class="filter-label"><?php esc_html_e( 'Modified:', 'nginx-helper' ); ?></span>
							<button type="button" class="date-filter-btn active" data-days=""><?php esc_html_e( 'All', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="1"><?php esc_html_e( '1 day', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="7"><?php esc_html_e( '1 week', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="30"><?php esc_html_e( '1 month', 'nginx-helper' ); ?></button>
							<button type="button" class="date-filter-btn" data-days="90"><?php esc_html_e( '3 months', 'nginx-helper' ); ?></button>
						</div>
					</div>
					
					<!-- Table -->
					<table class="wp-list-table widefat fixed striped nginx-orphaned-files-table">
						<thead>
							<tr>
								<th class="column-url-path sort" data-sort="url-path"><?php esc_html_e( 'URL Path', 'nginx-helper' ); ?></th>
								<th class="column-rel-path"><?php esc_html_e( 'Relative File Path', 'nginx-helper' ); ?></th>
								<th class="column-size sort" data-sort="size"><?php esc_html_e( 'Size', 'nginx-helper' ); ?></th>
								<th class="column-date sort" data-sort="date"><?php esc_html_e( 'Modified', 'nginx-helper' ); ?></th>
								<th class="column-details"><?php esc_html_e( 'Details', 'nginx-helper' ); ?></th>
							</tr>
						</thead>
						<tbody class="list" id="orphaned-files-tbody">
							<tr class="no-items">
								<td colspan="5"><?php esc_html_e( 'Click "Scan for Orphaned Files" to search the cache directory.', 'nginx-helper' ); ?></td>
							</tr>
						</tbody>
					</table>
					
					<!-- Pagination -->
					<ul class="pagination orphaned-pagination"></ul>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Diagnostics Dialog (jQuery UI) -->
<div id="nginx-diagnostics-dialog" title="<?php esc_attr_e( 'Cache Diagnostics', 'nginx-helper' ); ?>" style="display:none;">
	<div class="nginx-diagnostics-content">
		<p class="loading"><?php esc_html_e( 'Loading diagnostics...', 'nginx-helper' ); ?></p>
	</div>
</div>

<!-- Orphan Details Dialog (jQuery UI) -->
<div id="nginx-orphan-details-dialog" title="<?php esc_attr_e( 'Cache File Details', 'nginx-helper' ); ?>" style="display:none;">
	<div class="nginx-orphan-details-content">
		<div class="nginx-orphan-detail-row">
			<span class="nginx-orphan-detail-label"><?php esc_html_e( 'Full Cache Key:', 'nginx-helper' ); ?></span>
			<code class="nginx-orphan-detail-key"></code>
		</div>
		<div class="nginx-orphan-detail-row">
			<span class="nginx-orphan-detail-label"><?php esc_html_e( 'Full File Path:', 'nginx-helper' ); ?></span>
			<code class="nginx-orphan-detail-path"></code>
		</div>
		<div class="nginx-orphan-detail-row">
			<span class="nginx-orphan-detail-label"><?php esc_html_e( 'File Size:', 'nginx-helper' ); ?></span>
			<span class="nginx-orphan-detail-size"></span>
		</div>
		<div class="nginx-orphan-detail-row">
			<span class="nginx-orphan-detail-label"><?php esc_html_e( 'Last Modified:', 'nginx-helper' ); ?></span>
			<span class="nginx-orphan-detail-date"></span>
		</div>
	</div>
</div>
