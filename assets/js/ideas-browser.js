/**
 * PRAutoBlogger Ideas Browser — "Generate Article" button handler.
 *
 * Each idea row has a Generate button that triggers single-article generation
 * via AJAX → WP-Cron background job. Polls for status and shows stage updates
 * in the button cell, then swaps to a "View" link on completion.
 *
 * @see admin/class-ideas-browser.php — AJAX handlers.
 * @see templates/admin/ideas-browser.php — Button markup.
 */
(function ($) {
	'use strict';

	var config = window.prautobloggerAdmin || {};
	var pollTimers = {};

	/**
	 * Kick off generation for an idea and start polling.
	 *
	 * @param {jQuery} $cell  The table cell containing the button.
	 * @param {number} ideaId Analysis result row ID.
	 */
	function generateFromIdea($cell, ideaId) {
		$cell.html(
			'<span class="dashicons dashicons-update ab-spin"></span> ' +
			'<span class="prab-idea-stage">Starting…</span>'
		);

		$.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_generate_from_idea',
				nonce: config.ideaGenNonce,
				idea_id: ideaId
			},
			timeout: 15000,
			success: function (response) {
				if (response.success) {
					pollIdeaStatus($cell, ideaId);
				} else {
					showIdeaError($cell, ideaId, response.data && response.data.message || 'Failed to start.');
				}
			},
			error: function () {
				showIdeaError($cell, ideaId, 'Request failed.');
			}
		});
	}

	/**
	 * Poll the per-idea status endpoint until generation completes.
	 *
	 * @param {jQuery} $cell  The table cell to update.
	 * @param {number} ideaId Analysis result row ID.
	 */
	function pollIdeaStatus($cell, ideaId) {
		pollTimers[ideaId] = setInterval(function () {
			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				data: {
					action: 'prautoblogger_idea_gen_status',
					nonce: config.ideaGenNonce,
					idea_id: ideaId
				},
				timeout: 10000,
				success: function (response) {
					if (!response.success) return;
					var d = response.data;

					if (d.status === 'running') {
						$cell.find('.prab-idea-stage').text(d.stage || 'Generating…');
						return;
					}

					// Terminal — stop polling.
					clearInterval(pollTimers[ideaId]);
					delete pollTimers[ideaId];

					if (d.status === 'complete') {
						showIdeaComplete($cell, ideaId, d);
					} else if (d.status === 'error') {
						showIdeaError($cell, ideaId, d.message || 'Generation failed.');
					} else {
						showIdeaError($cell, ideaId, 'Unknown status.');
					}
				}
			});
		}, 4000);
	}

	/**
	 * Show the completion state with a View link.
	 *
	 * @param {jQuery} $cell  The table cell.
	 * @param {number} ideaId Analysis result row ID.
	 * @param {Object} data   Server response data.
	 */
	function showIdeaComplete($cell, ideaId, data) {
		var html = '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ';
		html += '<span style="color:#00a32a;">Done</span>';

		if (data.post_id) {
			var editUrl = config.adminUrl + 'post.php?post=' + data.post_id + '&action=edit';
			var viewUrl = config.adminUrl + '?p=' + data.post_id;
			html += ' &mdash; <a href="' + editUrl + '">Edit</a>';
			html += ' | <a href="' + viewUrl + '" target="_blank">View</a>';
		}

		if (data.cost > 0) {
			html += '<div style="font-size:11px;color:#666;margin-top:2px;">$' + parseFloat(data.cost).toFixed(4) + '</div>';
		}

		$cell.html(html);
	}

	/**
	 * Show an error state with a retry button.
	 *
	 * @param {jQuery} $cell   The table cell.
	 * @param {number} ideaId  Analysis result row ID.
	 * @param {string} message Error message.
	 */
	function showIdeaError($cell, ideaId, message) {
		$cell.html(
			'<span class="dashicons dashicons-warning" style="color:#d63638;"></span> ' +
			'<span style="color:#d63638; font-size:12px;">' + $('<span>').text(message).html() + '</span>' +
			'<br><button type="button" class="button button-small prab-gen-idea-btn" data-idea-id="' + ideaId + '" style="margin-top:4px;">Retry</button>'
		);
	}

	// Click handler for Generate / Retry buttons (event delegation).
	$(document).on('click', '.prab-gen-idea-btn', function () {
		var $btn = $(this);
		if ($btn.prop('disabled')) return;

		var ideaId = $btn.data('idea-id');
		var $cell  = $btn.closest('td');

		generateFromIdea($cell, ideaId);
	});

	// On page load, resume polling for any ideas that are mid-generation.
	$(function () {
		$('.prab-idea-gen-cell[data-status="running"]').each(function () {
			var $cell  = $(this);
			var ideaId = $cell.data('idea-id');
			$cell.html(
				'<span class="dashicons dashicons-update ab-spin"></span> ' +
				'<span class="prab-idea-stage">Resuming…</span>'
			);
			pollIdeaStatus($cell, ideaId);
		});
	});

})(jQuery);
