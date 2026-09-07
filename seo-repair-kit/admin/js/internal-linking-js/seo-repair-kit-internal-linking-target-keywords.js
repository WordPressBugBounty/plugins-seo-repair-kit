(function ($) {
	'use strict';

	function postAjax(data) {
		data.nonce = srkInternalLinking.nonce;
		return $.post(srkInternalLinking.ajaxUrl, data);
	}

	function runBatch(scanId, page) {
		postAjax({
			action: 'srk_il_run_keywords_batch',
			scan_id: scanId,
			page: page
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(response.data && response.data.message ? response.data.message : 'Keyword refresh failed.');
				return;
			}

			var percent = typeof response.data.percent !== 'undefined' ? response.data.percent : (typeof response.data.progress !== 'undefined' ? response.data.progress : 0);
			$('.srk-tk-refresh-status').text(percent + '% complete');

			if (response.data.complete) {
				window.location.reload();
				return;
			}

			runBatch(scanId, (response.data.next_page || response.data.page || (page + 1)));
		});
	}

	$(document).on('click', '.srk-tk-refresh-keywords', function () {
		var $button = $(this);

		$button.prop('disabled', true).text('Refreshing...');
		$('.srk-tk-refresh-status').text('Starting...');

		postAjax({
			action: 'srk_il_start_keywords_refresh'
		}).done(function (response) {
			if (!response || !response.success) {
				$button.prop('disabled', false).text('Refresh Keywords');
				window.alert(response.data && response.data.message ? response.data.message : 'Unable to start keyword refresh.');
				return;
			}

			runBatch(response.data.scan_id, 1);
		});
	});

	$(document).on('click', '.srk-tk-toggle-custom', function () {

			var postId = $(this).data('post-id');

			$('#srk-tk-custom-' + postId).toggle();

		});

		var currentPostId = 0;

	$(document).on('click', '.srk-tk-manage-keywords', function () {
		var $button = $(this);
		var postId = parseInt($button.data('post-id'), 10) || 0;
		var postTitle = $button.data('post-title') || '';

		currentPostId = postId;

		$('.srk-tk-modal-title').text(postTitle);
		$('.srk-tk-modal-input').val('');

		var detectedHtml = $('.srk-tk-keyword-list[data-post-id="' + postId + '"]').html() || '';

		$('.srk-tk-modal-detected').html(detectedHtml || '<em>No detected keywords found.</em>');
		$('.srk-tk-modal-custom').html('<em>Loading custom keywords...</em>');
		$('.srk-tk-modal').removeAttr('hidden');

		postAjax({
			action: 'srk_il_get_editor_keywords',
			post_id: postId
		}).done(function (response) {
			var custom = [];

			if (response && response.success && response.data && response.data.keywords) {
				custom = response.data.keywords.filter(function (item) {
					return item.source === 'custom';
				});
			}

			if (!custom.length) {
				$('.srk-tk-modal-custom').html('<em>No custom keywords added yet.</em>');
				return;
			}

			$('.srk-tk-modal-custom').html(
				custom.map(function (item) {
					return '<span class="srk-tk-custom-chip" data-keyword-id="' + parseInt(item.id, 10) + '">' +
					escapeHtml(item.keyword) +
					' <button type="button" class="srk-tk-delete-custom-keyword" data-keyword-id="' + parseInt(item.id, 10) + '">×</button>' +
				'</span>';
				}).join('')
			);
		});
	});

	function escapeHtml(text) {
		return String(text || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	$(document).on('click', '.srk-tk-delete-custom-keyword', function () {
		var $button = $(this);
		var keywordId = parseInt($button.data('keyword-id'), 10) || 0;

		if (!currentPostId || !keywordId) {
			window.alert('Missing keyword data.');
			return;
		}

		if (!window.confirm('Delete this custom keyword?')) {
			return;
		}

		$button.prop('disabled', true);

		postAjax({
			action: 'srk_il_editor_delete_custom_keyword',
			post_id: currentPostId,
			keyword_id: keywordId
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(response.data && response.data.message ? response.data.message : 'Unable to delete keyword.');
				return;
			}

			$button.closest('.srk-tk-custom-chip').remove();

			if (!$('.srk-tk-modal-custom .srk-tk-custom-chip').length) {
				$('.srk-tk-modal-custom').html('<em>No custom keywords added yet.</em>');
			}
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('click', '.srk-tk-modal-close, .srk-tk-modal-backdrop', function () {
		$('.srk-tk-modal').attr('hidden', true);
		currentPostId = 0;
	});

	$(document).on('click', '.srk-tk-modal-add-button', function () {
		var $button = $(this);
		var keyword = $.trim($('.srk-tk-modal-input').val() || '');

		if (!currentPostId || !keyword) {
			window.alert('Please enter a custom keyword.');
			return;
		}

		$button.prop('disabled', true).text('Adding...');

		postAjax({
			action: 'srk_il_add_custom_keyword',
			post_id: currentPostId,
			keyword: keyword
		}).done(function (response) {
			if (!response || !response.success) {
				window.alert(response.data && response.data.message ? response.data.message : 'Unable to add keyword.');
				return;
			}

			window.location.reload();
		}).always(function () {
			$button.prop('disabled', false).text('Add Keyword');
		});
	});


})(jQuery);