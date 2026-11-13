/**
 * Coming Soon Schemas for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

( function( $ ) {
	'use strict';

	/**
	 * HowTo Schema class.
	 *
	 * @since 2.1.0
	 */
	class HowToSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize HowTo Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'how_to';
		}

		/**
		 * Handle schema selection.
		 *
		 * @since 2.1.0
		 */
		handleSelection() {
			this.showComingSoon( 'HowTo' );
		}

		/**
		 * Show coming soon message.
		 *
		 * @since 2.1.0
		 * @param {string} schemaName Schema name.
		 */
		showComingSoon( schemaName ) {
			const container = $( '#srk-schema-config-wrapper' );
			container.html( `
				<div class="srk-schema-config-card">
					<h3>${ schemaName } Configuration</h3>
					<div class="srk-coming-soon">
						<div class="srk-coming-soon-content">
							<h4>Coming Soon</h4>
							<p>This schema type is currently under development and will be available in the next update.</p>
							<div class="srk-coming-soon-icon">🚧</div>
						</div>
					</div>
				</div>
			` );

			// Hide JSON preview.
			$( '#srk-json-preview-container' ).hide();
			$( '#srk-json-preview-loader' ).hide();
		}
	}

	/**
	 * VideoObject Schema class.
	 *
	 * @since 2.1.0
	 */
	class VideoObjectSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize VideoObject Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'video_object';
		}

		/**
		 * Handle schema selection.
		 *
		 * @since 2.1.0
		 */
		handleSelection() {
			this.showComingSoon( 'VideoObject' );
		}

		/**
		 * Show coming soon message.
		 *
		 * @since 2.1.0
		 * @param {string} schemaName Schema name.
		 */
		showComingSoon( schemaName ) {
			const container = $( '#srk-schema-config-wrapper' );
			container.html( `
				<div class="srk-schema-config-card">
					<h3>${ schemaName } Configuration</h3>
					<div class="srk-coming-soon">
						<div class="srk-coming-soon-content">
							<h4>Coming Soon</h4>
							<p>This schema type is currently under development and will be available in the next update.</p>
							<div class="srk-coming-soon-icon">🚧</div>
						</div>
					</div>
				</div>
			` );

			// Hide JSON preview.
			$( '#srk-json-preview-container' ).hide();
			$( '#srk-json-preview-loader' ).hide();
		}
	}

	/**
	 * Reservation Schema class.
	 *
	 * @since 2.1.0
	 */
	class ReservationSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Reservation Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'reservation';
		}

		/**
		 * Handle schema selection.
		 *
		 * @since 2.1.0
		 */
		handleSelection() {
			this.showComingSoon( 'Reservation' );
		}

		/**
		 * Show coming soon message.
		 *
		 * @since 2.1.0
		 * @param {string} schemaName Schema name.
		 */
		showComingSoon( schemaName ) {
			const container = $( '#srk-schema-config-wrapper' );
			container.html( `
				<div class="srk-schema-config-card">
					<h3>${ schemaName } Configuration</h3>
					<div class="srk-coming-soon">
						<div class="srk-coming-soon-content">
							<h4>Coming Soon</h4>
							<p>This schema type is currently under development and will be available in the next update.</p>
							<div class="srk-coming-soon-icon">🚧</div>
						</div>
					</div>
				</div>
			` );

			// Hide JSON preview.
			$( '#srk-json-preview-container' ).hide();
			$( '#srk-json-preview-loader' ).hide();
		}
	}

	/**
	 * MedicalWebPage Schema class.
	 *
	 * @since 2.1.0
	 */
	class MedicalWebPageSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize MedicalWebPage Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'medical_web_page';
		}

		/**
		 * Handle schema selection.
		 *
		 * @since 2.1.0
		 */
		handleSelection() {
			this.showComingSoon( 'MedicalWebPage' );
		}

		/**
		 * Show coming soon message.
		 *
		 * @since 2.1.0
		 * @param {string} schemaName Schema name.
		 */
		showComingSoon( schemaName ) {
			const container = $( '#srk-schema-config-wrapper' );
			container.html( `
				<div class="srk-schema-config-card">
					<h3>${ schemaName } Configuration</h3>
					<div class="srk-coming-soon">
						<div class="srk-coming-soon-content">
							<h4>Coming Soon</h4>
							<p>This schema type is currently under development and will be available in the next update.</p>
							<div class="srk-coming-soon-icon">🚧</div>
						</div>
					</div>
				</div>
			` );

			// Hide JSON preview.
			$( '#srk-json-preview-container' ).hide();
			$( '#srk-json-preview-loader' ).hide();
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.HowToSchema = HowToSchema;
	window.SRK.VideoObjectSchema = VideoObjectSchema;
	window.SRK.ReservationSchema = ReservationSchema;
	window.SRK.MedicalWebPageSchema = MedicalWebPageSchema;

} )( jQuery );
