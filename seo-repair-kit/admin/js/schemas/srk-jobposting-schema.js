/**
 * Job Posting Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

( function( $ ) {
	'use strict';

	/**
	 * Job Posting Schema class.
	 *
	 * @since 2.1.0
	 */
	class SRK_JobPostingSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Job Posting Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'job_posting';
		}

		/**
		 * Override loadPostTypeFields to handle validThrough as a custom date field
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
				const isValidThrough = field.id === 'validThrough';
				
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
				
				if (isValidThrough) {
					// ValidThrough uses a simple date input (custom value)
					tableHtml += `
								<input type="date" 
									class="srk-group-values srk-form-input" 
									name="meta_map[${field.id}]" 
									data-field="${field.id}"
									placeholder="Enter expiry date"
									style="width: 100%; padding: 8px 12px;">
								<input type="hidden" class="srk-group-selector" value="custom" data-field="${field.id}">`;
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

			// Load field options for non-validThrough fields
			schemaFields.forEach((field) => {
				if (field.id !== 'validThrough') {
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
		 * Get specific fields for job posting schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'title', name: 'Job Title', required: true },
				{ id: 'datePosted', name: 'Date Posted', required: true },
				{ id: 'validThrough', name: 'Valid Through', required: true },
				{ id: 'description', name: 'Job Description' },
				{ id: 'image', name: 'Job Image' },
				{ id: 'employmentType', name: 'Employment Type' },
				{ id: 'hiringOrganization', name: 'Hiring Organization', type: 'object' },
				{ id: 'jobLocation', name: 'Job Location', type: 'object' },
				{ id: 'baseSalary', name: 'Base Salary', type: 'object' },
				{ id: 'educationRequirements', name: 'Education Requirements' },
				{ id: 'experienceRequirements', name: 'Experience Requirements' },
				{ id: 'skills', name: 'Skills' },
				{ id: 'workHours', name: 'Work Hours' },
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
			jsonData[ '@type' ] = 'JobPosting';

			// ✅ Job Title normalize.
			if ( jsonData.title && typeof jsonData.title === 'string' ) {
				jsonData.title = jsonData.title;
			}

			// ✅ Job Description normalize.
			if ( jsonData.description && typeof jsonData.description === 'string' ) {
				jsonData.description = jsonData.description;
			}

			// ✅ Job Image normalize.
			if ( jsonData.image && typeof jsonData.image === 'string' ) {
				jsonData.image = jsonData.image;
			}

			// ✅ Hiring Organization.
			if ( jsonData.hiringOrganization && typeof jsonData.hiringOrganization === 'string' ) {
				jsonData.hiringOrganization = {
					'@type': 'Organization',
					name: jsonData.hiringOrganization,
				};
			}

			// ✅ Job Location: wrap into Place + PostalAddress for Google.
			if ( jsonData.jobLocation && typeof jsonData.jobLocation === 'string' ) {
				jsonData.jobLocation = {
					'@type': 'Place',
					address: {
						'@type': 'PostalAddress',
						streetAddress: jsonData.jobLocation,
					},
				};
			}

			// ✅ Base Salary (basic preview object).
			if ( jsonData.baseSalary && typeof jsonData.baseSalary === 'string' ) {
				jsonData.baseSalary = {
					'@type': 'MonetaryAmount',
					currency: 'USD',
					value: {
						'@type': 'QuantitativeValue',
						minValue: '50000',
						maxValue: '70000',
						unitText: 'YEAR',
					},
				};
			}

			// ✅ Applicant Location Requirements.
			if ( jsonData.applicantLocationRequirements && typeof jsonData.applicantLocationRequirements === 'string' ) {
				jsonData.applicantLocationRequirements = {
					'@type': 'Country',
					name: jsonData.applicantLocationRequirements,
				};
			}
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.SRK_JobPostingSchema = SRK_JobPostingSchema;

} )( jQuery );