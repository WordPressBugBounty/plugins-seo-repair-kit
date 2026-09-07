(function ($) {
	'use strict';

	function postAjax(data) {
		data.nonce = srkInternalLinking.nonce;
		return $.post(srkInternalLinking.ajaxUrl, data);
	}

	function escapeHtml(text) {
		return String(text || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function updateSummary(summary) {
		if (!summary) {
			return;
		}

		$('[data-srk-oc-summary="critical"]').text('⚠ ' + (summary.critical || 0));
		$('[data-srk-oc-summary="low"]').text(summary.low || 0);
		$('[data-srk-oc-summary="healthy"]').text(summary.healthy || 0);
		$('[data-srk-oc-summary="ignored"]').text(summary.ignored || 0);
	}

	function openOpportunitiesModal(data) {
		var rows = data.opportunities || [];
		var html = '';

		$('.srk-oc-modal-subtitle').text(data.message || 'Generated opportunities');

		if (!rows.length) {
			html = '<tr><td colspan="5">No opportunities found for this orphan content.</td></tr>';
		} else {
			rows.forEach(function (row) {
				html += '<tr data-opportunity-id="' + parseInt(row.id, 10) + '">' +
					'<td><strong>' + escapeHtml(row.source_title) + '</strong></td>' +
					'<td>' + escapeHtml(row.anchor_text) + '</td>' +
					'<td>' + escapeHtml(row.sentence) + '<br><small>' + escapeHtml(row.reason) + '</small></td>' +
					'<td><strong>' + parseInt(row.score || 0, 10) + '</strong></td>' +
					'<td>' +
						'<button type="button" class="button button-primary srk-oc-add-link" data-opportunity-id="' + parseInt(row.id, 10) + '">Add Link</button> ' +
						'<button type="button" class="button-link srk-oc-ignore-opportunity" data-opportunity-id="' + parseInt(row.id, 10) + '">Ignore</button>' +
					'</td>' +
				'</tr>';
			});
		}

		$('.srk-oc-modal-results').html(html);
		$('.srk-oc-modal').fadeIn(150);
		$('body').addClass('srk-oc-modal-open');
	}

	$(document).on('click', '.srk-oc-modal-close', function () {
		$('.srk-oc-modal').fadeOut(150);
		$('body').removeClass('srk-oc-modal-open');
	});

	$(document).on('click', '.srk-oc-modal', function (event) {
		if ($(event.target).hasClass('srk-oc-modal')) {
			$('.srk-oc-modal').fadeOut(150);
			$('body').removeClass('srk-oc-modal-open');
		}
	});

	$(document).on('click', '.srk-oc-refresh', function () {
		var $button = $(this);
		var $label = $button.find('.srk-oc-refresh-label');

		$button
			.prop('disabled', true)
			.addClass('is-loading');

		$label.text('Refreshing...');

		postAjax({
			action: 'srk_il_refresh_orphan_content'
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(
					response.data && response.data.message
						? response.data.message
						: 'Refresh failed.'
				);

				return;
			}

			updateSummary(response.data.summary);
			window.location.reload();
		}).fail(function () {
			window.alert(
				'Refresh failed. Please try again.'
			);
		}).always(function () {
			$button
				.prop('disabled', false)
				.removeClass('is-loading');

			$label.text('Refresh Orphan Status');
		});
	});

	$(document).on('click', '.srk-oc-find', function () {
		var $button = $(this);
		var postId = parseInt($button.data('post-id'), 10);

		if (!postId) {
			window.alert('Missing post ID.');
			return;
		}

		$button.prop('disabled', true).text('Generating...');

		postAjax({
			action: 'srk_il_find_orphan_opportunities',
			post_id: postId
		}).done(function (response) {
			if (
				!response ||
				!response.success
			) {
				window.alert(
					response &&
					response.data &&
					response.data.message
						? response.data.message
						: 'Opportunity generation failed.'
				);

				return;
			}

			if (
				response.data.processing
			) {
				window.alert(
					response.data.message ||
					'Opportunities are already being generated.'
				);

				return;
			}

			openOpportunitiesModal(
				response.data
			);
		}).fail(function () {
			window.alert('Opportunity generation failed. Please try again.');
		}).always(function () {
			$button.prop('disabled', false).text('Find Opportunities');
		});
	});

	$(document).on('click', '.srk-oc-add-link', function () {
		var $button = $(this);
		var opportunityId = parseInt($button.data('opportunity-id'), 10);

		if (!opportunityId) {
			window.alert('Missing opportunity ID.');
			return;
		}

		$button.prop('disabled', true).text('Adding...');

		postAjax({
			action: 'srk_il_apply_opportunity',
			opportunity_id: opportunityId
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(response.data && response.data.message ? response.data.message : 'Unable to add link.');
				return;
			}

			$button.closest('tr').fadeOut(150, function () {
				$(this).remove();

				if (!$('.srk-oc-modal-results tr').length) {
					$('.srk-oc-modal-results').html('<tr><td colspan="5">All opportunities handled.</td></tr>');
				}
			});
		}).fail(function () {
			window.alert('Unable to add link. Please try again.');
		}).always(function () {
			$button.prop('disabled', false).text('Add Link');
		});
	});

	$(document).on('click', '.srk-oc-ignore-opportunity', function () {
		var $button = $(this);
		var opportunityId = parseInt($button.data('opportunity-id'), 10);

		if (!opportunityId) {
			window.alert('Missing opportunity ID.');
			return;
		}

		$button.prop('disabled', true).text('Ignoring...');

		postAjax({
			action: 'srk_il_ignore_opportunity',
			opportunity_id: opportunityId
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(response.data && response.data.message ? response.data.message : 'Unable to ignore opportunity.');
				return;
			}

			$button.closest('tr').fadeOut(150, function () {
				$(this).remove();

				if (!$('.srk-oc-modal-results tr').length) {
					$('.srk-oc-modal-results').html('<tr><td colspan="5">All opportunities handled.</td></tr>');
				}
			});
		}).fail(function () {
			window.alert('Unable to ignore opportunity. Please try again.');
		});
	});

	$(document).on('click', '.srk-oc-ignore', function () {
		var $button = $(this);
		var postId = parseInt($button.data('post-id'), 10);

		if (!postId) {
			window.alert('Missing post ID.');
			return;
		}

		if (!window.confirm('Ignore this orphan content item?')) {
			return;
		}

		$button.prop('disabled', true).text('Ignoring...');

		postAjax({
			action: 'srk_il_ignore_orphan_content',
			post_id: postId
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(response.data && response.data.message ? response.data.message : 'Ignore failed.');
				return;
			}

			updateSummary(response.data.summary);

			$button.closest('tr').fadeOut(200, function () {
				$(this).remove();
			});
		}).fail(function () {
			window.alert('Ignore failed. Please try again.');
		}).always(function () {
			$button.prop('disabled', false).text('Ignore');
		});
	});

})(jQuery);