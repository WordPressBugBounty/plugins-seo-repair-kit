<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global Settings Tab for Meta Manager
 *
 * @package SEO_Repair_Kit
 * @version 2.1.3
 */
class SRK_Meta_Manager_Global {

	/**
	 * Relevant tags for specific sections
	 *
	 * @var array
	 */
	private $template_tags_relevant = array(
		'home_title'       => array(
			'Site Title' => '%site_title%',
			'Tagline'    => '%tagline%',
			'Separator'  => '%sep%',
		),
		'home_desc'        => array(
			'Site Title' => '%site_title%',
			'Tagline'    => '%tagline%',
			'Separator'  => '%sep%',
		),
		'title_template'   => array(
			'Post Title'     => '%title%',
			'Page Title'     => '%title%',
			'Site Title'     => '%site_title%',
			'Tagline'        => '%tagline%',
			'Separator'      => '%sep%',
			'Category Title' => '%term_title%',
			'Author Name'    => '%author_name%',
			'Archive Date'   => '%date%',
		),
		'desc_template'    => array(
			'Post Excerpt'       => '%excerpt%',
			'Post Content'       => '%content%',
			'Category Description' => '%category_description%',
			'Tag Description'    => '%tag_description%',
			'Author Bio'         => '%author_bio%',
			'Tagline'            => '%tagline%',
		),
		'org_name'         => array(
			'Site Title' => '%site_title%',
		),
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'srk_cleanup_knowledge_graph_data' ) );
	}

	/**
	 * Enqueue scripts
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'seo-repair-kit-meta-manager' !== $page ) {
			return;
		}
		wp_enqueue_media();

		$site_author_data = $this->get_site_author_data();

		$localized_data = array(
			'site_name'           => get_bloginfo( 'name' ),
			'site_description'    => get_bloginfo( 'description' ),
			'initial_separator'   => get_option( 'srk_title_separator', '-' ),
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'srk_meta_nonce' ),
			'post'                => array(
				'title'    => __( 'Sample Post Title', 'seo-repair-kit' ),
				'excerpt'  => __( 'This is a sample post excerpt...', 'seo-repair-kit' ),
				'content'  => __( 'Sample post content...', 'seo-repair-kit' ),
				'author'   => $site_author_data['display_name'],
				'date'     => date_i18n( get_option( 'date_format' ) ),
				'category' => __( 'Sample Category', 'seo-repair-kit' ),
			),
			'site_author'         => $site_author_data,
		);

		wp_localize_script( 'srk-meta-global', 'srkMetaPreview', $localized_data );

		wp_add_inline_script(
			'srk-meta-global',
			'console.log("SRK Data Loaded:", srkMetaPreview); console.log("Author:", srkMetaPreview.site_author);',
			'before'
		);
	}

	/**
	 * Render settings page
	 */
	public function render() {
		$srk_meta = get_option( 'srk_meta', array() );
		$settings = isset( $srk_meta['global'] ) && is_array( $srk_meta['global'] ) ? $srk_meta['global'] : array();

		$separator = isset( $settings['title_separator'] ) ? $settings['title_separator'] : get_option( 'srk_title_separator', '-' );

		$home_title = isset( $settings['home_title'] ) ? $settings['home_title'] : get_option( 'srk_home_title', '' );
		if ( empty( $home_title ) ) {
			$home_title = '%site_title% %sep% %tagline%';
		}

		$home_desc = isset( $settings['home_desc'] ) ? $settings['home_desc'] : get_option( 'srk_home_desc', '' );
		if ( empty( $home_desc ) ) {
			$home_desc = '%tagline%';
		}

		$title_template = isset( $settings['title_template'] ) ? $settings['title_template'] : get_option( 'srk_title_template', '%title% %sep% %site_title%' );
		$desc_template  = isset( $settings['desc_template'] ) ? $settings['desc_template'] : get_option( 'srk_desc_template', '%tagline%' );
		$desc_length    = isset( $settings['meta_description_length'] ) ? $settings['meta_description_length'] : get_option( 'srk_meta_description_length', 160 );

		$settings_updated = isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) );
		?>
		<div class="wrap srk-global-settings">
			<?php if ( $settings_updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully!', 'seo-repair-kit' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" id="srk-global-form">
				<?php settings_fields( 'srk_meta_global_settings' ); ?>

				<div class="srk-section">
					<h2 class="srk-section-title">
						<?php esc_html_e( 'Title Separator', 'seo-repair-kit' ); ?>
						<span class="srk-accordion-icon dashicons dashicons-arrow-right-alt2"></span>
					</h2>
					<p class="srk-section-desc">
						<?php esc_html_e( 'Choose the separator character that appears between your page title and site title.', 'seo-repair-kit' ); ?>
					</p>

					<div class="srk-preview-box">
						<div class="srk-preview-header">
							<h3><?php esc_html_e( 'Preview', 'seo-repair-kit' ); ?></h3>
							<span class="srk-preview-tag">Google Search Results</span>
						</div>
						<div class="srk-google-preview">
							<div class="srk-preview-author">
								<?php echo esc_url( home_url() ); ?>
							</div>
							<div class="srk-preview-title" id="srk-preview-title-template">
								<?php echo esc_html( get_bloginfo( 'name' ) . ' ' . $separator . ' ' . get_bloginfo( 'description' ) ); ?>
							</div>
							<div class="srk-preview-desc">
								<?php
								$site_description = get_bloginfo( 'description' );

								if ( ! empty( $site_description ) ) {
									echo esc_html( $site_description );
								} else {
									esc_html_e( 'Your site tagline will appear here in search results.', 'seo-repair-kit' );
								}
								?>
							</div>
						</div>
					</div>

					<div class="srk-separator-selector">
						<h3><?php esc_html_e( 'Separator Character', 'seo-repair-kit' ); ?></h3>

						<div class="srk-separator-buttons">
							<?php
							$separators = array(
								'-'  => 'Dash',
								'|'  => 'Vertical Bar',
								'>'  => 'Greater Than',
								'•'  => 'Bullet',
								':'  => 'Colon',
								'~'  => 'Tilde',
								'»'  => 'Right Arrow',
							);

							foreach ( $separators as $sep => $title ) :
								$active = ( $separator === $sep ) ? 'active' : '';
								?>
								<div class="srk-sep-option">
									<input type="radio"
											id="sep_<?php echo esc_attr( $sep ); ?>"
											name="srk_title_separator"
											value="<?php echo esc_attr( $sep ); ?>"
											<?php checked( $separator, $sep ); ?>>
									<label for="sep_<?php echo esc_attr( $sep ); ?>"
											class="srk-sep-btn <?php echo esc_attr( $active ); ?>"
											title="<?php echo esc_attr( $title ); ?>">
										<?php echo esc_html( $sep ); ?>
									</label>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="srk-show-more-wrap">
							<button type="button" class="button-link srk-show-more" id="srk-show-more">
								<?php esc_html_e( 'Show More...', 'seo-repair-kit' ); ?>
							</button>
						</div>

						<div class="srk-more-separators" id="srk-more-separators" style="display: none;">
							<div class="srk-separator-buttons">
								<?php
								$more_separators = array(
									'–' => 'En Dash',
									'—' => 'Em Dash',
									'·' => 'Middle Dot',
									'*' => 'Asterisk',
									'«' => 'Left Arrow',
									'→' => 'Right Arrow',
									'/' => 'Slash',
								);

								foreach ( $more_separators as $sep => $title ) :
									$active = ( $separator === $sep ) ? 'active' : '';
									?>
									<div class="srk-sep-option">
										<input type="radio"
												id="sep_more_<?php echo esc_attr( $sep ); ?>"
												name="srk_title_separator"
												value="<?php echo esc_attr( $sep ); ?>"
												<?php checked( $separator, $sep ); ?>>
										<label for="sep_more_<?php echo esc_attr( $sep ); ?>"
												class="srk-sep-btn <?php echo esc_attr( $active ); ?>"
												title="<?php echo esc_attr( $title ); ?>">
											<?php echo esc_html( $sep ); ?>
										</label>
									</div>
								<?php endforeach; ?>
							</div>

							<div class="srk-custom-separator">
								<div class="srk-sep-option">
									<input type="radio"
											id="sep_custom"
											name="srk_title_separator"
											value="custom"
											<?php echo ( ! in_array( $separator, array_keys( $separators ), true ) && ! in_array( $separator, array_keys( $more_separators ), true ) ) ? 'checked' : ''; ?>>
									<label for="sep_custom" class="srk-sep-btn srk-custom-btn <?php echo ( ! in_array( $separator, array_keys( $separators ), true ) && ! in_array( $separator, array_keys( $more_separators ), true ) ) ? 'active' : ''; ?>">
										<?php esc_html_e( 'Custom', 'seo-repair-kit' ); ?>
									</label>
								</div>

								<div class="srk-custom-input" id="srk-custom-input"
										style="<?php echo ( ! in_array( $separator, array_keys( $separators ), true ) && ! in_array( $separator, array_keys( $more_separators ), true ) ) ? 'display: block;' : 'display: none;'; ?>">
									<input type="text"
											id="srk_custom_sep_input"
											name="srk_custom_separator"
											value="<?php echo ( ! in_array( $separator, array_keys( $separators ), true ) && ! in_array( $separator, array_keys( $more_separators ), true ) ) ? esc_attr( $separator ) : ''; ?>"
											maxlength="3"
											placeholder="::">
									<p class="description"><?php esc_html_e( 'Enter up to 3 characters', 'seo-repair-kit' ); ?></p>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="srk-section">
					<h2 class="srk-section-title">
						<?php esc_html_e( 'Home Page', 'seo-repair-kit' ); ?>
						<span class="srk-accordion-icon dashicons dashicons-arrow-right-alt2"></span>
					</h2>
					<p class="srk-section-desc">
						<?php esc_html_e( 'Customize the title and description for your homepage.', 'seo-repair-kit' ); ?>
					</p>

					<div class="srk-preview-box">
						<div class="srk-preview-header">
							<h3><?php esc_html_e( 'Preview', 'seo-repair-kit' ); ?></h3>
							<span class="srk-preview-tag">SERP Preview</span>
						</div>
						<div class="srk-google-preview">
							<div class="srk-preview-title" id="srk-home-title-preview">
								<?php
								$preview_title = ! empty( $home_title ) ? $home_title : '%site_title% %sep% %tagline%';
								$preview_title = str_replace( '%site_title%', get_bloginfo( 'name' ), $preview_title );
								$preview_title = str_replace( '%tagline%', get_bloginfo( 'description' ), $preview_title );
								$preview_title = str_replace( '%sep%', $separator, $preview_title );
								echo esc_html( $preview_title );
								?>
							</div>
							<div class="srk-preview-url">
								<?php echo esc_url( home_url() ); ?>
							</div>
							<div class="srk-preview-desc" id="srk-home-desc-preview">
								<?php
								$preview_desc = ! empty( $home_desc ) ? $home_desc : '%tagline%';
								$preview_desc = str_replace( '%site_title%', get_bloginfo( 'name' ), $preview_desc );
								$preview_desc = str_replace( '%tagline%', get_bloginfo( 'description' ), $preview_desc );
								$preview_desc = str_replace( '%sep%', $separator, $preview_desc );
								echo esc_html( $preview_desc );
								?>
							</div>
						</div>
					</div>

					<div class="srk-field-group">
						<h3><?php esc_html_e( 'Site Title', 'seo-repair-kit' ); ?></h3>
						<p class="srk-field-desc">
							<?php esc_html_e( 'Customize your homepage title. Click on the tags below to insert dynamic variables.', 'seo-repair-kit' ); ?>
						</p>
						<div class="srk-field-example srk-important">
							<p class="srk-example-desc">
								<?php esc_html_e( 'Default title format for your homepage. You can override it here, while the homepage title is also controlled in the post editor.', 'seo-repair-kit' ); ?>
							</p>
						</div>

						<div class="srk-tag-buttons">
							<?php
							$relevant_tags = isset( $this->template_tags_relevant['home_title'] ) ? $this->template_tags_relevant['home_title'] : array();
							foreach ( $relevant_tags as $label => $tag ) :
								?>
								<button type="button"
										class="button srk-tag-btn"
										data-tag="<?php echo esc_attr( $tag ); ?>"
										data-field-id="srk_home_title">
									<?php echo esc_html( '+ ' . $label ); ?>
								</button>
							<?php endforeach; ?>
							<a href="#"
								class="srk-view-all-tags"
								data-field-id="srk_home_title">
								<?php esc_html_e( 'View all tags', 'seo-repair-kit' ); ?> →
							</a>
							<?php $this->render_global_smart_tags_modal( 'srk_home_title' ); ?>
						</div>

						<div class="srk-input-with-counter">
							<input type="text"
									id="srk_home_title"
									name="srk_home_title"
									value="<?php echo esc_attr( $home_title ); ?>"
									class="large-text"
									placeholder="<?php esc_attr_e( 'Enter custom homepage title or use tags...', 'seo-repair-kit' ); ?>">
							<div class="srk-counter">
								<span id="srk-home-title-count"><?php echo esc_html( strlen( $home_title ) ); ?></span>
								<?php esc_html_e( ' characters', 'seo-repair-kit' ); ?>
								<span class="srk-counter-recommended"><?php esc_html_e( '(Recommended: up to 60)', 'seo-repair-kit' ); ?></span>
								<span id="srk-home-title-status" class="srk-status"></span>
							</div>
						</div>
					</div>

					<div class="srk-field-group">
						<h3><?php esc_html_e( 'Meta Description', 'seo-repair-kit' ); ?></h3>
						<p class="srk-field-desc">
							<?php esc_html_e( 'Customize your homepage meta description. Click on the tags below to insert dynamic variables.', 'seo-repair-kit' ); ?>
						</p>
						<div class="srk-field-example srk-important">
							<p class="srk-example-desc">
								<?php esc_html_e( 'Default meta description for your homepage. Individual posts and pages can override this in their own SEO settings.', 'seo-repair-kit' ); ?>
							</p>
						</div>
						<div class="srk-tag-buttons">
							<?php
							$relevant_tags = isset( $this->template_tags_relevant['home_desc'] ) ? $this->template_tags_relevant['home_desc'] : array();
							foreach ( $relevant_tags as $label => $tag ) :
								?>
								<button type="button"
										class="button srk-tag-btn"
										data-tag="<?php echo esc_attr( $tag ); ?>"
										data-field-id="srk_home_desc">
									<?php echo esc_html( '+ ' . $label ); ?>
								</button>
							<?php endforeach; ?>
							<a href="#"
								class="srk-view-all-tags"
								data-field-id="srk_home_desc">
								<?php esc_html_e( 'View all tags', 'seo-repair-kit' ); ?> →
							</a>
							<?php $this->render_global_smart_tags_modal( 'srk_home_desc' ); ?>
						</div>

						<div class="srk-input-with-counter">
							<textarea id="srk_home_desc"
										name="srk_home_desc"
										rows="4"
										class="large-text"
										placeholder="<?php esc_attr_e( 'Enter custom homepage description or use tags...', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $home_desc ); ?></textarea>
							<div class="srk-counter">
								<span id="srk-home-desc-count"><?php echo esc_html( strlen( $home_desc ) ); ?></span>
								<?php esc_html_e( ' characters', 'seo-repair-kit' ); ?>
								<span class="srk-counter-recommended"><?php esc_html_e( '(Recommended: up to 160)', 'seo-repair-kit' ); ?></span>
								<span id="srk-home-desc-status" class="srk-status"></span>
							</div>
						</div>
					</div>
				</div>

				<div class="srk-section">
                    <h2 class="srk-section-title">
                        <?php esc_html_e( 'Knowledge Graph', 'seo-repair-kit' ); ?>
                        <span class="srk-accordion-icon dashicons dashicons-arrow-right-alt2"></span>
                    </h2>

                    <p class="srk-section-desc">
                        <?php esc_html_e( 'Help search engines understand who is behind your website.', 'seo-repair-kit' ); ?>
                    </p>

                    <div class="srk-knowledge-graph-notice" style="background: #f0f6fc; border-left: 4px solid #72aee6; padding: 20px; margin: 20px 0; border-radius: 4px;">
                        <div style="display: flex; align-items: flex-start; gap: 15px; flex-wrap: wrap;">

                            <div style="flex: 1; min-width: 250px;">
                                <h3 style="margin-top: 0; margin-bottom: 10px; color: #1d2327; font-size: 16px;">
                                    <span class="dashicons dashicons-lightbulb" style="color: #72aee6; margin-right: 5px;"></span>
                                    <?php esc_html_e( 'Build Your Website Knowledge Graph 🧠', 'seo-repair-kit' ); ?>
                                </h3>

                                <p style="margin-bottom: 15px; font-size: 14px; line-height: 1.5; color: #2c3338;">
                                    <?php esc_html_e( 'Add Author schema (Person or Organization) to help Google understand your website and build trust in search results.', 'seo-repair-kit' ); ?>
                                </p>

                                <p style="margin-bottom: 15px; font-size: 14px; line-height: 1.5; color: #2c3338;">
                                    <?php esc_html_e( 'Unlock Schema Manager in the Pro version to add Knowledge Graph, Author schema, and other advanced schema types for complete SEO.', 'seo-repair-kit' ); ?>
                                </p>

                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=srk-schema-manager' ) ); ?>" class="button button-primary">
                                        <?php esc_html_e( 'Unlock Schema Manager (Pro)', 'seo-repair-kit' ); ?>
                                    </a>
                                </div>
                            </div>

                            <div style="background: white; border-radius: 4px; padding: 15px; min-width: 250px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <h4 style="margin-top: 0; margin-bottom: 12px; font-size: 14px; color: #1d2327;">
                                    <?php esc_html_e( 'Why You Should Add Knowledge Graph? 🧠', 'seo-repair-kit' ); ?>
                                </h4>

                                <ul style="margin: 0; padding-left: 20px; list-style-type: disc; color: #50575e;">
                                    <li><?php esc_html_e( 'Improves entity understanding for search engines', 'seo-repair-kit' ); ?></li>
                                    <li><?php esc_html_e( 'Increases chances of Knowledge Panel appearance', 'seo-repair-kit' ); ?></li>
                                    <li><?php esc_html_e( 'Strengthens E-E-A-T signals', 'seo-repair-kit' ); ?></li>
                                    <li><?php esc_html_e( 'Enables richer search features', 'seo-repair-kit' ); ?></li>
                                    <li><?php esc_html_e( 'Builds brand authority in the semantic web', 'seo-repair-kit' ); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

				<div class="srk-submit-section">
					<button type="button" class="button srk-reset-global-button" style="margin-right: 10px;">
						<?php esc_html_e( 'Reset to Defaults', 'seo-repair-kit' ); ?>
					</button>
					<?php submit_button( __( 'Save Changes', 'seo-repair-kit' ), 'primary large', 'submit', false ); ?>
					<div id="srk-status-message" class="srk-status-message"></div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render global smart tags modal
	 *
	 * @param string $field_id Field ID.
	 */
	private function render_global_smart_tags_modal( $field_id ) {
		?>
		<div class="srk-all-tags-modal"
				id="srk-tags-modal-<?php echo esc_attr( $field_id ); ?>"
				style="display:none;">

			<div class="srk-modal-header">
				<h3><?php esc_html_e( 'Smart Tags', 'seo-repair-kit' ); ?></h3>
				<button type="button" class="srk-modal-close">&times;</button>
			</div>

			<div class="srk-modal-search">
				<input type="text"
						class="srk-tag-search"
						placeholder="<?php esc_attr_e( 'Search tags...', 'seo-repair-kit' ); ?>"
						autocomplete="off">
			</div>

			<div class="srk-modal-body">
				<div class="srk-modal-guide">
					<p>
						<span class="dashicons dashicons-info"></span>
						<?php esc_html_e( 'Click any tag to insert it. Tags will be replaced with actual values on the homepage.', 'seo-repair-kit' ); ?>
					</p>
				</div>

				<div class="srk-tags-list">
					<?php
					$tag_categories = array(
						'site'   => array(
							'title' => __( 'Site Info', 'seo-repair-kit' ),
							'tags'  => array(
								'%site_title%',
								'%sitedesc%',
								'%tagline%',
								'%sep%',
							),
						),
						'author' => array(
							'title' => __( 'Author', 'seo-repair-kit' ),
							'tags'  => array(
								'%author_name%',
								'%author_first_name%',
								'%author_last_name%',
							),
						),
						'date'   => array(
							'title' => __( 'Current Date', 'seo-repair-kit' ),
							'tags'  => array(
								'%current_date%',
								'%month%',
								'%year%',
							),
						),
					);

					foreach ( $tag_categories as $category => $data ) :
						?>
						<div class="srk-tag-category">
							<h4><?php echo esc_html( $data['title'] ); ?></h4>

							<?php foreach ( $data['tags'] as $tag ) : ?>
								<?php
								$description = $this->get_tag_description( $tag );
								$label       = $this->get_tag_label( $tag );
								?>

								<div class="srk-tag-item"
										data-tag="<?php echo esc_attr( $tag ); ?>"
										data-field-id="<?php echo esc_attr( $field_id ); ?>">

									<div class="srk-tag-icon">+</div>

									<div class="srk-tag-info">
										<strong><?php echo esc_html( $label ); ?></strong>

										<span class="srk-tag-code">
											<?php echo esc_html( $tag ); ?>
										</span>

										<?php if ( ! empty( $description ) ) : ?>
											<span class="srk-tag-description">
												<?php echo esc_html( $description ); ?>
											</span>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get tag description
	 *
	 * @param string $tag Tag code.
	 * @return string Tag description.
	 */
	private function get_tag_description( $tag ) {
		$descriptions = array(
			'%site_title%'         => 'Your website title',
			'%tagline%'            => 'Your website tagline',
			'%sep%'                => 'Title separator character',
			'%title%'              => 'Current page or post title',
			'%excerpt%'            => 'Excerpt of the current post',
			'%post_author%'        => 'Author of the current post',
			'%post_date%'          => 'Publication date of the post',
			'%post_category%'      => 'Primary category of the post',
			'%post_tag%'           => 'Tags associated with the post',
			'%post_count%'         => 'Number of posts in the current view',
			'%author_first_name%'  => 'The first name of the post author.',
			'%date%'               => 'The current date, localized.',
			'%month%'              => 'The current month, localized.',
			'%year%'               => 'The current year, localized.',
			'%content%'            => 'The content of your page.',
			'%post_day%'           => 'The day of the month when the page was published.',
			'%post_month%'         => 'The month when the page was published.',
			'%post_year%'          => 'The year when the page was published.',
			'%parent_title%'       => 'The title of the parent post of the current page.',
			'%permalink%'          => 'The permalink for the current page.',
			'%taxonomy_name%'      => 'The name of the first assigned taxonomy term.',
			'%author_first_name%'  => 'First name of the site author.',
			'%author_last_name%'   => 'Last name of the site author.',
			'%author_name%'        => 'Display name of the site author.',
			'%sitedesc%'           => 'Your site description.',
			'%current_date%'       => 'Current localized date.',
		);

		return isset( $descriptions[ $tag ] ) ? $descriptions[ $tag ] : '';
	}

	/**
	 * Get site author data dynamically
	 *
	 * @return array Author data.
	 */
	private function get_site_author_data() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$author_id = 0;
		if ( ! empty( $admins ) ) {
			$author_id = $admins[0]->ID;
		} else {
			$author_id = get_current_user_id();
		}

		if ( $author_id ) {
			return array(
				'first_name'   => get_user_meta( $author_id, 'first_name', true ),
				'last_name'    => get_user_meta( $author_id, 'last_name', true ),
				'display_name' => get_the_author_meta( 'display_name', $author_id ),
				'user_id'      => $author_id,
			);
		}

		return array(
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => '',
			'user_id'      => 0,
		);
	}

	/**
	 * Get tag label from tag code
	 *
	 * @param string $tag Tag code.
	 * @return string Tag label.
	 */
	private function get_tag_label( $tag ) {
		$labels = array(
			'%site_title%'         => 'Site Title',
			'%tagline%'            => 'Tagline',
			'%sep%'                => 'Separator',
			'%title%'              => 'Post Title',
			'%excerpt%'            => 'Post Excerpt',
			'%post_author%'        => 'Post Author',
			'%post_category%'      => 'Post Category',
			'%post_tag%'           => 'Post Tags',
			'%post_count%'         => 'Post Count',
			'%author_first_name%'  => 'Author First Name',
			'%author_last_name%'   => 'Author Last Name',
			'%author_name%'        => 'Author Name',
			'%date%'               => 'Current Date',
			'%month%'              => 'Current Month',
			'%year%'               => 'Current Year',
			'%content%'            => 'Page Content',
			'%post_date%'          => 'Page Date',
			'%post_day%'           => 'Page Day',
			'%post_month%'         => 'Page Month',
			'%post_year%'          => 'Page Year',
			'%parent_title%'       => 'Parent Title',
			'%permalink%'          => 'Permalink',
			'%taxonomy_name%'      => 'Taxonomy Name',
			'%author_name%'        => 'Author Name',
			'%sitedesc%'           => 'Site Description',
			'%current_date%'       => 'Current Date',
		);

		return isset( $labels[ $tag ] ) ? $labels[ $tag ] : str_replace( array( '%', '_' ), ' ', ucwords( $tag, '_' ) );
	}
}