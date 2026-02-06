<?php
/**
 * FAQ Manager for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  FAQ
 * @since       2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FAQ Schema Manager Class
 *
 * Handles FAQ schema functionality including meta boxes, saving, and JSON-LD output.
 *
 * @since 2.1.0
 */
class SeoRepairKit_FaqManager {

	/**
	 * Constants
	 *
	 * @since2.1.0
	 */
	const META_KEY_SCHEMA_TYPE = 'srk_selected_schema_type';
	const META_KEY_FAQ_ITEMS   = 'srk_faq_items';

	/**
	 * Initialize FAQ Manager
	 *
	 * @since 2.1.0
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_faq_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_faq_meta' ) );
		add_action( 'wp_head', array( $this, 'output_faq_schema' ) );

		// AJAX.
		// NOTE: The generic "srk_get_posts_by_type" AJAX endpoint is now handled centrally
		// by SeoRepairKit_AjaxHandlers::srk_get_posts_by_type(), which supports both:
		// - legacy format (ID => title map) for dropdowns
		// - array format for posts listing.
		//
		// To avoid duplicate handlers, we no longer register this class on
		// the same action. If needed in the future, a FAQ-specific endpoint
		// can be reintroduced with a
		// different action name.
	}

	/**
	 * Add FAQ meta box
	 *
	 * @since 2.1.0
	 */
	public function add_faq_meta_box() {
		global $post;
		if ( ! $post ) {
			return;
		}

		// Custom meta key use karein.
		$schema_type = get_post_meta( $post->ID, self::META_KEY_SCHEMA_TYPE, true );
		if ( 'faq' !== $schema_type ) {
			return;
		}

		add_meta_box(
			'srk_faq_repeater',
			__( 'FAQ Questions & Answers', 'textdomain' ),
			array( $this, 'render_faq_meta_box' ),
			null,
			'normal',
			'default'
		);
	}

	/**
	 * Render FAQ meta box
	 *
	 * @since 2.1.0
	 * @param WP_Post $post Post object.
	 */
	public function render_faq_meta_box( $post ) {
		// Custom meta key use karein.
		$faq_items = get_post_meta( $post->ID, self::META_KEY_FAQ_ITEMS, true );
		if ( ! is_array( $faq_items ) ) {
			$faq_items = array();
		}
		wp_nonce_field( 'srk_save_faq_items', 'srk_faq_nonce' );
		?>
		<div id="faq-repeater">
			<?php foreach ( $faq_items as $index => $item ) : ?>
				<div class="faq-row">
					<p>
						<label>Question:</label><br>
						<input type="text" name="faq_items[<?php echo $index; ?>][question]" value="<?php echo esc_attr( $item['question'] ); ?>" style="width:100%;">
					</p>
					<p>
						<label>Answer:</label><br>
						<textarea name="faq_items[<?php echo $index; ?>][answer]" style="width:100%; height:80px;"><?php echo esc_textarea( $item['answer'] ); ?></textarea>
					</p>
					<button type="button" class="remove-faq button">Remove</button>
					<hr>
				</div>
			<?php endforeach; ?>
		</div>
		<button type="button" id="add-faq" class="button">+ Add FAQ</button>
		<?php
	}

	/**
	 * Save FAQ meta data
	 *
	 * @since 2.1.0
	 * @param int $post_id Post ID.
	 */
	public function save_faq_meta( $post_id ) {
		if ( ! isset( $_POST['srk_faq_nonce'] ) || ! wp_verify_nonce( $_POST['srk_faq_nonce'], 'srk_save_faq_items' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Custom meta key use karein.
		$schema_type = get_post_meta( $post_id, self::META_KEY_SCHEMA_TYPE, true );
		if ( 'faq' !== $schema_type ) {
			return;
		}

		if ( isset( $_POST['faq_items'] ) && is_array( $_POST['faq_items'] ) ) {
			$clean_items = array();
			foreach ( $_POST['faq_items'] as $item ) {
				if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
					$clean_items[] = array(
						'question' => sanitize_text_field( $item['question'] ),
						'answer'   => wp_kses_post( $item['answer'] ),
					);
				}
			}
			// Custom meta key use karein.
			update_post_meta( $post_id, self::META_KEY_FAQ_ITEMS, $clean_items );
		} else {
			// Custom meta key use karein.
			delete_post_meta( $post_id, self::META_KEY_FAQ_ITEMS );
		}
	}

	/**
	 * Output FAQ schema
	 *
	 * @since 2.1.0
	 */
	public function output_faq_schema() {
		// ✅ Check if license plan is expired - block schema output if expired
		if ( class_exists( 'SRK_License_Helper' ) && SRK_License_Helper::is_license_expired() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		global $post;
		// Custom meta key use karein.
		$schema_type = get_post_meta( $post->ID, self::META_KEY_SCHEMA_TYPE, true );
		if ( 'faq' !== $schema_type ) {
			return;
		}

		// Custom meta key use karein.
		$faq_items = get_post_meta( $post->ID, self::META_KEY_FAQ_ITEMS, true );
		if ( ! is_array( $faq_items ) || empty( $faq_items ) ) {
			return;
		}

		// Guard: ek hi dafa output hoga.
		static $already_done = false;
		if ( $already_done ) {
			return;
		}
		$already_done = true;

		$faq_schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array(),
		);

		foreach ( $faq_items as $item ) {
			$faq_schema['mainEntity'][] = array(
				'@type' => 'Question',
				'name'  => wp_strip_all_tags( $item['question'] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wpautop( wp_kses_post( $item['answer'] ) ),
				),
			);
		}

		// ✅ NEW: Validate required fields before output
		if ( ! class_exists( 'SeoRepairKit_SchemaValidator' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-validator.php';
		}

		// Check if schema has all required fields (FAQ requires 'mainEntity')
		if ( ! SeoRepairKit_SchemaValidator::should_output_schema( $faq_schema, 'faq' ) ) {
			// Schema is missing required fields - do not output
			return;
		}

		// ✅ NEW: Check for conflicts before output
		if ( ! class_exists( 'SeoRepairKit_SchemaConflictDetector' ) ) {
			require_once plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'includes/class-seo-repair-kit-schema-conflict-detector.php';
		}

		if ( ! SeoRepairKit_SchemaConflictDetector::can_output_schema( $faq_schema, 'faq', 'faq-manager' ) ) {
			// Schema conflicts with another schema - do not output
			return;
		}

		echo '<script type="application/ld+json">' .
			wp_json_encode( $faq_schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) .
			'</script>';
	}

	/**
	 * AJAX: Get posts/pages by post type
	 *
	 * @since 2.1.0
	 */
	public function ajax_get_posts_by_type() {
		if ( empty( $_POST['post_type'] ) ) {
			wp_send_json_error( array( 'message' => 'No post type provided' ) );
		}

		$post_type = sanitize_text_field( $_POST['post_type'] );
		$posts     = get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		$options = '<option value="">Select Item</option>';
		foreach ( $posts as $post ) {
			$options .= '<option value="' . esc_attr( $post->ID ) . '">' . esc_html( $post->post_title ) . '</option>';
		}

		wp_send_json_success( $options );
	}
}

new SeoRepairKit_FaqManager();