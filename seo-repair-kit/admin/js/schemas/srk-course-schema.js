/**
 * Course Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

( function( $ ) {
	'use strict';

	/**
	 * Course Schema class.
	 *
	 * @since 2.1.0
	 */
	class SRK_CourseSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Course Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'course';
		}

		/**
		 * Get specific fields for course schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'provider', name: 'Provider', required: true },
				{ id: 'courseLevel', name: 'Course Level' },
				{ id: 'pricingModel', name: 'Pricing Model' },
				{ id: 'enrollStudent', name: 'Enroll Student' },
				{ id: 'courseVideo', name: 'Course Video', type: 'url' },
				{ id: 'courseCode', name: 'Course Code' },
			];
		}

		/**
		 * Override loadPostTypeFields to handle provider as a global field (like publisher)
		 *
		 * @since 2.1.0
		 * @param {Object} fieldData Field data object.
		 * @param {jQuery} $metaPlaceholder Placeholder element.
		 */
		loadPostTypeFields( fieldData, $metaPlaceholder ) {
			const { post_defaults, post_meta, user_meta, taxonomies } = fieldData;
			const schemaFields = this.getFields();

			let tableHtml = `
				<div class="srk-meta-mapping">
					<h4 class="srk-meta-heading">Field Mappings</h4>
					<table class="srk-schema-meta-table">
						<thead>
						</thead>
						<tbody>`;

			schemaFields.forEach((field) => {
				const isProvider = field.id === 'provider';
				
				tableHtml += `
					<tr class="srk-meta-row">
						<td>
							<input type="checkbox" class="srk-field-enable" data-field="${field.id}" ${field.required ? 'checked' : ''}>
						</td>
						<td class="srk-schema-meta-label">
							${field.name} ${field.required ? '<span class="srk-required">*</span>' : ''}
						</td>
						<td>
							<div class="srk-schema-field-mapping">`;
				
				if (isProvider) {
					// Provider uses global selector (site/custom)
					tableHtml += `
								<select class="srk-global-selector srk-select" data-field="${field.id}">
									<option value="">Select Source</option>
									<option value="site">Site Information</option>
									<option value="custom">Custom Value</option>
								</select>
								<div class="srk-value-container">
									<input type="text" class="srk-group-values srk-form-input"
										name="meta_map[${field.id}]" data-field="${field.id}"
										placeholder="Enter value" disabled>
								</div>`;
				} else {
					// Other fields use standard group selector
					tableHtml += `
								<select class="srk-group-selector srk-select" data-field="${field.id}">
									<option value="post">Post Default</option>
									<option value="meta">Post Meta</option>
									<option value="user">User Meta</option>
									<option value="tax">Taxonomy</option>
								</select>
								<select class="srk-group-values srk-select" name="meta_map[${field.id}]" data-field="${field.id}">
									<option value="">Select Field</option>
								</select>`;
				}
				
				tableHtml += `
							</div>
						</td>
					</tr>
				`;
			});

			tableHtml += `</tbody></table></div>`;
			$metaPlaceholder.html(tableHtml);

			// Load field options for non-provider fields
			schemaFields.forEach((field) => {
				if (field.id !== 'provider') {
					this.manager.loadFieldOptions(
						field.id,
						'post',
						post_defaults,
						post_meta,
						user_meta,
						taxonomies
					);
				}
			});

			this.initFieldEnableToggle();
			
			// Auto-enable and lock required fields with mappings
			setTimeout(() => {
				this.autoEnableRequiredFields(this.schemaKey);
			}, 800);
			
			// Restore checkbox states after fields are loaded
			if (this.restoreCheckboxStates) {
				this.restoreCheckboxStates();
			}
			
			this.manager.updateJsonPreview();

			if (typeof SRK_LOCK !== 'undefined' && (SRK_LOCK.enabled || !SRK_LOCK.can_map)) {
				$('#srk-schema-config-wrapper').css({ opacity: 0.5, pointerEvents: 'none' });
				if (typeof showLockNotice === 'function') {
					showLockNotice();
				}
			}
		}

		/**
		 * Apply schema specific preview.
		 *
		 * @since 2.1.0
		 * @param {Object} jsonData JSON data object.
		 * @param {Object} metaMap  Meta mapping object.
		 */
		applySchemaSpecificPreview( jsonData, metaMap ) {
			jsonData[ '@type' ] = 'Course';
			if ( jsonData.courseLevel ) {
				jsonData.educationalLevel = jsonData.courseLevel;
			}
			if ( jsonData.pricingModel ) {
				jsonData.offers = {
					'@type': 'Offer',
					price: jsonData.pricingModel,
					priceCurrency: 'USD',
					url: window.location.href,
					availability: 'https://schema.org/InStock',
					validFrom: new Date().toISOString(),
				};
			}
			if ( jsonData.enrollStudent ) {
				jsonData.numberOfCredits = parseInt( jsonData.enrollStudent, 10 ) || 0;
			}
			if ( jsonData.courseVideo ) {
				jsonData.video = {
					'@type': 'VideoObject',
					url: jsonData.courseVideo,
					name: document.title || 'Course Video',
					thumbnailUrl: '',
					uploadDate: new Date().toISOString(),
				};
			}
			if ( jsonData.courseCode ) {
				jsonData.courseCode = jsonData.courseCode;
			}
		}
	}

	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.SRK_CourseSchema = SRK_CourseSchema;

} )( jQuery );