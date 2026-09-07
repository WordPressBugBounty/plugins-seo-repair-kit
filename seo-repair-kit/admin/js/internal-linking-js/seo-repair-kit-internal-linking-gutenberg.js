/* global wp, jQuery, srkInternalLinkingEditor */
(function (wp, $) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerPlugin = wp.plugins.registerPlugin;

	var PluginSidebar = wp.editPost && wp.editPost.PluginSidebar
		? wp.editPost.PluginSidebar
		: wp.editor && wp.editor.PluginSidebar ? wp.editor.PluginSidebar : null;

	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;
	var Modal = wp.components.Modal;
	var TextControl = wp.components.TextControl;

	var select = wp.data.select;
	var dispatch = wp.data.dispatch;

	if (!PluginSidebar || !registerPlugin || typeof srkInternalLinkingEditor === 'undefined') {
		return;
	}

	var internalLinkingEnabled = parseInt(srkInternalLinkingEditor.internalLinkingEnabled || 0, 10) === 1;

	var SRKIcon = el(
		'span',
		{
			className: 'srk-il-editor-plugin-icon'
		},
		el(
			'img',
			{
				src: srkInternalLinkingEditor.iconUrl,
				alt: '',
				width: 25,
				height: 25,
				loading: 'eager',
				decoding: 'sync',
				fetchPriority: 'high'
			}
		)
	);

	function postAjax(data) {
		data.nonce = srkInternalLinkingEditor.nonce;
		return $.post(srkInternalLinkingEditor.ajaxUrl, data);
	}

	function getResponseMessage(response, fallback) {
		if (
			response &&
			response.data &&
			typeof response.data === 'object' &&
			response.data.message
		) {
			return response.data.message;
		}

		if (
			response &&
			typeof response.data === 'string' &&
			response.data
		) {
			return response.data;
		}

		return fallback;
	}

	function normalizeType(type) {
		type = String(type || '').toLowerCase();

		return type === 'ai' || type === 'ai_semantic'
			? 'ai'
			: 'rule';
	}

	function getTypeLabel(type) {
		return normalizeType(type) === 'ai'
			? 'AI Semantic'
			: 'Rule-Based';
	}

	function getTypeClass(type) {
		return normalizeType(type) === 'ai'
			? 'is-ai'
			: 'is-rule';
	}

	function getSuggestionKey(item) {
		return 'suggestion-' + parseInt(item.id || 0, 10);
	}

	function normalizeTargets(item) {
		var itemType = normalizeType(
			item.type || item.selected_type
		);

		if (item.targets && item.targets.length) {
			return item.targets.map(function (target) {
				return $.extend({}, target, {
					type: normalizeType(
						target.type ||
						target.selected_type ||
						itemType
					)
				});
			});
		}

		return [{
			opportunity_id: item.id,
			target_post_id: item.target_post_id || 0,
			target_title: item.target_title || 'Untitled target',
			target_url: item.target_url || '',
			score: item.final_score || item.score || 0,
			type: itemType
		}];
	}
	function escapeRegExp(text) {
		return (text || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function stripAnchorMarkup(text) {
		return (text || '')
			.replace(/<\/?strong>/gi, '')
			.replace(/<\/?a[^>]*>/gi, '')
			.trim();
	}

	function renderAnchorPreview(sentence, anchor) {
		if (!sentence) {
			return '';
		}

		if (!anchor) {
			return sentence;
		}

		return sentence.replace(
			new RegExp('(' + escapeRegExp(anchor) + ')', 'i'),
			'<span class="srk-il-preview-anchor">$1</span>'
		);
	}

	function getTargetUrl(target) {
		return target.target_url || target.url || target.permalink || '';
	}

	function getMatchLabel(score) {
		score = parseInt(score || 0, 10);

		if (score >= 90) {
			return 'High Match';
		}

		if (score >= 75) {
			return 'Good Match';
		}

		return 'Contextual';
	}

	function getCurrentEditorContent() {
		var blocks = select('core/block-editor').getBlocks();

		if (wp.blocks && wp.blocks.serialize) {
			return wp.blocks.serialize(blocks);
		}

		return select('core/editor').getEditedPostContent();
	}

	/**
	 * Replace Gutenberg editor blocks from serialized post content.
	 *
	 * @param {string} serializedContent Serialized Gutenberg content.
	 * @return {boolean}
	 */
	function setCurrentEditorContent(serializedContent) {
		if (!serializedContent) {
			return false;
		}

		if (
			wp.blocks &&
			typeof wp.blocks.parse === 'function'
		) {
			var parsedBlocks = wp.blocks.parse(serializedContent);
			var blockEditorDispatch = dispatch('core/block-editor');

			if (
				blockEditorDispatch &&
				typeof blockEditorDispatch.resetBlocks === 'function'
			) {
				blockEditorDispatch.resetBlocks(parsedBlocks);

				return true;
			}
		}

		var editorDispatch = dispatch('core/editor');

		if (
			editorDispatch &&
			typeof editorDispatch.editPost === 'function'
		) {
			editorDispatch.editPost({
				content: serializedContent
			});

			return true;
		}

		return false;
	}

	function Sidebar() {
		var postId = select('core/editor').getCurrentPostId();

		var activeFeatureState = useState(internalLinkingEnabled ? 'internal-linking' : 'meta-manager');
		var activeFeature = activeFeatureState[0];
		var setActiveFeature = activeFeatureState[1];

		var MetaManagerPanel = window.SRKMetaManagerPanel || null;

		var suggestionsState = useState([]);
		var suggestions = suggestionsState[0];
		var setSuggestions = suggestionsState[1];

		var keywordsState = useState([]);
		var keywords = keywordsState[0];
		var setKeywords = keywordsState[1];

		var selectedTargetsState = useState({});
		var selectedTargets = selectedTargetsState[0];
		var setSelectedTargets = selectedTargetsState[1];

		var editedAnchorsState = useState({});
		var editedAnchors = editedAnchorsState[0];
		var setEditedAnchors = editedAnchorsState[1];

		var anchorModalState = useState(null);
		var anchorModal = anchorModalState[0];
		var setAnchorModal = anchorModalState[1];

		var targetModalState = useState(null);
		var targetModal = targetModalState[0];
		var setTargetModal = targetModalState[1];

		var loadingState = useState(false);
		var loading = loadingState[0];
		var setLoading = loadingState[1];

		var messageState = useState('');
		var message = messageState[0];
		var setMessage = messageState[1];

		var suggestionStatusState = useState(null);
		var suggestionStatus = suggestionStatusState[0];
		var setSuggestionStatus = suggestionStatusState[1];

		function loadSuggestions() {
			var content = getCurrentEditorContent();
			var title = select('core/editor').getEditedPostAttribute('title') || '';

			if (!postId) {
				return;
			}

			setLoading(true);
			setMessage('Scanning current editor content...');

			postAjax({
				action: 'srk_il_get_editor_suggestions',
				post_id: postId,
				title: title,
				content: content
			}).done(function (response) {
				if (!response || !response.success) {
					setMessage(response && response.data && response.data.message ? response.data.message : 'Unable to load opportunities.');
					return;
				}

				var canonicalSuggestions =
					response.data.suggestions || [];

				setSuggestions(
					canonicalSuggestions
				);

				setSuggestionStatus(
					response.data.status || null
				);

				setMessage(
					(response.data.count || 0) + ' opportunities found.'
				);
			}).always(function () {
				setLoading(false);
			});
		}

		function loadSuggestionStatus() {
			if (!postId) {
				return;
			}

			postAjax({
				action: 'srk_il_get_editor_suggestion_status',
				post_id: postId
			}).done(function (response) {
				if (
					response &&
					response.success
				) {
					setSuggestionStatus(
						response.data || null
					);
				}
			});
		}

		function loadKeywords() {
			if (!postId) {
				return;
			}

			postAjax({
				action: 'srk_il_get_editor_keywords',
				post_id: postId
			}).done(function (response) {
				if (response && response.success) {
					setKeywords(response.data && response.data.keywords ? response.data.keywords : []);
				}
			});
		}

		function addCustomKeyword() {
			var input = document.getElementById('srk-il-custom-keyword-input');
			var keyword = input ? input.value.trim() : '';

			if (!keyword) {
				setMessage('Please enter a custom keyword.');
				return;
			}

			postAjax({
				action: 'srk_il_editor_add_custom_keyword',
				post_id: postId,
				keyword: keyword
			}).done(function (response) {
				if (!response || !response.success) {
					setMessage(response && response.data && response.data.message ? response.data.message : 'Unable to add keyword.');
					return;
				}

				input.value = '';
				setKeywords(response.data.keywords || []);
				setMessage(response.data.message || 'Custom keyword added.');
			});
		}

		function deleteCustomKeyword(keywordId) {
			if (!keywordId) {
				setMessage('Missing keyword ID.');
				return;
			}

			if (!window.confirm('Are you sure you want to delete this custom keyword?')) {
				return;
			}

			setLoading(true);
			setMessage('Deleting custom keyword...');

			postAjax({
				action: 'srk_il_editor_delete_custom_keyword',
				post_id: postId,
				keyword_id: keywordId
			}).done(function (response) {
				if (!response || !response.success) {
					setMessage(
						getResponseMessage(
							response,
							'Unable to delete custom keyword.'
						)
					);
					return;
				}

				setKeywords(
					response.data && response.data.keywords
						? response.data.keywords
						: []
				);

				setMessage(
					response.data.message ||
					'Custom keyword deleted successfully.'
				);
			}).fail(function (xhr) {
				var errorMessage = 'Unable to delete custom keyword.';

				if (xhr && xhr.responseJSON) {
					errorMessage = getResponseMessage(
						xhr.responseJSON,
						errorMessage
					);
				}

				setMessage(errorMessage);
			}).always(function () {
				setLoading(false);
			});
		}

		function applySuggestion(opportunityId, groupKey, anchorText) {
			if (!opportunityId) {
				setMessage('Missing opportunity ID.');
				return;
			}

			setLoading(true);
			setMessage('Applying internal link...');

			postAjax({
				action: 'srk_il_apply_editor_suggestion',
				post_id: postId,
				opportunity_id: opportunityId,
				anchor_text: stripAnchorMarkup(anchorText || ''),
				content: getCurrentEditorContent()
			}).done(function (response) {
				if (!response || !response.success) {
					setMessage(
						getResponseMessage(
							response,
							'Unable to apply link.'
						)
					);
					return;
				}

				/*
				* Elementor editor-side Apply only stages the opportunity.
				*
				* Do not modify Gutenberg blocks because Elementor owns the real
				* document content. The link will be committed when the user
				* presses Update/Save.
				*/
				if (
					response.data &&
					response.data.editor_type === 'elementor' &&
					response.data.pending_save
				) {
					setSuggestions(function (current) {
						return current.filter(function (row) {
							return getSuggestionKey(row) !== groupKey;
						});
					});

					setMessage(
						response.data.message ||
						'Link staged for Elementor. Save or update the post to apply it.'
					);

					return;
				}

				/*
				* Elementor documents are saved directly by the PHP adapter.
				*
				* Do not attempt to reset Gutenberg blocks with Elementor data.
				*/
				if (
					response.data &&
					response.data.editor_type === 'elementor' &&
					response.data.elementor_saved
				) {
					setSuggestions(function (current) {
						return current.filter(function (row) {
							return getSuggestionKey(row) !== groupKey;
						});
					});

					setMessage(
						response.data.message ||
						'Link inserted into Elementor content successfully.'
					);

					loadKeywords();

					if (
						typeof loadSuggestionStatus ===
						'function'
					) {
						loadSuggestionStatus();
					}

					return;
				}

				var updatedContent =
					response.data.post_content ||
					response.data.content ||
					'';

				if (
					!updatedContent ||
					!setCurrentEditorContent(updatedContent)
				) {
					setMessage(
						'The link was generated, but Gutenberg content could not be updated.'
					);

					return;
				}

				setSuggestions(function (current) {
					return current.filter(function (row) {
						return getSuggestionKey(row) !== groupKey;
					});
				});

				setMessage(
					response.data.message || 'Link inserted successfully.'
				);

				loadKeywords();
			}).fail(function (xhr) {
				var errorMessage = 'Unable to apply link.';

				if (xhr && xhr.responseJSON) {
					errorMessage = getResponseMessage(
						xhr.responseJSON,
						errorMessage
					);
				} else if (
					xhr &&
					xhr.responseText
				) {
					errorMessage = xhr.responseText;
				}

				setMessage(errorMessage);
			}).always(function () {
				setLoading(false);
			});
		}

		function openAnchorModal(groupKey, item, selectedTarget, targets) {
			var anchor = editedAnchors[groupKey] || item.anchor_text || '';

			setAnchorModal({
				groupKey: groupKey,
				anchorText: anchor,
				sentence: item.sentence || '',
				previewHtml: renderAnchorPreview(item.sentence || '', anchor),
				targets: targets,
				selectedTargetId: selectedTarget.opportunity_id,
				search: ''
			});
		}

		function saveAnchorModal() {
			if (!anchorModal || !anchorModal.groupKey) {
				return;
			}

			setEditedAnchors(function (current) {
				var updated = $.extend({}, current);
				updated[anchorModal.groupKey] = stripAnchorMarkup(anchorModal.anchorText);
				return updated;
			});

			setSelectedTargets(function (current) {
				var updated = $.extend({}, current);
				updated[anchorModal.groupKey] = parseInt(anchorModal.selectedTargetId, 10);
				return updated;
			});

			setAnchorModal(null);
		}

		function openTargetModal(groupKey, anchorText, targets, selectedId) {
			setTargetModal({
				groupKey: groupKey,
				anchorText: anchorText,
				targets: targets,
				selectedTargetId: selectedId
			});
		}

		function confirmTargetModal() {
			if (!targetModal || !targetModal.groupKey) {
				return;
			}

			setSelectedTargets(function (current) {
				var updated = $.extend({}, current);
				updated[targetModal.groupKey] = parseInt(targetModal.selectedTargetId, 10);
				return updated;
			});

			setTargetModal(null);
		}

		useEffect(function () {

			loadSuggestions();
			loadKeywords();

		}, []);

		/*
		* Poll only while AI for this post is actually processing.
		*
		* This request reads status only. It does not run the rule
		* engine or make an AI API request.
		*/
		useEffect(function () {

			if (
				!postId ||
				!suggestionStatus ||
				suggestionStatus.ai_state !== 'processing'
			) {
				return undefined;
			}

			var timer = window.setInterval(
				function () {
					loadSuggestionStatus();
				},
				15000
			);

			return function () {
				window.clearInterval(
					timer
				);
			};

		}, [
			postId,
			suggestionStatus
				? suggestionStatus.ai_state
				: ''
		]);

		function renderSuggestionStatus(status) {
			if (!status) {
				return null;
			}

			var aiState =
				String(
					status.ai_state ||
					'not_processed'
				);

			var canManageSettings =
				parseInt(
					srkInternalLinkingEditor.canManageSettings || 0,
					10
				) === 1;

			return el(
				'div',
				{
					className:
						'srk-il-suggestion-status-card'
				},

				el(
					'div',
					{
						className:
							'srk-il-suggestion-status-header'
					},
					el(
						'strong',
						null,
						'Suggestion Status'
					),
					el(
						'span',
						{
							className:
								'srk-il-suggestion-status-count'
						},
						String(
							status.available_count || 0
						) + ' available'
					)
				),

				el(
					'div',
					{
						className:
							'srk-il-suggestion-status-row'
					},
					el(
						'span',
						null,
						'Rule-Based'
					),
					el(
						'strong',
						{
							className:
								'srk-il-suggestion-state is-ready'
						},
						'Ready'
					)
				),

				el(
					'div',
					{
						className:
							'srk-il-suggestion-status-row'
					},
					el(
						'span',
						null,
						' AI-Powered '
					),
					el(
						'strong',
						{
							className:
								'srk-il-suggestion-state is-' +
								aiState
						},
						status.ai_label ||
							'Not Processed'
					)
				),

				status.ai_enabled
					? el(
						'div',
						{
							className:
								'srk-il-ai-coverage'
						},

						el(
							'div',
							{
								className:
									'srk-il-ai-coverage-heading'
							},
							el(
								'span',
								null,
								'AI Coverage'
							),
							el(
								'strong',
								null,
								String(
									status.embeddings_ready || 0
								) +
								' / ' +
								String(
									status.ai_total || 0
								)
							)
						),

						el(
							'div',
							{
								className:
									'srk-il-ai-coverage-track'
							},
							el(
								'span',
								{
									style: {
										width:
											String(
												status.coverage_percent || 0
											) + '%'
									}
								}
							)
						)
					)
					: null,

				el(
					'p',
					{
						className:
							'srk-il-suggestion-status-message'
					},
					status.ai_message ||
						'Rule-based suggestions are available now.'
				),

				canManageSettings &&
				srkInternalLinkingEditor.settingsUrl
					? el(
						'a',
						{
							href:
								srkInternalLinkingEditor.settingsUrl,

							className:
								'srk-il-ai-settings-link',

							target:
								'_blank',

							rel:
								'noopener noreferrer'
						},
						'AI Settings'
					)
					: null
			);
		}

		return el(
			PluginSidebar,
			{
				name: 'srk-editor-sidebar',
				title: 'SEO Repair Kit',
				icon: SRKIcon,
				className: 'srk-editor-sidebar'
			},

			el(
				'div',
				{
					className: 'srk-editor-feature-tabs'
				},

				el(
					'button',
					{
						type: 'button',
						className:
							'srk-editor-feature-tab ' +
							(
								activeFeature === 'internal-linking'
									? 'is-active'
									: ''
							),
						onClick: function () {
							setActiveFeature('internal-linking');
						},
						title: 'Internal Linking',
						'aria-label': 'Open Internal Linking'
					},

					el('span', {
						className: 'dashicons dashicons-admin-links srk-editor-feature-dashicon',
						'aria-hidden': 'true'
					}),

					el(
						'span',
						{
							className: 'srk-editor-feature-label'
						},
						'Internal Linking'
					)
				),

				el(
					'button',
					{
						type: 'button',
						className:
							'srk-editor-feature-tab ' +
							(
								activeFeature === 'meta-manager'
									? 'is-active'
									: ''
							),
						onClick: function () {
							setActiveFeature('meta-manager');
						},
						title: 'Meta Manager',
						'aria-label': 'Open Meta Manager'
					},

					el('span', {
						className: 'dashicons dashicons-search srk-editor-feature-dashicon',
						'aria-hidden': 'true'
					}),

					el(
						'span',
						{
							className: 'srk-editor-feature-label'
						},
						'Meta Manager'
					)
				)

			),

			el(
				'div',
				{
					className:
						'srk-editor-feature-view srk-editor-meta-view ' +
						(
							activeFeature === 'meta-manager'
								? 'is-active'
								: ''
						)
				},

				MetaManagerPanel
					? el(MetaManagerPanel, null)
					: el(
						Notice,
						{
							status: 'error',
							isDismissible: false
						},
						'Meta Manager could not be loaded.'
					)
			),

			el(
				'div',
				{
					className:
						'srk-editor-feature-view srk-editor-internal-linking-view ' +
						(
							activeFeature === 'internal-linking'
								? 'is-active'
								: ''
						)
				},

				internalLinkingEnabled && activeFeature === 'internal-linking' && targetModal
					? el(
						Modal,
						{
							title: 'Select Target Post',
							onRequestClose: function () {
								setTargetModal(null);
							},
							className: 'srk-il-target-modal'
						},

						el(
							'div',
							{ className: 'srk-il-target-modal-note' },
							'Multiple matches found for anchor: ',
							el('strong', null, '"' + targetModal.anchorText + '"')
						),

						el(
							'div',
							{ className: 'srk-il-target-option-list' },
							targetModal.targets.map(function (target) {
								var checked = parseInt(targetModal.selectedTargetId, 10) === parseInt(target.opportunity_id, 10);

								return el(
									'button',
									{
										type: 'button',
										key: target.opportunity_id,
										className: checked ? 'srk-il-target-option is-selected' : 'srk-il-target-option',
										onClick: function () {
											setTargetModal($.extend({}, targetModal, {
												selectedTargetId: target.opportunity_id
											}));
										}
									},
									el('span', { className: 'srk-il-target-radio' }),
									el(
										'span',
										{ className: 'srk-il-target-content' },
										el('span', { className: 'srk-il-target-title-line' }, target.target_title || 'Untitled target'),
										el('span', { className: 'srk-il-target-url-line' }, getTargetUrl(target) || 'No URL available')
									),
									el('span', { className: 'srk-il-target-badge' }, getMatchLabel(target.score))
								);
							})
						),

						el(
							'div',
							{ className: 'srk-il-editor-modal-footer' },
							el(Button, { variant: 'secondary', onClick: function () { setTargetModal(null); } }, 'Cancel'),
							el(Button, { variant: 'primary', onClick: confirmTargetModal }, 'Confirm Selection')
						)
					)
					: null,

				internalLinkingEnabled && activeFeature === 'internal-linking' && anchorModal
					? el(
						Modal,
						{
							title: 'Edit Anchor Selection',
							onRequestClose: function () {
								setAnchorModal(null);
							},
							className: 'srk-il-editor-anchor-modal'
						},

						el('div', { className: 'srk-il-modal-label' }, 'CONTEXT PREVIEW'),

						el('div', {
							className: 'srk-il-anchor-live-preview',
							dangerouslySetInnerHTML: {
								__html: anchorModal.previewHtml || anchorModal.sentence
							}
						}),

						el(TextControl, {
							label: 'Selected Anchor Text',
							value: anchorModal.anchorText,
							onChange: function (value) {
								value = stripAnchorMarkup(value);

								setAnchorModal($.extend({}, anchorModal, {
									anchorText: value,
									previewHtml: renderAnchorPreview(anchorModal.sentence, value)
								}));
							}
						}),

						el(
							'div',
							{ className: 'srk-il-editor-modal-footer' },
							el(Button, { variant: 'primary', onClick: saveAnchorModal }, 'Save Change')
						)
					)
					: null,

				! internalLinkingEnabled
					? el(
						Notice,
						{
							status: 'warning',
							isDismissible: false
						},
						srkInternalLinkingEditor.internalLinkingPaidRequiredMessage || 'Internal Linking is a paid module. Please upgrade or renew Internal Linking to use this feature.'
					)
					: null,

				internalLinkingEnabled
					? el(
						PanelBody,
						{
							title: 'Link Opportunities',
							initialOpen: true
						},

					el(Button, {
						variant: 'primary',
						className: 'srk-il-editor-scan-button',
						onClick: loadSuggestions,
						disabled: loading
					}, loading ? 'Scanning...' : 'Scan Current Post'),

					loading ? el(Spinner, null) : null,

					renderSuggestionStatus(
						suggestionStatus
					),

					message
						? el(Notice, {
							status: 'info',
							isDismissible: true,
							onRemove: function () {
								setMessage('');
							}
						}, message)
						: null,

					!loading && suggestions.length === 0
						? el('p', null, 'No opportunities found for this post.')
						: null,

					!loading
						? suggestions.map(function (item, index) {
							var targets = normalizeTargets(item);
							var groupKey = getSuggestionKey(item);
							var selectedId = selectedTargets[groupKey] || targets[0].opportunity_id;
							var selectedTarget = targets.filter(function (target) {
								return parseInt(target.opportunity_id, 10) === parseInt(selectedId, 10);
							})[0] || targets[0];

							var suggestionType = normalizeType(
								selectedTarget.type ||
								selectedTarget.selected_type ||
								item.type ||
								item.selected_type
							);

							var anchorText = editedAnchors[groupKey] || item.anchor_text || 'Untitled anchor';
							var score = selectedTarget.score || item.best_score || item.score || 0;

							return el(
								'div',
								{ key: groupKey, className: 'srk-il-editor-suggestion-card' },

								el(
									'div',
									{ className: 'srk-il-card-header' },

									el(
										'div',
										{ className: 'srk-il-card-badges' },

										el(
											'span',
											{
												className:
													'srk-il-source-badge ' +
													getTypeClass(suggestionType)
											},
											getTypeLabel(suggestionType)
										),

										el(
											'span',
											{ className: 'srk-il-match-badge' },
											getMatchLabel(score)
										)
									),

									el(
										'span',
										{ className: 'srk-il-score-text' },
										score + '/100'
									)
								),

								el('div', {
									className: 'srk-il-editor-context-text',
									dangerouslySetInnerHTML: {
										__html: renderAnchorPreview(item.sentence || 'No sentence preview available.', anchorText)
									}
								}),

								targets.length > 1
									? el(
										'div',
										{ className: 'srk-il-card-target-box' },
										el('div', { className: 'srk-il-card-target-label' }, targets.length + ' Target Options'),
										el(
											'button',
											{
												type: 'button',
												className: 'srk-il-card-target-button',
												onClick: function () {
													openTargetModal(groupKey, anchorText, targets, selectedId);
												}
											},
											selectedTarget.target_title || 'Select target post'
										)
									)
									: el(
										'a',
										{
											className: 'srk-il-editor-target-link',
											href: getTargetUrl(selectedTarget) || '#',
											target: '_blank',
											rel: 'noopener noreferrer'
										},
										selectedTarget.target_title || 'Untitled target'
									),

								el(
									'div',
									{ className: 'srk-il-card-actions' },
									el(
										Button,
										{
											variant: 'link',
											className: 'srk-il-edit-link',
											onClick: function () {
												openAnchorModal(groupKey, item, selectedTarget, targets);
											}
										},
										'Edit Anchor'
									),
									el(
										Button,
										{
											variant: 'primary',
											className: 'srk-il-editor-apply-button',
											onClick: function () {
												applySuggestion(
													selectedId,
													groupKey,
													anchorText
												);
											}
										},
										'Apply Link'
									)
								)
							);
						})
						: null
				)
				: null,

				internalLinkingEnabled
					? el(
					PanelBody,
					{
						title: 'Target Keywords',
						initialOpen: true
					},

					el('strong', { className: 'srk-il-editor-section-title' }, 'Detected Keywords'),

					el(
						'div',
						{ className: 'srk-il-editor-keyword-list' },
						keywords.filter(function (item) {
							return item.source !== 'custom';
						}).length === 0
							? el('p', { className: 'srk-il-editor-empty' }, 'No detected keywords yet.')
							: keywords.filter(function (item) {
								return item.source !== 'custom';
							}).map(function (item) {
								return el('span', {
									key: 'detected-' + item.id,
									className: 'srk-il-editor-keyword-chip'
								}, item.keyword);
							})
					),

					el('strong', { className: 'srk-il-editor-section-title' }, 'Custom Keywords'),

					el(
						'div',
						{ className: 'srk-il-editor-custom-add' },
						el('input', {
							type: 'text',
							placeholder: 'Add custom keyword...',
							id: 'srk-il-custom-keyword-input'
						}),
						el(Button, {
							variant: 'primary',
							isSmall: true,
							onClick: addCustomKeyword
						}, 'Add')
					),

					el(
						'div',
						{ className: 'srk-il-editor-keyword-list' },
						keywords.filter(function (item) {
							return item.source === 'custom';
						}).length === 0
							? el(
								'p',
								{
									className: 'srk-il-editor-empty'
								},
								'No custom keywords added yet.'
							)
							: keywords.filter(function (item) {
								return item.source === 'custom';
							}).map(function (item) {
								return el(
									'span',
									{
										key: 'custom-' + item.id,
										className: 'srk-il-editor-custom-chip'
									},

									el(
										'span',
										{
											className: 'srk-il-editor-custom-keyword-text'
										},
										item.keyword
									),

									el(
										'button',
										{
											type: 'button',
											className: 'srk-il-editor-custom-keyword-delete',
											title: 'Delete custom keyword',
											'aria-label': 'Delete ' + item.keyword,
											onClick: function () {
												deleteCustomKeyword(item.id);
											},
											disabled: loading
										},
										'×'
									)
								);
							})
					)
				)
				: null
			)	
		);
	}

	registerPlugin('srk-internal-linking-editor', {
		render: Sidebar,
		icon: SRKIcon
	});

})(window.wp, jQuery);
