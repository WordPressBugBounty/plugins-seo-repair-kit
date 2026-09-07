(function ($) {
	'use strict';

	var activeRequest = null;
	var currentReportType = '';

	/**
	 * Escape HTML output.
	 *
	 * @param {*} value Value to escape.
	 * @return {string}
	 */
	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Create the report modal once.
	 *
	 * @return {jQuery}
	 */
	function getReportModal() {
		if (!$('#srk-report-modal').length) {
			$('body').append(
				'<div id="srk-report-modal" class="srk-report-modal">' +
					'<div class="srk-report-modal-content">' +
						'<div class="srk-report-modal-header">' +
							'<h3 class="srk-report-modal-title"></h3>' +
							'<div class="srk-report-modal-actions">' +
								'<button type="button" class="button srk-report-download">Download</button>' +
								'<button type="button" class="button srk-report-close">&times;</button>' +
							'</div>' +
						'</div>' +
						'<div class="srk-report-modal-body"></div>' +
					'</div>' +
				'</div>'
			);
		}

		return $('#srk-report-modal');
	}

	/**
	 * Load report data.
	 *
	 * @param {string} reportType Report identifier.
	 * @return {void}
	 */
	function loadReport(reportType) {
		var $modal = getReportModal();
		var $body = $modal.find('.srk-report-modal-body');
		var reportTitle = reportType
			.replace(/_/g, ' ')
			.toUpperCase();

		currentReportType = reportType;

		$modal
			.show()
			.attr('aria-hidden', 'false');

		$modal
			.find('.srk-report-modal-title')
			.text(reportTitle);

		$body.html(
			'<p class="srk-report-loading">Loading report...</p>'
		);

		if (activeRequest) {
			activeRequest.abort();
		}

		activeRequest = $.ajax({
			url: srkInternalLinking.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'srk_il_get_report',
				nonce: srkInternalLinking.nonce,
				report_type: reportType
			}
		})
			.done(function (response) {
				if (
					response.success &&
					response.data &&
					response.data.html
				) {
					$body.html(response.data.html);
					return;
				}

				$body.html(
					'<p>' +
						escapeHtml(
							response.data && response.data.message
								? response.data.message
								: 'No report data found.'
						) +
					'</p>'
				);
			})
			.fail(function (xhr, status) {
				if ('abort' === status) {
					return;
				}

				$body.html(
					'<p>Unable to load report.</p>'
				);
			})
			.always(function () {
				activeRequest = null;
			});
	}

	/**
	 * Open report modal.
	 */
	$(document)
		.off('click.srkInternalLinkingReports', '.srk-report-open')
		.on('click.srkInternalLinkingReports', '.srk-report-open', function () {
			var reportType = String(
				$(this).data('report') || ''
			);

			if (!reportType) {
				return;
			}

			loadReport(reportType);
		});

	/**
	 * Close report modal.
	 */
	$(document)
		.off('click.srkInternalLinkingReports', '.srk-report-close')
		.on('click.srkInternalLinkingReports', '.srk-report-close', function () {
			if (activeRequest) {
				activeRequest.abort();
				activeRequest = null;
			}

			$('#srk-report-modal')
				.hide()
				.attr('aria-hidden', 'true');
		});

	/**
	 * Download the visible report table as CSV.
	 */
	$(document)
		.off('click.srkInternalLinkingReports', '.srk-report-download')
		.on('click.srkInternalLinkingReports', '.srk-report-download', function () {
			var table = $('#srk-report-modal')
				.find('table.srk-report-data-table')
				.get(0);

			if (!table) {
				window.alert('No table to download.');
				return;
			}

			var csvRows = [];

			$(table)
				.find('tr')
				.each(function () {
					var row = [];

					$(this)
						.find('th, td')
						.each(function () {
							var value = $(this)
								.text()
								.trim()
								.replace(/"/g, '""');

							row.push('"' + value + '"');
						});

					csvRows.push(row.join(','));
				});

			var blob = new Blob(
				[csvRows.join('\n')],
				{
					type: 'text/csv;charset=utf-8;'
				}
			);

			var downloadUrl = URL.createObjectURL(blob);
			var downloadLink = document.createElement('a');

			downloadLink.href = downloadUrl;
			downloadLink.download =
				(currentReportType || 'internal-linking-report') +
				'.csv';

			document.body.appendChild(downloadLink);
			downloadLink.click();
			document.body.removeChild(downloadLink);

			URL.revokeObjectURL(downloadUrl);
		});
})(jQuery);