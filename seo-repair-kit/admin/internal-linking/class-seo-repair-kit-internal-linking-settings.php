<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SeoRepairKit_InternalLinking_Settings {

	private function get_settings() {
		if ( class_exists( 'SRK_Internal_Linking_Settings' ) && method_exists( 'SRK_Internal_Linking_Settings', 'get' ) ) {
			return SRK_Internal_Linking_Settings::get();
		}

		if ( class_exists( 'SRK_Internal_Linking_Ajax' ) && method_exists( 'SRK_Internal_Linking_Ajax', 'get_settings' ) ) {
			return SRK_Internal_Linking_Ajax::get_settings();
		}

		return array();
	}

	/**
	 * Check whether a value exists in a settings array.
	 *
	 * @param string $value Value to check.
	 * @param array  $array Settings values.
	 *
	 * @return bool
	 */
	private function is_checked_array( $value, $array ) {
		return in_array(
			$value,
			(array) $array,
			true
		);
	}

	public function render() {
		$settings = $this->get_settings();
		?>

		<div class="srk-il-settings-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Internal Linking Settings', 'seo-repair-kit' ); ?>">
			<button
				type="button"
				class="srk-il-settings-tab is-active"
				data-tab="general"
				role="tab"
				aria-selected="true"
			>
				<?php esc_html_e( 'General Settings', 'seo-repair-kit' ); ?>
			</button>

			<button
				type="button"
				class="srk-il-settings-tab"
				data-tab="ai"
				role="tab"
				aria-selected="false"
			>
				<?php esc_html_e( 'AI Settings', 'seo-repair-kit' ); ?>
			</button>
		</div>

		<div class="srk-il-settings-tab-content srk-il-settings-tab-general is-active">

			<div class="srk-settings-grid">

				<div class="srk-settings-card">
					<div class="srk-settings-card-title">
						<span class="dashicons dashicons-controls-repeat"></span>
						<h3><?php esc_html_e( 'General Settings', 'seo-repair-kit' ); ?></h3>
					</div>

					<div class="srk-settings-body">
						<div class="srk-settings-toggle-row">
							<div>
								<strong><?php esc_html_e( 'Enable Internal Linking', 'seo-repair-kit' ); ?></strong>
								<p><?php esc_html_e( 'Globally toggle the internal linking module.', 'seo-repair-kit' ); ?></p>
							</div>
							<label class="srk-switch">
								<input type="checkbox" name="enabled" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
								<span></span>
							</label>
						</div>

						<div class="srk-settings-toggle-row">
							<div>
								<strong><?php esc_html_e( 'Enable Auto Linking', 'seo-repair-kit' ); ?></strong>
								<p><?php esc_html_e( 'Allow keyword rules to create links automatically.', 'seo-repair-kit' ); ?></p>
							</div>
							<label class="srk-switch">
								<input type="checkbox" name="auto_linking_enabled" <?php checked( ! empty( $settings['auto_linking_enabled'] ) ); ?>>
								<span></span>
							</label>
						</div>

						<div class="srk-settings-check-grid">
							<label><input type="checkbox" name="skip_existing_links" <?php checked( ! empty( $settings['skip_existing_links'] ) ); ?>> <?php esc_html_e( 'Skip Existing Links', 'seo-repair-kit' ); ?></label>
							<label><input type="checkbox" name="skip_headings" <?php checked( ! empty( $settings['skip_headings'] ) ); ?>> <?php esc_html_e( 'Skip Headings (H1-H6)', 'seo-repair-kit' ); ?></label>
							<label><input type="checkbox" name="skip_html_blocks" <?php checked( ! empty( $settings['skip_html_blocks'] ) ); ?>> <?php esc_html_e( 'Skip HTML Blocks', 'seo-repair-kit' ); ?></label>
							<label>
								<span><?php esc_html_e( 'Batch Size', 'seo-repair-kit' ); ?></span>
								<input type="number" name="batch_size" min="1" max="100" value="<?php echo esc_attr( absint( $settings['batch_size'] ) ); ?>">
							</label>
						</div>
					</div>
				</div>
				<div class="srk-settings-card srk-settings-stopwords-card">
					<div class="srk-settings-card-title">
						<span class="dashicons dashicons-editor-spellcheck"></span>

						<h3>
							<?php esc_html_e(
								'Stop / Ignore Words',
								'seo-repair-kit'
							); ?>
						</h3>
					</div>

					<div class="srk-settings-body">

						<?php
						$selected_language =
							SRK_Internal_Linking_Stopwords::sanitize_language(
								$settings['selected_language']
									?? SRK_Internal_Linking_Stopwords::detect_site_language()
							);

						$supported_languages =
							SRK_Internal_Linking_Stopwords::get_supported_languages();

						/*
						* This resolves the language-specific saved override first.
						* When no override exists, it loads the matching TXT file.
						*/
						$active_stopwords =
							SRK_Internal_Linking_Settings::get_stopwords(
								$selected_language
							);

						$language_overrides =
							! empty( $settings['ignore_words_by_language'] ) &&
							is_array( $settings['ignore_words_by_language'] )
								? $settings['ignore_words_by_language']
								: array();

						$has_language_override = array_key_exists(
							$selected_language,
							$language_overrides
						);
						?>

						<div class="srk-settings-field srk-settings-wide-field">
							<div class="srk-il-setting-row">
								<label for="srk-il-selected-language">
									<?php esc_html_e(
										'Content Language',
										'seo-repair-kit'
									); ?>
								</label>

								<select
									id="srk-il-selected-language"
									name="selected_language"
								>
									<?php foreach ( $supported_languages as $language_key => $language_data ) : ?>
										<option
											value="<?php echo esc_attr( $language_key ); ?>"
											<?php selected(
												$selected_language,
												$language_key
											); ?>
										>
											<?php echo esc_html(
												$language_data['label']
											); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<p class="description">
									<?php esc_html_e(
										'Select according to your Website Language.',
										'seo-repair-kit'
									); ?>
								</p>
							</div>

							<div class="srk-il-setting-row">

								<label for="srk-il-ignore-words">
									<?php esc_html_e(
										'Stop / Ignore Words',
										'seo-repair-kit'
									); ?>
								</label>

								<p class="description">
									<?php esc_html_e(
										'Enter one ignored word or phrase per line. Saved changes apply only to the currently selected language.',
										'seo-repair-kit'
									); ?>
								</p>

								<textarea
									id="srk-il-ignore-words"
									name="ignore_words"
									rows="10"
									placeholder="<?php esc_attr_e(
										'One word or phrase per line',
										'seo-repair-kit'
									); ?>"
								><?php echo esc_textarea(
									implode(
										PHP_EOL,
										(array) $active_stopwords
									)
								); ?></textarea>

								<div class="srk-il-stopwords-meta">

									<small id="srk-il-stopwords-source">
										<?php
										if ( $has_language_override ) {

											printf(
												/* translators: %d: number of stopwords */
												esc_html__(
													'Using saved language override: %d entries.',
													'seo-repair-kit'
												),
												count( $active_stopwords )
											);

										} else {

											printf(
												/* translators: %d: number of stopwords */
												esc_html__(
													'Using the default language file: %d entries.',
													'seo-repair-kit'
												),
												count( $active_stopwords )
											);
										}
										?>
									</small>

								</div>

							</div>

						</div>

					</div>
				</div>

				<div class="srk-settings-card">
					<div class="srk-settings-card-title">
						<span class="dashicons dashicons-filter"></span>
						<h3><?php esc_html_e( 'Content Scope', 'seo-repair-kit' ); ?></h3>
					</div>

					<div class="srk-settings-body">
						<div class="srk-settings-field">
							<span><?php esc_html_e( 'Post Types Whitelist', 'seo-repair-kit' ); ?></span>

							<?php
							$post_types = get_post_types(
								array(
									'public' => true,
								),
								'objects'
							);

							unset( $post_types['attachment'] );
							?>

							<div class="srk-settings-check-grid compact">
								<?php foreach ( $post_types as $post_type_key => $post_type_obj ) : ?>
									<label>
										<input
											type="checkbox"
											name="post_types[]"
											value="<?php echo esc_attr( $post_type_key ); ?>"
											<?php
											checked(
												$this->is_checked_array(
													$post_type_key,
													$settings['post_types'] ?? array()
												),
												true
											);
											?>
										>
										<?php echo esc_html( $post_type_obj->labels->singular_name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="srk-settings-field">
							<span><?php esc_html_e( 'Taxonomies Whitelist', 'seo-repair-kit' ); ?></span>

							<?php
							$taxonomies = get_taxonomies(
								array(
									'public' => true,
								),
								'objects'
							);
							?>

							<div class="srk-settings-check-grid compact">
								<?php foreach ( $taxonomies as $taxonomy_key => $taxonomy_obj ) : ?>
									<label>
										<input
											type="checkbox"
											name="taxonomies[]"
											value="<?php echo esc_attr( $taxonomy_key ); ?>"
											<?php
											checked(
												$this->is_checked_array(
													$taxonomy_key,
													$settings['taxonomies'] ?? array()
												),
												true
											);
											?>
										>
										<?php echo esc_html( $taxonomy_obj->labels->name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="srk-settings-inline-checks">
							<span><?php esc_html_e( 'Process Post Statuses', 'seo-repair-kit' ); ?></span>
							<label>
								<input
									type="checkbox"
									name="post_statuses[]"
									value="publish"
									<?php
									checked(
										$this->is_checked_array(
											'publish',
											$settings['post_statuses'] ?? array()
										),
										true
									);
									?>
								>
								<?php esc_html_e( 'Publish', 'seo-repair-kit' ); ?>
							</label>

							<label>
								<input
									type="checkbox"
									name="post_statuses[]"
									value="draft"
									<?php
									checked(
										$this->is_checked_array(
											'draft',
											$settings['post_statuses'] ?? array()
										),
										true
									);
									?>
								>
								<?php esc_html_e( 'Draft', 'seo-repair-kit' ); ?>
							</label>

							<label>
								<input
									type="checkbox"
									name="post_statuses[]"
									value="pending"
									<?php
									checked(
										$this->is_checked_array(
											'pending',
											$settings['post_statuses'] ?? array()
										),
										true
									);
									?>
								>
								<?php esc_html_e( 'Pending', 'seo-repair-kit' ); ?>
							</label>
						</div>
					</div>
				</div>

				<div class="srk-settings-card">
					<div class="srk-settings-card-title">
						<span class="dashicons dashicons-clock"></span>
						<h3><?php esc_html_e( 'Link Controls & Timeline', 'seo-repair-kit' ); ?></h3>
					</div>

					<div class="srk-settings-body">
						<div class="srk-settings-two-col">

							<label>
								<span>
									<?php esc_html_e( 'Max Outbound Links / Post', 'seo-repair-kit' ); ?>
								</span>

								<input
									type="number"
									name="max_outbound_links"
									min="0"
									placeholder="<?php esc_attr_e( 'Unlimited', 'seo-repair-kit' ); ?>"
									value="<?php
										echo ! empty( $settings['max_outbound_links'] )
											? esc_attr( absint( $settings['max_outbound_links'] ) )
											: '';
									?>"
								>

								<small class="description">
									<?php esc_html_e(
										'Leave empty for no outbound link limit.',
										'seo-repair-kit'
									); ?>
								</small>
							</label>

							<label>
								<span>
									<?php esc_html_e( 'Max Inbound Links / Post', 'seo-repair-kit' ); ?>
								</span>

								<input
									type="number"
									name="max_inbound_links"
									min="0"
									placeholder="<?php esc_attr_e( 'Unlimited', 'seo-repair-kit' ); ?>"
									value="<?php
										echo ! empty( $settings['max_inbound_links'] )
											? esc_attr( absint( $settings['max_inbound_links'] ) )
											: '';
									?>"
								>

								<small class="description">
									<?php esc_html_e(
										'Leave empty for no inbound link limit.',
										'seo-repair-kit'
									); ?>
								</small>
							</label>

						</div>

						<label class="srk-settings-field">
							<span><?php esc_html_e( 'Suggestions Limit', 'seo-repair-kit' ); ?></span>
							<input type="range" name="suggestions_limit" min="1" max="50" value="<?php echo esc_attr( absint( $settings['suggestions_limit'] ) ); ?>">
							<div class="srk-range-labels"><span>1</span><span class="srk-range-current"><?php echo esc_html( absint( $settings['suggestions_limit'] ) ); ?> suggestions</span><span>50</span></div>
						</label>

						<div class="srk-settings-two-col">
							<label><span><?php esc_html_e( 'Posts Older Than', 'seo-repair-kit' ); ?></span><input type="date" name="target_older_than" value="<?php echo esc_attr( $settings['target_older_than'] ); ?>"></label>
							<label><span><?php esc_html_e( 'Posts Published After', 'seo-repair-kit' ); ?></span><input type="date" name="source_published_after" value="<?php echo esc_attr( $settings['source_published_after'] ); ?>"></label>
						</div>
					</div>
				</div>

				<div class="srk-settings-card">
					<div class="srk-settings-card-title">
						<span class="dashicons dashicons-admin-links"></span>
						<h3><?php esc_html_e( 'Matching Rules', 'seo-repair-kit' ); ?></h3>
					</div>

					<div class="srk-settings-body">
						<div class="srk-settings-toggle-row small"><strong><?php esc_html_e( 'Same Category Only', 'seo-repair-kit' ); ?></strong><label class="srk-switch"><input type="checkbox" name="same_category_only" <?php checked( ! empty( $settings['same_category_only'] ) ); ?>><span></span></label></div>
						<div class="srk-settings-toggle-row small"><strong><?php esc_html_e( 'Link to Orphaned Only', 'seo-repair-kit' ); ?></strong><label class="srk-switch"><input type="checkbox" name="link_orphaned_only" <?php checked( ! empty( $settings['link_orphaned_only'] ) ); ?>><span></span></label></div>
						<div class="srk-settings-toggle-row small"><strong><?php esc_html_e( 'Ignore Numbers', 'seo-repair-kit' ); ?></strong><label class="srk-switch"><input type="checkbox" name="ignore_numbers" <?php checked( ! empty( $settings['ignore_numbers'] ) ); ?>><span></span></label></div>

						<hr>

						<div class="srk-settings-source-list">
							<span><?php esc_html_e( 'Target Keyword Sources', 'seo-repair-kit' ); ?></span>
							<label>
								<input
									type="checkbox"
									name="keyword_sources[]"
									value="title"
									<?php
									checked(
										$this->is_checked_array(
											'title',
											$settings['keyword_sources'] ?? array()
										),
										true
									);
									?>
								>

								<strong>
									<?php esc_html_e( 'Post Title', 'seo-repair-kit' ); ?>
								</strong>

								<small>
									<?php esc_html_e( 'Prioritize anchors matching the title.', 'seo-repair-kit' ); ?>
								</small>
							</label>
							<label>
								<input
									type="checkbox"
									name="keyword_sources[]"
									value="slug"
									<?php
									checked(
										$this->is_checked_array(
											'slug',
											$settings['keyword_sources'] ?? array()
										),
										true
									);
									?>
								>

								<strong>
									<?php esc_html_e( 'URL Slug', 'seo-repair-kit' ); ?>
								</strong>

								<small>
									<?php esc_html_e( 'Extract keywords from permalinks.', 'seo-repair-kit' ); ?>
								</small>
							</label>
							<label>
								<input
									type="checkbox"
									name="keyword_sources[]"
									value="taxonomy"
									<?php
									checked(
										$this->is_checked_array(
											'taxonomy',
											$settings['keyword_sources'] ?? array()
										),
										true
									);
									?>
								>

								<strong>
									<?php esc_html_e( 'Taxonomies', 'seo-repair-kit' ); ?>
								</strong>

								<small>
									<?php esc_html_e( 'Use categories, tags, and selected taxonomy terms.', 'seo-repair-kit' ); ?>
								</small>
							</label>
							<label>
								<input
									type="checkbox"
									name="keyword_sources[]"
									value="custom"
									<?php
									checked(
										$this->is_checked_array(
											'custom',
											$settings['keyword_sources'] ?? array()
										),
										true
									);
									?>
								>

								<strong>
									<?php esc_html_e( 'Custom Keywords', 'seo-repair-kit' ); ?>
								</strong>

								<small>
									<?php esc_html_e( 'Use custom target keywords.', 'seo-repair-kit' ); ?>
								</small>
							</label>
						</div>
					</div>
				</div>

				<div class="srk-settings-card">
					<div class="srk-settings-card-title">
						<span class="dashicons dashicons-editor-expand"></span>
						<h3><?php esc_html_e( 'Content Processing', 'seo-repair-kit' ); ?></h3>
					</div>

					<div class="srk-settings-body">
						<div class="srk-settings-two-col">
							<label><span><?php esc_html_e( 'Skip N Sentences', 'seo-repair-kit' ); ?></span><input type="number" name="skip_sentences" value="<?php echo esc_attr( absint( $settings['skip_sentences'] ) ); ?>"></label>
							<label><span><?php esc_html_e( 'Skip N Paragraphs', 'seo-repair-kit' ); ?></span><input type="number" name="skip_paragraphs" value="<?php echo esc_attr( absint( $settings['skip_paragraphs'] ) ); ?>"></label>
							<label>
								<span>
									<?php esc_html_e(
										'Min Anchor Words',
										'seo-repair-kit'
									); ?>
								</span>

								<input
									type="number"
									name="min_anchor_words"
									min="2"
									max="9"
									value="<?php echo esc_attr(
										absint(
											$settings['min_anchor_words']
										)
									); ?>"
								>
							</label>

							<label>
								<span>
									<?php esc_html_e(
										'Max Anchor Words',
										'seo-repair-kit'
									); ?>
								</span>

								<input
									type="number"
									name="max_anchor_words"
									min="2"
									max="9"
									value="<?php echo esc_attr(
										absint(
											$settings['max_anchor_words']
										)
									); ?>"
								>
							</label>
							<label>
								<span><?php esc_html_e( 'Max Keywords / Post', 'seo-repair-kit' ); ?></span>
								<input
									type="number"
									name="max_keywords_per_post"
									min="5"
									max="100"
									value="<?php echo esc_attr( absint( $settings['max_keywords_per_post'] ?? 40 ) ); ?>"
								>
							</label>
						</div>
					</div>
				</div>

			</div><!-- /.srk-settings-grid -->

		</div><!-- /.srk-il-settings-tab-general -->

		<div class="srk-il-settings-tab-content srk-il-settings-tab-ai">

			<div class="srk-settings-grid">

					<div class="srk-settings-card srk-settings-ai-card">

						<div class="srk-settings-card-title">
							<span class="dashicons dashicons-superhero-alt"></span>

							<h3>
								<?php esc_html_e(
									'AI Engine',
									'seo-repair-kit'
								); ?>
							</h3>
						</div>

						<div class="srk-settings-body">

							<div class="srk-settings-toggle-row">
								<div>
									<strong>
										<?php esc_html_e(
											'Enable AI Engine',
											'seo-repair-kit'
										); ?>
									</strong>

									<p>
										<?php esc_html_e(
											'Use AI to find internal-link opportunities based on the meaning and context of your content.',
											'seo-repair-kit'
										); ?>
									</p>
								</div>

								<label class="srk-switch">
									<input
										type="checkbox"
										name="ai_enabled"
										<?php checked(
											! empty(
												$settings['ai_enabled']
											)
										); ?>
									>
									<span></span>
								</label>
							</div>
							<div class="srk-settings-ai-config-grid">

								<div class="srk-settings-ai-field">

									<label for="srk-il-ai-batch-size">
										<?php esc_html_e(
											'AI Batch Size',
											'seo-repair-kit'
										); ?>

										<span class="srk-settings-ai-field-range">
											<?php esc_html_e(
												'(5–10)',
												'seo-repair-kit'
											); ?>
										</span>
									</label>

									<input
										id="srk-il-ai-batch-size"
										type="number"
										name="ai_batch_size"
										min="5"
										max="10"
										value="<?php echo esc_attr(
											absint(
												$settings['ai_batch_size']
													?? 5
											)
										); ?>"
									>

									<small class="description">
										<?php esc_html_e(
											'Number of content items processed in each background AI batch.',
											'seo-repair-kit'
										); ?>
									</small>

								</div>


								<div class="srk-settings-ai-field">

									<label for="srk-il-ai-api-key">
										<?php esc_html_e(
											'AI API Key',
											'seo-repair-kit'
										); ?>
									</label>

									<input
										id="srk-il-ai-api-key"
										type="password"
										name="openrouter_api_key"
										value="<?php echo esc_attr(
											class_exists(
												'SRK_Internal_Linking_Settings'
											)
												? SRK_Internal_Linking_Settings::mask_api_key(
													$settings['openrouter_api_key']
														?? ''
												)
												: ''
										); ?>"
										autocomplete="off"
										placeholder="<?php esc_attr_e(
											'Enter your AI provider API key',
											'seo-repair-kit'
										); ?>"
									>

									<small class="description">
										<?php esc_html_e(
											'Enter your AI API key, then click Save Changes to save it.',
											'seo-repair-kit'
										); ?>
									</small>

								</div>

							</div>


							<div class="srk-settings-ai-actions">

								<button
									type="button"
									class="button srk-il-ai-test-key"
								>
									<?php esc_html_e(
										'Test Connection',
										'seo-repair-kit'
									); ?>
								</button>

								<button
									type="button"
									class="button button-primary srk-il-ai-start-pipeline"
								>
									<?php esc_html_e(
										'Run AI Pipeline Now',
										'seo-repair-kit'
									); ?>
								</button>

							</div>
							<div
								class="srk-il-ai-status-text"
								role="status"
								aria-live="polite"
							></div>

							<?php
							$ai_status =
								class_exists(
									'SRK_Internal_Linking_Service'
								)
									? SRK_Internal_Linking_Service::get_ai_status()
									: array();

							$ai_analyzed = absint(
								$ai_status['embeddings_ready']
									?? 0
							);

							$ai_waiting = absint(
								$ai_status['embeddings_pending']
									?? 0
							);

							$ai_opportunities = absint(
								$ai_status['ai_opportunities']
									?? 0
							);

							$ai_opportunity_processed = absint(
								$ai_status['opportunity_processed']
									?? 0
							);

							$ai_opportunity_total = absint(
								$ai_status['opportunity_total']
									?? 0
							);

							$ai_opportunity_percent = absint(
								$ai_status['opportunity_percent']
									?? 0
							);

							$ai_total =
								$ai_analyzed +
								$ai_waiting;

							$ai_percent =
								$ai_total > 0
									? min(
										100,
										absint(
											round(
												(
													$ai_analyzed /
													$ai_total
												) * 100
											)
										)
									)
									: 0;

							$ai_pipeline_active =
								! empty(
									$ai_status['pipeline_active']
								);
							?>


							<div
								id="srk-il-ai-monitor"
								class="srk-settings-ai-monitor<?php echo esc_attr( $ai_pipeline_active ? ' is-running' : '' ); ?>"
							>

								<div class="srk-settings-ai-monitor-head">

									<div>
										<span class="srk-settings-ai-monitor-eyebrow">
											<?php esc_html_e(
												'AI Analysis Status',
												'seo-repair-kit'
											); ?>
										</span>

										<h4>
											<?php esc_html_e(
												'Content Intelligence Overview',
												'seo-repair-kit'
											); ?>
										</h4>
									</div>


									<div class="srk-settings-ai-live-badge">
										<span class="srk-settings-ai-live-dot"></span>

										<strong id="srk-il-ai-live-state">
											<?php
											if ( $ai_pipeline_active ) {

												esc_html_e(
													'AI processing is running',
													'seo-repair-kit'
												);

											} elseif ( $ai_waiting > 0 ) {

												esc_html_e(
													'Waiting for AI analysis',
													'seo-repair-kit'
												);

											} elseif ( $ai_total > 0 ) {

												esc_html_e(
													'AI analysis is up to date',
													'seo-repair-kit'
												);

											} else {

												esc_html_e(
													'Ready for AI analysis',
													'seo-repair-kit'
												);

											}
											?>
										</strong>
									</div>

								</div>


								<div class="srk-settings-ai-metrics">

									<div class="srk-settings-ai-metric">

										<div class="srk-settings-ai-metric-icon">
											<span class="dashicons dashicons-yes-alt"></span>
										</div>

										<div class="srk-settings-ai-metric-content">

											<strong
												id="srk-il-ai-analyzed"
												class="srk-settings-ai-metric-value"
											>
												<?php echo esc_html(
													number_format_i18n(
														$ai_analyzed
													)
												); ?>
											</strong>

											<span class="srk-settings-ai-metric-label">
												<?php esc_html_e(
													'Content Analyzed',
													'seo-repair-kit'
												); ?>
											</span>

											<small>
												<?php esc_html_e(
													'Content with a ready AI embedding.',
													'seo-repair-kit'
												); ?>
											</small>

										</div>

									</div>


									<div class="srk-settings-ai-metric">

										<div class="srk-settings-ai-metric-icon">
											<span class="dashicons dashicons-clock"></span>
										</div>

										<div class="srk-settings-ai-metric-content">

											<strong
												id="srk-il-ai-waiting"
												class="srk-settings-ai-metric-value"
											>
												<?php echo esc_html(
													number_format_i18n(
														$ai_waiting
													)
												); ?>
											</strong>

											<span class="srk-settings-ai-metric-label">
												<?php esc_html_e(
													'Waiting to Analyze',
													'seo-repair-kit'
												); ?>
											</span>

											<small>
												<?php esc_html_e(
													'Content still waiting for AI processing.',
													'seo-repair-kit'
												); ?>
											</small>

										</div>

									</div>


									<div class="srk-settings-ai-metric">

										<div class="srk-settings-ai-metric-icon">
											<span class="dashicons dashicons-admin-links"></span>
										</div>

										<div class="srk-settings-ai-metric-content">

											<strong
												id="srk-il-ai-opportunities"
												class="srk-settings-ai-metric-value"
											>
												<?php echo esc_html(
													number_format_i18n(
														$ai_opportunities
													)
												); ?>
											</strong>

											<span class="srk-settings-ai-metric-label">
												<?php esc_html_e(
													'AI Link Opportunities',
													'seo-repair-kit'
												); ?>
											</span>

											<small>
												<?php esc_html_e(
													'Canonical link opportunities selected by AI.',
													'seo-repair-kit'
												); ?>
											</small>

										</div>

									</div>

								</div>


								<div class="srk-settings-ai-progress">

									<div class="srk-settings-ai-progress-head">

										<span>
											<?php esc_html_e(
												'AI Content Analysis',
												'seo-repair-kit'
											); ?>
										</span>

										<strong id="srk-il-ai-progress-percent">
											<?php
											echo esc_html(
												$ai_percent . '%'
											);
											?>
										</strong>

									</div>


									<div
										id="srk-il-ai-progress-track"
										class="srk-settings-ai-progress-track"
										role="progressbar"
										aria-label="<?php esc_attr_e(
											'AI content analysis progress',
											'seo-repair-kit'
										); ?>"
										aria-valuemin="0"
										aria-valuemax="100"
										aria-valuenow="<?php echo esc_attr(
											$ai_percent
										); ?>"
									>
										<span
											id="srk-il-ai-progress-bar"
											style="width: <?php echo esc_attr(
												$ai_percent
											); ?>%;"
										></span>
									</div>


									<div class="srk-settings-ai-progress-meta">

										<span id="srk-il-ai-progress-summary">
											<?php
											printf(
												esc_html__(
													'%1$s of %2$s content items analyzed',
													'seo-repair-kit'
												),
												esc_html(
													number_format_i18n(
														$ai_analyzed
													)
												),
												esc_html(
													number_format_i18n(
														$ai_total
													)
												)
											);
											?>
										</span>

									</div>

								</div>
								<div class="srk-settings-ai-progress">

									<div class="srk-settings-ai-progress-head">

										<span>
											<?php esc_html_e(
												'AI Link Opportunity Discovery',
												'seo-repair-kit'
											); ?>
										</span>

										<strong
											id="srk-il-ai-opportunity-progress-percent"
										>
											<?php
											echo esc_html(
												$ai_opportunity_percent . '%'
											);
											?>
										</strong>

									</div>


									<div
										id="srk-il-ai-opportunity-progress-track"
										class="srk-settings-ai-progress-track"
										role="progressbar"
										aria-label="<?php esc_attr_e(
											'AI link opportunity discovery progress',
											'seo-repair-kit'
										); ?>"
										aria-valuemin="0"
										aria-valuemax="100"
										aria-valuenow="<?php echo esc_attr(
											$ai_opportunity_percent
										); ?>"
									>
										<span
											id="srk-il-ai-opportunity-progress-bar"
											style="width: <?php echo esc_attr(
												$ai_opportunity_percent
											); ?>%;"
										></span>
									</div>


									<div class="srk-settings-ai-progress-meta">

										<span
											id="srk-il-ai-opportunity-progress-summary"
										>
											<?php
											printf(
												/* translators: 1: processed posts, 2: total posts, 3: AI opportunities */
												esc_html__(
													'%1$s of %2$s analyzed content items checked — %3$s opportunities found',
													'seo-repair-kit'
												),
												esc_html(
													number_format_i18n(
														$ai_opportunity_processed
													)
												),
												esc_html(
													number_format_i18n(
														$ai_opportunity_total
													)
												),
												esc_html(
													number_format_i18n(
														$ai_opportunities
													)
												)
											);
											?>
										</span>

									</div>

								</div>

							</div>

						</div>

					</div>
			</div>

		</div><!-- /.srk-il-settings-tab-ai -->

		<div class="srk-settings-sticky-bar">
				<div class="srk-settings-status"><span></span><?php esc_html_e( 'System active', 'seo-repair-kit' ); ?><em class="srk-settings-last-saved"><?php esc_html_e( 'Ready to save', 'seo-repair-kit' ); ?></em></div>
				<div class="srk-settings-actions">
					<button type="button" class="button srk-il-settings-reset"><?php esc_html_e( 'Reset', 'seo-repair-kit' ); ?></button>
					<button type="button" class="button button-primary srk-il-settings-save"><?php esc_html_e( 'Save Changes', 'seo-repair-kit' ); ?></button>
				</div>
		</div>

		<?php
	}
}