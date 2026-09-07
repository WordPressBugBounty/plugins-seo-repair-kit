(function ($) {
	'use strict';

	/**
	 * Currently active/scanned rule ID.
	 */
	var activeRuleId = 0;
	var editingRuleId = 0;

	/**
	 * Send AJAX request to WordPress.
	 */
	function ajax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = srkInternalLinking.nonce;

		return $.post(srkInternalLinking.ajaxUrl, data);
	}

	/**
	 * Show simple admin notice.
	 */
	function notice(msg) {
		window.alert(msg);
	}

	/**
	 * Format the result returned by an auto-link application request.
	 */
	function applyResultMessage(data) {
		data = data || {};

		var processed = parseInt(data.processed || 0, 10);
		var inserted = parseInt(data.inserted || 0, 10);
		var failed = parseInt(data.failed || 0, 10);

		var message =
			'Processed: ' + processed +
			' | Inserted: ' + inserted +
			' | Failed: ' + failed;

		if (
			Array.isArray(data.errors) &&
			data.errors.length
		) {
			message += '\n\n';

			message += data.errors.map(function (error) {
				var postName = error.post_title
					? error.post_title
					: 'Post #' + (error.post_id || 0);

				return postName + ': ' +
					(error.message || 'Unable to insert link.');
			}).join('\n');
		}

		return message;
	}

	/**
	 * Collect form data including unchecked checkboxes.
	 */
	function collectForm($form) {
		var data = {};

		$form.serializeArray().forEach(function (i) {
			if (i.name.slice(-2) === '[]') {
				var k = i.name.slice(0, -2);

				data[k] = data[k] || [];
				data[k].push(i.value);
			} else {
				data[i.name] = i.value;
			}
		});

		$form.find('input[type="checkbox"]:not(:checked)').each(function () {
			if (
				this.name &&
				this.name.slice(-2) !== '[]' &&
				typeof data[this.name] === 'undefined'
			) {
				data[this.name] = 0;
			}
		});

		return data;
	}

	/**
	 * Refresh rules table.
	 */
	function refreshRules() {
		return ajax('srk_il_auto_get_rules', {}).done(function (res) {
			if (res.success && res.data) {
				$('#srk-auto-rules-body').html(res.data.html || '');
				$('#srk-auto-rules-count').text(res.data.count_text || '');
			}
		});
	}

	/**
	 * Render matched posts for a scanned rule.
	 */
	function renderMatches(ruleId, matches) {
		activeRuleId = ruleId;

		var $box = $('#srk-auto-preview-body');

		matches = matches || [];

		if (!matches.length) {
			$box.html('<div class="srk-auto-preview-empty">No keyword matched posts found.</div>');
			$('.srk-auto-apply-selected,.srk-auto-remove-all-rule').prop('disabled', true);
			return;
		}

		var html = '';

		html += '<div class="srk-auto-preview-table-wrap">';
		html += '<table class="srk-auto-preview-table">';
		html += '<thead>';
		html += '<tr>';
		html += '<th><input type="checkbox" class="srk-auto-check-all"></th>';
		html += '<th>Post</th>';
		html += '<th>Keyword</th>';
		html += '<th>Matches</th>';
		html += '<th>Status</th>';
		html += '<th>Action</th>';
		html += '</tr>';
		html += '</thead>';
		html += '<tbody>';

		matches.forEach(function (m) {
			var applied = parseInt(m.is_applied || 0, 10) === 1;

			html += '<tr data-post-id="' + esc(m.post_id) + '">';
			html += '<td>';
			html += '<input type="checkbox" class="srk-auto-match-check" value="' + esc(m.post_id) + '" ' + (applied ? 'disabled' : '') + '>';
			html += '</td>';
			html += '<td>';
			html += '<strong>' + esc(m.title || 'Untitled') + '</strong><br>';
			html += '<a href="' + esc(m.edit_link || m.edit_url || '#') + '" target="_blank">Edit</a>';
			html += ' · ';
			html += '<a href="' + esc(m.view_link || m.url || '#') + '" target="_blank">View</a>';
			html += '</td>';
			html += '<td>' + esc(m.matched_keyword || '') + '</td>';
			html += '<td><strong>' + esc(m.matches || 1) + '</strong></td>';
			html += '<td>';
			html += '<span class="srk-auto-preview-status ' + (applied ? 'applied' : 'eligible') + '">';
			html += applied ? 'Applied' : 'Matched';
			html += '</span>';
			html += '</td>';
			html += '<td>';
			html += applied
				? '<button type="button" class="button-link srk-auto-remove-post-link">Remove</button>'
				: '';
			html += '</td>';
			html += '</tr>';
		});

		html += '</tbody>';
		html += '</table>';
		html += '</div>';

		$box.html(html);

		$('.srk-auto-apply-selected,.srk-auto-remove-all-rule').prop('disabled', false);
	}

	/**
	 * Escape HTML output.
	 */
	function esc(v) {
		return String(v == null ? '' : v)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Change manual/auto selection mode.
	 */
	$(document).on('click', '.srk-auto-segment button', function () {
		$(this).addClass('is-active').siblings().removeClass('is-active');

		$(this)
			.closest('form')
			.find('input[name="selection_mode"]')
			.val($(this).data('srk-auto-mode'));
	});

	/**
	 * Open settings modal.
	 */
	$(document).on('click', '.srk-auto-open-settings', function (e) {
		e.preventDefault();

		$('.srk-auto-modal')
			.addClass('is-open')
			.attr('aria-hidden', 'false');

		$('body').addClass('srk-auto-modal-open');
	});

	/**
	 * Close settings modal.
	 */
	$(document).on('click', '.srk-auto-close-settings,.srk-auto-modal-backdrop', function (e) {
		e.preventDefault();

		$('.srk-auto-modal')
			.removeClass('is-open')
			.attr('aria-hidden', 'true');

		$('body').removeClass('srk-auto-modal-open');
	});

	/**
	 * Create rule and scan matches.
	 */
	$(document).on('submit', '#srk-auto-create-rule-form', function (e) {
		e.preventDefault();

		var $form = $(this);
		var action = editingRuleId ? 'srk_il_auto_update_rule' : 'srk_il_auto_create_rule';
		var data = collectForm($form);

		if (editingRuleId) {
			data.rule_id = editingRuleId;
		}

		ajax(action, data).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to save rule.');
				return;
			}

			editingRuleId = 0;

			$form[0].reset();

			$('.srk-auto-create-rule').html(
				'<span class="dashicons dashicons-plus-alt2"></span>Create & Scan Rule'
			);

			refreshRules();

			if (res.data.matches) {
				renderMatches(res.data.rule_id, res.data.matches || []);
			}

			notice(res.data.message || 'Rule saved.');
		});
	});

	/**
	 * Scan selected rule.
	 */
	$(document).on('click', '.srk-auto-scan-rule', function () {
		var id = $(this).closest('tr').data('rule-id');

		ajax('srk_il_auto_scan_rule', { rule_id: id }).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to scan rule.');
				return;
			}

			renderMatches(id, res.data.matches || res.data.rows || []);
			refreshRules();
		});
	});

	/**
	 * Apply selected matched posts.
	 */
	$(document).on('click', '.srk-auto-apply-selected', function () {
		var ids = [];

		$('.srk-auto-match-check:checked').each(function () {
			ids.push($(this).val());
		});

		if (!activeRuleId || !ids.length) {
			notice('Please select at least one matched post.');
			return;
		}

		ajax('srk_il_auto_apply_selected', {
			rule_id: activeRuleId,
			post_ids: ids
		}).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to apply links.');
				return;
			}

			notice(	applyResultMessage(res.data) );

			$('.srk-auto-scan-rule')
				.filter(function () {
					return $(this).closest('tr').data('rule-id') == activeRuleId;
				})
				.trigger('click');

			refreshRules();
		});
	});

	/**
	 * Apply full rule.
	 */
	$(document).on('click', '.srk-auto-apply-rule', function () {
		var id = $(this).closest('tr').data('rule-id');

		ajax('srk_il_auto_apply_rule', { rule_id: id }).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to apply rule.');
				return;
			}

			notice( applyResultMessage(res.data) );
			refreshRules();
		});
	});

	/**
	 * Pause or activate rule.
	 */
	$(document).on('click', '.srk-auto-pause,.srk-auto-play', function () {
		var $r = $(this).closest('tr');

		ajax('srk_il_auto_update_rule_status', {
			rule_id: $r.data('rule-id'),
			status: $(this).hasClass('srk-auto-pause') ? 'paused' : 'active'
		}).done(refreshRules);
	});

	/**
	 * Delete rule.
	 */
	var deleteRuleId = 0;

	$(document).on('click', '.srk-auto-delete', function () {
		deleteRuleId = $(this).closest('tr').data('rule-id');

		$('.srk-auto-delete-modal')
			.addClass('is-open')
			.attr('aria-hidden', 'false');

		$('body').addClass('srk-auto-modal-open');
	});

	$(document).on('click', '.srk-auto-delete-cancel,.srk-auto-delete-backdrop', function () {
		deleteRuleId = 0;

		$('.srk-auto-delete-modal')
			.removeClass('is-open')
			.attr('aria-hidden', 'true');

		$('body').removeClass('srk-auto-modal-open');
	});

	$(document).on('click', '.srk-auto-delete-with-links,.srk-auto-delete-rule-only', function () {
		if (!deleteRuleId) {
			return;
		}

		var removeLinks = $(this).hasClass('srk-auto-delete-with-links') ? 1 : 0;

		ajax('srk_il_auto_delete_rule', {
			rule_id: deleteRuleId,
			remove_links: removeLinks
		}).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to delete rule.');
				return;
			}

			var deletedRuleId = deleteRuleId;

			deleteRuleId = 0;

			$('.srk-auto-delete-modal')
				.removeClass('is-open')
				.attr('aria-hidden', 'true');

			$('body').removeClass('srk-auto-modal-open');

			refreshRules();

			/*
			* If the deleted rule is currently being displayed
			* in Content Matches Found, clear its stale results.
			*/
			if (parseInt(activeRuleId || 0, 10) === parseInt(deletedRuleId || 0, 10)) {
				activeRuleId = 0;

				$('#srk-auto-preview-body').html(
					'<div class="srk-auto-preview-empty">Select or scan a rule to view content matches.</div>'
				);

				$('.srk-auto-apply-selected,.srk-auto-remove-all-rule')
					.prop('disabled', true);
			}

			notice(res.data.message || 'Rule deleted.');
		});
	});

	/**
	 * Remove auto links inserted by the active rule from one post.
	 */
	$(document).on('click', '.srk-auto-remove-post-link', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var postId = parseInt($row.data('post-id') || 0, 10);

		if (!activeRuleId || !postId) {
			notice('Invalid rule or post.');
			return;
		}

		$button
			.prop('disabled', true)
			.text('Removing...');

		ajax('srk_il_auto_remove_post_links', {
			rule_id: activeRuleId,
			post_id: postId
		})
			.done(function (res) {
				if (!res.success) {
					notice(
						res.data && res.data.message
							? res.data.message
							: 'Unable to remove link.'
					);

					return;
				}

				/*
				* Backend returns a fresh rule scan, so Applied status,
				* checkbox state and Remove button are updated immediately.
				*/
				renderMatches(
					activeRuleId,
					res.data.matches || []
				);

				refreshRules();

				notice(
					res.data.message || 'Link removed.'
				);
			})
			.fail(function () {
				notice('Unable to remove link. Please try again.');
			})
			.always(function () {
				$button
					.prop('disabled', false)
					.text('Remove');
			});
	});

	/**
	 * Remove all auto links for the active rule.
	 */
	$(document).on('click', '.srk-auto-remove-all-rule', function () {
		var $button = $(this);

		if (
			!activeRuleId ||
			!window.confirm('Remove all links inserted by this rule?')
		) {
			return;
		}

		$button
			.prop('disabled', true)
			.text('Removing...');

		ajax('srk_il_auto_remove_all_rule_links', {
			rule_id: activeRuleId
		})
			.done(function (res) {
				if (!res.success) {
					notice(
						res.data && res.data.message
							? res.data.message
							: 'Unable to remove links.'
					);

					return;
				}

				renderMatches(
					activeRuleId,
					res.data.matches || []
				);

				refreshRules();

				notice(
					res.data.message || 'Rule links removed.'
				);
			})
			.fail(function () {
				notice('Unable to remove links. Please try again.');
			})
			.always(function () {
				$button
					.prop('disabled', false)
					.text('Remove Rule Links');
			});
	});

	$(document).on('click', '.srk-auto-edit-rule', function () {
		var id = $(this).closest('tr').data('rule-id');

		ajax('srk_il_auto_get_rule', {
			rule_id: id
		}).done(function (res) {
			if (!res.success || !res.data || !res.data.rule) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to load rule.');
				return;
			}

			var rule = res.data.rule;
			var $form = $('#srk-auto-create-rule-form');

			editingRuleId = rule.id;

			$form.find('[name="keyword"]').val(rule.keyword);
			$form.find('[name="target_url"]').val(rule.target_url);
			$form.find('[name="selection_mode"]').val(rule.selection_mode || 'manual');

			$('.srk-auto-segment button').removeClass('is-active');
			$('.srk-auto-segment button[data-srk-auto-mode="' + (rule.selection_mode || 'manual') + '"]').addClass('is-active');

			$form.find('[name="case_sensitive"]').prop('checked', parseInt(rule.case_sensitive || 0, 10) === 1);
			$form.find('[name="max_links_per_post"]').val(rule.max_links_per_post || 3);
			$form.find('[name="max_links_per_keyword"]').val(rule.max_links_per_keyword || 1);
			$form.find('[name="priority"]').val(rule.priority || 10);
			$form.find('[name="apply_after_date"]').val(rule.apply_after_date || '');
			$form.find('[name="allow_duplicate_target"]').prop('checked', parseInt(rule.allow_duplicate_target || 0, 10) === 1);
			$form.find('[name="require_target_published"]').prop('checked', parseInt(rule.require_target_published || 0, 10) === 1);

			$form.find('[name="post_types[]"]').prop('checked', false);
			(rule.post_types || []).forEach(function (type) {
				$form.find('[name="post_types[]"][value="' + type + '"]').prop('checked', true);
			});

			$form.find('[name="categories[]"]').prop('checked', false);
			(rule.categories || []).forEach(function (id) {
				$form.find('[name="categories[]"][value="' + id + '"]').prop('checked', true);
			});

			$form.find('[name="tags[]"]').prop('checked', false);
			(rule.tags || []).forEach(function (id) {
				$form.find('[name="tags[]"][value="' + id + '"]').prop('checked', true);
			});

			$('.srk-auto-create-rule').html(
				'<span class="dashicons dashicons-update"></span>Update & Refresh Rule'
			);

			$('html, body').animate({
				scrollTop: $form.offset().top - 80
			}, 300);
		});
	});

	$(document).on('click', '.srk-auto-refresh-rule', function () {
		var id = $(this).closest('tr').data('rule-id');

		ajax('srk_il_auto_scan_rule', {
			rule_id: id
		}).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to refresh rule.');
				return;
			}

			renderMatches(id, res.data.matches || res.data.rows || []);
			refreshRules();
			notice('Rule refreshed.');
		});
	});

	/**
	 * Save settings.
	 */
	$(document).on('submit', '#srk-auto-settings-form', function (e) {
		e.preventDefault();

		ajax('srk_il_auto_save_settings', collectForm($(this))).done(function (res) {
			if (!res.success) {
				notice(res.data && res.data.message ? res.data.message : 'Unable to save settings.');
				return;
			}

			$('.srk-auto-modal').removeClass('is-open');
			$('body').removeClass('srk-auto-modal-open');

			notice('Settings saved.');
		});
	});

	/**
	 * Select or unselect all matched posts.
	 */
	$(document).on('change', '.srk-auto-check-all', function () {
		$('.srk-auto-match-check:not(:disabled)').prop('checked', this.checked);
	});

	/**
	 * Initial rules load.
	 */
	refreshRules();

})(jQuery);