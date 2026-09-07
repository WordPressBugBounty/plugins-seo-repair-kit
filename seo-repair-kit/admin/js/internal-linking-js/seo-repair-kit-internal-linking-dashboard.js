/* global jQuery, srkInternalLinking */
(function ($) {
	'use strict';

	var allowedTabs = [
		'dashboard',
		'content-index',
		'link-opportunities',
		'auto-linking',
		'target-keywords',
		'url-changer',
		'approved-links',
		'orphan-content',
		'reports',
		'settings'
	];

	function postAjax(data) {
		data.nonce = srkInternalLinking.nonce;

		return $.post(
			srkInternalLinking.ajaxUrl,
			data
		);
	}

	function setInternalLinkingTab(
		$wrap,
		tabKey,
		updateUrl
	) {
		if (!$wrap || !$wrap.length) {
			return;
		}

		if (allowedTabs.indexOf(tabKey) === -1) {
			tabKey = 'dashboard';
		}

		var $button = $wrap.find(
			'.srk-il-tab[data-srk-il-tab="' +
			tabKey +
			'"]'
		);

		var $panel = $wrap.find(
			'#srk-il-tab-' + tabKey
		);

		if (!$button.length || !$panel.length) {
			return;
		}

		$wrap
			.find('.srk-il-tab')
			.removeClass('is-active');

		$button.addClass('is-active');

		$wrap
			.find('.srk-il-tab-panel')
			.removeClass('is-active')
			.attr('hidden', true)
			.hide();

		$panel
			.addClass('is-active')
			.removeAttr('hidden')
			.show();

		if (
			updateUrl &&
			window.history &&
			window.history.replaceState
		) {
			var url = new URL(
				window.location.href
			);

			if (tabKey === 'dashboard') {
				url.searchParams.delete(
					'srk_il_tab'
				);
			} else {
				url.searchParams.set(
					'srk_il_tab',
					tabKey
				);
			}

			window.history.replaceState(
				{},
				'',
				url.toString()
			);
		}
	}

	function updateDashboardValue(
		$dashboard,
		key,
		value
	) {
		$dashboard
			.find(
				'[data-srk-dashboard="' +
				key +
				'"]'
			)
			.text(value);
	}

	function renderDashboardActivity(
		$dashboard,
		activity
	) {
		var $list = $dashboard.find(
			'[data-srk-dashboard-activity]'
		);

		var iconMap = {
			opportunities: {
				className: 'blue',
				icon: 'dashicons-admin-links'
			},
			orphans: {
				className: 'orange',
				icon: 'dashicons-warning'
			},
			auto: {
				className: 'green',
				icon: 'dashicons-admin-generic'
			},
			index: {
				className: 'slate',
				icon: 'dashicons-search'
			}
		};

		$list.empty();

		if (!activity || !activity.length) {
			$list.append(
				$('<div>', {
					class: 'srk-il-activity-item'
				}).append(
					$('<div>').append(
						$('<strong>').text(
							'No activity is available yet.'
						)
					)
				)
			);

			return;
		}

		activity.forEach(function (item) {
			var type = String(
				item.type || 'index'
			);

			var icon = iconMap[type] ||
				iconMap.index;

			var $icon = $('<span>', {
				class:
					'icon ' +
					icon.className +
					' dashicons ' +
					icon.icon
			});

			var $content = $('<div>').append(
				$('<strong>').text(
					item.text || ''
				),
				$('<small>').text(
					item.time || ''
				)
			);

			$list.append(
				$('<div>', {
					class: 'srk-il-activity-item'
				}).append(
					$icon,
					$content
				)
			);
		});
	}

	function refreshDashboardData($wrap) {
		var $dashboard = $wrap.find(
			'.srk-il-dashboard'
		);

		if (!$dashboard.length) {
			return;
		}

		postAjax({
			action: 'srk_il_get_dashboard_data'
		}).done(function (response) {
			if (
				!response ||
				!response.success ||
				!response.data
			) {
				return;
			}

			var summary =
				response.data.summary || {};

			updateDashboardValue(
				$dashboard,
				'indexed_total',
				summary.indexed_total || 0
			);

			updateDashboardValue(
				$dashboard,
				'internal_links',
				summary.internal_links || 0
			);

			updateDashboardValue(
				$dashboard,
				'pending_opportunities',
				summary.pending_opportunities || 0
			);

			updateDashboardValue(
				$dashboard,
				'auto_links',
				summary.auto_links || 0
			);

			updateDashboardValue(
				$dashboard,
				'orphan_total',
				summary.orphan_total || 0
			);

			updateDashboardValue(
				$dashboard,
				'last_index',
				summary.last_index || 'Never'
			);

			updateDashboardValue(
				$dashboard,
				'health_score',
				summary.health_score || 0
			);

			updateDashboardValue(
				$dashboard,
				'health_message',
				summary.health_message || ''
			);

			renderDashboardActivity(
				$dashboard,
				response.data.activity || []
			);
		});
	}

	$(document).ready(function () {
		$('.srk-il-wrap').each(function () {
			var $wrap = $(this);

			var url = new URL(
				window.location.href
			);

			var currentTab =
				url.searchParams.get(
					'srk_il_tab'
				) || 'dashboard';

			setInternalLinkingTab(
				$wrap,
				currentTab,
				false
			);

			if (currentTab === 'dashboard') {
				refreshDashboardData($wrap);
			}
		});
	});

	$(document).on(
		'click',
		'.srk-il-tab',
		function (event) {
			event.preventDefault();
			event.stopPropagation();

			var $button = $(this);
			var $wrap = $button.closest(
				'.srk-il-wrap'
			);

			var tabKey = $button.attr(
				'data-srk-il-tab'
			);

			setInternalLinkingTab(
				$wrap,
				tabKey,
				true
			);

			if (tabKey === 'dashboard') {
				refreshDashboardData($wrap);
			}
		}
	);

	$(document).on(
		'click',
		'[data-srk-il-tab-jump]',
		function (event) {
			event.preventDefault();
			event.stopPropagation();

			var $link = $(this);
			var $wrap = $link.closest(
				'.srk-il-wrap'
			);

			var tabKey = $link.attr(
				'data-srk-il-tab-jump'
			);

			setInternalLinkingTab(
				$wrap,
				tabKey,
				true
			);

			if (tabKey === 'dashboard') {
				refreshDashboardData($wrap);
			}
		}
	);

	window.setInterval(function () {
		$('.srk-il-wrap').each(function () {
			var $wrap = $(this);

			if (
				$wrap
					.find('#srk-il-tab-dashboard')
					.hasClass('is-active')
			) {
				refreshDashboardData($wrap);
			}
		});
	}, 30000);

})(jQuery);