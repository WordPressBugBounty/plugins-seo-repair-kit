(function ($) {
	'use strict';

	function setLoading($button, text) {
		if (!$button.data('original-text')) {
			$button.data('original-text', $button.text());
		}

		$button.prop('disabled', true).text(text);
	}

	function resetLoading($button) {
		$button
			.prop('disabled', false)
			.text($button.data('original-text'));
	}

	function postAjax(data) {
		data.nonce = srkInternalLinking.nonce;

		return $.post(
			srkInternalLinking.ajaxUrl,
			data
		);
	}

	function getUrls() {
		return {
			oldUrl: $('.srk-url-old').val().trim(),
			newUrl: $('.srk-url-new').val().trim()
		};
	}

	function escapeHtml(value) {
		return $('<div>')
			.text(value == null ? '' : String(value))
			.html();
	}

	function renderPreview(data) {
		var affectedPosts = parseInt(data.affected_posts || 0, 10);
		var changedLinks = parseInt(data.changed_links || 0, 10);
		var html = '';

		html += '<div class="srk-il-info-box">';
		html += '<strong>Dry Scan Result</strong>';
		html += '<p><strong>' + affectedPosts + '</strong> posts affected. ';
		html += '<strong>' + changedLinks + '</strong> links will be changed.</p>';

		if (data.posts && data.posts.length) {
			html += '<ul class="srk-url-preview-list">';

			data.posts.forEach(function (post) {
				html += '<li>';
				html += '<a href="' + escapeHtml(post.edit_url) + '" target="_blank" rel="noopener noreferrer">';
				html += escapeHtml(post.post_title);
				html += '</a>';
				html += ' — ' + parseInt(post.count || 0, 10) + ' match(es)';
				html += '</li>';
			});

			html += '</ul>';
		}

		html += '</div>';

		$('.srk-url-preview')
			.html(html)
			.show();

		$('.srk-url-replace')
			.prop('disabled', affectedPosts < 1);
	}

	/*
	 * Require another dry scan if either URL changes.
	 */
	$(document).on(
		'input',
		'.srk-url-old, .srk-url-new',
		function () {
			$('.srk-url-replace').prop('disabled', true);
			$('.srk-url-preview').hide().empty();
		}
	);

	/*
	 * Dry scan.
	 */
	$(document).on(
		'click',
		'.srk-url-dry-scan',
		function () {
			var $button = $(this);
			var urls = getUrls();

			setLoading($button, 'Scanning...');

			postAjax({
				action: 'srk_il_url_changer_dry_run',
				old_url: urls.oldUrl,
				new_url: urls.newUrl
			})
				.done(function (response) {
					if (!response || !response.success) {
						window.alert(
							response &&
							response.data &&
							response.data.message
								? response.data.message
								: 'Dry scan failed.'
						);

						$('.srk-url-replace').prop('disabled', true);
						return;
					}

					renderPreview(response.data || {});
				})
				.fail(function () {
					window.alert('Dry scan request failed.');
				})
				.always(function () {
					resetLoading($button);
				});
		}
	);

	/*
	 * Replace URL.
	 */
	$(document).on(
		'click',
		'.srk-url-replace',
		function () {
			var $button = $(this);
			var urls = getUrls();

			if (
				!window.confirm(
					'Replace this URL across affected content? Reversible link-change data will be recorded for Undo.'
				)
			) {
				return;
			}

			setLoading($button, 'Replacing...');

			postAjax({
				action: 'srk_il_url_changer_replace',
				old_url: urls.oldUrl,
				new_url: urls.newUrl
			})
				.done(function (response) {
					if (!response || !response.success) {
						window.alert(
							response &&
							response.data &&
							response.data.message
								? response.data.message
								: 'Replacement failed.'
						);

						return;
					}

					var data = response.data || {};

					window.alert(
						'Replacement completed. Posts affected: ' +
						parseInt(data.affected_posts || 0, 10) +
						'. Links changed: ' +
						parseInt(data.changed_links || 0, 10) +
						'. Failed: ' +
						parseInt(data.failed_count || 0, 10)
					);

					window.location.reload();
				})
				.fail(function () {
					window.alert('Replacement request failed.');
				})
				.always(function () {
					resetLoading($button);
				});
		}
	);

	/*
	 * Undo URL replacement.
	 */
	$(document).on(
		'click',
		'.srk-url-undo-row',
		function () {
			var $button = $(this);
			var changeId = parseInt(
				$button.data('change-id') || 0,
				10
			);

			if (!changeId) {
				window.alert('Missing change ID.');
				return;
			}

			if (
				!window.confirm(
					'Undo this URL change? Only links changed by this operation will be restored. Posts edited afterward will be skipped.'
				)
			) {
				return;
			}

			setLoading($button, 'Undoing...');

			postAjax({
				action: 'srk_il_url_changer_undo',
				change_id: changeId
			})
				.done(function (response) {
					if (!response || !response.success) {
						window.alert(
							response &&
							response.data &&
							response.data.message
								? response.data.message
								: 'Undo failed.'
						);

						return;
					}

					var data = response.data || {};

					var message =
						'Undo finished. Restored posts: ' +
						parseInt(data.restored || 0, 10) +
						'. Conflicts skipped: ' +
						parseInt(data.conflicts || 0, 10) +
						'. Failed: ' +
						parseInt(data.failed || 0, 10) +
						'.';

					if (parseInt(data.remaining || 0, 10) > 0) {
						message +=
							' Remaining rollback items: ' +
							parseInt(data.remaining || 0, 10) +
							'.';
					}

					window.alert(message);
					window.location.reload();
				})
				.fail(function () {
					window.alert('Undo request failed.');
				})
				.always(function () {
					resetLoading($button);
				});
		}
	);

})(jQuery);