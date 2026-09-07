(function ($) {
	'use strict';

	/**
	 * Send an Internal Linking AJAX request.
	 *
	 * @param {Object} data Request data.
	 * @return {jqXHR}
	 */
	function postAjax(data) {
		data = data || {};
		data.nonce = srkInternalLinking.nonce;

		return $.post(
			srkInternalLinking.ajaxUrl,
			data
		);
	}

	/**
	 * Remove one inserted internal link.
	 *
	 * Support both the current class and the legacy class so older markup
	 * continues to work safely.
	 */
	$(document).on(
		'click',
		'.srk-al-remove-link, .srk-al-remove',
		function () {
			var $button = $(this);
			var $row = $button.closest('tr');

			var opportunityId =
				parseInt(
					$button.data('opportunity-id') ||
					$row.data('opportunity-id') ||
					0,
					10
				) || 0;

			if (!opportunityId) {
				window.alert(
					'Missing inserted link ID.'
				);

				return;
			}

			if (
				!window.confirm(
					'Remove this inserted internal link from the source post?'
				)
			) {
				return;
			}

			$button
				.prop('disabled', true)
				.addClass('is-loading');

			postAjax({
				action:
					'srk_il_remove_inserted_link',

				opportunity_id:
					opportunityId
			})
				.done(function (response) {

					if (
						!response ||
						!response.success
					) {
						window.alert(
							response &&
							response.data &&
							response.data.message
								? response.data.message
								: 'Remove failed.'
						);

						return;
					}

					$row.fadeOut(
						180,
						function () {
							window.location.reload();
						}
					);
				})
				.fail(function (xhr) {

					var message =
						xhr &&
						xhr.responseJSON &&
						xhr.responseJSON.data &&
						xhr.responseJSON.data.message
							? xhr.responseJSON.data.message
							: 'Remove request failed. Please try again.';

					window.alert(
						message
					);
				})
				.always(function () {

					$button
						.prop('disabled', false)
						.removeClass('is-loading');
				});
		}
	);

})(jQuery);