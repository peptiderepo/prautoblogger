/**
 * PRAutoBlogger Posts Widget — React Frontend Component
 *
 * Uses wp.element (WordPress-bundled React) — no build step needed.
 * Fetches posts from the plugin's REST API and renders a card grid.
 *
 * @see includes/frontend/class-posts-widget.php — Registers the shortcode and REST endpoint.
 * @see assets/css/posts-widget.css              — Styles for all widget states.
 */

/* global wp, prabPostsWidget */

(function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	/**
	 * SVG icon components — inline to avoid external dependencies.
	 */
	var ClockIcon = function () {
		return el('svg', {
			viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2'
		},
			el('circle', { cx: '12', cy: '12', r: '10' }),
			el('polyline', { points: '12 6 12 12 16 14' })
		);
	};

	var ArrowIcon = function () {
		return el('svg', {
			viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '2'
		},
			el('path', { d: 'M5 12h14M12 5l7 7-7 7' })
		);
	};

	var BookIcon = function () {
		return el('svg', {
			viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '1.5'
		},
			el('path', { d: 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25' })
		);
	};

	var DocumentIcon = function () {
		return el('svg', {
			viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: '1.5'
		},
			el('path', { d: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z' })
		);
	};

	/**
	 * Map category slug to a CSS modifier class.
	 *
	 * @param {string} categorySlug The category slug from the REST API.
	 * @return {string} CSS class suffix.
	 */
	function getCategoryClass(categorySlug) {
		var slug = (categorySlug || '').toLowerCase();
		var map = {
			guides: 'guides',
			solutions: 'solutions',
			comparisons: 'comparisons',
			articles: 'articles'
		};
		return map[slug] || 'default';
	}

	/**
	 * Format a date string to "Mon DD, YYYY".
	 *
	 * @param {string} dateStr ISO 8601 date string.
	 * @return {string} Formatted date.
	 */
	function formatDate(dateStr) {
		var date = new Date(dateStr);
		if (isNaN(date.getTime())) {
			return 'Recent';
		}
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
			'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
	}

	/**
	 * Estimate reading time from word count.
	 *
	 * @param {number} wordCount Number of words.
	 * @return {number} Minutes to read (minimum 1).
	 */
	function readingTime(wordCount) {
		return Math.max(1, Math.round((wordCount || 0) / 250));
	}

	/**
	 * Decode HTML entities in a string (e.g., &#8217; → ').
	 *
	 * Uses a DOMParser to safely decode entities without executing scripts.
	 * Falls back to common entity replacements if DOMParser is unavailable.
	 *
	 * @param {string} text Text that may contain HTML entities.
	 * @return {string} Decoded plain text.
	 */
	function decodeEntities(text) {
		if (!text) {
			return '';
		}
		if (typeof DOMParser !== 'undefined') {
			var doc = new DOMParser().parseFromString(text, 'text/html');
			return doc.body.textContent || '';
		}
		// Fallback: decode common entities manually.
		return text
			.replace(/&#8217;/g, '\u2019')
			.replace(/&#8216;/g, '\u2018')
			.replace(/&#8220;/g, '\u201C')
			.replace(/&#8221;/g, '\u201D')
			.replace(/&#8211;/g, '\u2013')
			.replace(/&#8212;/g, '\u2014')
			.replace(/&amp;/g, '&')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"')
			.replace(/&#039;/g, "'");
	}

	/**
	 * Strip HTML tags from a string.
	 *
	 * Uses a DOMParser to safely parse HTML without executing scripts.
	 * Falls back to regex stripping if DOMParser is unavailable.
	 *
	 * @param {string} html HTML string.
	 * @return {string} Plain text.
	 */
	function stripHtml(html) {
		if (!html) {
			return '';
		}
		if (typeof DOMParser !== 'undefined') {
			var doc = new DOMParser().parseFromString(html, 'text/html');
			return doc.body.textContent || '';
		}
		// Fallback: regex strip (less accurate but safe).
		return html.replace(/<[^>]*>/g, '');
	}

	/**
	 * Single post card component.
	 */
	var PostCard = function (props) {
		var post = props.post;
		var catClass = getCategoryClass(post.category_slug);
		var minutes = readingTime(post.word_count);
		var title = decodeEntities(post.title);
		var excerpt = stripHtml(post.excerpt);

		// Choose placeholder icon based on category
		var PlaceholderIcon = post.category_slug === 'guides' ? BookIcon : DocumentIcon;

		return el('a', {
			href: post.url || '#',
			className: 'prab-post-card',
			'aria-label': title
		},
			// Image area
			post.featured_image
				? el('div', { className: 'prab-post-image' },
					el('img', {
						src: post.featured_image,
						alt: post.title || '',
						loading: 'lazy',
						decoding: 'async'
					})
				)
				: el('div', { className: 'prab-post-image prab-post-image--placeholder' },
					el(PlaceholderIcon)
				),

			// Body
			el('div', { className: 'prab-post-body' },
				// Meta row
				el('div', { className: 'prab-post-meta' },
					post.category_name
						? el('span', {
							className: 'prab-badge prab-badge--' + catClass
						}, post.category_name)
						: null,
					el('span', { className: 'prab-post-date' }, formatDate(post.date))
				),

				// Title
				el('h3', { className: 'prab-post-title' }, title),

				// Excerpt
				el('p', { className: 'prab-post-excerpt' }, excerpt),

				// Footer
				el('div', { className: 'prab-post-footer' },
					el('span', { className: 'prab-reading-time' },
						el(ClockIcon),
						minutes + ' min read'
					),
					el('span', { className: 'prab-read-more' },
						'Read article',
						el(ArrowIcon)
					)
				)
			)
		);
	};

	/**
	 * Loading skeleton card.
	 */
	var SkeletonCard = function () {
		return el('div', { className: 'prab-post-card', style: { pointerEvents: 'none' } },
			el('div', { className: 'prab-skeleton prab-skeleton-image' }),
			el('div', { className: 'prab-post-body' },
				el('div', { className: 'prab-post-meta' },
					el('div', { className: 'prab-skeleton prab-skeleton-badge' })
				),
				el('div', { className: 'prab-skeleton prab-skeleton-title' }),
				el('div', { className: 'prab-skeleton prab-skeleton-title-2' }),
				el('div', { className: 'prab-skeleton prab-skeleton-text', style: { marginTop: '8px' } }),
				el('div', { className: 'prab-skeleton prab-skeleton-text-2' })
			)
		);
	};

	/**
	 * Empty state component.
	 */
	var EmptyState = function () {
		return el('div', { className: 'prab-empty-state' },
			el(DocumentIcon),
			el('h3', null, 'No articles yet'),
			el('p', null, 'New research articles will appear here once they\'re published. Check back soon!')
		);
	};

	/**
	 * Error state component.
	 */
	var ErrorState = function (props) {
		return el('div', { className: 'prab-error-state' },
			el('p', null, props.message || 'Unable to load articles. Please try again later.')
		);
	};

	/**
	 * Main widget component.
	 *
	 * Fetches posts from the REST API on mount and renders the appropriate state.
	 */
	var PostsWidget = function (props) {
		var config = props.config || {};
		var count = config.count || 6;
		var title = config.title || 'Latest Research & Insights';
		var subtitle = config.subtitle || 'Evidence-based articles on peptides, protocols, and emerging research';
		var category = config.category || '';
		var archiveUrl = config.archiveUrl || '';

		var stateArr = useState('loading');
		var state = stateArr[0];
		var setState = stateArr[1];

		var postsArr = useState([]);
		var posts = postsArr[0];
		var setPosts = postsArr[1];

		var errorArr = useState('');
		var error = errorArr[0];
		var setError = errorArr[1];

		useEffect(function () {
			if (!config.restUrl) {
				setError('Widget misconfigured: REST URL not available.');
				setState('error');
				return;
			}

			var url = config.restUrl + '?per_page=' + count;
			if (category) {
				url += '&category=' + encodeURIComponent(category);
			}

			fetch(url)
				.then(function (response) {
					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}
					return response.json();
				})
				.then(function (data) {
					setPosts(data);
					setState(data.length > 0 ? 'loaded' : 'empty');
				})
				.catch(function (err) {
					setError(err.message);
					setState('error');
				});
		}, []); // Fetch only on mount; results persist for page lifetime.

		// Build children array
		var children = [];

		// Header
		children.push(
			el('div', { className: 'prab-widget-header', key: 'header' },
				el('h2', null, title),
				el('p', null, subtitle)
			)
		);

		// Content based on state
		if (state === 'loading') {
			var skeletons = [];
			for (var i = 0; i < Math.min(count, 3); i++) {
				skeletons.push(el(SkeletonCard, { key: 'skeleton-' + i }));
			}
			children.push(el('div', { className: 'prab-posts-grid', key: 'grid' }, skeletons));

		} else if (state === 'empty') {
			children.push(el(EmptyState, { key: 'empty' }));

		} else if (state === 'error') {
			children.push(el(ErrorState, { key: 'error', message: error }));

		} else {
			// Loaded
			var cards = posts.map(function (post) {
				return el(PostCard, { key: post.id, post: post });
			});
			children.push(el('div', { className: 'prab-posts-grid', key: 'grid' }, cards));

			// "View all" link
			if (archiveUrl) {
				children.push(
					el('div', { className: 'prab-view-all', key: 'viewall' },
						el('a', { href: archiveUrl },
							'View all articles',
							el(ArrowIcon)
						)
					)
				);
			}
		}

		return el('div', { className: 'prab-posts-widget' }, children);
	};

	/**
	 * Mount the widget into the DOM.
	 *
	 * Reads configuration from the global `prabPostsWidget` object
	 * set by wp_localize_script in the PHP class.
	 */
	function mount() {
		var root = document.getElementById('prab-posts-root');
		if (!root) {
			return;
		}

		var config = window.prabPostsWidget || {};
		wp.element.render(
			el(PostsWidget, { config: config }),
			root
		);
	}

	// Mount when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		mount();
	}

})();
