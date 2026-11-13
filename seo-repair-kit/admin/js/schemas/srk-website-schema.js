/**
 * Website Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
( function( $ ) {
	'use strict';

	/**
	 * Website Schema for SEO Repair Kit
	 *
	 * @since 2.1.0
	 */
	class SRK_WebsiteSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Website Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'website';
		}

		/**
		 * Get specific fields for website schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				// Required by schema.org / Google for WebSite.
				{ id: 'name', name: 'Website Name', required: true },
				{ id: 'url', name: 'Website URL', required: true },
				// Recommended by Google.
				{ id: 'description', name: 'Description' },
				// Optional: organization that publishes the site.
				{ id: 'publisher', name: 'Publisher (Organization Name)' },
			];
		}

		/**
		 * Apply schema-specific preview transformations.
		 *
		 * @since 2.1.0
		 * @param {Object} jsonData JSON data object.
		 * @param {Object} metaMap Meta mapping object.
		 */
		applySchemaSpecificPreview( jsonData, metaMap ) {
			jsonData['@type'] = 'WebSite';

			// Publisher as an Organization object.
			if ( jsonData.publisher && typeof jsonData.publisher === 'string' ) {
				jsonData.publisher = {
					'@type': 'Organization',
					'name': jsonData.publisher,
				};
			}
		}

		/**
		 * Handle website-specific pre-filling.
		 *
		 * @since 2.1.0
		 * @param {Object} savedData Saved data object.
		 */
		preFillFields( savedData ) {
			if ( savedData.meta_map ) {
				// First call parent method.
				super.preFillFields( savedData );

				// Website-specific pre-filling logic.
				setTimeout( () => {
					this.manager.updateJsonPreview();
				}, 200 );
			}
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.SRK_WebsiteSchema = SRK_WebsiteSchema;

} )( jQuery );