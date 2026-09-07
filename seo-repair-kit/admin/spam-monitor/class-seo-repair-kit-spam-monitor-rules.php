<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SERP-only Spam Rules tab.
 */
class SeoRepairKit_SpamMonitor_Rules {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_srk_sm_save_rules', array( $this, 'ajax_save_rules' ) );
		add_action( 'wp_ajax_srk_sm_reset_rules', array( $this, 'ajax_reset_rules' ) );
	}

	/**
	 * Render rules UI.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rules    = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_all_rules() : array();
		$provider = class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ? new SRK_Spam_Monitor_SERP_Provider() : null;
		$sync     = $provider ? $provider->get_local_sync_status() : array();
		$synced   = ! empty( $sync['synced'] ) && ( ! array_key_exists( 'hash_matches', $sync ) || ! empty( $sync['hash_matches'] ) );
		?>
		<div class="srk-sm-rules-wrap" id="srk-rules-tab">
			<div id="srk-rules-notices" class="srk-sm-notices" style="display:none;" aria-live="polite"></div>

			<!-- Page heading -->
			<div class="srk-rules-header">
				<h2><?php esc_html_e( 'Spam Rules', 'seo-repair-kit' ); ?></h2>
				<p><?php esc_html_e( 'Configure scoring rules for language mismatch, spam keywords, suspicious URL patterns, and risk thresholds.', 'seo-repair-kit' ); ?></p>
			</div>

			<!-- Rules sync status bar -->
			<div class="srk-rules-sync-bar">
				<div class="srk-rules-sync-bar__left">
					<span class="srk-rules-sync-bar__label"><?php esc_html_e( 'Rules Sync Status', 'seo-repair-kit' ); ?></span>
					<span class="srk-rules-sync-bar__pill <?php echo $synced ? 'srk-rules-sync-bar__pill--synced' : 'srk-rules-sync-bar__pill--unsynced'; ?>">
						<?php echo $synced ? esc_html__( 'Synced', 'seo-repair-kit' ) : esc_html__( 'Not Synced', 'seo-repair-kit' ); ?>
					</span>
					<?php if ( ! empty( $sync['last_synced_at'] ) ) : ?>
						<span class="srk-rules-sync-bar__meta"><?php esc_html_e( 'Last Synced:', 'seo-repair-kit' ); ?> <strong><?php echo esc_html( $sync['last_synced_at'] ); ?></strong></span>
					<?php endif; ?>
					<?php if ( ! empty( $sync['rules_hash'] ) ) : ?>
						<span class="srk-rules-sync-bar__meta"><?php esc_html_e( 'Rules Hash:', 'seo-repair-kit' ); ?> <strong><?php echo esc_html( $sync['rules_hash'] ); ?></strong></span>
					<?php endif; ?>
				</div>
				<div class="srk-rules-sync-bar__actions">
					<button type="button" class="button" id="srk-serp-refresh-rules-status">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Sync Rules Now', 'seo-repair-kit' ); ?>
					</button>
					<button type="button" id="srk-rules-save-btn" class="button button-primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save All Rules', 'seo-repair-kit' ); ?>
					</button>
				</div>
			</div>

			<form id="srk-rules-form" method="post">
				<input type="hidden" name="srk_sm_rules_nonce" value="<?php echo esc_attr( wp_create_nonce( 'srk_sm_rules_nonce' ) ); ?>">

				<div class="srk-sm-rules-layout">
					<div class="srk-sm-rules-main">
						<?php $this->render_language_section( $rules ); ?>
						<?php $this->render_keyword_section( $rules ); ?>
						<div class="srk-rules-lower-grid">
							<?php $this->render_url_pattern_section( $rules ); ?>
							<?php $this->render_score_thresholds_section( $rules ); ?>
						</div>
					</div>

					<div class="srk-sm-rules-sidebar">
						<?php $this->render_scoring_preview_sidebar( $rules ); ?>
						<!-- Save card -->
						<div class="srk-sm-card srk-rules-save-card">
							<div class="srk-rules-save-icon"><span class="dashicons dashicons-saved"></span></div>
							<h3><?php esc_html_e( 'Save SERP Rules', 'seo-repair-kit' ); ?></h3>
							<p><?php esc_html_e( 'Rules are saved in WordPress and synced to the Python SERP engine.', 'seo-repair-kit' ); ?></p>
							<button type="button" id="srk-rules-save-btn-sidebar" class="button button-primary srk-sm-btn-block" style="justify-content:center;" onclick="document.getElementById('srk-rules-save-btn').click();">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Save All Rules', 'seo-repair-kit' ); ?>
							</button>
							<button type="button" id="srk-rules-reset-btn" class="button srk-sm-btn-block">
								<span class="dashicons dashicons-image-rotate"></span>
								<?php esc_html_e( 'Reset Spam Rules', 'seo-repair-kit' ); ?>
							</button>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render language rules.
	 *
	 * @param array $rules Saved rules.
	 * @return void
	 */
	private function render_language_section( array $rules ) {
		$expected_codes  = $this->normalize_render_codes( $rules['lang_expected'] ?? array( 'zh', 'ko', 'ja', 'ru', 'fr', 'de', 'ur', 'th', 'vi', 'id' ) );
		$allowed_codes   = $this->normalize_render_codes( $rules['lang_allowed'] ?? array( 'en' ) );
		$expected        = $this->render_list_value( $expected_codes );
		$allowed         = $this->render_list_value( $allowed_codes );
		$mismatch_score  = absint( $rules['lang_mismatch_score'] ?? 45 );
		$flag_unexpected = ! empty( $rules['lang_flag_unexpected'] );
		$languages       = $this->get_language_options();
		?>
		<div class="srk-sm-card srk-rules-section srk-rules-language-section">
			<div class="srk-rules-section-title">
				<div class="srk-rules-section-icon srk-rules-section-icon--primary"><span class="dashicons dashicons-translation"></span></div>
				<div>
					<h3><?php esc_html_e( 'Language Rules', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'Score SERP results when detected language does not match expected site language.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>

			<div class="srk-rules-language-grid">
			<!-- Expected spam languages -->
			<div class="srk-rules-field-group srk-rules-language-card srk-rules-language-card--expected">
				<div class="srk-rules-language-card__title"><span class="dashicons dashicons-warning"></span><span class="srk-rules-field-label"><?php esc_html_e( 'Expected Spam Languages', 'seo-repair-kit' ); ?></span></div>
				<input type="hidden" id="srk-lang-expected" name="lang_expected" value="<?php echo esc_attr( $expected ); ?>">
				<div class="srk-rules-lang-tags" id="srk-lang-expected-tags" data-language-tags-for="#srk-lang-expected">
					<?php foreach ( array_slice( $expected_codes, 0, 8 ) as $code ) : ?>
						<span class="srk-rules-lang-tag"><?php echo esc_html( $code ); ?> ×</span>
					<?php endforeach; ?>
				</div>
				<button type="button" class="srk-rules-browse-btn" data-language-picker="srk-lang-expected-picker" aria-controls="srk-lang-expected-picker" aria-expanded="false">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Browse languages...', 'seo-repair-kit' ); ?>
				</button>
				<?php $this->render_language_checkbox_picker( 'srk-lang-expected-picker', '#srk-lang-expected', $languages, $expected_codes ); ?>
				<p class="srk-sm-field-help"><?php esc_html_e( 'Comma-separated ISO 639-1 codes.', 'seo-repair-kit' ); ?></p>
			</div>

			<!-- Allowed site languages -->
			<div class="srk-rules-field-group srk-rules-language-card srk-rules-language-card--allowed">
				<div class="srk-rules-language-card__title"><span class="dashicons dashicons-yes-alt"></span><span class="srk-rules-field-label"><?php esc_html_e( 'Website Allowed Languages', 'seo-repair-kit' ); ?></span></div>
				<input type="hidden" id="srk-lang-allowed" name="lang_allowed" value="<?php echo esc_attr( $allowed ); ?>">
				<div class="srk-rules-lang-tags" data-language-tags-for="#srk-lang-allowed">
					<?php foreach ( $allowed_codes as $code ) : ?>
						<span class="srk-rules-lang-tag srk-rules-lang-tag--green"><?php echo esc_html( $code ); ?> ×</span>
					<?php endforeach; ?>
				</div>
				<button type="button" class="srk-rules-browse-btn" data-language-picker="srk-lang-allowed-picker" aria-controls="srk-lang-allowed-picker" aria-expanded="false">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Browse languages...', 'seo-repair-kit' ); ?>
				</button>
				<?php $this->render_language_checkbox_picker( 'srk-lang-allowed-picker', '#srk-lang-allowed', $languages, $allowed_codes ); ?>
			</div>
			</div>

			<!-- Score + toggle row -->
			<div class="srk-rules-field-row">
				<div class="srk-rules-field-group">
					<span class="srk-rules-field-label"><?php esc_html_e( 'Language Mismatch Score', 'seo-repair-kit' ); ?></span>
					<input type="number" id="srk-lang-mismatch-score" name="lang_mismatch_score" value="<?php echo esc_attr( $mismatch_score ); ?>" min="0" max="100" class="srk-sm-score-input">
				</div>
				<div class="srk-rules-field-group">
					<span class="srk-rules-field-label"><?php esc_html_e( 'Flag Unexpected Languages', 'seo-repair-kit' ); ?></span>
					<p class="srk-sm-field-help"><?php esc_html_e( 'Score any non-allowed language as spam', 'seo-repair-kit' ); ?></p>
					<div class="srk-rules-toggle-row" style="border:none;padding:0;">
						<label class="srk-sm-toggle">
							<input type="checkbox" name="lang_flag_unexpected" value="1" <?php checked( $flag_unexpected ); ?>>
							<span class="srk-sm-toggle-slider"></span>
						</label>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render keyword rules.
	 *
	 * @param array $rules Saved rules.
	 * @return void
	 */
	private function render_keyword_section( array $rules ) {
		$saved = (array) ( $rules['keyword_categories'] ?? array( 'gambling', 'adult', 'pharma', 'vape', 'counterfeit', 'jp_cn_spam' ) );
		$custom = $this->render_multiline_value( $rules['custom_blocked_keywords'] ?? array() );
		$builtin = class_exists( 'SRK_Spam_Monitor_Check_Keywords' ) ? SRK_Spam_Monitor_Check_Keywords::get_builtin_keywords() : array();
		$category_terms = is_array( $rules['keyword_category_terms'] ?? null ) ? $rules['keyword_category_terms'] : $builtin;
		$categories = array(
			'gambling'    => __( 'Gambling', 'seo-repair-kit' ),
			'adult'       => __( 'Adult Content', 'seo-repair-kit' ),
			'pharma'      => __( 'Pharma Spam', 'seo-repair-kit' ),
			'vape'        => __( 'Vape / Tobacco / Shisha', 'seo-repair-kit' ),
			'counterfeit' => __( 'Counterfeit / Luxury', 'seo-repair-kit' ),
			'jp_cn_spam'  => __( 'JP / CN Ecommerce Spam', 'seo-repair-kit' ),
		);
		$category_icons = array(
			'gambling'    => 'dashicons-tickets-alt',
			'adult'       => 'dashicons-hidden',
			'pharma'      => 'dashicons-plus-alt2',
			'vape'        => 'dashicons-cloud',
			'counterfeit' => 'dashicons-awards',
			'jp_cn_spam'  => 'dashicons-cart',
		);
		?>
		<div class="srk-sm-card srk-rules-section srk-rules-keyword-section">
			<div class="srk-rules-section-title">
				<div class="srk-rules-section-icon"><span class="dashicons dashicons-shield-alt"></span></div>
				<div>
					<h3><?php esc_html_e( 'Spam Keyword Rules', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'Category-based keyword detection in titles, snippets and URLs.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>

			<div class="srk-rules-category-grid">
				<?php foreach ( $categories as $key => $label ) :
					$is_active = in_array( $key, $saved, true );
					$kw_list   = $category_terms[ $key ] ?? ( $builtin[ $key ] ?? array() );
					$kw_count  = count( $kw_list );
					$chips     = array_slice( $kw_list, 0, 4 );
					$more      = max( 0, $kw_count - 4 );
				?>
					<div class="srk-rules-category-card srk-rules-category-card--<?php echo esc_attr( $key ); ?>">
						<div class="srk-rules-category-head">
							<label>
								<input type="checkbox" name="keyword_categories[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $is_active ); ?>>
								<span class="srk-rules-category-icon"><span class="dashicons <?php echo esc_attr( $category_icons[ $key ] ); ?>"></span></span>
								<div>
									<div class="srk-rules-category-name"><?php echo esc_html( $label ); ?></div>
									<div class="srk-rules-category-desc"><?php echo esc_html( ucfirst( $key ) ); ?></div>
								</div>
							</label>
							<span class="srk-rules-category-count"><?php echo esc_html( $kw_count ); ?>kw</span>
						</div>

						<div class="srk-rules-kw-chips">
							<?php foreach ( $chips as $kw ) : ?>
								<span class="srk-rules-kw-chip"><?php echo esc_html( $kw ); ?></span>
							<?php endforeach; ?>
							<?php if ( $more > 0 ) : ?>
								<span class="srk-rules-kw-chip srk-rules-kw-chip--more">+<?php echo esc_html( $more ); ?></span>
							<?php endif; ?>
						</div>

						<div class="srk-rules-category-links">
							<?php if ( ! empty( $builtin[ $key ] ) ) : ?>
								<details class="srk-rules-kw-defaults">
									<summary><?php esc_html_e( 'View defaults', 'seo-repair-kit' ); ?></summary>
									<code><?php echo esc_html( implode( ', ', $builtin[ $key ] ) ); ?></code>
								</details>
							<?php endif; ?>
							<a href="#" class="srk-rules-category-links--edit" onclick="this.closest('.srk-rules-category-card').querySelector('.srk-rules-keyword-editor').classList.toggle('is-open');return false;">
								<?php esc_html_e( 'Edit keywords', 'seo-repair-kit' ); ?>
							</a>
						</div>

						<div class="srk-rules-keyword-editor">
							<textarea name="keyword_category_terms[<?php echo esc_attr( $key ); ?>]" rows="4" class="srk-sm-textarea" placeholder="<?php esc_attr_e( 'One keyword per line', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $this->render_multiline_value( $kw_list ) ); ?></textarea>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Custom blocked keywords -->
			<div class="srk-rules-field-group" style="margin-top:20px;">
				<span class="srk-rules-field-label"><?php esc_html_e( 'Custom Blocked Keywords', 'seo-repair-kit' ); ?></span>
				<p class="srk-sm-field-help" style="margin:0 0 6px;"><?php esc_html_e( 'Site-specific blocked terms', 'seo-repair-kit' ); ?></p>
				<textarea id="srk-custom-keywords" name="custom_blocked_keywords" rows="4" class="srk-sm-textarea" placeholder="<?php esc_attr_e( 'One keyword per line', 'seo-repair-kit' ); ?>"><?php echo esc_textarea( $custom ); ?></textarea>
			</div>

			<div class="srk-rules-score-row">
				<label class="srk-rules-field-label" for="srk-spam-keyword-score"><?php esc_html_e( 'Spam Keyword Score', 'seo-repair-kit' ); ?></label>
				<input type="number" id="srk-spam-keyword-score" name="spam_keyword_score" value="<?php echo esc_attr( absint( $rules['spam_keyword_score'] ?? 35 ) ); ?>" min="0" max="100" class="srk-sm-score-input">
			</div>
		</div>
		<?php
	}

	/**
	 * Render URL pattern rules.
	 *
	 * @param array $rules Saved rules.
	 * @return void
	 */
	private function render_url_pattern_section( array $rules ) {
		?>
		<div class="srk-sm-card srk-rules-section srk-rules-url-section">
			<div class="srk-rules-section-title">
				<div class="srk-rules-section-icon srk-rules-section-icon--primary"><span class="dashicons dashicons-admin-site-alt3"></span></div>
				<div>
					<h3><?php esc_html_e( 'URL Pattern Rules', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'Score suspicious URL slugs and injected URL patterns from Google results.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>

			<div class="srk-rules-field-row">
				<div class="srk-rules-toggle-row">
					<span class="srk-rules-toggle-label"><?php esc_html_e( 'Detect Fake Product URLs', 'seo-repair-kit' ); ?></span>
					<label class="srk-sm-toggle">
						<input type="checkbox" name="url_detect_fake_product" value="1" <?php checked( ! empty( $rules['url_detect_fake_product'] ) ); ?>>
						<span class="srk-sm-toggle-slider"></span>
					</label>
				</div>
				<div class="srk-rules-toggle-row">
					<span class="srk-rules-toggle-label"><?php esc_html_e( 'Detect Suspicious URL Slugs', 'seo-repair-kit' ); ?></span>
					<label class="srk-sm-toggle">
						<input type="checkbox" name="url_detect_suspicious_slugs" value="1" <?php checked( ! empty( $rules['url_detect_suspicious_slugs'] ) ); ?>>
						<span class="srk-sm-toggle-slider"></span>
					</label>
				</div>
			</div>

			<div class="srk-rules-field-group" style="margin-top:16px;">
				<span class="srk-rules-field-label"><?php esc_html_e( 'Blocked URL Patterns', 'seo-repair-kit' ); ?></span>
				<p class="srk-sm-field-help" style="margin:0 0 6px;"><?php esc_html_e( 'One per line. Use /regex/ or plain substring.', 'seo-repair-kit' ); ?></p>
				<textarea id="srk-url-blocked-patterns" name="url_blocked_patterns" rows="4" class="srk-sm-textarea" placeholder="/wp-content/uploads/spam/*"><?php echo esc_textarea( $this->render_multiline_value( $rules['url_blocked_patterns'] ?? array() ) ); ?></textarea>
			</div>

			<div class="srk-rules-score-row" style="margin-top:14px;">
				<label class="srk-rules-field-label" for="srk-url-pattern-score"><?php esc_html_e( 'URL Pattern Score', 'seo-repair-kit' ); ?></label>
				<input type="number" id="srk-url-pattern-score" name="url_pattern_score" value="<?php echo esc_attr( absint( $rules['url_pattern_score'] ?? 35 ) ); ?>" min="0" max="100" class="srk-sm-score-input">
			</div>
		</div>
		<?php
	}

	/**
	 * Render score thresholds — now shown in main column.
	 *
	 * @param array $rules Saved rules.
	 * @return void
	 */
	private function render_score_thresholds_section( array $rules ) {
		$clean_max  = absint( $rules['score_clean_max']      ?? 30 );
		$susp_max   = absint( $rules['score_suspicious_max'] ?? 60 );
		$spam_min   = absint( $rules['score_spam_min']       ?? 61 );
		$crit_min   = absint( $rules['score_critical_min']   ?? 81 );
		?>
		<div class="srk-sm-card srk-rules-section srk-rules-threshold-section">
			<div class="srk-rules-section-title">
				<div class="srk-rules-section-icon srk-rules-section-icon--chart"><span class="dashicons dashicons-chart-bar"></span></div>
				<div>
					<h3><?php esc_html_e( 'Score Thresholds', 'seo-repair-kit' ); ?></h3>
					<p><?php esc_html_e( 'How raw scores map to risk levels.', 'seo-repair-kit' ); ?></p>
				</div>
			</div>

			<!-- Gradient bar -->
			<div class="srk-rules-threshold-bar">
				<div class="srk-rules-threshold-bar__clean"></div>
				<div class="srk-rules-threshold-bar__suspicious"></div>
				<div class="srk-rules-threshold-bar__spam"></div>
				<div class="srk-rules-threshold-bar__critical"></div>
			</div>
			<div class="srk-rules-threshold-labels">
				<span class="srk-rules-threshold-label-item">Clean · 0–<?php echo esc_html( $clean_max ); ?></span>
				<span class="srk-rules-threshold-label-item">Suspicious · <?php echo esc_html( $clean_max + 1 ); ?>–<?php echo esc_html( $susp_max ); ?></span>
				<span class="srk-rules-threshold-label-item">Spam · <?php echo esc_html( $spam_min ); ?>–<?php echo esc_html( $crit_min - 1 ); ?></span>
				<span class="srk-rules-threshold-label-item">Critical · <?php echo esc_html( $crit_min ); ?>–100</span>
			</div>

			<div class="srk-rules-field-row">
				<div class="srk-rules-field-group">
					<span class="srk-rules-field-label"><?php esc_html_e( 'Clean max', 'seo-repair-kit' ); ?></span>
					<input type="number" name="score_clean_max" value="<?php echo esc_attr( $clean_max ); ?>" min="0" max="100" class="srk-sm-score-input">
				</div>
				<div class="srk-rules-field-group">
					<span class="srk-rules-field-label"><?php esc_html_e( 'Suspicious max', 'seo-repair-kit' ); ?></span>
					<input type="number" name="score_suspicious_max" value="<?php echo esc_attr( $susp_max ); ?>" min="0" max="100" class="srk-sm-score-input">
				</div>
				<div class="srk-rules-field-group">
					<span class="srk-rules-field-label"><?php esc_html_e( 'Spam from', 'seo-repair-kit' ); ?></span>
					<input type="number" name="score_spam_min" value="<?php echo esc_attr( $spam_min ); ?>" min="0" max="100" class="srk-sm-score-input">
				</div>
				<div class="srk-rules-field-group">
					<span class="srk-rules-field-label"><?php esc_html_e( 'Critical from', 'seo-repair-kit' ); ?></span>
					<input type="number" name="score_critical_min" value="<?php echo esc_attr( $crit_min ); ?>" min="0" max="100" class="srk-sm-score-input">
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sidebar scoring preview + threshold summary.
	 *
	 * @param array $rules Saved rules.
	 * @return void
	 */
	private function render_scoring_preview_sidebar( array $rules ) {
		$lang_score    = absint( $rules['lang_mismatch_score']  ?? 45 );
		$kw_score      = absint( $rules['spam_keyword_score']   ?? 35 );
		$url_score     = absint( $rules['url_pattern_score']    ?? 35 );
		$total         = $lang_score + $kw_score + $url_score;
		$clean_max     = absint( $rules['score_clean_max']      ?? 30 );
		$susp_max      = absint( $rules['score_suspicious_max'] ?? 60 );
		$spam_min      = absint( $rules['score_spam_min']       ?? 61 );
		$crit_min      = absint( $rules['score_critical_min']   ?? 81 );
		if      ( $total >= $crit_min ) { $risk_label = 'Critical'; $risk_color = '#7C3AED'; }
		elseif  ( $total >= $spam_min ) { $risk_label = 'Spam';     $risk_color = '#EF4444'; }
		elseif  ( $total > $clean_max ) { $risk_label = 'Suspicious'; $risk_color = '#F59E0B'; }
		else                            { $risk_label = 'Clean';    $risk_color = '#10B981'; }
		?>
		<div class="srk-sm-card srk-rules-scoring-preview">
			<h3><?php esc_html_e( 'Scoring Preview', 'seo-repair-kit' ); ?></h3>
			<p class="srk-rules-scoring-preview__sub"><?php esc_html_e( 'Live calculation against thresholds', 'seo-repair-kit' ); ?></p>

			<div class="srk-rules-score-breakdown">
				<div class="srk-rules-score-line">
					<span class="srk-rules-score-line__label"><?php esc_html_e( 'Mismatch hit', 'seo-repair-kit' ); ?></span>
					<strong class="srk-rules-score-line__val">+<?php echo esc_html( $lang_score ); ?></strong>
				</div>
				<div class="srk-rules-score-line">
					<span class="srk-rules-score-line__label"><?php esc_html_e( 'Pharma keyword', 'seo-repair-kit' ); ?></span>
					<strong class="srk-rules-score-line__val">+<?php echo esc_html( $kw_score ); ?></strong>
				</div>
				<div class="srk-rules-score-line">
					<span class="srk-rules-score-line__label"><?php esc_html_e( 'Suspicious slug', 'seo-repair-kit' ); ?></span>
					<strong class="srk-rules-score-line__val">+<?php echo esc_html( $url_score ); ?></strong>
				</div>
			</div>

			<div class="srk-rules-score-total">
				<div>
					<div class="srk-rules-score-total__label"><?php esc_html_e( 'Final score', 'seo-repair-kit' ); ?></div>
					<div class="srk-rules-score-total__note" style="color:<?php echo esc_attr( $risk_color ); ?>;">
						<?php printf( esc_html__( 'Maps to %s (capped at 100)', 'seo-repair-kit' ), esc_html( $risk_label ) ); ?>
					</div>
				</div>
				<span class="srk-rules-score-total__value" style="color:<?php echo esc_attr( $risk_color ); ?>;"><?php echo esc_html( min( 100, $total ) ); ?></span>
			</div>

			<!-- Threshold summary -->
			<div class="srk-rules-threshold-summary" style="margin-top:12px;">
				<h4><?php esc_html_e( 'Thresholds Summary', 'seo-repair-kit' ); ?></h4>
				<div class="srk-rules-threshold-row--sm">
					<span class="srk-rules-threshold-name--sm"><span class="srk-rules-threshold-dot srk-rules-threshold-dot--clean"></span><?php esc_html_e( 'Clean', 'seo-repair-kit' ); ?></span>
					<span class="srk-rules-threshold-range--sm">0–<?php echo esc_html( $clean_max ); ?></span>
				</div>
				<div class="srk-rules-threshold-row--sm">
					<span class="srk-rules-threshold-name--sm"><span class="srk-rules-threshold-dot srk-rules-threshold-dot--suspicious"></span><?php esc_html_e( 'Suspicious', 'seo-repair-kit' ); ?></span>
					<span class="srk-rules-threshold-range--sm"><?php echo esc_html( $clean_max + 1 ); ?>–<?php echo esc_html( $susp_max ); ?></span>
				</div>
				<div class="srk-rules-threshold-row--sm">
					<span class="srk-rules-threshold-name--sm"><span class="srk-rules-threshold-dot srk-rules-threshold-dot--spam"></span><?php esc_html_e( 'Spam', 'seo-repair-kit' ); ?></span>
					<span class="srk-rules-threshold-range--sm"><?php echo esc_html( $spam_min ); ?>–<?php echo esc_html( $crit_min - 1 ); ?></span>
				</div>
				<div class="srk-rules-threshold-row--sm">
					<span class="srk-rules-threshold-name--sm"><span class="srk-rules-threshold-dot srk-rules-threshold-dot--critical"></span><?php esc_html_e( 'Critical', 'seo-repair-kit' ); ?></span>
					<span class="srk-rules-threshold-range--sm"><?php echo esc_html( $crit_min ); ?>–100</span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save SERP rules.
	 *
	 * @return void
	 */
	public function ajax_save_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		check_ajax_referer( 'srk_sm_rules_nonce', 'srk_sm_rules_nonce' );

		if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			wp_send_json_error( array( 'message' => __( 'Database class not available.', 'seo-repair-kit' ) ) );
		}

		SRK_Spam_Monitor_DB::save_rule( 'lang_expected', $this->sanitize_code_list( $_POST['lang_expected'] ?? 'zh, ko, ja, ru, fr, de, ur, th, vi, id' ) );
		SRK_Spam_Monitor_DB::save_rule( 'lang_allowed', $this->sanitize_code_list( $_POST['lang_allowed'] ?? 'en' ) );
		SRK_Spam_Monitor_DB::save_rule( 'lang_flag_unexpected', ! empty( $_POST['lang_flag_unexpected'] ) ? 1 : 0 );
		SRK_Spam_Monitor_DB::save_rule( 'lang_mismatch_score', absint( $_POST['lang_mismatch_score'] ?? 45 ) );

		$valid_categories = array( 'gambling', 'adult', 'pharma', 'vape', 'counterfeit', 'jp_cn_spam' );
		$categories = isset( $_POST['keyword_categories'] ) ? (array) $_POST['keyword_categories'] : array();
		$categories = array_values( array_intersect( array_map( 'sanitize_key', $categories ), $valid_categories ) );
		SRK_Spam_Monitor_DB::save_rule( 'keyword_categories', $categories );
		SRK_Spam_Monitor_DB::save_rule( 'keyword_category_terms', $this->sanitize_keyword_category_terms( $_POST['keyword_category_terms'] ?? array(), $valid_categories ) );
		SRK_Spam_Monitor_DB::save_rule( 'custom_blocked_keywords', $this->sanitize_text_lines( $_POST['custom_blocked_keywords'] ?? '' ) );
		SRK_Spam_Monitor_DB::save_rule( 'spam_keyword_score', $this->sanitize_score( $_POST['spam_keyword_score'] ?? 35 ) );

		SRK_Spam_Monitor_DB::save_rule( 'url_detect_fake_product', ! empty( $_POST['url_detect_fake_product'] ) ? 1 : 0 );
		SRK_Spam_Monitor_DB::save_rule( 'url_detect_suspicious_slugs', ! empty( $_POST['url_detect_suspicious_slugs'] ) ? 1 : 0 );
		SRK_Spam_Monitor_DB::save_rule( 'url_blocked_patterns', $this->sanitize_text_lines( $_POST['url_blocked_patterns'] ?? '' ) );
		SRK_Spam_Monitor_DB::save_rule( 'url_pattern_score', $this->sanitize_score( $_POST['url_pattern_score'] ?? 35 ) );

		SRK_Spam_Monitor_DB::save_rule( 'score_clean_max', $this->sanitize_score( $_POST['score_clean_max'] ?? 30 ) );
		SRK_Spam_Monitor_DB::save_rule( 'score_suspicious_max', $this->sanitize_score( $_POST['score_suspicious_max'] ?? 60 ) );
		SRK_Spam_Monitor_DB::save_rule( 'score_spam_min', $this->sanitize_score( $_POST['score_spam_min'] ?? 61 ) );
		SRK_Spam_Monitor_DB::save_rule( 'score_critical_min', $this->sanitize_score( $_POST['score_critical_min'] ?? 81 ) );

		$sync_status = $this->sync_rules_to_engine();

		if ( is_wp_error( $sync_status ) ) {
			wp_send_json_success(
				array(
					'message'     => __( 'Rules saved, but Python SERP rules sync failed. Use Sync Rules Now after starting the Python server.', 'seo-repair-kit' ),
					'sync_status' => array( 'synced' => false, 'message' => $sync_status->get_error_message() ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Rules saved and synced with the Python SERP engine.', 'seo-repair-kit' ),
				'sync_status' => $sync_status,
			)
		);
	}

	/**
	 * Reset Spam Rules to defaults and sync them.
	 *
	 * @return void
	 */
	public function ajax_reset_rules() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'seo-repair-kit' ) ), 403 );
		}

		check_ajax_referer( 'srk_sm_rules_nonce', 'srk_sm_rules_nonce' );

		if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			wp_send_json_error( array( 'message' => __( 'Database class not available.', 'seo-repair-kit' ) ) );
		}

		foreach ( SRK_Spam_Monitor_DB::get_default_rules() as $key => $value ) {
			SRK_Spam_Monitor_DB::save_rule( $key, $value );
		}

		$sync_status = $this->sync_rules_to_engine();

		if ( is_wp_error( $sync_status ) ) {
			wp_send_json_success(
				array(
					'message'     => __( 'Spam Rules reset to defaults, but Python SERP rules sync failed. Use Sync Rules Now after starting the Python server.', 'seo-repair-kit' ),
					'sync_status' => array( 'synced' => false, 'message' => $sync_status->get_error_message() ),
				)
			);
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Spam Rules reset to defaults and synced with the Python SERP engine.', 'seo-repair-kit' ),
				'sync_status' => $sync_status,
			)
		);
	}

	/**
	 * Render one-line list values.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function render_list_value( $value ) {
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * Render multiline values.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function render_multiline_value( $value ) {
		return is_array( $value ) ? implode( "\n", $value ) : (string) $value;
	}

	/**
	 * Format a language selector option.
	 *
	 * @param string $code ISO 639-1 code.
	 * @param array  $language Language metadata.
	 * @return string
	 */
	private function format_language_option_label( $code, array $language ) {
		return sprintf( '%1$s (%2$s, e.g: %3$s)', $code, $language['name'], $language['example'] );
	}

	/**
	 * Render checkbox language selector.
	 *
	 * @param string $id Picker ID.
	 * @param string $target Target input selector.
	 * @param array  $languages Language option map.
	 * @param array  $selected Selected ISO codes.
	 * @return void
	 */
	private function render_language_checkbox_picker( $id, $target, array $languages, array $selected ) {
		$selected = array_fill_keys( array_map( 'sanitize_key', $selected ), true );
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="srk-sm-language-checkbox-picker" data-target="<?php echo esc_attr( $target ); ?>" hidden>
			<div class="srk-sm-language-picker-head">
				<input type="search" class="srk-sm-language-search" placeholder="<?php esc_attr_e( 'Search languages by name, ISO code, or example...', 'seo-repair-kit' ); ?>" aria-label="<?php esc_attr_e( 'Search languages', 'seo-repair-kit' ); ?>">
				<div class="srk-sm-language-picker-actions">
					<button type="button" class="srk-sm-language-action srk-sm-language-select-all-btn"><?php esc_html_e( 'Select all', 'seo-repair-kit' ); ?></button>
					<button type="button" class="srk-sm-language-action srk-sm-language-clear-btn"><?php esc_html_e( 'Clear', 'seo-repair-kit' ); ?></button>
				</div>
				<span class="srk-sm-language-count" aria-live="polite"></span>
			</div>
			<div class="srk-sm-language-checkbox-list">
				<?php foreach ( $languages as $code => $language ) : ?>
					<?php
					$search_text = strtolower( $code . ' ' . $language['name'] . ' ' . $language['example'] );
					?>
					<label class="srk-sm-language-checkbox-item" data-language-search="<?php echo esc_attr( $search_text ); ?>">
						<input type="checkbox" class="srk-sm-language-checkbox" value="<?php echo esc_attr( $code ); ?>" <?php checked( ! empty( $selected[ $code ] ) ); ?>>
						<span><?php echo esc_html( $this->format_language_option_label( $code, $language ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="srk-sm-language-empty" hidden><?php esc_html_e( 'No matching languages found.', 'seo-repair-kit' ); ?></p>
			<div class="srk-sm-language-picker-footer">
				<button type="button" class="button button-primary srk-sm-language-done-btn"><?php esc_html_e( 'Done', 'seo-repair-kit' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Normalize language codes for rendering selected checkboxes.
	 *
	 * @param mixed $value Saved value.
	 * @return array
	 */
	private function normalize_render_codes( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
	}

	/**
	 * Get ISO 639-1 language options for layman-friendly language rule input.
	 *
	 * @return array
	 */
	private function get_language_options() {
		return array(
			'aa' => array( 'name' => 'Afar', 'example' => 'Afaraf' ),
			'ab' => array( 'name' => 'Abkhazian', 'example' => 'Аҧсуа' ),
			'ae' => array( 'name' => 'Avestan', 'example' => 'Avesta' ),
			'af' => array( 'name' => 'Afrikaans', 'example' => 'Hallo' ),
			'ak' => array( 'name' => 'Akan', 'example' => 'Akwaaba' ),
			'am' => array( 'name' => 'Amharic', 'example' => 'ሰላም' ),
			'an' => array( 'name' => 'Aragonese', 'example' => 'Ola' ),
			'ar' => array( 'name' => 'Arabic', 'example' => 'مرحبا' ),
			'as' => array( 'name' => 'Assamese', 'example' => 'নমস্কাৰ' ),
			'av' => array( 'name' => 'Avaric', 'example' => 'Салам' ),
			'ay' => array( 'name' => 'Aymara', 'example' => 'Kamisaraki' ),
			'az' => array( 'name' => 'Azerbaijani', 'example' => 'Salam' ),
			'ba' => array( 'name' => 'Bashkir', 'example' => 'Сәләм' ),
			'be' => array( 'name' => 'Belarusian', 'example' => 'Прывітанне' ),
			'bg' => array( 'name' => 'Bulgarian', 'example' => 'Здравей' ),
			'bh' => array( 'name' => 'Bihari languages', 'example' => 'नमस्ते' ),
			'bi' => array( 'name' => 'Bislama', 'example' => 'Halo' ),
			'bm' => array( 'name' => 'Bambara', 'example' => 'Aw ni ce' ),
			'bn' => array( 'name' => 'Bengali', 'example' => 'নমস্কার' ),
			'bo' => array( 'name' => 'Tibetan', 'example' => 'བཀྲ་ཤིས་བདེ་ལེགས' ),
			'br' => array( 'name' => 'Breton', 'example' => 'Demat' ),
			'bs' => array( 'name' => 'Bosnian', 'example' => 'Zdravo' ),
			'ca' => array( 'name' => 'Catalan', 'example' => 'Hola' ),
			'ce' => array( 'name' => 'Chechen', 'example' => 'Марша' ),
			'ch' => array( 'name' => 'Chamorro', 'example' => 'Håfa adai' ),
			'co' => array( 'name' => 'Corsican', 'example' => 'Bonghjornu' ),
			'cr' => array( 'name' => 'Cree', 'example' => 'ᑕᓂᓯ' ),
			'cs' => array( 'name' => 'Czech', 'example' => 'Ahoj' ),
			'cu' => array( 'name' => 'Church Slavic', 'example' => 'Слава' ),
			'cv' => array( 'name' => 'Chuvash', 'example' => 'Салам' ),
			'cy' => array( 'name' => 'Welsh', 'example' => 'Helo' ),
			'da' => array( 'name' => 'Danish', 'example' => 'Hej' ),
			'de' => array( 'name' => 'German', 'example' => 'Hallo' ),
			'dv' => array( 'name' => 'Divehi', 'example' => 'އައްސަލާމު' ),
			'dz' => array( 'name' => 'Dzongkha', 'example' => 'ཀུ་ཟུ་ཟང་པོ' ),
			'ee' => array( 'name' => 'Ewe', 'example' => 'Woezo' ),
			'el' => array( 'name' => 'Greek', 'example' => 'Γεια' ),
			'en' => array( 'name' => 'English', 'example' => 'Hello' ),
			'eo' => array( 'name' => 'Esperanto', 'example' => 'Saluton' ),
			'es' => array( 'name' => 'Spanish', 'example' => 'Hola' ),
			'et' => array( 'name' => 'Estonian', 'example' => 'Tere' ),
			'eu' => array( 'name' => 'Basque', 'example' => 'Kaixo' ),
			'fa' => array( 'name' => 'Persian', 'example' => 'سلام' ),
			'ff' => array( 'name' => 'Fulah', 'example' => 'Jam' ),
			'fi' => array( 'name' => 'Finnish', 'example' => 'Hei' ),
			'fj' => array( 'name' => 'Fijian', 'example' => 'Bula' ),
			'fo' => array( 'name' => 'Faroese', 'example' => 'Halló' ),
			'fr' => array( 'name' => 'French', 'example' => 'Bonjour' ),
			'fy' => array( 'name' => 'Western Frisian', 'example' => 'Hallo' ),
			'ga' => array( 'name' => 'Irish', 'example' => 'Dia dhuit' ),
			'gd' => array( 'name' => 'Scottish Gaelic', 'example' => 'Halò' ),
			'gl' => array( 'name' => 'Galician', 'example' => 'Ola' ),
			'gn' => array( 'name' => 'Guarani', 'example' => 'Mba\'eichapa' ),
			'gu' => array( 'name' => 'Gujarati', 'example' => 'નમસ્તે' ),
			'gv' => array( 'name' => 'Manx', 'example' => 'Moghrey mie' ),
			'ha' => array( 'name' => 'Hausa', 'example' => 'Sannu' ),
			'he' => array( 'name' => 'Hebrew', 'example' => 'שלום' ),
			'hi' => array( 'name' => 'Hindi', 'example' => 'नमस्ते' ),
			'ho' => array( 'name' => 'Hiri Motu', 'example' => 'Halo' ),
			'hr' => array( 'name' => 'Croatian', 'example' => 'Bok' ),
			'ht' => array( 'name' => 'Haitian Creole', 'example' => 'Bonjou' ),
			'hu' => array( 'name' => 'Hungarian', 'example' => 'Szia' ),
			'hy' => array( 'name' => 'Armenian', 'example' => 'Բարև' ),
			'hz' => array( 'name' => 'Herero', 'example' => 'Tjike' ),
			'ia' => array( 'name' => 'Interlingua', 'example' => 'Salute' ),
			'id' => array( 'name' => 'Indonesian', 'example' => 'Halo' ),
			'ie' => array( 'name' => 'Interlingue', 'example' => 'Salute' ),
			'ig' => array( 'name' => 'Igbo', 'example' => 'Ndewo' ),
			'ii' => array( 'name' => 'Sichuan Yi', 'example' => 'ꆈꌠꉙ' ),
			'ik' => array( 'name' => 'Inupiaq', 'example' => 'Haluu' ),
			'io' => array( 'name' => 'Ido', 'example' => 'Saluto' ),
			'is' => array( 'name' => 'Icelandic', 'example' => 'Halló' ),
			'it' => array( 'name' => 'Italian', 'example' => 'Ciao' ),
			'iu' => array( 'name' => 'Inuktitut', 'example' => 'ᐊᐃ' ),
			'ja' => array( 'name' => 'Japanese', 'example' => 'こんにちは' ),
			'jv' => array( 'name' => 'Javanese', 'example' => 'Halo' ),
			'ka' => array( 'name' => 'Georgian', 'example' => 'გამარჯობა' ),
			'kg' => array( 'name' => 'Kongo', 'example' => 'Mbote' ),
			'ki' => array( 'name' => 'Kikuyu', 'example' => 'Wimwĩga' ),
			'kj' => array( 'name' => 'Kuanyama', 'example' => 'Wa uhala po' ),
			'kk' => array( 'name' => 'Kazakh', 'example' => 'Сәлем' ),
			'kl' => array( 'name' => 'Kalaallisut', 'example' => 'Aluu' ),
			'km' => array( 'name' => 'Khmer', 'example' => 'សួស្តី' ),
			'kn' => array( 'name' => 'Kannada', 'example' => 'ನಮಸ್ಕಾರ' ),
			'ko' => array( 'name' => 'Korean', 'example' => '안녕하세요' ),
			'kr' => array( 'name' => 'Kanuri', 'example' => 'Sannu' ),
			'ks' => array( 'name' => 'Kashmiri', 'example' => 'آداب' ),
			'ku' => array( 'name' => 'Kurdish', 'example' => 'Slav' ),
			'kv' => array( 'name' => 'Komi', 'example' => 'Видза олан' ),
			'kw' => array( 'name' => 'Cornish', 'example' => 'Dydh da' ),
			'ky' => array( 'name' => 'Kyrgyz', 'example' => 'Салам' ),
			'la' => array( 'name' => 'Latin', 'example' => 'Salve' ),
			'lb' => array( 'name' => 'Luxembourgish', 'example' => 'Moien' ),
			'lg' => array( 'name' => 'Ganda', 'example' => 'Gyebale' ),
			'li' => array( 'name' => 'Limburgish', 'example' => 'Hallo' ),
			'ln' => array( 'name' => 'Lingala', 'example' => 'Mbote' ),
			'lo' => array( 'name' => 'Lao', 'example' => 'ສະບາຍດີ' ),
			'lt' => array( 'name' => 'Lithuanian', 'example' => 'Labas' ),
			'lu' => array( 'name' => 'Luba-Katanga', 'example' => 'Moyo' ),
			'lv' => array( 'name' => 'Latvian', 'example' => 'Sveiki' ),
			'mg' => array( 'name' => 'Malagasy', 'example' => 'Salama' ),
			'mh' => array( 'name' => 'Marshallese', 'example' => 'Iakwe' ),
			'mi' => array( 'name' => 'Maori', 'example' => 'Kia ora' ),
			'mk' => array( 'name' => 'Macedonian', 'example' => 'Здраво' ),
			'ml' => array( 'name' => 'Malayalam', 'example' => 'നമസ്കാരം' ),
			'mn' => array( 'name' => 'Mongolian', 'example' => 'Сайн байна уу' ),
			'mr' => array( 'name' => 'Marathi', 'example' => 'नमस्कार' ),
			'ms' => array( 'name' => 'Malay', 'example' => 'Halo' ),
			'mt' => array( 'name' => 'Maltese', 'example' => 'Bongu' ),
			'my' => array( 'name' => 'Burmese', 'example' => 'မင်္ဂလာပါ' ),
			'na' => array( 'name' => 'Nauru', 'example' => 'Ekamawir omo' ),
			'nb' => array( 'name' => 'Norwegian Bokmal', 'example' => 'Hei' ),
			'nd' => array( 'name' => 'North Ndebele', 'example' => 'Sawubona' ),
			'ne' => array( 'name' => 'Nepali', 'example' => 'नमस्ते' ),
			'ng' => array( 'name' => 'Ndonga', 'example' => 'Wa lalapo' ),
			'nl' => array( 'name' => 'Dutch', 'example' => 'Hallo' ),
			'nn' => array( 'name' => 'Norwegian Nynorsk', 'example' => 'Hei' ),
			'no' => array( 'name' => 'Norwegian', 'example' => 'Hei' ),
			'nr' => array( 'name' => 'South Ndebele', 'example' => 'Lotjhani' ),
			'nv' => array( 'name' => 'Navajo', 'example' => 'Ya\'at\'eeh' ),
			'ny' => array( 'name' => 'Chichewa', 'example' => 'Moni' ),
			'oc' => array( 'name' => 'Occitan', 'example' => 'Adieu' ),
			'oj' => array( 'name' => 'Ojibwa', 'example' => 'Boozhoo' ),
			'om' => array( 'name' => 'Oromo', 'example' => 'Akkam' ),
			'or' => array( 'name' => 'Odia', 'example' => 'ନମସ୍କାର' ),
			'os' => array( 'name' => 'Ossetian', 'example' => 'Салам' ),
			'pa' => array( 'name' => 'Punjabi', 'example' => 'ਸਤ ਸ੍ਰੀ ਅਕਾਲ' ),
			'pi' => array( 'name' => 'Pali', 'example' => 'नमो' ),
			'pl' => array( 'name' => 'Polish', 'example' => 'Czesc' ),
			'ps' => array( 'name' => 'Pashto', 'example' => 'سلام' ),
			'pt' => array( 'name' => 'Portuguese', 'example' => 'Ola' ),
			'qu' => array( 'name' => 'Quechua', 'example' => 'Rimaykullayki' ),
			'rm' => array( 'name' => 'Romansh', 'example' => 'Allegra' ),
			'rn' => array( 'name' => 'Rundi', 'example' => 'Bwakeye' ),
			'ro' => array( 'name' => 'Romanian', 'example' => 'Salut' ),
			'ru' => array( 'name' => 'Russian', 'example' => 'Привет' ),
			'rw' => array( 'name' => 'Kinyarwanda', 'example' => 'Muraho' ),
			'sa' => array( 'name' => 'Sanskrit', 'example' => 'नमस्ते' ),
			'sc' => array( 'name' => 'Sardinian', 'example' => 'Salude' ),
			'sd' => array( 'name' => 'Sindhi', 'example' => 'سلام' ),
			'se' => array( 'name' => 'Northern Sami', 'example' => 'Bures' ),
			'sg' => array( 'name' => 'Sango', 'example' => 'Balao' ),
			'si' => array( 'name' => 'Sinhala', 'example' => 'ආයුබෝවන්' ),
			'sk' => array( 'name' => 'Slovak', 'example' => 'Ahoj' ),
			'sl' => array( 'name' => 'Slovenian', 'example' => 'Zivjo' ),
			'sm' => array( 'name' => 'Samoan', 'example' => 'Talofa' ),
			'sn' => array( 'name' => 'Shona', 'example' => 'Mhoro' ),
			'so' => array( 'name' => 'Somali', 'example' => 'Salaam' ),
			'sq' => array( 'name' => 'Albanian', 'example' => 'Pershendetje' ),
			'sr' => array( 'name' => 'Serbian', 'example' => 'Здраво' ),
			'ss' => array( 'name' => 'Swati', 'example' => 'Sawubona' ),
			'st' => array( 'name' => 'Southern Sotho', 'example' => 'Dumela' ),
			'su' => array( 'name' => 'Sundanese', 'example' => 'Halo' ),
			'sv' => array( 'name' => 'Swedish', 'example' => 'Hej' ),
			'sw' => array( 'name' => 'Swahili', 'example' => 'Habari' ),
			'ta' => array( 'name' => 'Tamil', 'example' => 'வணக்கம்' ),
			'te' => array( 'name' => 'Telugu', 'example' => 'నమస్కారం' ),
			'tg' => array( 'name' => 'Tajik', 'example' => 'Салом' ),
			'th' => array( 'name' => 'Thai', 'example' => 'สวัสดี' ),
			'ti' => array( 'name' => 'Tigrinya', 'example' => 'ሰላም' ),
			'tk' => array( 'name' => 'Turkmen', 'example' => 'Salam' ),
			'tl' => array( 'name' => 'Tagalog', 'example' => 'Kamusta' ),
			'tn' => array( 'name' => 'Tswana', 'example' => 'Dumela' ),
			'to' => array( 'name' => 'Tonga', 'example' => 'Malo e lelei' ),
			'tr' => array( 'name' => 'Turkish', 'example' => 'Merhaba' ),
			'ts' => array( 'name' => 'Tsonga', 'example' => 'Avuxeni' ),
			'tt' => array( 'name' => 'Tatar', 'example' => 'Сәлам' ),
			'tw' => array( 'name' => 'Twi', 'example' => 'Akwaaba' ),
			'ty' => array( 'name' => 'Tahitian', 'example' => 'Ia ora na' ),
			'ug' => array( 'name' => 'Uyghur', 'example' => 'ياخشىمۇسىز' ),
			'uk' => array( 'name' => 'Ukrainian', 'example' => 'Привіт' ),
			'ur' => array( 'name' => 'Urdu', 'example' => 'سلام' ),
			'uz' => array( 'name' => 'Uzbek', 'example' => 'Salom' ),
			've' => array( 'name' => 'Venda', 'example' => 'Ndaa' ),
			'vi' => array( 'name' => 'Vietnamese', 'example' => 'Xin chao' ),
			'vo' => array( 'name' => 'Volapuk', 'example' => 'Glidis' ),
			'wa' => array( 'name' => 'Walloon', 'example' => 'Bondjou' ),
			'wo' => array( 'name' => 'Wolof', 'example' => 'Na nga def' ),
			'xh' => array( 'name' => 'Xhosa', 'example' => 'Molo' ),
			'yi' => array( 'name' => 'Yiddish', 'example' => 'העלא' ),
			'yo' => array( 'name' => 'Yoruba', 'example' => 'Bawo' ),
			'za' => array( 'name' => 'Zhuang', 'example' => 'Vahcuengh' ),
			'zh' => array( 'name' => 'Chinese', 'example' => '你好' ),
			'zu' => array( 'name' => 'Zulu', 'example' => 'Sawubona' ),
		);
	}

	/**
	 * Sanitize comma/newline separated language code list.
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	private function sanitize_code_list( $raw ) {
		$raw = sanitize_text_field( wp_unslash( $raw ) );
		return array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\r\n,]+/', $raw ) ) ) );
	}

	/**
	 * Sanitize textarea lines.
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	private function sanitize_text_lines( $raw ) {
		$raw = sanitize_textarea_field( wp_unslash( $raw ) );
		return array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n]+/', $raw ) ) ) );
	}

	/**
	 * Sanitize editable keyword category lists.
	 *
	 * @param mixed $raw Raw category term map.
	 * @param array $valid_categories Allowed category keys.
	 * @return array
	 */
	private function sanitize_keyword_category_terms( $raw, array $valid_categories ) {
		$terms = array();
		$raw   = is_array( $raw ) ? wp_unslash( $raw ) : array();

		foreach ( $valid_categories as $category ) {
			$value = $raw[ $category ] ?? '';
			$terms[ $category ] = $this->sanitize_text_lines( $value );
		}

		return $terms;
	}

	/**
	 * Sanitize score fields.
	 *
	 * @param mixed $raw Raw score.
	 * @return int
	 */
	private function sanitize_score( $raw ) {
		return max( 0, min( 100, absint( $raw ) ) );
	}

	/**
	 * Sync saved Spam Rules to SRK Cloud.
	 *
	 * @return array|WP_Error|null
	 */
	private function sync_rules_to_engine() {
		if ( class_exists( 'SRK_Spam_Monitor_SERP_Provider' ) ) {
			return ( new SRK_Spam_Monitor_SERP_Provider() )->sync_rules();
		}

		return null;
	}
}
