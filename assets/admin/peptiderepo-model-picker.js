/**
 * Model registry refresh handler for PRAutoBlogger admin settings.
 *
 * Handles the "Refresh Model List" button click in the AI Models admin tab.
 * Sends AJAX request to refresh the OpenRouter model registry cache and shows
 * success/error toast notifications.
 *
 * @see admin/class-admin-page.php — Renders the refresh button + nonce.
 * @see ajax/class-model-registry-refresh.php — Server-side handler.
 */
(function ($) {
	'use strict';

	// Refresh model registry button.
	$(document).on('click', '#prautoblogger-refresh-models', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var nonce = $btn.data('nonce');
		var originalText = $btn.html();

		$btn.prop('disabled', true).addClass('ab-btn-loading');

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'prautoblogger_refresh_models',
				nonce: nonce
			},
			timeout: 30000
		})
		.done(function (response) {
			if (response.success && response.data && response.data.message) {
				// Show success toast
				var $notice = $('<div class="ab-save-notice" role="status"></div>')
					.html('<span class="dashicons dashicons-yes"></span> ' + response.data.message)
					.insertBefore('.ab-layout')
					.delay(4000)
					.fadeOut(function () {
						$(this).remove();
					});
			}
		})
		.fail(function () {
			var $notice = $('<div class="ab-save-notice notice-error" role="alert"></div>')
				.html('<span class="dashicons dashicons-warning"></span> ' + 'Failed to refresh model list. Please try again.')
				.insertBefore('.ab-layout')
				.delay(5000)
				.fadeOut(function () {
					$(this).remove();
				});
		})
		.always(function () {
			$btn.prop('disabled', false).removeClass('ab-btn-loading').html(originalText);
		});
	});

})(jQuery);
