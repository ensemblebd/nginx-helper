/**
 * File to add JavaScript for nginx-helper.
 *
 * @package nginx-helper
 */

(function ($) {
	'use strict';

	// State variables for preload.
	var discoveredList = null;
	var orphanedList = null;
	var orphansLoaded = false;
	var preloadPollingInterval = null;

	/**
	 * All of the code for your admin-specific JavaScript source
	 * should reside in this file.
	 */
	$(function () {

		// Debug: Confirm script is loaded and running.
		console.log('Nginx Helper admin JS initialized');

		// ========================================
		// RSS Feed Loading (existing)
		// ========================================
		var news_section = jQuery( '#latest_news' );

		if ( news_section.length > 0 ) {

			var args = {
				'action': 'rt_get_feeds'
			};

			jQuery.get(
				ajaxurl,
				args,
				function( data ) {
					// phpcs:ignore -- WordPressVIPMinimum.JS.HTMLExecutingFunctions.append
					news_section.find( '.inside' ).empty().append( data );
				}
			);

		}

		// ========================================
		// Purge Confirmation (existing)
		// ========================================
		jQuery( "form#purgeall a" ).click(function (e) {
			if ( confirm( nginx_helper.purge_confirm_string ) === true ) {
				// Continue submitting form.
			} else {
				e.preventDefault();
			}
		});

		// ========================================
		// Password Show/Hide (existing)
		// ========================================
		jQuery('.password-show-hide-btn').on('click', function() {
			var passwordInput = $(this).siblings('.password-input');
			var icon = $(this).find('.password-input-icon');

			if (passwordInput.attr('type') === 'password') {
				passwordInput.attr('type', 'text');
				icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
			} else {
				passwordInput.attr('type', 'password');
				icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
			}
		});

		// ========================================
		// Option Checkbox Toggles (existing)
		// ========================================
		function nginx_show_option( selector ) {
			jQuery( '#' + selector ).on('change', function () {
				if ( jQuery( this ).is( ':checked' ) ) {
					jQuery( '.' + selector ).show();
					if ( 'cache_method_redis' === selector ) {
						jQuery( '.cache_method_fastcgi' ).hide();
					} else if ( selector === 'cache_method_fastcgi' ) {
						jQuery( '.cache_method_redis' ).hide();
					}
				} else {
					jQuery( '.' + selector ).hide();
				}
			});
		}

		nginx_show_option( 'cache_method_fastcgi' );
		nginx_show_option( 'cache_method_redis' );
		nginx_show_option( 'enable_map' );
		nginx_show_option( 'enable_log' );
		nginx_show_option( 'enable_purge' );

		// ========================================
		// Preload Mode Switching
		// ========================================
		var $preloadMode = $('#preload_mode');
		var $cronScheduleRow = $('#cron_schedule_row');
		var $modeHelpTexts = $('.preload-mode-help');

		function updatePreloadModeUI() {
			var mode = $preloadMode.val();

			// Hide all help texts, show the relevant one.
			$modeHelpTexts.hide();
			$('#preload_mode_help_' + mode).show();

			// Show/hide cron schedule row.
			if (mode === 'cron') {
				$cronScheduleRow.show();
			} else {
				$cronScheduleRow.hide();
			}
		}

		// Initialize on page load.
		if ($preloadMode.length) {
			updatePreloadModeUI();
			$preloadMode.on('change', updatePreloadModeUI);
		}

		// ========================================
		// jQuery UI Tabs Initialization
		// ========================================
		try {
			if ($('#nginx-preload-tabs').length && typeof $.fn.tabs === 'function') {
				console.log('Initializing jQuery UI Tabs');
				$('#nginx-preload-tabs').tabs({
					activate: function(event, ui) {
						// Load orphaned files on first tab activation.
						if (ui.newPanel.attr('id') === 'tab-orphaned' && !orphansLoaded) {
							// Don't auto-load, let user click the button.
						}
					}
				});
			}
		} catch (e) {
			console.warn('jQuery UI Tabs initialization failed:', e);
		}

		// ========================================
		// jQuery UI Dialog Initialization
		// ========================================
		try {
			if ($('#nginx-diagnostics-dialog').length && typeof $.fn.dialog === 'function') {
				console.log('Initializing jQuery UI Dialog');
				$('#nginx-diagnostics-dialog').dialog({
					autoOpen: false,
					modal: true,
					width: 650,
					maxHeight: $(window).height() * 0.8,
					dialogClass: 'nginx-diagnostics-dialog',
					buttons: {
						Close: function() {
							$(this).dialog('close');
						}
					}
				});
			}
		} catch (e) {
			console.warn('jQuery UI Dialog initialization failed:', e);
		}

		// ========================================
		// List.js Initialization for Discovered URLs
		// ========================================
		try {
			if (typeof List === 'function' && $('#discovered-urls-list').length && $('#discovered-urls-list tbody tr').length > 0) {
				console.log('Initializing List.js for discovered URLs');
				discoveredList = new List('discovered-urls-list', {
					valueNames: ['title', 'url', { name: 'status', attr: 'class' }, 'cache-date'],
					page: 20,
					pagination: {
						paginationClass: 'pagination',
						innerWindow: 2,
						outerWindow: 1
					}
				});
			}
		} catch (e) {
			console.warn('List.js initialization failed for discovered URLs:', e);
		}

		// ========================================
		// Filter Buttons for Discovered URLs
		// ========================================
		$(document).on('click', '#discovered-urls-list .filter-btn', function(e) {
			e.preventDefault();
			console.log('Filter button clicked');

			var $btn = $(this);
			var filter = $btn.data('filter');

			console.log('Filter value:', filter);

			// Update active state.
			$('#discovered-urls-list .filter-btn').removeClass('active');
			$btn.addClass('active');

			if (!discoveredList) {
				console.log('discoveredList not initialized');
				return;
			}

			if (filter === '' || filter === undefined) {
				discoveredList.filter();
			} else {
				discoveredList.filter(function(item) {
					var statusCell = item.elm.querySelector('.status');
					if (statusCell) {
						return statusCell.classList.contains(filter);
					}
					return false;
				});
			}
		});

		// ========================================
		// Preload Action Buttons
		// ========================================

		// Debug: Check if buttons exist.
		console.log('Rescan button found:', $('#nginx-preload-rescan').length);
		console.log('Start button found:', $('#nginx-preload-start').length);
		console.log('Stop button found:', $('#nginx-preload-stop').length);
		console.log('nginx_helper object:', typeof nginx_helper !== 'undefined' ? 'defined' : 'undefined');
		console.log('ajaxurl:', typeof ajaxurl !== 'undefined' ? ajaxurl : 'undefined');

		// Rescan Cache Status - use document delegation for reliability.
		$(document).on('click', '#nginx-preload-rescan', function(e) {
			e.preventDefault();
			console.log('Rescan button clicked');

			var $btn = $(this);
			var $statusMsg = $('#nginx-preload-status-message');

			if ($btn.prop('disabled')) {
				console.log('Button is disabled, aborting');
				return;
			}

			$btn.prop('disabled', true).text(nginx_helper.i18n.loading);
			$statusMsg.text('');

			console.log('Sending AJAX request to:', ajaxurl);

			$.post(ajaxurl, {
				action: 'nginx_helper_rescan_cache',
				nonce: nginx_helper.preload_nonce
			}, function(response) {
				console.log('Rescan response:', response);
				$btn.prop('disabled', false).text('Rescan Cache Status');

				if (response.success) {
					$statusMsg.text(nginx_helper.i18n.rescan_complete + ' ' + response.data.page_count + ' pages found.');
					// Reload page to show updated data.
					location.reload();
				} else {
					$statusMsg.text(nginx_helper.i18n.error + ' ' + (response.data.message || ''));
				}
			}).fail(function(xhr, status, error) {
				console.error('Rescan AJAX failed:', status, error);
				$btn.prop('disabled', false).text('Rescan Cache Status');
				$statusMsg.text(nginx_helper.i18n.error);
			});
		});

		// Start Preload - use document delegation for reliability.
		$(document).on('click', '#nginx-preload-start', function(e) {
			e.preventDefault();
			console.log('Start preload button clicked');

			var $btn = $(this);
			var $stopBtn = $('#nginx-preload-stop');
			var $statusMsg = $('#nginx-preload-status-message');

			if ($btn.prop('disabled')) {
				console.log('Button is disabled, aborting');
				return;
			}

			$btn.prop('disabled', true);
			$statusMsg.text('');

			$.post(ajaxurl, {
				action: 'nginx_helper_preload_start',
				nonce: nginx_helper.preload_nonce
			}, function(response) {
				console.log('Start preload response:', response);
				if (response.success) {
					$statusMsg.text(nginx_helper.i18n.preload_started);
					$stopBtn.prop('disabled', false);
					showProgressBanner();
					startProgressPolling();
				} else {
					$btn.prop('disabled', false);
					$statusMsg.text(nginx_helper.i18n.error + ' ' + (response.data.message || ''));
				}
			}).fail(function(xhr, status, error) {
				console.error('Start preload AJAX failed:', status, error);
				$btn.prop('disabled', false);
				$statusMsg.text(nginx_helper.i18n.error);
			});
		});

		// Stop Preload - use document delegation for reliability.
		$(document).on('click', '#nginx-preload-stop', function(e) {
			e.preventDefault();
			console.log('Stop preload button clicked');

			if (!confirm(nginx_helper.i18n.confirm_stop)) {
				return;
			}

			var $btn = $(this);
			var $startBtn = $('#nginx-preload-start');
			var $statusMsg = $('#nginx-preload-status-message');

			$btn.prop('disabled', true);

			$.post(ajaxurl, {
				action: 'nginx_helper_preload_stop',
				nonce: nginx_helper.preload_nonce
			}, function(response) {
				console.log('Stop preload response:', response);
				if (response.success) {
					$statusMsg.text(nginx_helper.i18n.preload_stopped);
					$startBtn.prop('disabled', false);
					stopProgressPolling();
					hideProgressBanner();
				} else {
					$btn.prop('disabled', false);
					$statusMsg.text(nginx_helper.i18n.error + ' ' + (response.data.message || ''));
				}
			}).fail(function(xhr, status, error) {
				console.error('Stop preload AJAX failed:', status, error);
				$btn.prop('disabled', false);
				$statusMsg.text(nginx_helper.i18n.error);
			});
		});

		// ========================================
		// Progress Banner Functions
		// ========================================
		function showProgressBanner() {
			$('#nginx-preload-progress-banner').show();
		}

		function hideProgressBanner() {
			$('#nginx-preload-progress-banner').hide();
		}

		function updateProgressBanner(progress) {
			var percent = progress.total_items > 0 
				? (progress.processed_items / progress.total_items * 100) 
				: 0;

			$('#preload-progress-text').text(
				progress.processed_items + ' / ' + progress.total_items + ' items processed'
			);

			$('#nginx-preload-progress-fill').css('width', percent + '%');

			if (progress.current_url) {
				$('#preload-current-url').text(' - ' + progress.current_url);
			} else {
				$('#preload-current-url').text('');
			}
		}

		// ========================================
		// Progress Polling
		// ========================================
		function startProgressPolling() {
			if (preloadPollingInterval) {
				clearInterval(preloadPollingInterval);
			}

			preloadPollingInterval = setInterval(pollProgress, 2000);
			pollProgress(); // Immediate first poll.
		}

		function stopProgressPolling() {
			if (preloadPollingInterval) {
				clearInterval(preloadPollingInterval);
				preloadPollingInterval = null;
			}
		}

		function pollProgress() {
			$.post(ajaxurl, {
				action: 'nginx_helper_preload_progress',
				nonce: nginx_helper.preload_nonce
			}, function(response) {
				if (response.success) {
					var progress = response.data;
					updateProgressBanner(progress);

					if (progress.status === 'completed' || progress.status === 'idle') {
						stopProgressPolling();
						$('#nginx-preload-start').prop('disabled', false);
						$('#nginx-preload-stop').prop('disabled', true);
						$('#nginx-preload-status-message').text(nginx_helper.i18n.preload_complete);
					} else if (progress.status === 'running') {
						// Trigger continuation.
						continuePreload();
					}
				}
			});
		}

		function continuePreload() {
			$.post(ajaxurl, {
				action: 'nginx_helper_preload_continue',
				nonce: nginx_helper.preload_nonce
			});
		}

		// Check if preload is running on page load.
		if ($('#nginx-preload-progress-banner').is(':visible')) {
			startProgressPolling();
		}

		// ========================================
		// Per-Page Actions
		// ========================================

		// Reset (re-cache) single page.
		$(document).on('click', '.nginx-reset-page', function(e) {
			e.preventDefault();
			console.log('Reset page button clicked');

			var $btn = $(this);
			var url = $btn.data('url');
			var $row = $btn.closest('tr');

			console.log('Resetting page:', url);

			$btn.prop('disabled', true);

			$.post(ajaxurl, {
				action: 'nginx_helper_preload_single',
				nonce: nginx_helper.preload_nonce,
				url: url
			}, function(response) {
				console.log('Reset page response:', response);
				$btn.prop('disabled', false);

				if (response.success) {
					// Update row status visually.
					$row.find('.status')
						.removeClass('not-cached partial')
						.addClass('cached');
					$row.find('.status .dashicons')
						.removeClass('dashicons-dismiss dashicons-warning nginx-status-error nginx-status-warning')
						.addClass('dashicons-yes-alt nginx-status-ok');
				}
			}).fail(function(xhr, status, error) {
				console.error('Reset page AJAX failed:', status, error);
				$btn.prop('disabled', false);
			});
		});

		// Toggle page enabled/disabled.
		$(document).on('change', '.nginx-page-enabled', function() {
			console.log('Toggle page enabled changed');

			var $checkbox = $(this);
			var url = $checkbox.data('url');
			var enabled = $checkbox.is(':checked');

			console.log('Toggling page:', url, 'Enabled:', enabled);

			$.post(ajaxurl, {
				action: 'nginx_helper_toggle_page',
				nonce: nginx_helper.preload_nonce,
				url: url,
				enabled: enabled ? 1 : 0
			}, function(response) {
				console.log('Toggle page response:', response);
			}).fail(function(xhr, status, error) {
				console.error('Toggle page AJAX failed:', status, error);
			});
		});

		// ========================================
		// Diagnostics Modal
		// ========================================
		$(document).on('click', '.nginx-diagnostics-btn', function(e) {
			e.preventDefault();
			console.log('Diagnostics button clicked');

			var url = $(this).data('url');
			var $dialog = $('#nginx-diagnostics-dialog');
			var $content = $dialog.find('.nginx-diagnostics-content');

			console.log('Opening diagnostics for URL:', url);

			// Update dialog title.
			$dialog.dialog('option', 'title', 'Cache Diagnostics: ' + url);

			// Show loading state.
			$content.html('<p class="loading">' + nginx_helper.i18n.loading + '</p>');
			$dialog.dialog('open');

			// Fetch diagnostics.
			$.post(ajaxurl, {
				action: 'nginx_helper_get_diagnostics',
				nonce: nginx_helper.preload_nonce,
				url: url
			}, function(response) {
				console.log('Diagnostics response:', response);
				if (response.success && response.data.html) {
					$content.html(response.data.html);
				} else {
					$content.html('<p class="error">' + nginx_helper.i18n.error + '</p>');
				}
			}).fail(function(xhr, status, error) {
				console.error('Diagnostics AJAX failed:', status, error);
				$content.html('<p class="error">' + nginx_helper.i18n.error + '</p>');
			});
		});

		// ========================================
		// Orphan Details Dialog Initialization
		// ========================================
		try {
			if ($('#nginx-orphan-details-dialog').length && typeof $.fn.dialog === 'function') {
				$('#nginx-orphan-details-dialog').dialog({
					autoOpen: false,
					modal: true,
					width: 600,
					dialogClass: 'nginx-orphan-details-dialog',
					buttons: {
						Close: function() {
							$(this).dialog('close');
						}
					}
				});
			}
		} catch (e) {
			console.warn('Orphan details dialog initialization failed:', e);
		}

		// ========================================
		// Orphaned Files Scanning
		// ========================================
		var orphanedFilesData = []; // Store orphaned files data for filtering.

		$(document).on('click', '#nginx-scan-orphans', function(e) {
			e.preventDefault();
			console.log('Scan orphans button clicked');

			var $btn = $(this);
			var $tbody = $('#orphaned-files-tbody');

			$btn.prop('disabled', true).text(nginx_helper.i18n.loading);

			$.post(ajaxurl, {
				action: 'nginx_helper_scan_orphans',
				nonce: nginx_helper.preload_nonce
			}, function(response) {
				$btn.prop('disabled', false).text('Scan for Orphaned Files');

				if (response.success) {
					orphanedFilesData = response.data.orphaned;
					$('#orphaned-count').text(response.data.count);
					orphansLoaded = true;

					renderOrphanedFilesTable(orphanedFilesData);
				} else {
					$tbody.html('<tr class="no-items"><td colspan="5">' + nginx_helper.i18n.error + '</td></tr>');
				}
			}).fail(function() {
				$btn.prop('disabled', false).text('Scan for Orphaned Files');
				$tbody.html('<tr class="no-items"><td colspan="5">' + nginx_helper.i18n.error + '</td></tr>');
			});
		});

		function renderOrphanedFilesTable(data) {
			var $tbody = $('#orphaned-files-tbody');
			var siteUrl = window.location.origin;

			$tbody.empty();

			if (data.length === 0) {
				$tbody.html('<tr class="no-items"><td colspan="5">No orphaned cache files found.</td></tr>');
				return;
			}

			var html = '';
			for (var i = 0; i < data.length; i++) {
				var file = data[i];
				var urlPath = file.url_path || '(unknown)';
				var fullUrl = urlPath !== '(unknown)' ? siteUrl + urlPath : '#';
				
				html += '<tr data-timestamp="' + file.date_raw + '">';
				html += '<td class="url-path">';
				if (urlPath !== '(unknown)') {
					html += '<a href="' + escapeHtml(fullUrl) + '" target="_blank">' + escapeHtml(urlPath) + ' <span class="dashicons dashicons-external"></span></a>';
				} else {
					html += '<span class="nginx-unknown-path">' + escapeHtml(urlPath) + '</span>';
				}
				html += '</td>';
				html += '<td class="rel-path"><code>' + escapeHtml(file.relative_path) + '</code></td>';
				html += '<td class="size" data-sort="' + file.size_raw + '">' + escapeHtml(file.size) + '</td>';
				html += '<td class="date" data-sort="' + file.date_raw + '">' + escapeHtml(file.date) + '</td>';
				html += '<td class="details"><button type="button" class="button button-small nginx-orphan-details-btn" ';
				html += 'data-key="' + escapeHtml(file.key) + '" ';
				html += 'data-path="' + escapeHtml(file.path) + '" ';
				html += 'data-size="' + escapeHtml(file.size) + '" ';
				html += 'data-date="' + escapeHtml(file.date) + '">';
				html += '<span class="dashicons dashicons-info"></span></button></td>';
				html += '</tr>';
			}
			$tbody.html(html);

			// Re-initialize List.js.
			if (orphanedList) {
				orphanedList.reIndex();
			} else {
				try {
					orphanedList = new List('orphaned-files-list', {
						valueNames: ['url-path', 'rel-path', 'size', 'date'],
						page: 20,
						pagination: {
							paginationClass: 'orphaned-pagination',
							innerWindow: 2,
							outerWindow: 1
						}
					});
				} catch (e) {
					console.warn('List.js initialization failed for orphaned files:', e);
				}
			}
		}

		// Orphan details button handler.
		$(document).on('click', '.nginx-orphan-details-btn', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var $dialog = $('#nginx-orphan-details-dialog');

			$dialog.find('.nginx-orphan-detail-key').text($btn.data('key'));
			$dialog.find('.nginx-orphan-detail-path').text($btn.data('path'));
			$dialog.find('.nginx-orphan-detail-size').text($btn.data('size'));
			$dialog.find('.nginx-orphan-detail-date').text($btn.data('date'));

			$dialog.dialog('open');
		});

		// ========================================
		// Date Filter Buttons - Discovered URLs
		// ========================================
		$(document).on('click', '#discovered-urls-list .date-filter-btn', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var days = $btn.data('days');

			$('#discovered-urls-list .date-filter-btn').removeClass('active');
			$btn.addClass('active');

			filterDiscoveredByDate(days);
		});

		function filterDiscoveredByDate(days) {
			var $rows = $('#discovered-urls-list tbody tr');
			var now = Math.floor(Date.now() / 1000);
			var cutoff = days ? now - (days * 24 * 60 * 60) : 0;

			$rows.each(function() {
				var $row = $(this);
				var timestamp = parseInt($row.data('cache-timestamp'), 10) || 0;

				if (days === '' || days === undefined || days === null) {
					$row.show();
				} else if (timestamp >= cutoff) {
					$row.show();
				} else {
					$row.hide();
				}
			});

			// Re-index List.js if active.
			if (discoveredList) {
				discoveredList.reIndex();
			}
		}

		// ========================================
		// Date Filter Buttons - Orphaned Files
		// ========================================
		$(document).on('click', '#orphaned-files-list .date-filter-btn', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var days = $btn.data('days');

			$('#orphaned-files-list .date-filter-btn').removeClass('active');
			$btn.addClass('active');

			filterOrphanedByDate(days);
		});

		function filterOrphanedByDate(days) {
			var $rows = $('#orphaned-files-tbody tr');
			var now = Math.floor(Date.now() / 1000);
			var cutoff = days ? now - (days * 24 * 60 * 60) : 0;

			$rows.each(function() {
				var $row = $(this);
				if ($row.hasClass('no-items')) return;

				var timestamp = parseInt($row.data('timestamp'), 10) || 0;

				if (days === '' || days === undefined || days === null) {
					$row.show();
				} else if (timestamp >= cutoff) {
					$row.show();
				} else {
					$row.hide();
				}
			});

			// Re-index List.js if active.
			if (orphanedList) {
				orphanedList.reIndex();
			}
		}

		// ========================================
		// Utility Functions
		// ========================================
		function escapeHtml(text) {
			if (!text) return '';
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(text));
			return div.innerHTML;
		}

	});
})( jQuery );
