/**
 * PRAutoBlogger admin JavaScript.
 *
 * Handles: tab switching, "Generate Now", "Test Connections", review queue actions.
 * Uses vanilla jQuery (WordPress admin already loads it).
 *
 * @see admin/class-admin-page.php — Localizes prautobloggerAdmin object.
 */
(function ($) {
	'use strict';

	var config = window.prautobloggerAdmin || {};

	/*
	 * ── Settings page: tab switching ───────────────────────────────────
	 * Switches tabs client-side without page reload. Updates URL hash
	 * so the browser back button works and refreshes keep the active tab.
	 */
	$(document).on('click', '.ab-tab', function (e) {
		e.preventDefault();
		var tab = $(this).data('tab');
		if (!tab) return;

		// Switch tab nav
		$('.ab-tab').removeClass('ab-tab-active').attr('aria-selected', 'false');
		$(this).addClass('ab-tab-active').attr('aria-selected', 'true');

		// Switch panel
		$('.ab-panel').removeClass('ab-panel-active');
		$('.ab-panel[data-tab="' + tab + '"]').addClass('ab-panel-active');

		// Update URL without reload
		if (window.history.replaceState) {
			var url = new URL(window.location);
			url.searchParams.set('tab', tab);
			window.history.replaceState({}, '', url);
		}
	});

	/**
	 * Show a status message in the admin page.
	 *
	 * @param {string} message Text to display.
	 * @param {string} type    'success' or 'error'.
	 */
	function showStatus(message, type) {
		var $el = $('#prautoblogger-status-message');
		$el.removeClass('hidden error')
			.text(message);
		if (type === 'error') {
			$el.addClass('error');
		}
	}

	/**
	 * Handle "Generate Now" button click.
	 */
	$(document).on('click', '#prautoblogger-generate-now', function () {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		$btn.prop('disabled', true).text(config.generatingText || 'Generating...');

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_generate_now',
				nonce: config.generateNonce
			},
			timeout: 300000, // 5 minutes — generation can be slow.
			success: function (response) {
				if (response.success) {
					var d = response.data;
					showStatus(
						'Generation complete: ' + d.generated + ' generated, ' +
						d.published + ' published, ' + d.rejected + ' rejected. ' +
						'Cost: $' + parseFloat(d.cost).toFixed(4),
						'success'
					);
				} else {
					showStatus(
						'Generation failed: ' + (response.data && response.data.message || 'Unknown error'),
						'error'
					);
				}
			},
			error: function (xhr, status) {
				showStatus(
					'Request failed: ' + status + '. Check server error logs.',
					'error'
				);
			},
			complete: function () {
				$btn.prop('disabled', false).text(config.generateText || 'Generate Now');
			}
		});
	});

	/**
	 * Handle "Test Connections" button click.
	 */
	$(document).on('click', '#prautoblogger-test-connection', function () {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		$btn.prop('disabled', true).text(config.testingText || 'Testing...');

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_test_connection',
				nonce: config.testNonce,
				service: 'all'
			},
			timeout: 30000,
			success: function (response) {
				if (response.success) {
					var messages = [];
					var hasError = false;
					$.each(response.data, function (service, result) {
						messages.push(service + ': ' + result.message);
						if (result.status === 'error') hasError = true;
					});
					showStatus(messages.join(' | '), hasError ? 'error' : 'success');
				} else {
					showStatus('Connection test failed.', 'error');
				}
			},
			error: function () {
				showStatus('Connection test request failed.', 'error');
			},
			complete: function () {
				$btn.prop('disabled', false).text(config.testText || 'Test Connections');
			}
		});
	});

	/*
	 * Review Queue — inline approve/reject via AJAX.
	 * Removes the row on success so the user sees immediate feedback.
	 */

	/**
	 * Show a status message on the review queue page.
	 *
	 * @param {string} message Text to display.
	 * @param {string} type    'success' or 'error'.
	 */
	function showQueueStatus(message, type) {
		var $el = $('#prautoblogger-queue-status');
		$el.removeClass('hidden error').text(message);
		if (type === 'error') {
			$el.addClass('error');
		}
	}

	/**
	 * Handle inline "Approve" button click on review queue.
	 */
	$(document).on('click', '.prautoblogger-approve-btn', function () {
		var $btn = $(this);
		var postId = $btn.data('post-id');
		if ($btn.prop('disabled')) return;

		$btn.prop('disabled', true).text('Publishing...');

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_approve_post',
				nonce: config.reviewNonce,
				post_id: postId
			},
			timeout: 15000,
			success: function (response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
					showQueueStatus(response.data.message, 'success');
				} else {
					showQueueStatus(response.data && response.data.message || 'Approve failed.', 'error');
					$btn.prop('disabled', false).text('Approve');
				}
			},
			error: function () {
				showQueueStatus('Request failed. Check server logs.', 'error');
				$btn.prop('disabled', false).text('Approve');
			}
		});
	});

	/**
	 * Handle inline "Reject" button click on review queue.
	 */
	$(document).on('click', '.prautoblogger-reject-btn', function () {
		var $btn = $(this);
		var postId = $btn.data('post-id');
		if ($btn.prop('disabled')) return;

		if (!confirm('Reject this post? It will be moved to trash.')) return;

		$btn.prop('disabled', true).text('Rejecting...');

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_reject_post',
				nonce: config.reviewNonce,
				post_id: postId
			},
			timeout: 15000,
			success: function (response) {
				if (response.success) {
					$btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
					showQueueStatus(response.data.message, 'success');
				} else {
					showQueueStatus(response.data && response.data.message || 'Reject failed.', 'error');
					$btn.prop('disabled', false).text('Reject');
				}
			},
			error: function () {
				showQueueStatus('Request failed. Check server logs.', 'error');
				$btn.prop('disabled', false).text('Reject');
			}
		});
	});

	/**
	 * Handle "Select All" checkbox on review queue.
	 */
	$(document).on('change', '#prautoblogger-select-all', function () {
		$('input[name="prautoblogger_post_ids[]"]').prop('checked', this.checked);
	});

	/*
	 * ── Log viewer ─────────────────────────────────────────────────────
	 */

	/** Toggle log entry meta detail. */
	$(document).on('click', '.ab-log-meta-toggle', function () {
		$(this).next('.ab-log-meta').slideToggle(150);
		$(this).find('.dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
	});

	/** Clear old logs button. */
	$(document).on('click', '#prautoblogger-clear-logs', function () {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;
		if (!confirm('Delete log entries older than 30 days?')) return;

		$btn.prop('disabled', true);
		var nonce = $btn.data('nonce');

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: { action: 'prautoblogger_clear_logs', nonce: nonce, days: 30 },
			timeout: 15000,
			success: function (response) {
				var $status = $('#prautoblogger-log-status');
				if (response.success) {
					$status.removeClass('hidden error').text(response.data.message);
					setTimeout(function () { window.location.reload(); }, 1200);
				} else {
					$status.removeClass('hidden').addClass('error').text(response.data && response.data.message || 'Failed.');
				}
			},
			complete: function () { $btn.prop('disabled', false); }
		});
	});

})(jQuery);
