/**
 * Article Schema Classes for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

( function( $ ) {
	'use strict';

	// Base Article Schema class.
	class ArticleBaseSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Article Base Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager   Schema manager instance.
		 * @param {string} schemaKey Schema key.
		 * @param {string} schemaName Schema name.
		 */
		constructor( manager, schemaKey, schemaName ) {
			super( manager );
			this.schemaKey = schemaKey;
			this.schemaName = schemaName;
		}

		/**
		 * Article schemas should show common fields.
		 *
		 * @since 2.1.0
		 * @return {boolean} True.
		 */
		shouldShowCommonFields() {
			return true;
		}

		/**
		 * Get specific fields for article schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'headline', name: 'Headline', required: true },
				{ id: 'publisher', name: 'Publisher', required: true },
				{ id: 'author', name: 'Author', type: 'object' },
				{ id: 'datePublished', name: 'Date Published' },
				{ id: 'dateModified', name: 'Date Modified' },
				{ id: 'articleBody', name: 'Article Body' },
			];
		}

		/**
		 * Override loadPostTypeFields to handle headline and publisher fields correctly
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
				const isPublisher = field.id === 'publisher';
				
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
				
				if (isPublisher) {
					// Publisher uses global selector (site information)
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
					// Other fields use group selector (post/meta/user/tax)
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

			// Load field options for post-type fields (non-publisher fields)
			schemaFields.forEach((field) => {
				if (field.id !== 'publisher') {
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
			this.autoEnableRequiredFields(this.schemaKey);
			
			// Initialize source selector for publisher field
			if (this.initSourceSelector) {
				this.initSourceSelector();
			}
			
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
			// Article-specific preview adjustments.
			jsonData[ '@type' ] = this.schemaName;

			// Handle publisher as Organization.
			if ( jsonData.publisher && typeof jsonData.publisher === 'string' ) {
				jsonData.publisher = {
					'@type': 'Organization',
					name: jsonData.publisher,
				};
			}

			// Ensure mainEntityOfPage if not set.
			if ( ! jsonData.mainEntityOfPage && metaMap.url ) {
				jsonData.mainEntityOfPage = {
					'@type': 'WebPage',
					'@id': this.resolveValue( metaMap.url ),
				};
			}
		}

		/**
		 * Resolve value for preview.
		 *
		 * @since 2.1.0
		 * @param {string} mapping Field mapping.
		 * @return {string} Resolved value.
		 */
		resolveValue( mapping ) {
			// Simple value resolution for preview.
			if ( mapping.includes( 'site:' ) ) {
				return `[${ mapping }]`;
			} else if ( mapping.includes( 'custom:' ) ) {
				return mapping.replace( 'custom:', '' );
			} else if ( mapping.includes( ':' ) ) {
				return `[${ mapping }]`;
			}
			return mapping;
		}
	}
	
	/**
	 * Article Schema class.
	 * Specific schema classes.
	 *
	 * @since 2.1.0
	 */
	class ArticleSchema extends ArticleBaseSchema {

		/**
		 * Initialize Article Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager, 'article', 'Article' );
		}
	}

	/**
	 * Blog Posting Schema class.
	 *
	 * @since 2.1.0
	 */
	class BlogPostingSchema extends ArticleBaseSchema {

		/**
		 * Initialize Blog Posting Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager, 'blog_posting', 'BlogPosting' );
		}
	}

	/**
	 * News Article Schema class.
	 *
	 * @since 2.1.0
	 */
	class NewsArticleSchema extends ArticleBaseSchema {

		/**
		 * Initialize News Article Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager, 'news_article', 'NewsArticle' );
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.ArticleSchema = ArticleSchema;
	window.SRK.BlogPostingSchema = BlogPostingSchema;
	window.SRK.NewsArticleSchema = NewsArticleSchema;

} )( jQuery );