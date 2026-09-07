(function ($) {
	'use strict';

	var settingsConfig =
		window.srkInternalLinkingSettings || {};

	var settingsAjaxUrl =
		settingsConfig.ajaxUrl ||
		window.ajaxurl ||
		'';

	var settingsNonce =
		settingsConfig.nonce || '';

	var stopwordsDirty = false;
	var stopwordsLoading = false;
	var currentLanguage = '';

	var $language;
	var $ignoreWords;
	var $resetStopwords;
	var $stopwordsSource;

	/**
	 * Get selected checkbox values.
	 *
	 * @param {string} name Field name.
	 * @return {Array}
	 */
	function getCheckedValues(name) {
		var values = [];

		$('[name="' + name + '"]:checked').each(function () {
			values.push($(this).val());
		});

		return values;
	}

	/**
	 * Parse comma-separated or line-separated stopwords.
	 *
	 * @param {string} value Raw textarea content.
	 * @return {Array}
	 */
	function parseStopwords(value) {
		var seen = {};

		return String(value || '')
			.split(/[\r\n,]+/)
			.map(function (item) {
				return item.trim();
			})
			.filter(function (item) {
				var key = item.toLocaleLowerCase();

				if (!item || seen[key]) {
					return false;
				}

				seen[key] = true;

				return true;
			});
	}
	
	function getSettingsPayload() {
		var payload = {
			enabled:
				$('[name="enabled"]').is(':checked')
					? 1
					: 0,

			auto_linking_enabled:
				$('[name="auto_linking_enabled"]').is(':checked')
					? 1
					: 0,

			selected_language:
				String(
					$('[name="selected_language"]').val() || 'english'
				),

			skip_existing_links:
				$('[name="skip_existing_links"]').is(':checked')
					? 1
					: 0,

			skip_headings:
				$('[name="skip_headings"]').is(':checked')
					? 1
					: 0,

			skip_html_blocks:
				$('[name="skip_html_blocks"]').is(':checked')
					? 1
					: 0,

			batch_size:
				$('[name="batch_size"]').val(),

			post_types:
				getCheckedValues('post_types[]'),

			taxonomies:
				getCheckedValues('taxonomies[]'),

			post_statuses:
				getCheckedValues('post_statuses[]'),

			max_outbound_links:
				$('[name="max_outbound_links"]').val(),

			max_inbound_links:
				$('[name="max_inbound_links"]').val(),

			suggestions_limit:
				$('[name="suggestions_limit"]').val(),

			target_older_than:
				$('[name="target_older_than"]').val(),

			source_published_after:
				$('[name="source_published_after"]').val(),

			same_category_only:
				$('[name="same_category_only"]').is(':checked')
					? 1
					: 0,

			link_orphaned_only:
				$('[name="link_orphaned_only"]').is(':checked')
					? 1
					: 0,

			ignore_numbers:
				$('[name="ignore_numbers"]').is(':checked')
					? 1
					: 0,

			keyword_sources:
				getCheckedValues('keyword_sources[]'),

			skip_sentences:
				$('[name="skip_sentences"]').val(),

			skip_paragraphs:
				$('[name="skip_paragraphs"]').val(),

			min_anchor_words:
				$('[name="min_anchor_words"]').val(),

			max_anchor_words:
				$('[name="max_anchor_words"]').val(),

			max_keywords_per_post:
				$('[name="max_keywords_per_post"]').val(),

			ai_enabled:
				$('[name="ai_enabled"]').is(':checked')
					? 1
					: 0,

			openrouter_api_key:
				$('[name="openrouter_api_key"]').val(),

			ai_semantic_matching: 1,

			ai_batch_size:
				$('[name="ai_batch_size"]').val()
		};

		/*
		 * Reset has priority over the textarea.
		 */
		if ($resetStopwords.is(':checked')) {
			payload.reset_language_stopwords = 1;
		} else if (stopwordsDirty) {
			/*
			 * The key is deliberately omitted when the user did not edit the
			 * textarea. This prevents normal settings saves from creating an
			 * unnecessary language override.
			 */
			payload.ignore_words =
				parseStopwords($ignoreWords.val());

			payload.ignore_words_changed = 1;
		}

		return payload;
	}

	$(function () {
		$('.srk-il-settings-tab').on('click', function () {
			var $tab = $(this);
			var tab = String($tab.data('tab') || '');

			if (!tab) {
				return;
			}

			$('.srk-il-settings-tab')
				.removeClass('is-active')
				.attr('aria-selected', 'false');

			$tab
				.addClass('is-active')
				.attr('aria-selected', 'true');

			$('.srk-il-settings-tab-content')
				.removeClass('is-active');

			$('.srk-il-settings-tab-' + tab)
				.addClass('is-active');
		});
	});

	/**
	 * Set button loading state.
	 *
	 * @param {jQuery} $button Button.
	 * @param {string} loadingText Loading text.
	 */
	function setButtonLoading($button, loadingText) {
		if (!$button.data('original-text')) {
			$button.data(
				'original-text',
				$button.text()
			);
		}

		$button
			.addClass('srk-il-btn-loading')
			.prop('disabled', true)
			.text(loadingText);
	}

	/**
	 * Restore button.
	 *
	 * @param {jQuery} $button Button.
	 */
	function resetButton($button) {
		$button
			.removeClass('srk-il-btn-loading')
			.prop('disabled', false)
			.text(
				$button.data('original-text')
			);
	}

	/**
	 * Update stopword source information.
	 *
	 * @param {Object} data AJAX response data.
	 */
	function updateStopwordSource(data) {
		var count = Number(data.word_count || 0);

		if (data.source === 'override') {
			$stopwordsSource.text(
				'Using saved language override: ' +
				count +
				' entries.'
			);

			return;
		}

		$stopwordsSource.text(
			'Using the default language file: ' +
			count +
			' entries.'
		);
	}

	/**
	 * Load active stopwords for a language.
	 *
	 * @param {string} language Language key.
	 * @param {string} fallbackLanguage Previous language.
	 */
	function loadLanguageStopwords(
		language,
		fallbackLanguage
	) {
		stopwordsLoading = true;

		$ignoreWords.prop(
			'disabled',
			true
		);

		$language.prop(
			'disabled',
			true
		);

		$.ajax({
			url: settingsAjaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action:
					'srk_il_get_language_stopwords',

				nonce:
					settingsNonce,

				language:
					language
			}
		})
			.done(function (response) {
				if (
					!response ||
					!response.success ||
					!response.data ||
					typeof response.data.words !== 'string'
				) {
					var message =
						response &&
						response.data &&
						response.data.message
							? response.data.message
							: 'Unable to load the language stopword list.';

					window.alert(message);

					if (fallbackLanguage) {
						$language.val(
							fallbackLanguage
						);
					}

					return;
				}

				$ignoreWords.val(
					response.data.words
				);

				$resetStopwords.prop(
					'checked',
					false
				);

				stopwordsDirty = false;
				currentLanguage = language;

				updateStopwordSource(
					response.data
				);
			})
			.fail(function (xhr) {
				console.error(
					'Stopword loading failed:',
					xhr.responseText ||
					xhr.statusText
				);

				window.alert(
					'Unable to load the language stopword list.'
				);

				if (fallbackLanguage) {
					$language.val(
						fallbackLanguage
					);
				}
			})
			.always(function () {
				stopwordsLoading = false;

				$ignoreWords.prop(
					'disabled',
					false
				);

				$language.prop(
					'disabled',
					false
				);
			});
	}

	$(function () {
		$language =
			$('#srk-il-selected-language');

		$ignoreWords =
			$('#srk-il-ignore-words');

		$resetStopwords =
			$('#srk-il-reset-language-stopwords');

		$stopwordsSource =
			$('#srk-il-stopwords-source');

		currentLanguage =
			String(
				$language.val() || 'english'
			);

		/*
		 * Mark stopwords as edited only when the administrator types in the
		 * textarea. Programmatic language loading does not create an override.
		 */
		$ignoreWords.on(
			'input',
			function () {
				if (stopwordsLoading) {
					return;
				}

				stopwordsDirty = true;

				$resetStopwords.prop(
					'checked',
					false
				);

				$stopwordsSource.text(
					'Unsaved language override.'
				);
			}
		);

		$resetStopwords.on(
			'change',
			function () {
				if ($(this).is(':checked')) {
					$stopwordsSource.text(
						'The default language file will be restored when settings are saved.'
					);

					return;
				}

				if (stopwordsDirty) {
					$stopwordsSource.text(
						'Unsaved language override.'
					);
				}
			}
		);

		$language.on(
			'change',
			function () {
				var language =
					String(
						$(this).val() || ''
					);

				var previousLanguage =
					currentLanguage;

				if (
					stopwordsDirty &&
					!window.confirm(
						'Unsaved stopword changes for the current language will be discarded. Continue?'
					)
				) {
					$(this).val(
						previousLanguage
					);

					return;
				}

				stopwordsDirty = false;

				loadLanguageStopwords(
					language,
					previousLanguage
				);
			}
		);
	});

	$(document).on(
		'input change',
		'[name="suggestions_limit"]',
		function () {
			$('.srk-range-current').text(
				$(this).val() +
				' suggestions'
			);
		}
	);

	$(document).on(
		'click',
		'.srk-il-settings-save',
		function () {
			var $button = $(this);

			var resetRequested =
				$resetStopwords.is(':checked');

			setButtonLoading(
				$button,
				'Saving...'
			);

			$.post(
				settingsAjaxUrl,
				{
					action:
						'srk_il_save_settings',

					nonce:
						settingsNonce,

					settings:
						getSettingsPayload()
				}
			)
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
								: 'Unable to save settings.'
						);

						return;
					}

					$('.srk-settings-last-saved')
						.text(
							'Last saved just now'
						);

					stopwordsDirty = false;

					$resetStopwords.prop(
						'checked',
						false
					);

					/*
					 * Reload after a reset so the textarea immediately shows
					 * the packaged file list.
					 */
					if (resetRequested) {
						loadLanguageStopwords(
							String(
								$language.val() || ''
							),
							currentLanguage
						);
					} else if (
						response.data &&
						response.data.stopwords
					) {
						updateStopwordSource(
							response.data.stopwords
						);
					} else {
						$stopwordsSource.text(
							'Saved language override.'
						);
					}

					if (
						response.data &&
						response.data.keywords_refresh_required
					) {
						window.alert(
							'Language processing settings changed. Refresh Target Keywords before generating new Link Opportunities.'
						);
					}
				})
				.fail(function () {
					window.alert(
						'Settings save request failed.'
					);
				})
				.always(function () {
					resetButton(
						$button
					);
				});
		}
	);

	$(document).on(
		'click',
		'.srk-il-settings-reset',
		function () {
			var $button = $(this);

			if (
				!window.confirm(
					'Reset internal linking settings to defaults?'
				)
			) {
				return;
			}

			setButtonLoading(
				$button,
				'Resetting...'
			);

			$.post(
				settingsAjaxUrl,
				{
					action:
						'srk_il_reset_settings',

					nonce:
						settingsNonce
				}
			)
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
								: 'Unable to reset settings.'
						);

						return;
					}

					window.location.reload();
				})
				.fail(function () {
					window.alert(
						'Settings reset request failed.'
					);
				})
				.always(function () {
					resetButton(
						$button
					);
				});
		}
	);

	$(document).on(
		'click',
		'.srk-il-ai-test-key',
		function () {

			var $button =
				$(this);

			var $status =
				$('.srk-il-ai-status-text');

			setButtonLoading(
				$button,
				'Testing...'
			);

			$status
				.removeClass(
					'is-success is-error'
				)
				.text('');

			$.ajax({
				url:
					settingsAjaxUrl,

				type:
					'POST',

				dataType:
					'json',

				data: {
					action:
						'srk_il_test_ai_provider',

					nonce:
						settingsNonce,

					api_key:
						$('[name="openrouter_api_key"]').val()
				}
			})

			.done(function (
				response
			) {

				if (
					!response ||
					!response.success
				) {

					$status
						.addClass(
							'is-error'
						)
						.text(
							response &&
							response.data &&
							response.data.message
								? response.data.message
								: 'AI provider test failed.'
						);

					return;
				}

				var data =
					response.data || {};

				var text =
					data.message ||
					'AI provider connected successfully.';

				if (
					data.model &&
					data.dimensions
				) {
					text +=
						' Model: ' +
						data.model +
						' · Dimensions: ' +
						data.dimensions;
				}

				$status
					.addClass(
						'is-success'
					)
					.text(
						text
					);
			})

			.fail(function (
				xhr
			) {

				var message =
					'AI provider test request failed.';

				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					message =
						xhr.responseJSON.data.message;
				} else if (
					xhr.responseText
				) {
					try {

						var response =
							JSON.parse(
								xhr.responseText
							);

						if (
							response.data &&
							response.data.message
						) {
							message =
								response.data.message;
						}

					} catch (error) {

						console.error(
							'AI provider test response:',
							xhr.responseText
						);
					}
				}

				$status
					.addClass(
						'is-error'
					)
					.text(
						message
					);
			})

			.always(function () {

				resetButton(
					$button
				);
			});
		}
	);

	$(document).on(
		'click',
		'.srk-il-ai-start-pipeline',
		function () {
			var $button = $(this);
			var $status =
				$('.srk-il-ai-status-text');

			setButtonLoading(
				$button,
				'Queueing...'
			);

			$status.text('');

			$.post(
				settingsAjaxUrl,
				{
					action:
						'srk_il_start_ai_pipeline',

					nonce:
						settingsNonce
				}
			)
				.done(function (response) {
					if (
						!response ||
						!response.success
					) {
						$status.text(
							response &&
							response.data &&
							response.data.message
								? response.data.message
								: 'Unable to queue AI pipeline.'
						);

						return;
					}

					$status
						.removeClass(
							'is-error'
						)
						.addClass(
							'is-success'
						)
						.text(
							response.data.message ||
							'AI pipeline queued.'
						);

					refreshAiStatus();
					startAiStatusPolling();
				})
				.fail(function () {
					$status.text(
						'AI pipeline request failed.'
					);
				})
				.always(function () {
					resetButton(
						$button
					);
				});
		}
	);

		/*
	|--------------------------------------------------------------------------
	| Live AI status
	|--------------------------------------------------------------------------
	*/

	var aiStatusTimer = null;
	var aiStatusRequestRunning = false;

	/**
	 * Safely convert a value to a non-negative integer.
	 */
	function aiNumber(value) {

		var number =
			parseInt(
				value,
				10
			);

		return isNaN(number)
			? 0
			: Math.max(
				0,
				number
			);
	}


	/**
	 * Render live AI status data.
	 */
	function renderAiStatus(data) {

		data =
			data || {};

		var analyzed =
			aiNumber(
				data.embeddings_ready
			);

		var waiting =
			aiNumber(
				data.embeddings_pending
			);

		var opportunities =
			aiNumber(
				data.ai_opportunities
			);

		var opportunityProcessed =
			aiNumber(
				data.opportunity_processed
			);

		var opportunityTotal =
			aiNumber(
				data.opportunity_total
			);

		var opportunityPercent =
			aiNumber(
				data.opportunity_percent
			);

		opportunityPercent =
			Math.max(
				0,
				Math.min(
					100,
					opportunityPercent
				)
			);	

		var total =
			aiNumber(
				data.analysis_total
			);

		if (!total) {
			total =
				analyzed +
				waiting;
		}

		var percent =
			aiNumber(
				data.analysis_percent
			);

		if (
			!data.analysis_percent &&
			total > 0
		) {
			percent =
				Math.round(
					(
						analyzed /
						total
					) * 100
				);
		}

		percent =
			Math.max(
				0,
				Math.min(
					100,
					percent
				)
			);


		$('#srk-il-ai-analyzed')
			.text(
				analyzed.toLocaleString()
			);

		$('#srk-il-ai-waiting')
			.text(
				waiting.toLocaleString()
			);

		$('#srk-il-ai-opportunities')
			.text(
				opportunities.toLocaleString()
			);

		$('#srk-il-ai-progress-percent')
			.text(
				percent + '%'
			);

		$('#srk-il-ai-progress-bar')
			.css(
				'width',
				percent + '%'
			);

		$('#srk-il-ai-progress-track')
			.attr(
				'aria-valuenow',
				percent
			);

		$('#srk-il-ai-progress-summary')
			.text(
				analyzed.toLocaleString() +
				' of ' +
				total.toLocaleString() +
				' content items analyzed'
			);
		/*
		* AI opportunity-discovery progress.
		*/
		$('#srk-il-ai-opportunity-progress-percent')
			.text(
				opportunityPercent + '%'
			);

		$('#srk-il-ai-opportunity-progress-bar')
			.css(
				'width',
				opportunityPercent + '%'
			);

		$('#srk-il-ai-opportunity-progress-track')
			.attr(
				'aria-valuenow',
				opportunityPercent
			);

		$('#srk-il-ai-opportunity-progress-summary')
			.text(
				opportunityProcessed.toLocaleString() +
					' of ' +
					opportunityTotal.toLocaleString() +
					' analyzed content items checked — ' +
					opportunities.toLocaleString() +
					' opportunities found'
			);	

		var $monitor =
			$('#srk-il-ai-monitor');

		var $state =
			$('#srk-il-ai-live-state');


		if (
			data.pipeline_active
		) {

			$monitor
				.addClass(
					'is-running'
				);

			$state
				.text(
					'AI processing is running'
				);

			return;
		}


		$monitor
			.removeClass(
				'is-running'
			);


		if (
			waiting > 0
		) {

			$state
				.text(
					'Waiting for AI analysis'
				);

			return;
		}


		if (
			total > 0
		) {

			$state
				.text(
					'AI analysis is up to date'
				);

			return;
		}


		$state
			.text(
				'Ready for AI analysis'
			);
	}


	/**
	 * Request fresh status from WordPress.
	 */
	function refreshAiStatus() {

		var $monitor =
			$('#srk-il-ai-monitor');

		if (
			!$monitor.length ||
			!$monitor.is(':visible') ||
			aiStatusRequestRunning
		) {
			return;
		}


		aiStatusRequestRunning =
			true;


		$.ajax({
			url:
				settingsAjaxUrl,

			type:
				'POST',

			dataType:
				'json',

			data: {
				action:
					'srk_il_get_ai_status',

				nonce:
					settingsNonce
			}
		})

		.done(function (
			response
		) {

			if (
				!response ||
				!response.success
			) {
				return;
			}


			renderAiStatus(
				response.data || {}
			);
		})

		.always(function () {

			aiStatusRequestRunning =
				false;
		});
	}


	/**
	 * Start four-second live polling.
	 */
	function startAiStatusPolling() {

		refreshAiStatus();


		if (
			aiStatusTimer
		) {
			return;
		}


		aiStatusTimer =
			window.setInterval(
				refreshAiStatus,
				4000
			);
	}


	/**
	 * Stop polling when browser tab is not visible.
	 */
	function stopAiStatusPolling() {

		if (
			!aiStatusTimer
		) {
			return;
		}


		window.clearInterval(
			aiStatusTimer
		);

		aiStatusTimer =
			null;
	}


	/*
	 * Initialize only when the AI status component exists.
	 */
	$(function () {

		if (
			$('#srk-il-ai-monitor').length
		) {
			startAiStatusPolling();
		}
	});


	/*
	 * Avoid unnecessary admin-ajax requests when the browser tab
	 * is in the background.
	 */
	document.addEventListener(
		'visibilitychange',
		function () {

			if (
				document.hidden
			) {

				stopAiStatusPolling();

			} else {

				startAiStatusPolling();
			}
		}
	);

})(jQuery);