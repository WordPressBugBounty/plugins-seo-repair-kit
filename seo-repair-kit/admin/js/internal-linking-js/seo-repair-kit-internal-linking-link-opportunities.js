(function ($) {
	'use strict';

	function postAjax(data) {
		data.nonce = srkInternalLinking.nonce;
		return $.post(srkInternalLinking.ajaxUrl, data);
	}

	function getAjaxError(xhr, fallback) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}

		if (xhr && xhr.responseText) {
			return xhr.responseText;
		}

		return fallback;
	}

	function notify(message, type) {
		var $notice = $('.srk-lo-notice');

		if (!$notice.length) {
			$('.srk-il-link-opportunities').prepend(
				'<div class="srk-lo-notice notice is-dismissible"><p></p></div>'
			);
			$notice = $('.srk-lo-notice');
		}

		$notice
			.removeClass('notice-success notice-error notice-warning')
			.addClass(type === 'error' ? 'notice-error' : 'notice-success')
			.find('p')
			.text(message);

		$notice.show();

		setTimeout(function () {
			$notice.fadeOut(200);
		}, 4000);
	}

	function runBatch(scanId, page) {
		postAjax({
			action: 'srk_il_run_opportunities_batch',
			scan_id: scanId,
			page: page
		}).done(function (response) {
			if (!response || !response.success) {
				notify(response.data && response.data.message ? response.data.message : 'Opportunity refresh failed.', 'error');
				return;
			}

			var percent = typeof response.data.percent !== 'undefined' ? response.data.percent : (typeof response.data.progress !== 'undefined' ? response.data.progress : 0);
			$('.srk-lo-refresh-status').text(percent + '% complete');

			if (response.data.complete) {
				window.location.reload();
				return;
			}

			runBatch(scanId, (response.data.next_page || response.data.page || (page + 1)));
		}).fail(function (xhr) {
			notify(getAjaxError(xhr, 'Opportunity refresh request failed.'), 'error');
			$('.srk-lo-refresh-opportunities').prop('disabled', false).text('Refresh Opportunities');
		});
	}

	$(document).on('click', '.srk-lo-refresh-opportunities', function () {
		var $button = $(this);

		$button.prop('disabled', true).text('Refreshing...');
		$('.srk-lo-refresh-status').text('Starting...');

		postAjax({
			action: 'srk_il_start_opportunities_refresh'
		}).done(function (response) {
			if (!response || !response.success) {
				$button.prop('disabled', false).text('Refresh Opportunities');
				notify(response.data && response.data.message ? response.data.message : 'Unable to start refresh.', 'error');
				return;
			}

			runBatch(response.data.scan_id, 1);
		}).fail(function (xhr) {
			$button.prop('disabled', false).text('Refresh Opportunities');
			notify(getAjaxError(xhr, 'Unable to start refresh request.'), 'error');
		});
	});

	function getTypeLabel(type) {
		return type === 'ai'
			? 'AI Semantic'
			: 'Rule-Based';
	}

	$(document).on(
		'change',
		'.srk-lo-target-select',
		function () {
			var $select = $(this);
			var $row = $select.closest('tr');
			var $option = $select.find('option:selected');

			var editUrl = $option.attr('data-edit-url');
			if (editUrl) {
				var $targetLink = $row.find('.srk-lo-target-link');
				if ($targetLink.length) {
					$targetLink.attr('href', editUrl);
				}
			}

			var type = String(
				$option.attr('data-type') || 'rule'
			).toLowerCase();

			type = type === 'ai'
				? 'ai'
				: 'rule';

			var score = parseInt(
				$option.attr('data-score') || 0,
				10
			);

			var reason = $option.attr('data-reason') || '';

			$row
				.find('.srk-il-type-badge')
				.removeClass('is-ai is-rule')
				.addClass(
					type === 'ai'
						? 'is-ai'
						: 'is-rule'
				)
				.text(getTypeLabel(type));

			$row
				.find('.srk-lo-score')
				.text(score);

			var $reasonWrap = $row.find('.srk-lo-reason-wrap');
			var $reasonText = $reasonWrap.find('.srk-lo-reason-text');
			var $reasonToggle = $reasonWrap.find('.srk-lo-reason-toggle');

			$reasonWrap.removeClass('is-expanded');

			$reasonText.text(
				reason || 'No reason available.'
			);

			$reasonToggle
				.prop('hidden', String(reason).length <= 115)
				.attr('aria-expanded', 'false');
		}
	);

	$(document).on('click', '.srk-lo-insert, .srk-lo-ignore', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var id = parseInt($row.find('.srk-lo-target-select').val(), 10) || parseInt($row.find('[data-opportunity-id]').data('opportunity-id'), 10) || 0;
		var isInsert = $button.hasClass('srk-lo-insert');
		var anchorText = $.trim($row.find('.srk-lo-anchor').text()).replace(/^“|”$/g, '');
		var action = isInsert ? 'srk_il_apply_opportunity' : 'srk_il_ignore_opportunity';
		var successMessage = isInsert ? 'Internal link applied successfully.' : 'Opportunity ignored successfully.';

		if (!id) {
			notify('Missing opportunity ID.', 'error');
			return;
		}

		$row.find('button').prop('disabled', true);

		postAjax({
			action: action,
			opportunity_id: id,
			anchor_text: anchorText
		}).done(function (response) {
			if (!response || !response.success) {
				notify(response.data && response.data.message ? response.data.message : 'Action failed.', 'error');
				$row.find('button').prop('disabled', false);
				return;
			}

			notify(response.data && response.data.message ? response.data.message : successMessage, 'success');

			$row.fadeOut(180);

			window.setTimeout(function () {
				window.location.reload();
			}, 350);
		}).fail(function (xhr) {
			notify(getAjaxError(xhr, 'Action request failed.'), 'error');
			$row.find('button').prop('disabled', false);
		});
	});

	$(document).on(
		'click',
		'.srk-lo-reason-toggle',
		function () {
			var $button = $(this);
			var $wrap = $button.closest(
				'.srk-lo-reason-wrap'
			);

			var expanded = !$wrap.hasClass(
				'is-expanded'
			);

			$wrap.toggleClass(
				'is-expanded',
				expanded
			);

			$button.attr(
				'aria-expanded',
				expanded ? 'true' : 'false'
			);
		}
	);

})(jQuery);