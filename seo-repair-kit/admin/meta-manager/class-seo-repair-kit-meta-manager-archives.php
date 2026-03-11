<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archives Meta Manager
 *
 * @package SEO_Repair_Kit
 * @since   2.1.3
 * @version 2.1.3
 */
class SRK_Meta_Manager_Archives {

	/**
	 * Archive types
	 *
	 * @var array
	 */
	private $archive_types;

	/**
	 * Available template tags
	 *
	 * @var array
	 */
	private $template_tags;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_archive_types();
		$this->init_template_tags();
	}

	/**
	 * Initialize archive types
	 */
	private function init_archive_types() {
		$this->archive_types = array(
			'author' => array(
				'label'       => __( 'Author Archives', 'seo-repair-kit' ),
				'description' => __( 'Control SEO settings for author archive pages.', 'seo-repair-kit' ),
				'icon'        => 'admin-users',
				'page_label'  => __( 'Author Archives', 'seo-repair-kit' ),
			),
			'date'   => array(
				'label'       => __( 'Date Archives', 'seo-repair-kit' ),
				'description' => __( 'Control SEO settings for date-based archive pages.', 'seo-repair-kit' ),
				'icon'        => 'calendar-alt',
				'page_label'  => __( 'Date Archives', 'seo-repair-kit' ),
			),
			'search' => array(
				'label'       => __( 'Search Results', 'seo-repair-kit' ),
				'description' => __( 'Control SEO settings for search result pages.', 'seo-repair-kit' ),
				'icon'        => 'search',
				'page_label'  => __( 'Search Page', 'seo-repair-kit' ),
			),
		);
	}

	/**
	 * Initialize template tags
	 */
	private function init_template_tags() {
		$this->template_tags = array(
			'title'       => array(
				'%archive_title%'      => array(
					'label' => __( 'Archive Title', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the current archive title.', 'seo-repair-kit' ),
				),
				'%author%'             => array(
					'label' => __( 'Author Name', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the name of the author.', 'seo-repair-kit' ),
				),
				'%search%'             => array(
					'label' => __( 'Search Term', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the searched keyword.', 'seo-repair-kit' ),
				),
				'%site_title%'         => array(
					'label' => __( 'Site Title', 'seo-repair-kit' ),
					'desc'  => __( 'Displays your website title set in Settings → General.', 'seo-repair-kit' ),
				),
				'%sep%'                => array(
					'label' => __( 'Separator', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the selected title separator.', 'seo-repair-kit' ),
				),
				'%tags%'               => array(
					'label' => __( 'Post Tags', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the post tags assigned to the post.', 'seo-repair-kit' ),
				),
				'%author_first_name%'  => array(
					'label' => __( 'Author First Name', 'seo-repair-kit' ),
					'desc'  => __( 'The first name of the post author.', 'seo-repair-kit' ),
				),
				'%author_last_name%'   => array(
					'label' => __( 'Author Last Name', 'seo-repair-kit' ),
					'desc'  => __( 'The last name of the post author.', 'seo-repair-kit' ),
				),
				'%month%'              => array(
					'label' => __( 'Current Month', 'seo-repair-kit' ),
					'desc'  => __( 'The current month, localized.', 'seo-repair-kit' ),
				),
				'%year%'               => array(
					'label' => __( 'Current Year', 'seo-repair-kit' ),
					'desc'  => __( 'The current year, localized.', 'seo-repair-kit' ),
				),
			),
			'description' => array(
				'%archive_description%' => array(
					'label' => __( 'Archive Description', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the archive description.', 'seo-repair-kit' ),
				),
				'%author_bio%'          => array(
					'label' => __( 'Author Biography', 'seo-repair-kit' ),
					'desc'  => __( 'Displays the author biography.', 'seo-repair-kit' ),
				),
				'%sitedesc%'            => array(
					'label' => __( 'Site Description', 'seo-repair-kit' ),
					'desc'  => __( 'Displays your website tagline.', 'seo-repair-kit' ),
				),
			),
		);
	}

	/**
	 * Get relevant tags for archive type
	 *
	 * @param string $archive_type Archive type.
	 * @param string $type         Tag type (title or description).
	 * @return array Relevant tags.
	 */
	private function get_relevant_tags( $archive_type, $type = 'title' ) {
		$relevant = array();

		if ( 'title' === $type ) {
			switch ( $archive_type ) {
				case 'author':
					$relevant = array(
						'%author%'      => __( 'Author Name', 'seo-repair-kit' ),
						'%sep%'         => __( 'Separator', 'seo-repair-kit' ),
						'%site_title%'  => __( 'Site Title', 'seo-repair-kit' ),
					);
					break;

				case 'date':
					$relevant = array(
						'%archive_title%' => __( 'Archive Date', 'seo-repair-kit' ),
						'%sep%'           => __( 'Separator', 'seo-repair-kit' ),
						'%site_title%'    => __( 'Site Title', 'seo-repair-kit' ),
					);
					break;

				case 'search':
					$relevant = array(
						'%search%'     => __( 'Search Term', 'seo-repair-kit' ),
						'%sep%'        => __( 'Separator', 'seo-repair-kit' ),
						'%site_title%' => __( 'Site Title', 'seo-repair-kit' ),
					);
					break;
			}
		} else {
			switch ( $archive_type ) {
				case 'author':
					$relevant = array(
						'%author_bio%'          => __( 'Author Biography', 'seo-repair-kit' ),
						'%archive_description%' => __( 'Archive Description', 'seo-repair-kit' ),
						'%sitedesc%'            => __( 'Site Description', 'seo-repair-kit' ),
					);
					break;

				case 'date':
				case 'search':
					$relevant = array(
						'%archive_description%' => __( 'Archive Description', 'seo-repair-kit' ),
						'%sitedesc%'            => __( 'Site Description', 'seo-repair-kit' ),
					);
					break;
			}
		}

		return $relevant;
	}

	/**
	 * Generate Google-style preview HTML
	 *
	 * @param string $title        Title template.
	 * @param string $description  Description template.
	 * @param string $archive_type Archive type.
	 * @param string $separator    Title separator.
	 * @return string HTML preview.
	 */
	private function generate_google_preview( $title, $description, $archive_type, $separator ) {
		$site_url        = home_url();
		$preview_title   = $this->generate_preview( $title, $archive_type, 'title', $separator );
		$preview_desc    = $this->generate_preview( $description, $archive_type, 'description', $separator );

		if ( empty( $preview_title ) ) {
			$preview_title = get_bloginfo( 'name' );
		}

		if ( empty( $preview_desc ) ) {
			$preview_desc = get_bloginfo( 'description' );
		}

		$html  = '<div class="srk-google-preview">';
		$html .= '<div class="srk-preview-url">' . esc_html( $site_url ) . '</div>';
		$html .= '<div class="srk-preview-title">' . esc_html( $preview_title ) . '</div>';

		if ( ! empty( $preview_desc ) ) {
			$html .= '<div class="srk-preview-description">' . esc_html( $preview_desc ) . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Get default archive settings
	 *
	 * @param string $archive_type Archive type key.
	 * @return array Default settings.
	 */
	private function get_default_archive_settings( $archive_type ) {
		$defaults = array(
			'author' => array(
				'enable'            => '0',
				'title'             => '%author% %sep% %site_title%',
				'description'       => '%author_bio%',
				'noindex'           => '1',
				'nofollow'          => '0',
				'noarchive'         => '0',
				'nosnippet'         => '0',
				'noimageindex'      => '0',
				'max_snippet'       => '-1',
				'max_image_preview' => 'large',
				'max_video_preview' => '-1',
				'use_default'       => '0',
			),
			'date'   => array(
				'enable'            => '0',
				'title'             => '%archive_title% %sep% %site_title%',
				'description'       => '%archive_description%',
				'noindex'           => '1',
				'nofollow'          => '0',
				'noarchive'         => '0',
				'nosnippet'         => '0',
				'noimageindex'      => '0',
				'max_snippet'       => '-1',
				'max_image_preview' => 'large',
				'max_video_preview' => '-1',
				'use_default'       => '0',
			),
			'search' => array(
				'enable'            => '0',
				'title'             => '%search% %sep% %site_title%',
				'description'       => '%archive_description%',
				'noindex'           => '1',
				'nofollow'          => '0',
				'noarchive'         => '0',
				'nosnippet'         => '0',
				'noimageindex'      => '0',
				'max_snippet'       => '-1',
				'max_image_preview' => 'large',
				'max_video_preview' => '-1',
				'use_default'       => '0',
			),
		);

		return $defaults[ $archive_type ] ?? array();
	}

	/**
	 * Get robots meta default values
	 *
	 * @return array Default robots meta values.
	 */
	private function get_default_robots_meta() {
		return array(
			'index'             => '1',
			'noindex'           => '0',
			'follow'            => '1',
			'nofollow'          => '0',
			'noarchive'         => '0',
			'nosnippet'         => '0',
			'noimageindex'      => '0',
			'max_snippet'       => '-1',
			'max_image_preview' => 'large',
			'max_video_preview' => '-1',
		);
	}

	/**
	 * Render settings page
	 */
	public function render() {
		$srk_meta          = get_option( 'srk_meta', array() );
		$archives_settings = isset( $srk_meta['archives'] ) && is_array( $srk_meta['archives'] )
			? $srk_meta['archives']
			: array();

		$global_settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] )
			? $srk_meta['global']
			: array();

		$separator = isset( $global_settings['title_separator'] )
			? $global_settings['title_separator']
			: get_option( 'srk_title_separator', '-' );
		?>

		<div class="wrap srk-archives-settings">
			<h1><?php esc_html_e( 'Archive Settings', 'seo-repair-kit' ); ?></h1>

			<?php
			if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				esc_html_e( 'Archive settings saved successfully!', 'seo-repair-kit' );
				echo '</p></div>';
			}
			?>

			<form method="post" action="" id="srk-archives-form">
				<?php wp_nonce_field( 'srk_save_archives_settings', 'srk_archives_nonce' ); ?>
				<input type="hidden" name="srk_archives_submitted" value="1">

				<div class="srk-archives-container">
					<?php
					foreach ( $this->archive_types as $archive_key => $archive_data ) :
						$defaults        = $this->get_default_archive_settings( $archive_key );
						$robots_defaults = $this->get_default_robots_meta();

						$settings = isset( $archives_settings[ $archive_key ] ) && is_array( $archives_settings[ $archive_key ] )
							? wp_parse_args( $archives_settings[ $archive_key ], $defaults )
							: $defaults;

						$robots_settings = array();
						foreach ( $robots_defaults as $key => $default_value ) {
							$robots_settings[ $key ] = isset( $settings[ $key ] ) ? $settings[ $key ] : $default_value;
						}

						$title             = isset( $settings['title'] ) ? $settings['title'] : $defaults['title'];
						$description       = isset( $settings['description'] ) ? $settings['description'] : $defaults['description'];
						$noindex           = isset( $settings['noindex'] ) ? $settings['noindex'] : $defaults['noindex'];
						$nofollow          = isset( $settings['nofollow'] ) ? $settings['nofollow'] : $defaults['nofollow'];
						$noarchive         = isset( $settings['noarchive'] ) ? $settings['noarchive'] : $defaults['noarchive'];
						$nosnippet         = isset( $settings['nosnippet'] ) ? $settings['nosnippet'] : $defaults['nosnippet'];
						$noimageindex      = isset( $settings['noimageindex'] ) ? $settings['noimageindex'] : $defaults['noimageindex'];
						$max_snippet       = isset( $settings['max_snippet'] ) ? $settings['max_snippet'] : $defaults['max_snippet'];
						$max_image_preview = isset( $settings['max_image_preview'] ) ? $settings['max_image_preview'] : $defaults['max_image_preview'];
						$max_video_preview = isset( $settings['max_video_preview'] ) ? $settings['max_video_preview'] : $defaults['max_video_preview'];

						$title_tags = $this->get_relevant_tags( $archive_key, 'title' );
						$desc_tags  = $this->get_relevant_tags( $archive_key, 'description' );
						?>

						<div class="srk-archive-card" data-archive-type="<?php echo esc_attr( $archive_key ); ?>">
							<div class="srk-archive-header">
								<div class="srk-archive-title-wrapper">
									<span class="dashicons dashicons-<?php echo esc_attr( $archive_data['icon'] ); ?>"></span>
									<h2 class="srk-archive-title">
										<?php echo esc_html( $archive_data['page_label'] ); ?>
									</h2>
								</div>
								<span class="srk-accordion-icon dashicons dashicons-arrow-right-alt2"></span>
							</div>

							<div class="srk-archive-tabs">
								<button type="button" class="srk-tab-button active" data-tab="title-desc">
									<?php esc_html_e( 'Title & Description', 'seo-repair-kit' ); ?>
								</button>
								<button type="button" class="srk-tab-button" data-tab="advanced">
									<?php esc_html_e( 'Advanced Robots Meta', 'seo-repair-kit' ); ?>
								</button>
							</div>

							<div class="srk-tab-content active" data-tab-content="title-desc">
								<div class="srk-preview-section">
									<label><strong><?php esc_html_e( 'Preview', 'seo-repair-kit' ); ?></strong></label>
									<div class="srk-google-preview-box" id="srk-archive-<?php echo esc_attr( $archive_key ); ?>-preview">
										<?php
										echo wp_kses(
											$this->generate_google_preview( $title, $description, $archive_key, $separator ),
											array(
												'div' => array(
													'class' => true,
													'id'    => true,
													'style' => true,
												),
											)
										);
										?>
									</div>
								</div>

								<div class="srk-field-group">
									<label class="srk-field-title">
										<strong>
											<?php
											$archive_label = ucfirst( str_replace( '_', ' ', $archive_key ) );
											printf( esc_html__( 'Meta Title for %s', 'seo-repair-kit' ), esc_html( $archive_label ) );
											?>
										</strong>
									</label>
									<p class="srk-field-instruction">
										<?php esc_html_e( 'Click on the tags below to insert variables into your title.', 'seo-repair-kit' ); ?>
									</p>
									<div class="srk-field-example srk-important">
										<p class="srk-example-desc">
											<?php esc_html_e( 'Default title format for this taxonomy archive. Individual terms will follow this pattern automatically.', 'seo-repair-kit' ); ?>
										</p>
									</div>

									<div class="srk-relevant-tags">
										<?php foreach ( $title_tags as $tag => $label ) : ?>
											<button type="button" class="srk-tag-btn" data-tag="<?php echo esc_attr( $tag ); ?>" data-target="title">
												<span class="srk-tag-icon">+</span> <?php echo esc_html( $label ); ?>
											</button>
										<?php endforeach; ?>
										<a href="+" class="srk-view-all-tags" data-target="title">
											<?php esc_html_e( 'View all tags →', 'seo-repair-kit' ); ?>
										</a>
									</div>

									<div class="srk-tag-input-container">
										<input type="text"
												id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_title"
												name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][title]"
												class="srk-title-input regular-text"
												value="<?php echo esc_attr( $title ); ?>"
												placeholder="<?php esc_attr_e( 'Enter title template', 'seo-repair-kit' ); ?>">
									</div>
								</div>

								<div class="srk-field-group">
									<label for="srk_archive_<?php echo esc_attr( $archive_key ); ?>_description" class="srk-field-title">
										<strong><?php esc_html_e( 'Meta Description', 'seo-repair-kit' ); ?></strong>
									</label>
									<p class="srk-field-instruction">
										<?php esc_html_e( 'Click on the tags below to insert variables into your meta description.', 'seo-repair-kit' ); ?>
									</p>
									<div class="srk-field-example srk-important">
										<p class="srk-example-desc">
											<?php esc_html_e( 'Default meta description for this taxonomy archive. It will be used when no custom description is provided.', 'seo-repair-kit' ); ?>
										</p>
									</div>

									<div class="srk-relevant-tags">
										<?php foreach ( $desc_tags as $tag => $label ) : ?>
											<button type="button" class="srk-tag-btn" data-tag="<?php echo esc_attr( $tag ); ?>" data-target="description">
												<span class="srk-tag-icon">+</span> <?php echo esc_html( $label ); ?>
											</button>
										<?php endforeach; ?>
										<a href="+" class="srk-view-all-tags" data-target="description">
											<?php esc_html_e( 'View all tags →', 'seo-repair-kit' ); ?>
										</a>
									</div>

									<div class="srk-tag-input-container">
										<textarea id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_description"
													name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][description]"
													class="srk-desc-input large-text"
													rows="3"
													placeholder="<?php esc_attr_e( 'Enter meta description template', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
									</div>
								</div>
							</div>

							<div class="srk-tab-content" data-tab-content="advanced" style="display: none;">
								<div class="srk-field-group">
									<div class="srk-toggle-section">
										<?php
										$use_default_value = isset( $settings['use_default'] ) ? $settings['use_default'] : '1';
										?>
										<label class="srk-toggle-label">
											<div class="srk-toggle-switch">
												<input type="checkbox"
														class="srk-use-default-toggle"
														name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][use_default]"
														value="1"
														id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_use_default"
														<?php checked( $use_default_value, '1' ); ?>>
												<span class="srk-toggle-slider"></span>
											</div>
											<div class="srk-toggle-text">
												<strong><?php esc_html_e( 'Sync Advanced Settings', 'seo-repair-kit' ); ?></strong>
												<span><?php esc_html_e( 'When enabled, this archive type uses robots settings from Advanced Settings.', 'seo-repair-kit' ); ?></span>
											</div>
											<span class="srk-toggle-state <?php echo ( '1' === $use_default_value ) ? 'enabled' : 'disabled'; ?>">
												<?php echo ( '1' === $use_default_value ) ? esc_html__( 'Enabled', 'seo-repair-kit' ) : esc_html__( 'Disabled', 'seo-repair-kit' ); ?>
											</span>
										</label>
									</div>
								</div>

								<div class="srk-advanced-settings <?php echo ( '1' === $use_default_value ) ? 'hidden' : ''; ?>">
									<div class="srk-section-divider"></div>

									<div class="srk-default-mode-message">
										<strong><?php esc_html_e( 'How These Settings Work', 'seo-repair-kit' ); ?></strong>
										<p>
											<?php esc_html_e( 'These robots meta settings apply only to this archive type (Author, Date, or Search).', 'seo-repair-kit' ); ?>
										</p>
										<ul>
											<li>
												<?php esc_html_e( 'If "Use Default Settings" is enabled, the global robots directives will apply.', 'seo-repair-kit' ); ?>
											</li>
											<li>
												<?php esc_html_e( 'If disabled, the custom directives below will override global settings for this archive type only.', 'seo-repair-kit' ); ?>
											</li>
										</ul>
									</div>

									<div class="srk-field-group">
										<label class="srk-section-label">
											<span class="dashicons dashicons-search"></span>
											<strong><?php esc_html_e( 'Custom Robots Settings', 'seo-repair-kit' ); ?></strong>
										</label>

										<p class="description" style="color:#dc3545;">
											<?php esc_html_e( 'Overrides global robots settings for all pages of this archive type.', 'seo-repair-kit' ); ?>
										</p>

										<div class="srk-checkbox-grid">
											<div class="srk-checkbox-column">
												<label class="srk-checkbox-label">
													<input type="checkbox"
															name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][noindex]"
															value="1"
															id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_noindex"
															<?php checked( $noindex, '1' ); ?>
															<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<span class="srk-checkbox-text">
														<strong><?php esc_html_e( 'No Index', 'seo-repair-kit' ); ?></strong>
														<span><?php esc_html_e( 'Prevent search engines from indexing this page', 'seo-repair-kit' ); ?></span>
													</span>
												</label>

												<label class="srk-checkbox-label">
													<input type="checkbox"
															name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][nofollow]"
															value="1"
															id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_nofollow"
															<?php checked( $nofollow, '1' ); ?>
															<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<span class="srk-checkbox-text">
														<strong><?php esc_html_e( 'No Follow', 'seo-repair-kit' ); ?></strong>
														<span><?php esc_html_e( 'Prevent search engines from following links on this page', 'seo-repair-kit' ); ?></span>
													</span>
												</label>
											</div>

											<div class="srk-checkbox-column">
												<label class="srk-checkbox-label">
													<input type="checkbox"
															name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][noarchive]"
															value="1"
															id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_noarchive"
															<?php checked( $noarchive, '1' ); ?>
															<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<span class="srk-checkbox-text">
														<strong><?php esc_html_e( 'No Archive', 'seo-repair-kit' ); ?></strong>
														<span><?php esc_html_e( 'Prevent search engines from showing cached version', 'seo-repair-kit' ); ?></span>
													</span>
												</label>

												<label class="srk-checkbox-label">
													<input type="checkbox"
															name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][nosnippet]"
															value="1"
															id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_nosnippet"
															<?php checked( $nosnippet, '1' ); ?>
															<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<span class="srk-checkbox-text">
														<strong><?php esc_html_e( 'No Snippet', 'seo-repair-kit' ); ?></strong>
														<span><?php esc_html_e( 'Prevent search engines from showing a snippet', 'seo-repair-kit' ); ?></span>
													</span>
												</label>
											</div>

											<div class="srk-checkbox-column">
												<label class="srk-checkbox-label">
													<input type="checkbox"
															name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][noimageindex]"
															value="1"
															id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_noimageindex"
															<?php checked( $noimageindex, '1' ); ?>
															<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<span class="srk-checkbox-text">
														<strong><?php esc_html_e( 'No Image Index', 'seo-repair-kit' ); ?></strong>
														<span><?php esc_html_e( 'Prevent search engines from indexing images on this page', 'seo-repair-kit' ); ?></span>
													</span>
												</label>
											</div>
										</div>
									</div>

									<div class="srk-section-divider"></div>

									<div class="srk-field-group">
										<label class="srk-section-label">
											<span class="dashicons dashicons-admin-settings"></span>
											<strong><?php esc_html_e( 'Advanced Meta Settings', 'seo-repair-kit' ); ?></strong>
										</label>

										<div class="srk-settings-grid">
											<div class="srk-setting-item">
												<label for="srk_archive_<?php echo esc_attr( $archive_key ); ?>_max_snippet">
													<?php esc_html_e( 'Max Snippet Length', 'seo-repair-kit' ); ?>
												</label>
												<select name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][max_snippet]"
														id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_max_snippet"
														class="srk-select"
														<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<option value="-1" <?php selected( $max_snippet, '-1' ); ?>><?php esc_html_e( 'Unlimited', 'seo-repair-kit' ); ?></option>
													<option value="0" <?php selected( $max_snippet, '0' ); ?>><?php esc_html_e( 'No snippet', 'seo-repair-kit' ); ?></option>
													<option value="10" <?php selected( $max_snippet, '10' ); ?>><?php esc_html_e( '10 characters', 'seo-repair-kit' ); ?></option>
													<option value="50" <?php selected( $max_snippet, '50' ); ?>><?php esc_html_e( '50 characters', 'seo-repair-kit' ); ?></option>
													<option value="100" <?php selected( $max_snippet, '100' ); ?>><?php esc_html_e( '100 characters', 'seo-repair-kit' ); ?></option>
													<option value="150" <?php selected( $max_snippet, '150' ); ?>><?php esc_html_e( '150 characters', 'seo-repair-kit' ); ?></option>
													<option value="200" <?php selected( $max_snippet, '200' ); ?>><?php esc_html_e( '200 characters', 'seo-repair-kit' ); ?></option>
												</select>
												<p class="srk-setting-description">
													<?php esc_html_e( 'Maximum characters for search result snippets', 'seo-repair-kit' ); ?>
												</p>
											</div>

											<div class="srk-setting-item">
												<label for="srk_archive_<?php echo esc_attr( $archive_key ); ?>_max_image_preview">
													<?php esc_html_e( 'Max Image Preview', 'seo-repair-kit' ); ?>
												</label>
												<select name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][max_image_preview]"
														id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_max_image_preview"
														class="srk-select"
														<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<option value="none" <?php selected( $max_image_preview, 'none' ); ?>><?php esc_html_e( 'None', 'seo-repair-kit' ); ?></option>
													<option value="standard" <?php selected( $max_image_preview, 'standard' ); ?>><?php esc_html_e( 'Standard', 'seo-repair-kit' ); ?></option>
													<option value="large" <?php selected( $max_image_preview, 'large' ); ?>><?php esc_html_e( 'Large', 'seo-repair-kit' ); ?></option>
												</select>
												<p class="srk-setting-description">
													<?php esc_html_e( 'Maximum size for image previews', 'seo-repair-kit' ); ?>
												</p>
											</div>

											<div class="srk-setting-item">
												<label for="srk_archive_<?php echo esc_attr( $archive_key ); ?>_max_video_preview">
													<?php esc_html_e( 'Max Video Preview', 'seo-repair-kit' ); ?>
												</label>
												<select name="srk_archives[<?php echo esc_attr( $archive_key ); ?>][max_video_preview]"
														id="srk_archive_<?php echo esc_attr( $archive_key ); ?>_max_video_preview"
														class="srk-select"
														<?php echo ( isset( $settings['use_default'] ) && '1' === $settings['use_default'] ) ? 'disabled' : ''; ?>>
													<option value="-1" <?php selected( $max_video_preview, '-1' ); ?>><?php esc_html_e( 'Unlimited', 'seo-repair-kit' ); ?></option>
													<option value="0" <?php selected( $max_video_preview, '0' ); ?>><?php esc_html_e( 'No preview', 'seo-repair-kit' ); ?></option>
													<option value="10" <?php selected( $max_video_preview, '10' ); ?>><?php esc_html_e( '10 seconds', 'seo-repair-kit' ); ?></option>
													<option value="30" <?php selected( $max_video_preview, '30' ); ?>><?php esc_html_e( '30 seconds', 'seo-repair-kit' ); ?></option>
													<option value="60" <?php selected( $max_video_preview, '60' ); ?>><?php esc_html_e( '60 seconds', 'seo-repair-kit' ); ?></option>
												</select>
												<p class="srk-setting-description">
													<?php esc_html_e( 'Maximum length for video previews', 'seo-repair-kit' ); ?>
												</p>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>

						<?php $this->render_archive_tags_modal( $archive_key ); ?>

					<?php endforeach; ?>
				</div>

				<div class="srk-submit-section">
					<button type="button" class="button srk-reset-archives-button" style="margin-right: 10px;">
						<?php esc_html_e( 'Reset to Defaults', 'seo-repair-kit' ); ?>
					</button>
					<?php submit_button( __( 'Save Changes', 'seo-repair-kit' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render archive tags modal
	 *
	 * @param string $archive_key Archive type key.
	 */
	private function render_archive_tags_modal( $archive_key ) {
		$all_title_tags = $this->template_tags['title'];
		$all_desc_tags  = $this->template_tags['description'];
		?>
		<div class="srk-all-tags-modal"
				id="srk-archive-tags-modal-<?php echo esc_attr( $archive_key ); ?>"
				style="display:none;">

			<div class="srk-modal-header">
				<h3><?php esc_html_e( 'Smart Tags', 'seo-repair-kit' ); ?></h3>
				<button type="button" class="srk-modal-close">&times;</button>
			</div>

			<div class="srk-modal-body">
				<div class="srk-tags-search-wrapper">
					<input type="text"
						class="srk-tags-search"
						placeholder="<?php esc_attr_e( 'Search tags...', 'seo-repair-kit' ); ?>">
				</div>

				<div class="srk-tags-list">
					<?php foreach ( $all_title_tags as $tag => $data ) : ?>
						<div class="srk-tag-item"
								data-tag="<?php echo esc_attr( $tag ); ?>"
								data-archive="<?php echo esc_attr( $archive_key ); ?>"
								data-target="title">

							<div class="srk-tag-info">
								<strong><?php echo esc_html( $data['label'] ); ?></strong>
								<span class="srk-tag-code"><?php echo esc_html( $tag ); ?></span>
								<p class="srk-tag-description">
									<?php echo esc_html( $data['desc'] ); ?>
								</p>
							</div>
						</div>
					<?php endforeach; ?>

					<?php foreach ( $all_desc_tags as $tag => $data ) : ?>
						<div class="srk-tag-item"
								data-tag="<?php echo esc_attr( $tag ); ?>"
								data-archive="<?php echo esc_attr( $archive_key ); ?>"
								data-target="description">

							<div class="srk-tag-info">
								<strong><?php echo esc_html( $data['label'] ); ?></strong>
								<span class="srk-tag-code"><?php echo esc_html( $tag ); ?></span>
								<p class="srk-tag-description">
									<?php echo esc_html( $data['desc'] ); ?>
								</p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Generate preview text
	 *
	 * @param string $template     Template string.
	 * @param string $archive_type Archive type.
	 * @param string $type         Preview type.
	 * @param string $separator    Title separator.
	 * @return string Preview text.
	 */
	private function generate_preview( $template, $archive_type, $type, $separator ) {
		if ( empty( $template ) ) {
			return '';
		}

		$template = preg_replace( '/(%[a-z_]+%)(?=\1)/i', '$1', $template );

		$site_name        = get_bloginfo( 'name' ) ?: 'Site Name';
		$site_description = get_bloginfo( 'description' ) ?: 'Site Description';

		$replacements = array(
			'%sep%'                => $separator,
			'%site_title%'         => $site_name,
			'%sitedesc%'           => $site_description,
			'%author%'             => 'John Doe',
			'%author_bio%'         => 'Author biography text...',
			'%search%'             => 'example search',
			'%tags%'               => 'Example Tag 1, Example Tag 2',
			'%author_first_name%'  => 'John',
			'%author_last_name%'   => 'Doe',
			'%date%'               => date_i18n( get_option( 'date_format' ) ),
			'%month%'              => date_i18n( 'F' ),
			'%year%'               => date_i18n( 'Y' ),
			'%current_date%'       => date_i18n( get_option( 'date_format' ) ),
		);

		switch ( $archive_type ) {
			case 'author':
				$current_user = wp_get_current_user();
				$author_name  = $current_user->display_name ?: ( $current_user->user_login ?: 'John Doe' );
				$author_bio   = get_the_author_meta( 'description', $current_user->ID ) ?: 'Author biography text...';

				$replacements['%author%']              = $author_name;
				$replacements['%author_bio%']          = $author_bio;
				$replacements['%archive_title%']       = $author_name;
				$replacements['%archive_description%'] = 'Posts by ' . $author_name;
				$replacements['%date%']                = '';
				break;

			case 'date':
				$current_date = date_i18n( 'F Y' );
				$full_date    = date_i18n( 'F j, Y' );
				$day          = date_i18n( 'j' );
				$month        = date_i18n( 'F' );
				$year         = date_i18n( 'Y' );

				$replacements['%date%']                = $current_date;
				$replacements['%archive_title%']       = $full_date;
				$replacements['%archive_description%'] = 'Archive for ' . $full_date;
				$replacements['%day%']                   = $day;
				$replacements['%month%']                 = $month;
				$replacements['%year%']                  = $year;
				$replacements['%current_date%']          = $full_date;
				$replacements['%author%']              = '';
				$replacements['%author_bio%']            = '';
				break;

			case 'search':
				$search_term                           = 'example search';
				$replacements['%search%']              = $search_term;
				$replacements['%archive_title%']       = 'Search Results';
				$replacements['%archive_description%'] = 'Search results for "' . $search_term . '"';
				$replacements['%date%']                = '';
				$replacements['%author%']              = '';
				$replacements['%author_bio%']          = '';
				break;
		}

		$preview = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
		$preview = preg_replace( '/%[a-z_]+%/i', '', $preview );
		$preview = preg_replace( '/\s+/', ' ', $preview );
		$preview = trim( $preview );
		$preview = preg_replace( '/' . preg_quote( $separator, '/' ) . '\s*' . preg_quote( $separator, '/' ) . '/', $separator, $preview );

		return $preview;
	}
}