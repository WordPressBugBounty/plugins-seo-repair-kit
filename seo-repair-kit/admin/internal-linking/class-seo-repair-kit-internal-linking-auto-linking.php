<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Auto Linking Admin Screen
 */
class SeoRepairKit_InternalLinking_Auto_Linking {

	/**
	 * Render Auto Linking page.
	 */
	public function render() {

		// Get summary.
		$summary = class_exists( 'SRK_Internal_Linking_DB' )
			? SRK_Internal_Linking_DB::get_auto_linking_summary()
			: array();

		// Get settings.
		$settings = class_exists( 'SRK_Internal_Linking_DB' )
			? SRK_Internal_Linking_DB::get_auto_linking_settings()
			: array();

		// Public post types.
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		// Default settings.
		$settings = wp_parse_args(
			$settings,
			array(
				'default_post_types'      => array( 'post', 'page' ),
				'default_max_links_post'  => 1,
				'default_max_keyword'     => 1,
				'manual_review'           => 1,
				'case_sensitive'          => 0,
				'allow_duplicate_target'  => 0,
				'require_target_published'=> 1,
				'internal_only'           => 1,
			)
		);

		// Default statistics.
		$summary = wp_parse_args(
			$summary,
			array(
				'total_rules'         => 0,
				'active_rules'        => 0,
				'active_links'        => 0,
				'total_links_created' => 0,
				'links_created'       => 0,
				'removed_links'       => 0,
			)
		);
		?>

		<div class="srk-il-page srk-il-auto-linking">

			<!-- =====================================================
				Page Header
			====================================================== -->

			<div class="srk-il-page-title srk-auto-title-row">

				<div>
					<h2><?php esc_html_e( 'Auto Linking Rules', 'seo-repair-kit' ); ?></h2>

					<p>
						<?php esc_html_e(
							'Create keyword-to-URL rules, scan only matching content, apply selected links, and remove auto links safely.',
							'seo-repair-kit'
						); ?>
					</p>
				</div>

				<div class="srk-il-title-actions">
					<button
						type="button"
						class="button button-primary srk-auto-open-settings"
					>
						<?php esc_html_e( 'Rule Settings', 'seo-repair-kit' ); ?>
					</button>
				</div>

			</div>

			<!-- =====================================================
				Statistics
			====================================================== -->

			<div class="srk-il-stats-grid srk-auto-stats-grid-simple">

				<?php $this->stat( 'Total Rules', $summary['total_rules'], 'srk-auto-stat-muted' ); ?>

				<?php $this->stat( 'Active Rules', $summary['active_rules'], 'srk-auto-stat-blue' ); ?>

				<?php
				$this->stat(
					__( 'Active Links', 'seo-repair-kit' ),
					$summary['active_links'],
					'srk-auto-stat-blue'
				);
				?>

				<?php $this->stat( 'Removed Links', $summary['removed_links'], 'srk-auto-stat-muted' ); ?>

			</div>

			<!-- =====================================================
				Main Layout
			====================================================== -->

			<div class="srk-auto-layout">

				<!-- ==========================================
					Create Rule Form
				========================================== -->

				<form
					class="srk-auto-form-card"
					id="srk-auto-create-rule-form">

					<div class="srk-auto-form-title">

						<span class="dashicons dashicons-admin-links"></span>

						<h3><?php esc_html_e( 'Create Rule', 'seo-repair-kit' ); ?></h3>

					</div>

					<div class="srk-auto-form-body">

						<!-- Keyword -->

						<label>

							<span><?php esc_html_e( 'Keyword / Anchor Text', 'seo-repair-kit' ); ?></span>

							<input
								type="text"
								name="keyword"
								required
								placeholder="<?php esc_attr_e( 'e.g. SEO audit', 'seo-repair-kit' ); ?>">

						</label>

						<!-- Target URL -->

						<label>

							<span><?php esc_html_e( 'Destination URL', 'seo-repair-kit' ); ?></span>

							<input
								type="url"
								name="target_url"
								required
								placeholder="<?php echo esc_url( home_url( '/target-page/' ) ); ?>">

						</label>

						<!-- Selection Mode -->

						<div>

							<span class="srk-auto-field-label">
								<?php esc_html_e( 'Selection Mode', 'seo-repair-kit' ); ?>
							</span>

							<input
								type="hidden"
								name="selection_mode"
								value="manual">

							<div class="srk-auto-segment">

								<button
									type="button"
									class="is-active"
									data-srk-auto-mode="manual">

									<?php esc_html_e( 'Manual', 'seo-repair-kit' ); ?>

								</button>

								<button
									type="button"
									data-srk-auto-mode="auto">

									<?php esc_html_e( 'Auto', 'seo-repair-kit' ); ?>

								</button>

							</div>

						</div>

						<!-- Rule Options -->

						<div class="srk-auto-checks-compact">

							<label>

								<input
									type="checkbox"
									name="case_sensitive"
									value="1"
									<?php checked( ! empty( $settings['case_sensitive'] ) ); ?>>

								<?php esc_html_e( 'Case sensitive', 'seo-repair-kit' ); ?>

							</label>

						</div>

						<button type="button" class="button srk-auto-open-settings srk-auto-rule-settings-btn">
							<?php esc_html_e( 'Rule Settings', 'seo-repair-kit' ); ?>
						</button>

						<!-- Submit -->

						<button
							type="submit"
							class="button button-primary srk-auto-create-rule">

							<?php esc_html_e( 'Create & Scan Rule', 'seo-repair-kit' ); ?>

						</button>

					</div>

				</form>

				<!-- ==========================================
					Right Column
				========================================== -->

				<div class="srk-auto-main-column">

					<!-- Rules Table -->

					<div class="srk-il-table-card srk-auto-rules-card">

						<div class="srk-auto-table-header">

							<h3><?php esc_html_e( 'Rules', 'seo-repair-kit' ); ?></h3>

							<span id="srk-auto-rules-count"></span>

						</div>

						<div class="srk-auto-table-scroll">

							<table class="srk-il-data-table srk-il-auto-table">

								<thead>

									<tr>

										<th><?php esc_html_e( 'Keyword', 'seo-repair-kit' ); ?></th>

										<th><?php esc_html_e( 'Target URL', 'seo-repair-kit' ); ?></th>

										<th><?php esc_html_e( 'Links', 'seo-repair-kit' ); ?></th>

										<th><?php esc_html_e( 'Status', 'seo-repair-kit' ); ?></th>

										<th><?php esc_html_e( 'Actions', 'seo-repair-kit' ); ?></th>

									</tr>

								</thead>

								<tbody id="srk-auto-rules-body">

									<tr>

										<td colspan="5" class="srk-auto-preview-empty">

											<?php esc_html_e( 'Loading rules...', 'seo-repair-kit' ); ?>

										</td>

									</tr>

								</tbody>

							</table>

						</div>

					</div>

					<!-- Content Matches -->

					<div class="srk-il-table-card srk-auto-preview-card">

						<div class="srk-auto-table-header">

							<h3><?php esc_html_e( 'Content Matches Found', 'seo-repair-kit' ); ?></h3>

							<div class="srk-auto-preview-actions">

								<button
									type="button"
									class="button button-primary srk-auto-apply-selected"
									disabled>

									<?php esc_html_e( 'Apply Selected', 'seo-repair-kit' ); ?>

								</button>

								<button
									type="button"
									class="button srk-auto-remove-all-rule"
									disabled>

									<?php esc_html_e( 'Remove Rule Links', 'seo-repair-kit' ); ?>

								</button>

							</div>

						</div>

						<div id="srk-auto-preview-body">

							<div class="srk-auto-preview-empty">

								<?php esc_html_e(
									'Create or scan a rule to see only posts containing the keyword.',
									'seo-repair-kit'
								); ?>

							</div>

						</div>

					</div>

				</div>

			</div>

			<?php $this->settings_modal( $settings, $post_types ); ?>

			<div class="srk-auto-delete-modal" aria-hidden="true">
				<div class="srk-auto-delete-backdrop"></div>

				<div class="srk-auto-delete-dialog">
					<h3><?php esc_html_e( 'You are about to delete an Autolinking Rule', 'seo-repair-kit' ); ?></h3>

					<p>
						<?php esc_html_e( 'Do you want to remove the rule, but leave the links in the content?', 'seo-repair-kit' ); ?>
					</p>

					<p>
						<?php esc_html_e( 'Or do you want to remove the links too?', 'seo-repair-kit' ); ?>
					</p>

					<div class="srk-auto-delete-actions">
						<button type="button" class="button button-primary srk-auto-delete-with-links">
							<?php esc_html_e( 'Delete Rule And Links', 'seo-repair-kit' ); ?>
						</button>

						<button type="button" class="button srk-auto-delete-rule-only">
							<?php esc_html_e( 'Just Delete Rule', 'seo-repair-kit' ); ?>
						</button>

						<button type="button" class="button srk-auto-delete-cancel">
							<?php esc_html_e( 'Cancel', 'seo-repair-kit' ); ?>
						</button>
					</div>
				</div>
			</div>

		</div>

		<?php
	}

	/**
	 * Render statistics card.
	 */
	private function stat( $label, $value, $class ) {

		echo '<div class="srk-il-stat-card ' . esc_attr( $class ) . '">';
		echo '<p>' . esc_html( $label ) . '</p>';
		echo '<span>' . esc_html( absint( $value ) ) . '</span>';
		echo '</div>';
	}

	/**
	 * Render rule settings modal.
	 */
	private function settings_modal( $settings, $post_types ) {
		$categories = get_categories(
			array(
				'hide_empty' => false,
			)
		);

		$tags = get_tags(
			array(
				'hide_empty' => false,
			)
		);
		?>

		<form
			class="srk-auto-modal"
			id="srk-auto-settings-form"
			aria-hidden="true">

			<div class="srk-auto-modal-backdrop"></div>

			<div class="srk-auto-modal-dialog">

				<div class="srk-auto-modal-header">

					<div class="srk-auto-modal-title">
						<span class="dashicons dashicons-admin-generic"></span>

						<div>
							<h3><?php esc_html_e( 'Rule Settings', 'seo-repair-kit' ); ?></h3>
							<p><?php esc_html_e( 'Configure advanced options for this auto-link rule.', 'seo-repair-kit' ); ?></p>
						</div>
					</div>

					<button type="button" class="button-link srk-auto-close-settings">
						<span class="dashicons dashicons-no-alt"></span>
					</button>

				</div>

				<div class="srk-auto-modal-body">

					<div class="srk-auto-settings-grid">

						<label>
							<span><?php esc_html_e( 'Max Links Per Post', 'seo-repair-kit' ); ?></span>
							<input
								type="number"
								name="max_links_per_post"
								form="srk-auto-create-rule-form"
								value="3"
								min="1">

							<small>
								<?php esc_html_e( 'Limits the number of automatic links inserted into one source post.', 'seo-repair-kit' ); ?>
							</small>
						</label>

						<label>
							<span><?php esc_html_e( 'Max Links Per Keyword', 'seo-repair-kit' ); ?></span>
							<input
								type="number"
								name="max_links_per_keyword"
								form="srk-auto-create-rule-form"
								value="1"
								min="1">
						</label>

						<label>
							<span><?php esc_html_e( 'Default Link Priority', 'seo-repair-kit' ); ?></span>
							<input
								type="number"
								name="priority"
								form="srk-auto-create-rule-form"
								value="10"
								min="0">

							<small>
								<?php esc_html_e( 'Higher priority links are inserted first when multiple keywords match the same sentence.', 'seo-repair-kit' ); ?>
							</small>
						</label>

						<label>
							<span><?php esc_html_e( 'Only add links to posts published after the given date', 'seo-repair-kit' ); ?></span>
							<input
								type="date"
								name="apply_after_date"
								form="srk-auto-create-rule-form">
						</label>

					</div>

					<div class="srk-auto-post-types-card">

						<span class="srk-auto-field-label">
							<?php esc_html_e( 'Target Post Types', 'seo-repair-kit' ); ?>
						</span>

						<div class="srk-auto-post-types">

							<?php foreach ( $post_types as $type ) : ?>

								<label>
									<input
										type="checkbox"
										name="post_types[]"
										form="srk-auto-create-rule-form"
										value="<?php echo esc_attr( $type->name ); ?>"
										<?php checked( in_array( $type->name, array( 'post', 'page' ), true ) ); ?>>

									<?php echo esc_html( $type->labels->singular_name ); ?>
								</label>

							<?php endforeach; ?>

						</div>

					</div>

					<div class="srk-auto-check-list srk-auto-check-list-spaced">

						<label>
							<span><?php esc_html_e( 'Add link if post already has this link?', 'seo-repair-kit' ); ?></span>

							<input
								type="checkbox"
								name="allow_duplicate_target"
								form="srk-auto-create-rule-form"
								value="1">
						</label>

						<label>
							<span><?php esc_html_e( 'Only create internal links when target is published?', 'seo-repair-kit' ); ?></span>

							<input
								type="checkbox"
								name="require_target_published"
								form="srk-auto-create-rule-form"
								value="1"
								checked>
						</label>

					</div>

					<div class="srk-auto-post-types-card">

						<span class="srk-auto-field-label">
							<?php esc_html_e( 'Restrict autolinks to specific categories', 'seo-repair-kit' ); ?>
						</span>

						<div class="srk-auto-post-types">

							<?php foreach ( $categories as $category ) : ?>

								<label>
									<input
										type="checkbox"
										name="categories[]"
										form="srk-auto-create-rule-form"
										value="<?php echo esc_attr( $category->term_id ); ?>">

									<?php echo esc_html( $category->name ); ?>
								</label>

							<?php endforeach; ?>

						</div>

					</div>

					<div class="srk-auto-post-types-card">

						<span class="srk-auto-field-label">
							<?php esc_html_e( 'Restrict autolinks to specific tags', 'seo-repair-kit' ); ?>
						</span>

						<div class="srk-auto-post-types">

							<?php foreach ( $tags as $tag ) : ?>

								<label>
									<input
										type="checkbox"
										name="tags[]"
										form="srk-auto-create-rule-form"
										value="<?php echo esc_attr( $tag->term_id ); ?>">

									<?php echo esc_html( $tag->name ); ?>
								</label>

							<?php endforeach; ?>

						</div>

					</div>

				</div>

				<div class="srk-auto-modal-footer">

					<button type="button" class="button srk-auto-close-settings">
						<?php esc_html_e( 'Close', 'seo-repair-kit' ); ?>
					</button>

				</div>

			</div>

		</form>

		<?php
	}
}