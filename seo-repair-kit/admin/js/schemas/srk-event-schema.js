/**
 * Event Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

( function( $ ) {
	'use strict';

	// Event Schema.
	/**
	 * Event Schema class.
	 *
	 * @since 2.1.0
	 */
	class SRK_EventSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Event Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'event';
		}

		/**
		 * Get specific fields for event schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'startDate', name: 'Start Date', required: true },
				{ id: 'location', name: 'Location', type: 'object', required: true },
				{ id: 'endDate', name: 'End Date' },
				{ id: 'performer', name: 'Performer', type: 'object' },
				{ id: 'organizer', name: 'Organizer', type: 'object' },
				{ id: 'offers', name: 'Offers', type: 'object' },
				{ id: 'cost', name: 'Cost', type: 'object' },
				{ id: 'eventStatus', name: 'Event Status' },
			];
		}

		/**
		 * Apply schema specific preview.
		 *
		 * @since 2.1.0
		 * @param {Object} jsonData JSON data object.
		 * @param {Object} metaMap  Meta mapping object.
		 */
		applySchemaSpecificPreview( jsonData, metaMap ) {
			jsonData[ '@type' ] = 'Event';

			// Handle location as Place object with PostalAddress structure (Google expects this).
			if ( jsonData.location && typeof jsonData.location === 'string' ) {
				// Clean up any meta: prefix or ID-looking values for preview.
				let locationValue = jsonData.location;
				if ( locationValue.indexOf( 'meta:' ) === 0 ) {
					locationValue = locationValue.replace( 'meta:', '' );
				}
				
				jsonData.location = {
					'@type': 'Place',
					name: locationValue,
					address: {
						'@type': 'PostalAddress',
						streetAddress: locationValue,
					},
				};
			}

			// Handle performer as Person or Organization.
			if ( jsonData.performer && typeof jsonData.performer === 'string' ) {
				// Clean up any meta: prefix or ID-looking values for preview.
				let performerValue = jsonData.performer;
				if ( performerValue.indexOf( 'meta:' ) === 0 ) {
					performerValue = performerValue.replace( 'meta:', '' );
				}
				
				// Determine if it's an organization based on common indicators.
				const isOrg = /inc\.?|llc\.?|ltd\.?|corp\.?|company|association|foundation|group/i.test( performerValue );
				
				jsonData.performer = {
					'@type': isOrg ? 'Organization' : 'Person',
					name: performerValue,
				};
			}

			// Handle organizer as Organization.
			if ( jsonData.organizer && typeof jsonData.organizer === 'string' ) {
				let organizerValue = jsonData.organizer;
				if ( organizerValue.indexOf( 'meta:' ) === 0 ) {
					organizerValue = organizerValue.replace( 'meta:', '' );
				}
				
				jsonData.organizer = {
					'@type': 'Organization',
					name: organizerValue,
				};
			}

			// Handle offers as Offer object.
			if ( jsonData.offers && typeof jsonData.offers === 'string' ) {
				// Try to extract price and currency from the string.
				let price = '0';
				let currency = 'USD';
				
				const priceMatch = jsonData.offers.match( /(\d+(?:\.\d+)?)/ );
				if ( priceMatch ) {
					price = priceMatch[1];
				}
				
				if ( /\$|USD/i.test( jsonData.offers ) ) {
					currency = 'USD';
				} else if ( /€|EUR/i.test( jsonData.offers ) ) {
					currency = 'EUR';
				} else if ( /£|GBP/i.test( jsonData.offers ) ) {
					currency = 'GBP';
				}
				
				jsonData.offers = {
					'@type': 'Offer',
					name: 'Ticket',
					price: price,
					priceCurrency: currency,
					url: window.location.href || '#',
					availability: 'https://schema.org/InStock',
					validFrom: new Date().toISOString(),
				};
			}

			// Handle eventStatus: ensure it's a valid schema.org URL if present.
			if ( jsonData.eventStatus && typeof jsonData.eventStatus === 'string' ) {
				const statusLower = jsonData.eventStatus.toLowerCase().trim();
				const statusMap = {
					'scheduled': 'https://schema.org/EventScheduled',
					'cancelled': 'https://schema.org/EventCancelled',
					'postponed': 'https://schema.org/EventPostponed',
					'rescheduled': 'https://schema.org/EventRescheduled',
				};
				
				if ( statusMap[ statusLower ] ) {
					jsonData.eventStatus = statusMap[ statusLower ];
				} else if ( ! jsonData.eventStatus.match( /^https?:\/\// ) ) {
					// If it's not a URL, default to EventScheduled.
					jsonData.eventStatus = 'https://schema.org/EventScheduled';
				}
			}

			// Ensure dates are in ISO 8601 format (remove custom: prefix if present).
			if ( jsonData.startDate && typeof jsonData.startDate === 'string' ) {
				if ( jsonData.startDate.indexOf( 'custom:' ) === 0 ) {
					jsonData.startDate = jsonData.startDate.replace( 'custom:', '' );
				}
				// If it's just YYYY-MM-DD, append time for ISO compliance.
				if ( /^\d{4}-\d{2}-\d{2}$/.test( jsonData.startDate ) ) {
					jsonData.startDate = jsonData.startDate + 'T00:00:00Z';
				}
			}
			
			if ( jsonData.endDate && typeof jsonData.endDate === 'string' ) {
				if ( jsonData.endDate.indexOf( 'custom:' ) === 0 ) {
					jsonData.endDate = jsonData.endDate.replace( 'custom:', '' );
				}
				if ( /^\d{4}-\d{2}-\d{2}$/.test( jsonData.endDate ) ) {
					jsonData.endDate = jsonData.endDate + 'T23:59:59Z';
				}
			}

			// Ensure URL and image are valid absolute URLs (remove if invalid for preview clarity).
			if ( jsonData.url && ( typeof jsonData.url !== 'string' || ! jsonData.url.match( /^https?:\/\// ) ) ) {
				// If URL is invalid, try to use current page URL or remove it.
				jsonData.url = window.location.href || undefined;
			}
			
			if ( jsonData.image && ( typeof jsonData.image !== 'string' || ! jsonData.image.match( /^https?:\/\// ) ) ) {
				// Remove invalid image URL from preview.
				delete jsonData.image;
			}
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.SRK_EventSchema = SRK_EventSchema;

} )( jQuery );