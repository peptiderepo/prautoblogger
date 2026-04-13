/**
 * PRAutoBlogger admin JavaScript.
 *
 * Handles: tab switching, "Generate Now" with pipeline progress stages,
 * "Test Connections" with spinner, review queue actions, save feedback.
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

	/*
	 * ── Save Settings feedback ─────────────────────────────────────────
	 * Shows "Saving…" on submit, auto-hides success notice after a delay.
	 */
	$(document).on('submit', '#ab-settings-form', function () {
		var $btn = $(this).find('input[type="submit"]');
		$btn.val('Saving…').prop('disabled', true);
	});

	// Auto-dismiss save notice after 6 seconds.
	$(function () {
		var $notice = $('#ab-save-notice');
		if ($notice.length) {
			setTimeout(function () {
				$notice.fadeOut(400, function () { $(this).remove(); });
			}, 6000);
		}
	});

	/*
	 * ── Helpers ─────────────────────────────────────────────────────────
	 */

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
		hideProgress();
	}

	/**
	 * Show a progress stage message with spinner.
	 *
	 * @param {string} message The stage text to display.
	 */
	function showProgress(message) {
		var $el = $('#prautoblogger-progress-stage');
		$el.html(
			'<span class="dashicons dashicons-update"></span>' +
			'<span>' + message + '</span>'
		).show();
		// Hide the status message while progress is shown.
		$('#prautoblogger-status-message').addClass('hidden');
	}

	/** Hide the progress stage message. */
	function hideProgress() {
		$('#prautoblogger-progress-stage').hide();
	}

	/**
	 * Set a button into loading state with spinner.
	 *
	 * @param {jQuery}  $btn  The button element.
	 * @param {string}  text  The loading text to show.
	 */
	function setButtonLoading($btn, text) {
		$btn.addClass('ab-btn-loading')
			.prop('disabled', true);
		$btn.find('.ab-btn-label').text(text);
	}

	/**
	 * Reset a button back to its default state.
	 *
	 * @param {jQuery}  $btn  The button element.
	 * @param {string}  text  The default text to restore.
	 */
	function resetButton($btn, text) {
		$btn.removeClass('ab-btn-loading')
			.prop('disabled', false);
		$btn.find('.ab-btn-label').text(text);
	}

	/*
	 * ── Generate Now ───────────────────────────────────────────────────
	 * Shows pipeline stages while the request is in-flight so the user
	 * knows it hasn't hung.
	 */
	var generateStages = [
		{ text: 'Collecting sources from Reddit…',      delay: 0 },
		{ text: 'Analyzing topics and scoring…',        delay: 8000 },
		{ text: 'Selecting best topic…',                delay: 16000 },
		{ text: 'Generating article draft via AI…',     delay: 24000 },
		{ text: 'Running editorial pass…',              delay: 50000 },
		{ text: 'Saving and publishing…',               delay: 75000 },
		{ text: 'Almost done — finalizing…',            delay: 100000 }
	];
	var stageTimers = [];

	$(document).on('click', '#prautoblogger-generate-now', function () {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		setButtonLoading($btn, config.generatingText || 'Generating…');

		// Start pipeline stage messages.
		stageTimers = [];
		$.each(generateStages, function (i, stage) {
			var t = setTimeout(function () { showProgress(stage.text); }, stage.delay);
			stageTimers.push(t);
		});

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_generate_now',
				nonce: config.generateNonce,
				force: '1' // Always clear stale locks on manual runs.
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
				// Clear all stage timers.
				$.each(stageTimers, function (_, t) { clearTimeout(t); });
				stageTimers = [];
				hideProgress();
				resetButton($btn, config.generateText || 'Generate Now');
			}
		});
	});

	/*
	 * ── Test Connections ───────────────────────────────────────────────
	 */
	$(document).on('click', '#prautoblogger-test-connection', function () {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		setButtonLoading($btn, config.testingText || 'Testing…');
		showProgress('Testing API connections…');

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
				resetButton($btn, config.testText || 'Test Connections');
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

		$btn.prop('disabled', true).text('Publishing…');

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

		$btn.prop('disabled', true).text('Rejecting…');

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
