/**
 * Review Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
( function( $ ) {
	'use strict';

	/**
	 * Review Schema for SEO Repair Kit
	 *
	 * @since 2.1.0
	 */
	class SRK_ReviewSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Review Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'review';
		}

		/**
		 * Get specific fields for review schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'author', name: 'Review Author' },
				{ id: 'itemReviewed', name: 'Item Reviewed' },
				{ id: 'reviewRating', name: 'Rating' },
				{ id: 'reviewBody', name: 'Review Body' },
				{ id: 'datePublished', name: 'Date Published' }
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
			jsonData['@type'] = 'Review';

			// Process each mapped field dynamically.
			for ( const [field, value] of Object.entries( metaMap ) ) {
				if ( ! value ) continue;

				switch ( field ) {
					case 'author':
						jsonData.author = {
							'@type': 'Person',
							'name': value
						};
						break;

					case 'itemReviewed':
						let name = Array.isArray( value ) ? value.join( ', ' ) : value;
						jsonData.itemReviewed = {
							'@type': 'Thing',
							'name': name
						};
						break;

					case 'reviewRating':
						let ratingValue = value;
						if ( typeof ratingValue === 'string' ) {
							ratingValue = parseFloat( ratingValue );
						}

						jsonData.reviewRating = {
							'@type': 'Rating',
							'ratingValue': ratingValue,
							'bestRating': '5',
							'worstRating': '1'
						};
						break;

					case 'reviewBody':
						jsonData.reviewBody = value;
						break;

					case 'datePublished':
						let date = new Date( value );
						if ( ! isNaN( date.getTime() ) ) {
							jsonData.datePublished = date.toISOString();
						} else {
							jsonData.datePublished = value;
						}
						break;

					default:
						// For any other fields, add them directly.
						jsonData[field] = value;
						break;
				}
			}
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) window.SRK = {};
	window.SRK.SRK_ReviewSchema = SRK_ReviewSchema;

} )( jQuery );