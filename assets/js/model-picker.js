/**
 * Model picker popup for PRAutoBlogger admin settings.
 *
 * Fetches the OpenRouter model registry via AJAX, then renders a searchable
 * popup overlay when the user clicks a model-select trigger button. Shows
 * name, provider, input/output cost, and context window per model.
 *
 * @see admin/class-admin-page.php — Renders model_select fields and localizes config.
 * @see services/class-open-router-model-registry.php — Server-side registry source.
 */
(function ($) {
	'use strict';

	var config = window.prautobloggerAdmin || {};
	var cachedModels = null;
	var fetchInProgress = null;

	/**
	 * Fetch models from the server (cached after first call).
	 *
	 * @param {string} capability Filter capability (e.g. 'text→text').
	 * @return {jQuery.Deferred} Resolves with model array.
	 */
	function fetchModels(capability) {
		// Image models are embedded in the page config — no AJAX needed.
		if (capability === 'image_generation') {
			var imgModels = config.imageModels || [];
			return $.Deferred().resolve(imgModels);
		}

		if (cachedModels) {
			return $.Deferred().resolve(filterModels(cachedModels, capability));
		}

		if (fetchInProgress) {
			return fetchInProgress.then(function () {
				return filterModels(cachedModels, capability);
			});
		}

		fetchInProgress = $.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			data: {
				action: 'prautoblogger_get_models',
				nonce: config.modelsNonce
			},
			timeout: 15000
		}).then(function (response) {
			fetchInProgress = null;
			if (response.success && Array.isArray(response.data)) {
				cachedModels = response.data;
				return filterModels(cachedModels, capability);
			}
			cachedModels = [];
			return [];
		}, function () {
			fetchInProgress = null;
			cachedModels = [];
			return [];
		});

		return fetchInProgress;
	}

	/**
	 * Filter model array by capability.
	 *
	 * @param {Array}  models     Full model list.
	 * @param {string} capability Capability string to match.
	 * @return {Array} Filtered models.
	 */
	function filterModels(models, capability) {
		if (!capability) return models;
		return models.filter(function (m) {
			return m.capabilities && m.capabilities.indexOf(capability) !== -1;
		});
	}

	/**
	 * Format a price-per-million-tokens value for display.
	 *
	 * @param {number} price Price per 1M tokens.
	 * @return {string} Formatted string like "$0.25" or "Free".
	 */
	function formatPrice(price) {
		if (!price || price <= 0) return 'Free';
		if (price < 0.01) return '$' + price.toFixed(4);
		if (price < 1) return '$' + price.toFixed(2);
		return '$' + price.toFixed(2);
	}

	/**
	 * Format context length for display.
	 *
	 * @param {number} ctx Context length in tokens.
	 * @return {string} Formatted string like "128K" or "1M".
	 */
	function formatContext(ctx) {
		if (!ctx) return '—';
		if (ctx >= 1000000) return (ctx / 1000000).toFixed(0) + 'M';
		if (ctx >= 1000) return Math.round(ctx / 1000) + 'K';
		return ctx.toString();
	}

	/**
	 * Build HTML for a single model row in the picker list.
	 *
	 * @param {Object} model Normalized model record.
	 * @return {string} HTML string.
	 */
	function renderModelRow(model) {
		var deprecated = model.deprecated ? ' ab-mp-deprecated' : '';
		var isImageModel = model.cost_per_image !== undefined && model.cost_per_image > 0;
		var priceHtml, priceTitle, metaRight;

		if (isImageModel) {
			priceHtml  = '$' + model.cost_per_image.toFixed(4) + '/image';
			priceTitle = 'Cost per generated image';
			metaRight  = model.description ? '<span class="ab-mp-ctx" title="Details">' + escHtml(model.description) + '</span>' : '';
		} else {
			priceHtml  = formatPrice(model.input_price_per_m) + ' / ' + formatPrice(model.output_price_per_m);
			priceTitle = 'Input / Output per 1M tokens';
			metaRight  = '<span class="ab-mp-ctx" title="Context window">' + formatContext(model.context_length) + '</span>';
		}

		return (
			'<div class="ab-mp-row' + deprecated + '" data-model-id="' + escAttr(model.id) + '">' +
				'<div class="ab-mp-row-main">' +
					'<span class="ab-mp-name">' + escHtml(model.name) + '</span>' +
					'<span class="ab-mp-provider">' + escHtml(model.provider) + '</span>' +
					(model.deprecated ? '<span class="ab-mp-dep-badge">deprecated</span>' : '') +
				'</div>' +
				'<div class="ab-mp-row-meta">' +
					'<span class="ab-mp-price" title="' + priceTitle + '">' + priceHtml + '</span>' +
					metaRight +
				'</div>' +
			'</div>'
		);
	}

	/** Minimal HTML escaping for display. */
	function escHtml(str) {
		if (!str) return '';
		return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	/** Escape a string for use in an HTML attribute. */
	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	/**
	 * Open the model picker popup for a given trigger button.
	 *
	 * @param {jQuery} $trigger The .ab-mp-trigger button that was clicked.
	 */
	function openPicker($trigger) {
		var fieldId = $trigger.data('field-id');
		var capability = $trigger.data('capability') || '';

		// Build overlay + popup structure.
		var isImageCap = capability === 'image_generation';
		var priceLabel = isImageCap ? 'Cost per generated image' : 'Prices per 1M tokens (in / out)';

		var $overlay = $('<div class="ab-mp-overlay"></div>');
		var $popup = $(
			'<div class="ab-mp-popup">' +
				'<div class="ab-mp-header">' +
					'<span class="ab-mp-title">Select Model</span>' +
					'<button type="button" class="ab-mp-close">&times;</button>' +
				'</div>' +
				'<div class="ab-mp-search-wrap">' +
					'<input type="text" class="ab-mp-search" placeholder="Search models…" autocomplete="off" />' +
				'</div>' +
				'<div class="ab-mp-list"><div class="ab-mp-loading">Loading models…</div></div>' +
				'<div class="ab-mp-footer">' +
					'<span class="ab-mp-count"></span>' +
					'<span class="ab-mp-price-label">' + priceLabel + '</span>' +
				'</div>' +
			'</div>'
		);

		$overlay.append($popup);
		$('body').append($overlay);

		var $search = $popup.find('.ab-mp-search');
		var $list = $popup.find('.ab-mp-list');
		var $count = $popup.find('.ab-mp-count');
		var allModels = [];

		// Focus search after render.
		setTimeout(function () { $search.focus(); }, 50);

		// Fetch and render model list.
		fetchModels(capability).then(function (models) {
			allModels = models;
			renderList(models, '');
		});

		/** Render filtered model list. */
		function renderList(models, query) {
			var filtered = models;
			if (query) {
				var q = query.toLowerCase();
				filtered = models.filter(function (m) {
					return (m.name && m.name.toLowerCase().indexOf(q) !== -1) ||
						(m.id && m.id.toLowerCase().indexOf(q) !== -1) ||
						(m.provider && m.provider.toLowerCase().indexOf(q) !== -1);
				});
			}

			// Sort: non-deprecated first, then by provider + name.
			filtered.sort(function (a, b) {
				if (a.deprecated !== b.deprecated) return a.deprecated ? 1 : -1;
				var pa = (a.provider || '').toLowerCase();
				var pb = (b.provider || '').toLowerCase();
				if (pa !== pb) return pa < pb ? -1 : 1;
				return (a.name || '').toLowerCase().localeCompare((b.name || '').toLowerCase());
			});

			if (filtered.length === 0) {
				$list.html('<div class="ab-mp-empty">No models found.</div>');
			} else {
				var html = '';
				for (var i = 0; i < filtered.length; i++) {
					html += renderModelRow(filtered[i]);
				}
				$list.html(html);
			}
			$count.text(filtered.length + ' model' + (filtered.length !== 1 ? 's' : ''));
		}

		// Search input handler (debounced).
		var searchTimer = null;
		$search.on('input', function () {
			var q = $(this).val();
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				renderList(allModels, q);
			}, 150);
		});

		// Select a model.
		$list.on('click', '.ab-mp-row', function () {
			var id = $(this).data('model-id');
			var model = allModels.find(function (m) { return m.id === id; });
			if (!model) return;

			// Update hidden input + display.
			$('#' + fieldId).val(model.id);
			$trigger.find('.ab-mp-display-name').text(model.name || model.id);

			var isImageModel = model.cost_per_image !== undefined && model.cost_per_image > 0;
			var priceText = isImageModel
				? '$' + model.cost_per_image.toFixed(4) + '/image'
				: formatPrice(model.input_price_per_m) + ' / ' + formatPrice(model.output_price_per_m);
			$trigger.find('.ab-mp-display-price').text(priceText);
			closePicker();
		});

		// Close handlers.
		function closePicker() {
			$overlay.remove();
		}

		$popup.find('.ab-mp-close').on('click', closePicker);
		$overlay.on('click', function (e) {
			if ($(e.target).is('.ab-mp-overlay')) closePicker();
		});
		$(document).on('keydown.abModelPicker', function (e) {
			if (e.key === 'Escape') {
				closePicker();
				$(document).off('keydown.abModelPicker');
			}
		});
	}

	// Bind click on all model picker triggers.
	$(document).on('click', '.ab-mp-trigger', function (e) {
		e.preventDefault();
		openPicker($(this));
	});

})(jQuery);
