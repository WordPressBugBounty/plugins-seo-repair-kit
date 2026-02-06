/**
 * Schema Manager for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.1
 */

( function( $ ) {
	'use strict';

	// Create a global namespace for our schema manager.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}

	/**
	 * Main Schema Manager class.
	 *
	 * Handles schema selection, configuration, and management with dynamic
	 * field mapping, preview generation, and AJAX integration.
	 *
	 * @since 2.1.0
	 */
	class SchemaManager {

		/**
		 * Initialize the Schema Manager.
		 *
		 * @since 2.1.0
		 */
		constructor() {
			this.changesMade = false;
			this.currentSchema = null;
			this.savedMappings = {};
			this.schemaClasses = {};
			this.modalSchemaKey = null;
			this.modalPostType = null;
			this.globalOptions = null;
			this.globalOptionsLoaded = false;
			this.globalOptionsPromise = null;

			// ✅ PERFORMANCE: Cache for field data per post type
			this.fieldDataCache = {};
			this.fieldDataPromises = {};

			// ✅ PERFORMANCE: Debounce timer for JSON preview updates
			this.previewUpdateTimer = null;
			this.previewUpdateDelay = 300; // 300ms debounce delay

			// ✅ PERFORMANCE: Track active AJAX requests for cancellation
			this.activeAjaxRequests = {};

			// Global schemas that don't require post type assignment.
			this.GLOBAL_SCHEMAS = [
				'organization',
				'local_business',
				'website',
				'corporation',
				'author',
			];

			// Coming soon schemas that should not have pre-filled functionality.
			this.COMING_SOON_SCHEMAS = [
				'how_to',
				'video_object',
				'reservation',
				'medical_web_page',
			];

			this.init();
		}

		/**
		 * Initialize the schema manager.
		 *
		 * @since 2.1.0
		 */
		init() {
			// Pre-load global options on initialization.
			this.preloadGlobalOptions();
			this.bindEvents();
			this.updateSchemaStatusIndicators();
			$( '#srk-json-preview-container' ).hide();
			$( '#srk-json-preview-loader' ).hide();

			// Modal event bindings (only if modal exists).
			if ( $( '#srk-schema-modal' ).length ) {
				$( '.srk-modal-close, #srk-modal-cancel' ).on( 'click', () => this.hideModal() );

				$( '#srk-modal-edit' ).on( 'click', () => {
					if ( this.modalSchemaKey ) {
						this.handleSchemaEdit( this.modalSchemaKey );
					}
					this.hideModal();
				} );

				// Close modal when clicking outside.
				$( document ).on( 'click', ( e ) => {
					if ( $( e.target ).hasClass( 'srk-modal' ) ) {
						this.hideModal();
					}
				} );
			}
		}

		/**
		 * Pre-load global options.
		 *
		 * @since 2.1.0
		 */
		preloadGlobalOptions() {
			this.globalOptionsPromise = new Promise( ( resolve ) => {
				// ✅ FIX: Check if srk_ajax_object exists
				if ( typeof srk_ajax_object === 'undefined' || ! srk_ajax_object ) {
					this.globalOptions = {};
					this.globalOptionsLoaded = false;
					resolve( null );
					return;
				}
				
				// Get nonce with fallback
				const nonce = srk_ajax_object.schema_nonce || srk_ajax_object.nonce || '';
				
				$.post(
					srk_ajax_object.ajax_url,
					{
						action: 'srk_get_global_options',
						nonce: nonce, // ✅ FIX: Add nonce for security
					},
					( response ) => {
						if ( response.success ) {
							this.globalOptions = response.data;
							this.globalOptionsLoaded = true;
						} else {
							// Don't block functionality if global options fail to load
							this.globalOptions = {};
							this.globalOptionsLoaded = false;
						}
						resolve( response.data || null );
					}
				).fail( () => {
					// Don't block functionality if global options fail to load
					this.globalOptions = {};
					this.globalOptionsLoaded = false;
					resolve( null );
				} );
			} );
		}

		/**
		 * Ensure global options are loaded before proceeding.
		 *
		 * @since 2.1.0
		 * @return {Promise} Promise that resolves when options are loaded.
		 */
		async ensureGlobalOptionsLoaded() {
			if ( ! this.globalOptionsLoaded && this.globalOptionsPromise ) {
				await this.globalOptionsPromise;
			}
			return this.globalOptions;
		}

		/**
		 * Bind event handlers.
		 *
		 * @since 2.1.0
		 */
		bindEvents() {
			// Schema selection.
			$( document ).on( 'change', '.srk-schema-input', ( e ) => this.handleSchemaChange( e ) );

			// Post type selection.
			$( document ).on( 'change', '.srk-form-select', ( e ) => this.handlePostTypeChange( e ) );

			// Global selector.
			$( document ).on( 'change', '.srk-global-selector', ( e ) => this.handleGlobalSelectorChange( e ) );

			// Group selector.
			$( document ).on( 'change', '.srk-group-selector', ( e ) => this.handleGroupSelectorChange( e ) );

			// Input changes.
			$( document ).on( 'input', 'input.srk-group-values', () => {
				this.changesMade = true;
				this.updateJsonPreview();
				// Clear validation errors when user makes changes
				this.clearValidationErrors();
			} );

			// Select changes.
			$( document ).on( 'change', 'select.srk-group-values', () => {
				this.changesMade = true;
				this.updateJsonPreview();
				// Clear validation errors when user makes changes
				this.clearValidationErrors();
			} );

			// ✅ NEW: Post selector change handler for CPT preview updates
			$( document ).on( 'change', '[id^="assign-posts-"]', () => {
				this.changesMade = true;
				this.updateJsonPreview();
			} );

			// Save button.
			$( '#srk-save-schema-settings' ).on( 'click', ( e ) => this.saveSchemaSettings( e ) );

			// ✅ NEW: Edit schema button.
			$( document ).on( 'click', '.srk-btn-edit-schema', ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				const schemaKey = $( e.currentTarget ).data( 'schema' );
				if ( schemaKey ) {
					this.handleSchemaEdit( schemaKey );
				}
			} );

			// ✅ NEW: Delete schema button.
			$( document ).on( 'click', '.srk-btn-delete-schema', ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				const schemaKey = $( e.currentTarget ).data( 'schema' );
				const postType = $( e.currentTarget ).data( 'post-type' ) || '';
				if ( schemaKey ) {
					this.handleSchemaDelete( schemaKey, postType );
				}
			} );
		}

		/**
		 * Show confirmation modal.
		 *
		 * @since 2.1.0
		 * @param {string} message  Modal message.
		 * @param {string} schemaKey Schema key.
		 * @param {string} postType  Post type.
		 */
		showModal( message, schemaKey, postType ) {
			if ( $( '#srk-schema-modal' ).length ) {
				// ✅ Add class to body FIRST before showing modal to fix z-index issues
				$( 'body' ).addClass( 'srk-modal-open' );
				$( '#srk-modal-message' ).text( message );
				$( '#srk-schema-modal' ).fadeIn( 200 );

				// Store the current schema info for later use.
				this.modalSchemaKey = schemaKey;
				this.modalPostType = postType;
			} else {
				// Fallback to regular confirm if modal doesn't exist.
				if ( confirm( message ) ) {
					this.handleSchemaEdit( schemaKey );
				}
			}
		}

		/**
		 * Hide confirmation modal.
		 *
		 * @since 2.1.0
		 */
		hideModal() {
			if ( $( '#srk-schema-modal' ).length ) {
				$( '#srk-schema-modal' ).fadeOut( 200, function() {
					// ✅ Remove class from body AFTER fadeOut completes
					$( 'body' ).removeClass( 'srk-modal-open' );
				} );
			} else {
				// ✅ Ensure class is removed even if modal doesn't exist
				$( 'body' ).removeClass( 'srk-modal-open' );
			}
			this.modalSchemaKey = null;
			this.modalPostType = null;
		}

		/**
		 * Handle schema selection change.
		 *
		 * @since 2.1.0
		 * @param {Event} e Change event.
		 */
		handleSchemaChange( e ) {
			const $target = $( e.target );
			const schemaKey = $target.val();
			const isChecked = $target.is( ':checked' );
			const container = $( '#srk-schema-config-wrapper' );

			// Skip pre-filled check for coming soon schemas.
			const isComingSoon = this.COMING_SOON_SCHEMAS && Array.isArray( this.COMING_SOON_SCHEMAS ) && this.COMING_SOON_SCHEMAS.includes( schemaKey );
			const isFaq = schemaKey === 'faq';

			// Check if already configured (skip for coming soon schemas, but include FAQ).
			if ( isChecked && $target.data( 'configured' ) && ! isComingSoon ) {
				const postType = $target.data( 'post-type' );
				this.showModal(
					`This schema is already configured for "${ postType }". Do you want to edit the existing configuration?`,
					schemaKey,
					postType
				);
				$target.prop( 'checked', false );
				return false;
			}

			if ( this.currentSchema && this.currentSchema !== schemaKey && this.changesMade ) {
				if ( ! confirm( 'You have unsaved changes. Switching schemas will lose these changes. Continue?' ) ) {
					$target.prop( 'checked', false );
					return false;
				}
			}

			$( '.srk-schema-input' ).not( $target ).prop( 'checked', false );
			container.empty();
			$( '#srk-json-preview-container' ).hide();
			$( '#srk-json-preview-loader' ).hide();

			if ( ! isChecked ) {
				this.currentSchema = null;
				return;
			}

			this.currentSchema = schemaKey;
			this.changesMade = false;

			// Load saved mappings for this schema (skip for coming soon).
			if ( ! isComingSoon ) {
				this.loadSchemaMappings( schemaKey );
			}

			// Get the schema class instance.
			const schemaClass = this.getSchemaClass( schemaKey );
			if ( schemaClass ) {
				schemaClass.handleSelection();
			}
		}

		/**
		 * Handle schema edit action.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key to edit.
		 */
		handleSchemaEdit( schemaKey ) {
			// Uncheck all other schemas first.
			$( '.srk-schema-input' ).prop( 'checked', false );

			// Check the schema that user wants to edit.
			$( `.srk-schema-input[value="${ schemaKey }"]` ).prop( 'checked', true );

			this.currentSchema = schemaKey;
			this.changesMade = false;
			const container = $( '#srk-schema-config-wrapper' );
			container.empty();

			// Get the schema class instance.
			const schemaClass = this.getSchemaClass( schemaKey );
			if ( ! schemaClass ) {
				return;
			}

			// Special handling for FAQ schema.
			if ( schemaKey === 'faq' ) {
				// ✅ FIX: Ensure checkbox is checked and trigger change event for FAQ
				const $checkbox = $( `.srk-schema-input[value="${ schemaKey }"]` );
				$checkbox.prop( 'checked', true );
				
				// Show preview container
				$( '#srk-json-preview-container' ).show();
				
				// Load FAQ configuration
				schemaClass.handleSelection();
				// The FAQ schema will auto-load its configuration internally via checkAndLoadSavedConfiguration.
				return;
			}

			// Load the appropriate UI based on schema type.
			if ( this.GLOBAL_SCHEMAS && Array.isArray( this.GLOBAL_SCHEMAS ) && this.GLOBAL_SCHEMAS.includes( schemaKey ) ) {
				schemaClass.loadGlobalSchema( container );
				this.loadGlobalConfiguration( schemaKey );
			} else {
				schemaClass.loadPostTypeSchema( container );
				// Post type will be auto-selected by checkAndLoadSavedConfiguration in BaseSchema.
			}
		}

		/**
		 * Handle schema delete action.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key to delete.
		 * @param {string} postType  Post type (for confirmation message).
		 */
		handleSchemaDelete( schemaKey, postType ) {
			const schemaName = schemaKey.replace( /_/g, ' ' ).replace( /\b\w/g, l => l.toUpperCase() );
			const confirmMessage = postType 
				? `Are you sure you want to delete the "${ schemaName }" schema configuration for "${ postType }"? This action cannot be undone.`
				: `Are you sure you want to delete the "${ schemaName }" schema configuration? This action cannot be undone.`;

			if ( ! confirm( confirmMessage ) ) {
				return;
			}

			// Show loading state
			const $button = $( `.srk-btn-delete-schema[data-schema="${ schemaKey }"]` );
			const originalHtml = $button.html();
			$button.prop( 'disabled', true ).html( '<span class="dashicons dashicons-update spin"></span>' );

			// Check if srk_ajax_object exists
			if ( typeof srk_ajax_object === 'undefined' || ! srk_ajax_object ) {
				console.error( 'srk_ajax_object is not defined. Cannot delete schema.' );
				$button.prop( 'disabled', false ).html( originalHtml );
				return;
			}

			const nonce = srk_ajax_object.schema_nonce || srk_ajax_object.nonce || '';

			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_delete_schema_assignment',
					schema: schemaKey,
					nonce: nonce,
				},
				( response ) => {
					$button.prop( 'disabled', false ).html( originalHtml );

					if ( response.success ) {
						// Remove the configured state from the schema item
						const $schemaItem = $( `.srk-schema-item:has(.srk-schema-input[value="${ schemaKey }"])` );
						const $checkbox = $( `.srk-schema-input[value="${ schemaKey }"]` );
						
						// Remove configured attributes
						$checkbox.removeAttr( 'data-configured' ).removeAttr( 'data-post-type' );
						$schemaItem.removeClass( 'has-settings' );
						
						// Remove done tag and action buttons
						$schemaItem.find( '.srk-done-tag' ).remove();
						$schemaItem.find( '.srk-schema-actions' ).remove();

						// Clear current schema if it was the deleted one
						if ( this.currentSchema === schemaKey ) {
							this.currentSchema = null;
							$( '#srk-schema-config-wrapper' ).empty();
							$( '#srk-json-preview-container' ).hide();
							$checkbox.prop( 'checked', false );
						}

						// Show success message
						this.showAlert( `Schema "${ schemaName }" deleted successfully.`, 'success' );

						// Update schema status indicators
						this.updateSchemaStatusIndicators();

						// Clear schema count cache on frontend (will be cleared on backend too)
						if ( typeof window.srkClearSchemaCache === 'function' ) {
							window.srkClearSchemaCache();
						}
					} else {
						const errorMsg = response.data && response.data.message 
							? response.data.message 
							: 'Failed to delete schema. Please try again.';
						this.showAlert( errorMsg, 'error' );
					}
				}
			).fail( () => {
				$button.prop( 'disabled', false ).html( originalHtml );
				this.showAlert( 'An error occurred while deleting the schema. Please try again.', 'error' );
			} );
		}

		/**
		 * Handle FAQ schema edit with configuration loading.
		 *
		 * @since 2.1.0
		 * @param {Object} schemaClass Schema class instance.
		 */
		handleFaqSchemaEdit( schemaClass ) {
			// For FAQ, we need to find a post with FAQ schema and load its configuration.
			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_faq_configuration',
				},
				( response ) => {
					if ( response.success && response.data.configured ) {
						const config = response.data;
						const container = $( '#srk-schema-config-wrapper' );

						schemaClass.handleSelection();

						// After UI is loaded, pre-fill the values.
						setTimeout( () => {
							// Set the post type selection.
							$( `#assign-faq` ).val( config.post_type );

							// Trigger change to load posts for this post type.
							$( `#assign-faq` ).trigger( 'change' );

							// After posts are loaded, select the post with FAQ schema.
							setTimeout( () => {
								$( '#faq-item-select' ).val( config.post_id );

								// Load the FAQ items.
								if ( config.faq_items && config.faq_items.length > 0 ) {
									$( '#faq-items' ).empty();
									config.faq_items.forEach( ( item, index ) => {
										$( '#faq-items' ).append( `
											<div class="faq-item">
												<div class="faq-row">
													<label>Question:</label><br>
													<input type="text" class="faq-question srk-group-values"
														placeholder="Enter Question" data-field="question_${ index }"
														value="${ item.question }" style="width:100%;">
												</div>
												<div class="faq-row">
													<label>Answer:</label><br>
													<textarea class="faq-answer srk-group-values"
														placeholder="Enter Answer" data-field="answer_${ index }"
														style="width:100%; height:80px;">${ item.answer }</textarea>
												</div>
												<button type="button" class="remove-faq button">Remove</button>
												<hr>
											</div>
										` );
									} );

									// Update the preview.
									this.updateJsonPreview();
								}
							}, 500 );
						}, 300 );
					} else {
						// If no FAQ configuration found, just load the empty UI.
						schemaClass.handleSelection();
					}
				}
			);
		}

		/**
		 * Load global configuration for a schema.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 */
		loadGlobalConfiguration( schemaKey ) {
			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_schema_configuration',
					schema: schemaKey,
				},
				( response ) => {
					if ( response.success && response.data.configured ) {
						const config = response.data;

						// Pre-fill global values after options are loaded.
						this.loadGlobalOptions().then( () => {
							setTimeout( () => {
								this.prefillGlobalSchemaValues( schemaKey, config.meta_map );
							}, 500 );
						} );
					}
				}
			);
		}

		/**
		 * Load saved schema mappings.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 */
		loadSchemaMappings( schemaKey ) {
			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_schema_configuration',
					schema: schemaKey,
				},
				( response ) => {
					if ( response.success && response.data.configured ) {
						this.savedMappings = response.data.meta_map || {};
					}
				}
			);
		}

		/**
		 * Check if we're in edit mode for a schema.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @return {boolean} True if in edit mode.
		 */
		isEditMode( schemaKey ) {
			if ( ! schemaKey ) {
				return false;
			}
			const $checkbox = $( `.srk-schema-input[value="${ schemaKey }"]` );
			return $checkbox.data( 'configured' ) === true;
		}

		/**
		 * Load global options via AJAX.
		 *
		 * @since 2.1.0
		 * @return {Promise} Promise that resolves with global options.
		 */
		loadGlobalOptions() {
			return new Promise( ( resolve ) => {
			// Check if srk_ajax_object exists
			if ( typeof srk_ajax_object === 'undefined' || ! srk_ajax_object ) {
				resolve( null );
				return;
			}
				
				// ✅ FIX: Get nonce with fallback
				const nonce = srk_ajax_object.schema_nonce || srk_ajax_object.nonce || '';
				
				$.post(
					srk_ajax_object.ajax_url,
					{
						action: 'srk_get_global_options',
						nonce: nonce, // ✅ FIX: Add nonce for security
					},
					( response ) => {
						if ( response.success ) {
							this.globalOptions = response.data;
							resolve( response.data );
					} else {
						resolve( null );
					}
				}
			).fail( () => {
					resolve( null );
				} );
			} );
		}

		/**
		 * Pre-fill schema values for all field types.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @param {Object} metaMap   Field mappings.
		 */
		prefillSchemaValues( schemaKey, metaMap ) {
			if ( ! metaMap || Object.keys( metaMap ).length === 0 ) {
				return;
			}

			let fieldsProcessed = 0;
			const totalFields = Object.keys( metaMap ).length;

			for ( const [ field, savedValue ] of Object.entries( metaMap ) ) {
				// Find the selector and value inputs for this field.
				const $fieldRow = $( `.srk-schema-field-mapping:has([data-field="${ field }"])` );

				if ( $fieldRow.length ) {
					const $selector = $fieldRow.find( '.srk-group-selector' );
					const $valueInput = $fieldRow.find( '.srk-group-values' );

					if ( $selector.length && $valueInput.length && savedValue ) {
						// Determine the type from the saved value.
						let sourceType = 'post';
						if ( savedValue && typeof savedValue === 'string' && savedValue.includes( ':' ) ) {
							sourceType = savedValue.split( ':' )[ 0 ];
						}

						// Set the selector value.
						$selector.val( sourceType );

						// Trigger change to load appropriate options.
						$selector.trigger( 'change' );

						// Set the value after a short delay to allow options to load.
						setTimeout( () => {
							if ( $valueInput.is( 'select' ) ) {
								if ( $valueInput.find( `option[value="${ savedValue }"]` ).length > 0 ) {
									$valueInput.val( savedValue );
								} else {
									// For post meta, user meta, and taxonomy fields, we need to wait for options to load.
									setTimeout( () => {
										if ( $valueInput.find( `option[value="${ savedValue }"]` ).length > 0 ) {
											$valueInput.val( savedValue );
										} else {
											// If still not found, try to create the option dynamically.
											const optionText = this.getOptionDisplayText( savedValue );
											if ( optionText ) {
												$valueInput.append( `<option value="${ savedValue }">${ optionText }</option>` );
												$valueInput.val( savedValue );
											}
										}
										fieldsProcessed++;
										if ( fieldsProcessed === totalFields ) {
											this.updateJsonPreview();
										}
									}, 300 );
									return; // Don't increment fieldsProcessed here.
								}
							} else {
								$valueInput.val( savedValue );
							}

							fieldsProcessed++;
							if ( fieldsProcessed === totalFields ) {
								this.updateJsonPreview();
							}
						}, 300 );
					} else {
						fieldsProcessed++;
					}
				} else {
					fieldsProcessed++;
				}
			}

			// Update preview when all fields are processed.
			if ( fieldsProcessed > 0 ) {
				setTimeout( () => {
					this.updateJsonPreview();
				}, 800 );
			}
		}

		/**
		 * Get display text for field options.
		 *
		 * @since 2.1.0
		 * @param {string} value Option value.
		 * @return {string} Display text.
		 */
		getOptionDisplayText( value ) {
			if ( ! value || typeof value !== 'string' || ! value.includes( ':' ) ) {
				return value;
			}

			const [ type, key ] = value.split( ':' );
			switch ( type ) {
				case 'meta':
					return `Meta: ${ key }`;
				case 'user':
					return `User Meta: ${ key }`;
				case 'tax':
					return `Taxonomy: ${ key }`;
				case 'post':
					return this.getPostFieldDisplayName( key );
				default:
					return value;
			}
		}

		/**
		 * Get display names for post fields.
		 *
		 * @since 2.1.0
		 * @param {string} fieldKey Field key.
		 * @return {string} Display name.
		 */
		getPostFieldDisplayName( fieldKey ) {
			const postFieldNames = {
				post_title: 'Post Title',
				post_excerpt: 'Post Excerpt',
				post_content: 'Post Content',
				post_date: 'Post Date',
				featured_image: 'Site Logo',
				post_author: 'Post Author (ID)',
				author_name: 'Author Name',
				author_url: 'Author URL',
				post_modified: 'Post Modified',
			};

			return postFieldNames[ fieldKey ] || fieldKey;
		}

		/**
		 * Pre-fill global schema values.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @param {Object} metaMap   Field mappings.
		 */
		async prefillGlobalSchemaValues( schemaKey, metaMap ) {
			if ( ! metaMap || Object.keys( metaMap ).length === 0 ) {
				return;
			}

			// Wait for global options to load.
			await this.ensureGlobalOptionsLoaded();

		if ( ! this.globalOptions || ! this.globalOptions.options ) {
			return;
		}

			let fieldsProcessed = 0;
			const totalFields = Object.keys( metaMap ).length;

			for ( const [ field, savedValue ] of Object.entries( metaMap ) ) {
				// ✅ Handle address sub-fields
				if ( ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'].includes( field ) ) {
					const $addressRow = $( `.srk-meta-row:has([data-field="address"])` );
					if ( $addressRow.length ) {
						const $subInput = $addressRow.find( `input[name="meta_map[${ field }]"]` );
						if ( $subInput.length && savedValue ) {
							const displayValue = typeof savedValue === 'string' && savedValue.includes( 'custom:' ) 
								? savedValue.replace( 'custom:', '' ) 
								: savedValue;
							$subInput.val( displayValue.replace( /"/g, '&quot;' ) ).prop( 'disabled', false );
							
							// Enable address selector and checkbox if any sub-field has value
							const $addressSelector = $addressRow.find( '.srk-global-selector[data-field="address"]' );
							const $addressCheckbox = $addressRow.find( '.srk-field-enable[data-field="address"]' );
							if ( $addressSelector.length ) {
								$addressSelector.val( 'custom' ).trigger( 'change' );
							}
							if ( $addressCheckbox.length ) {
								$addressCheckbox.prop( 'checked', true );
							}
						}
					}
					continue;
				}
				
				const $fieldRow = $( `.srk-schema-field-mapping:has([data-field="${ field }"])` );

				if ( $fieldRow.length ) {
					const $selector = $fieldRow.find( '.srk-global-selector' );
					const $valueContainer = $fieldRow.find( '.srk-value-container' );

					if ( $selector.length && $valueContainer.length && savedValue ) {
						
						// ✅ Handle image field with special sources
						if ( field === 'image' ) {
							let sourceType = 'custom';
							let displayValue = savedValue;
							
							if ( savedValue === 'featured_image' || savedValue.includes( 'featured_image' ) ) {
								sourceType = 'featured_image';
								displayValue = '';
							} else if ( savedValue === 'site_logo' || savedValue.includes( 'site_logo' ) || ( typeof savedValue === 'string' && savedValue.includes( 'site:logo_url' ) ) ) {
								sourceType = 'site_logo';
								displayValue = '';
							} else if ( typeof savedValue === 'string' && savedValue.includes( 'custom:' ) ) {
								sourceType = 'custom';
								displayValue = savedValue.replace( 'custom:', '' );
							} else if ( typeof savedValue === 'string' && savedValue.includes( 'site:' ) ) {
								sourceType = 'site';
								displayValue = '';
							}
							
							$selector.val( sourceType );
							if ( sourceType === 'custom' && displayValue ) {
								const $input = $( `<input type="text" class="srk-group-values srk-form-input srk-image-url-input"
									name="meta_map[${ field }]" data-field="${ field }"
									value="${ displayValue.replace( /"/g, '&quot;' ) }" placeholder="Enter custom image URL">` );
								$valueContainer.html( $input );
							} else {
								const $input = $( `<input type="text" class="srk-group-values srk-form-input srk-image-url-input"
									name="meta_map[${ field }]" data-field="${ field }"
									placeholder="${ sourceType === 'featured_image' ? 'Will use site logo' : 'Will use site logo' }" disabled>` );
								$valueContainer.html( $input );
							}
							continue;
						}
						
						// ✅ Handle logo field with special sources
						if ( field === 'logo' ) {
							let sourceType = 'custom';
							let displayValue = savedValue;
							
							if ( savedValue === 'site_logo' || savedValue.includes( 'site_logo' ) || ( typeof savedValue === 'string' && savedValue.includes( 'site:logo_url' ) ) ) {
								sourceType = 'site_logo';
								displayValue = '';
							} else if ( typeof savedValue === 'string' && savedValue.includes( 'custom:' ) ) {
								sourceType = 'custom';
								displayValue = savedValue.replace( 'custom:', '' );
							}
							
							$selector.val( sourceType );
							if ( sourceType === 'custom' && displayValue ) {
								const $input = $( `<input type="text" class="srk-group-values srk-form-input srk-logo-url-input"
									name="meta_map[${ field }]" data-field="${ field }"
									value="${ displayValue.replace( /"/g, '&quot;' ) }" placeholder="Enter custom logo URL">` );
								$valueContainer.html( $input );
							} else {
								const $input = $( `<input type="text" class="srk-group-values srk-form-input srk-logo-url-input"
									name="meta_map[${ field }]" data-field="${ field }"
									placeholder="Will use site logo" disabled>` );
								$valueContainer.html( $input );
							}
							continue;
						}
						// Determine the type from the saved value.
						let sourceType = 'custom';
						let displayValue = savedValue;
						let storageValue = savedValue;

						// Handle site information mappings.
						if ( savedValue && typeof savedValue === 'string' && savedValue.includes( 'site:' ) ) {
							sourceType = 'site';
							const siteKey = savedValue.replace( 'site:', '' );
							displayValue = this.globalOptions.options[ siteKey ] || siteKey;
							storageValue = savedValue;

							// Set selector to site and create display span.
							$selector.val( sourceType );

							const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
								name="meta_map[${ field }]" data-field="${ field }" value="${ storageValue }">` );

							const $displaySpan = $( `<span class="srk-site-value-display" title="${ displayValue }">${ displayValue }</span>` );

							$valueContainer.empty().append( $hiddenInput ).append( $displaySpan );
						} else if ( savedValue && typeof savedValue === 'string' && savedValue.includes( 'custom:' ) ) {
							sourceType = 'custom';
							displayValue = savedValue.replace( 'custom:', '' );
							storageValue = savedValue;

							// Set selector to custom and create input field.
							$selector.val( sourceType );

							const $input = $( `<input type="text" class="srk-group-values srk-form-input"
								name="meta_map[${ field }]" data-field="${ field }"
								value="${ displayValue.replace( /"/g, '&quot;' ) }" placeholder="Enter custom value">` );

							$valueContainer.html( $input );
						} else {
							// Handle raw values (backward compatibility).
							if ( this.globalOptions.options[ savedValue ] ) {
								sourceType = 'site';
								displayValue = this.globalOptions.options[ savedValue ];
								storageValue = `site:${ savedValue }`;

								$selector.val( sourceType );

								const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
									name="meta_map[${ field }]" data-field="${ field }" value="${ storageValue }">` );

								const $displaySpan = $( `<span class="srk-site-value-display">${ displayValue }</span>` );

								$valueContainer.empty().append( $hiddenInput ).append( $displaySpan );
							} else {
								sourceType = 'custom';
								displayValue = savedValue;
								storageValue = `custom:${ savedValue }`;

								$selector.val( sourceType );

								const $input = $( `<input type="text" class="srk-group-values srk-form-input"
									name="meta_map[${ field }]" data-field="${ field }"
									value="${ displayValue.replace( /"/g, '&quot;' ) }" placeholder="Enter custom value">` );

								$valueContainer.html( $input );
							}
						}

						fieldsProcessed++;
						this.checkAllFieldsProcessed( fieldsProcessed, totalFields );
					} else {
						fieldsProcessed++;
						this.checkAllFieldsProcessed( fieldsProcessed, totalFields );
					}
				} else {
					fieldsProcessed++;
					this.checkAllFieldsProcessed( fieldsProcessed, totalFields );
				}
			}
		}

		/**
		 * Check when all fields are processed.
		 *
		 * @since 2.1.0
		 * @param {number} processed Number of processed fields.
		 * @param {number} total     Total number of fields.
		 */
		checkAllFieldsProcessed( processed, total ) {
			if ( processed === total ) {
				setTimeout( () => {
					this.updateJsonPreview();
				}, 500 );
			}
		}

		/**
		 * Handle post type change.
		 *
		 * @since 2.1.0
		 * @param {Event} e Change event.
		 */
		handlePostTypeChange( e ) {
			const schemaKey = this.currentSchema;
			const postType = $( e.target ).val();
			const $metaPlaceholder = $( e.target ).closest( '.srk-form-group' ).next( '.srk-meta-placeholder' );

			if ( ! postType || ! schemaKey ) {
				// Clear any existing article-type assignment warnings if post type is reset.
				this.showArticleTypeAssignmentWarning( schemaKey, null, $( e.target ) );
				return;
			}

			// Show article-type assignment warnings when Article / BlogPosting / NewsArticle
			// are mapped to the same post type. This helps avoid multiple article-type schemas
			// on the same content, which Google may ignore.
			this.showArticleTypeAssignmentWarning( schemaKey, postType, $( e.target ) );

			$metaPlaceholder.html( '<div class="srk-loading"><div class="srk-spinner"></div> Loading fields...</div>' );

			// Get the schema class instance.
			const schemaClass = this.getSchemaClass( schemaKey );
			if ( ! schemaClass ) {
				return;
			}

			// Load posts for this post type.
			this.loadPostsForPostType( schemaKey, postType );

			// ✅ PERFORMANCE: Use cached field data if available
			if ( this.fieldDataCache[ postType ] ) {
				schemaClass.loadPostTypeFields( this.fieldDataCache[ postType ], $metaPlaceholder );
				// Check if we're editing existing configuration and pre-fill values.
				this.handleEditModeConfiguration( schemaKey, postType );
				return;
			}

			// ✅ PERFORMANCE: Cancel any pending request for this post type
			const cacheKey = `fields_${ postType }`;
			if ( this.activeAjaxRequests[ cacheKey ] ) {
				this.activeAjaxRequests[ cacheKey ].abort();
			}

			// ✅ PERFORMANCE: Use existing promise if available
			if ( this.fieldDataPromises[ postType ] ) {
				this.fieldDataPromises[ postType ].then( ( res ) => {
					if ( ! res.success ) {
						this.showAlert( 'Failed to load fields', 'error' );
						return;
					}
					schemaClass.loadPostTypeFields( res.data, $metaPlaceholder );
					// Check if we're editing existing configuration and pre-fill values.
					this.handleEditModeConfiguration( schemaKey, postType );
				} );
				return;
			}

			// ✅ PERFORMANCE: Create new request and cache it
			const request = $.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_post_related_fields',
					post_type: postType,
				},
				( res ) => {
					if ( ! res.success ) {
						this.showAlert( 'Failed to load fields', 'error' );
						delete this.activeAjaxRequests[ cacheKey ];
						delete this.fieldDataPromises[ postType ];
						return;
					}

					// ✅ PERFORMANCE: Cache the field data
					this.fieldDataCache[ postType ] = res.data;

					schemaClass.loadPostTypeFields( res.data, $metaPlaceholder );

					// Check if we're editing existing configuration and pre-fill values.
					this.handleEditModeConfiguration( schemaKey, postType );

					// Clean up
					delete this.activeAjaxRequests[ cacheKey ];
					delete this.fieldDataPromises[ postType ];
				}
			).fail( () => {
				delete this.activeAjaxRequests[ cacheKey ];
				delete this.fieldDataPromises[ postType ];
			} );

			// ✅ PERFORMANCE: Store request for potential cancellation
			this.activeAjaxRequests[ cacheKey ] = request;
			this.fieldDataPromises[ postType ] = request;
		}

		/**
		 * Show inline warnings when multiple article-type schemas (Article, BlogPosting, NewsArticle)
		 * are assigned to the same post type.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey  Current schema key.
		 * @param {string|null} postType Selected post type (or null to clear).
		 * @param {jQuery} $postTypeSelect jQuery object for the post type <select>.
		 */
		showArticleTypeAssignmentWarning( schemaKey, postType, $postTypeSelect ) {
			const articleSchemas = [ 'article', 'blog_posting', 'news_article' ];

			// Only applies to article-type schemas.
			if ( ! schemaKey || ! articleSchemas.includes( schemaKey ) || !$postTypeSelect || !$postTypeSelect.length ) {
				return;
			}

			const $configCard = $postTypeSelect.closest( '.srk-schema-config-card' );
			const $warningContainer = $configCard.find( '.srk-article-type-warning[data-schema-key="' + schemaKey + '"]' );

			if ( ! $warningContainer.length ) {
				return;
			}

			// If no post type is selected, just clear any existing warning.
			if ( ! postType ) {
				$warningContainer.hide().empty();
				return;
			}

			// Friendly display names.
			const labelMap = {
				article: 'Article',
				blog_posting: 'BlogPosting',
				news_article: 'NewsArticle',
			};

			const otherSchemas = articleSchemas.filter( ( key ) => key !== schemaKey );
			const requests = otherSchemas.map( ( otherKey ) => {
				return $.post(
					srk_ajax_object.ajax_url,
					{
						action: 'srk_get_schema_assignment',
						schema: otherKey,
						nonce: srk_ajax_object.nonce,
					}
				);
			} );

			if ( ! requests.length ) {
				$warningContainer.hide().empty();
				return;
			}

			// Resolve all lookups, then build a single concise warning message.
			$.when.apply( $, requests ).done( ( ...responses ) => {
				// Normalize responses shape for 1+ requests.
				const normalized = responses.length && Array.isArray( responses[ 0 ] ) ? responses : [ responses ];
				const conflictingLabels = [];

				normalized.forEach( ( resTuple, index ) => {
					const response = resTuple[ 0 ] || resTuple;

					if (
						response &&
						response.success &&
						response.data &&
						response.data.configured &&
						response.data.post_type === postType
					) {
						const otherKey = otherSchemas[ index ];
						conflictingLabels.push( labelMap[ otherKey ] || otherKey );
					}
				} );

				if ( ! conflictingLabels.length ) {
					$warningContainer.hide().empty();
					return;
				}

				const currentLabel = labelMap[ schemaKey ] || schemaKey;
				const othersList = conflictingLabels.join( ', ' );

				let warningMessage = '';

				if ( schemaKey === 'blog_posting' ) {
					warningMessage = `⚠️ Warning: BlogPosting is being assigned to the same post type as ${ othersList }. According to schema.org guidelines, you should use only ONE article-type schema per page. The most specific type (NewsArticle > BlogPosting > Article) is recommended. This may confuse search engine validators.`;
				} else if ( schemaKey === 'news_article' ) {
					warningMessage = `⚠️ Warning: NewsArticle is being assigned to the same post type as ${ othersList }. According to schema.org guidelines, you should use only ONE article-type schema per page. The most specific type (NewsArticle > BlogPosting > Article) is recommended. This may confuse search engine validators.`;
				} else if ( schemaKey === 'article' ) {
					warningMessage = `⚠️ Warning: Article is being assigned to the same post type as ${ othersList }. According to schema.org guidelines, you should use only ONE article-type schema per page. The most specific type (NewsArticle > BlogPosting > Article) is recommended. This may confuse search engine validators.`;
				} else {
					warningMessage = `⚠️ Warning: ${ currentLabel } is being assigned to the same post type as ${ othersList }. According to schema.org guidelines, you should use only ONE article-type schema per page. The most specific type (NewsArticle > BlogPosting > Article) is recommended. This may confuse search engine validators.`;
				}

				$warningContainer
					.html(
						`<div class="notice notice-warning" style="margin: 8px 0; padding: 8px 12px; border-left: 4px solid #f59e0b;">
							<p style="margin:0; font-size:13px;">${ warningMessage }</p>
						</div>`
					)
					.show();
			} );
		}

		/**
		 * Load posts for a post type.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @param {string} postType  Post type.
		 */
		loadPostsForPostType( schemaKey, postType ) {
			const $postsDropdown = $( `#assign-posts-${ schemaKey }` );
			if ( ! $postsDropdown.length ) {
				return;
			}

			$postsDropdown.html( '<option value="">⏳ Loading posts...</option>' );

			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_posts_by_type',
					post_type: postType,
				},
				( postsResponse ) => {
					if ( postsResponse.success ) {
						let options = '<option value="">Select Post/Page</option>';
						$.each( postsResponse.data, ( id, title ) => {
							options += `<option value="${ id }">${ title }</option>`;
						} );
						$postsDropdown.html( options );

						// Auto-select saved post if in edit mode.
						this.autoSelectSavedPost( schemaKey );
					}
				}
			);
		}

		/**
		 * Auto-select saved post in edit mode.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 */
		autoSelectSavedPost( schemaKey ) {
			if ( ! this.isEditMode( schemaKey ) ) {
				return;
			}

			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_schema_configuration',
					schema: schemaKey,
				},
				( configResponse ) => {
					if ( configResponse.success && configResponse.data.configured ) {
						const config = configResponse.data;
						const $postsDropdown = $( `#assign-posts-${ schemaKey }` );

						if ( config.selected_post && $postsDropdown.length ) {
							// Wait for options to be populated.
							setTimeout( () => {
								if ( $postsDropdown.find( `option[value="${ config.selected_post }"]` ).length > 0 ) {
									$postsDropdown.val( config.selected_post );
								}
							}, 500 );
						}
					}
				}
			);
		}

		/**
		 * Handle edit mode configuration.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @param {string} postType  Post type.
		 */
		handleEditModeConfiguration( schemaKey, postType ) {
			if ( ! this.isEditMode( schemaKey ) ) {
				// If not in edit mode, auto-enable and map required fields for first-time setup
				setTimeout( () => {
					this.autoEnableRequiredFields( schemaKey );
					this.updateJsonPreview();
				}, 800 );
				return;
			}

			// Load saved configuration and pre-fill values.
			$.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_schema_configuration',
					schema: schemaKey,
				},
				( response ) => {
					if ( response.success && response.data.configured ) {
						const config = response.data;

						// Pre-fill values after UI is rendered.
						setTimeout( () => {
							// Pass enabled_fields to prefill function (group selectors).
							this.prefillSchemaValues(
								schemaKey,
								config.meta_map,
								config.enabled_fields || []
							);

							// Ensure group selectors (post/meta/user/tax) reflect saved source.
							this.ensureProperSelectorValues( schemaKey, config.meta_map );

							// Also pre-fill any global selectors (site/custom) used inside post-type schemas
							// such as Article "Publisher" or Course "Provider", reusing the global prefill helper.
							this.prefillGlobalSchemaValues( schemaKey, config.meta_map );
						}, 800 );
					}
				}
			);
		}

		/**
		 * Auto-enable and auto-map required fields for a schema type
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 */
		async autoEnableRequiredFields( schemaKey ) {
			// Get required fields for this schema type
			try {
				const response = await $.post(
					srk_ajax_object.ajax_url,
					{
						action: 'srk_get_required_fields',
						schema: schemaKey,
					}
				);

				if ( response.success && response.data.required_fields && response.data.required_fields.length > 0 ) {
					const requiredFields = response.data.required_fields;
					
					// Default mappings for required fields
					const defaultMappings = {
						headline: { type: 'post', value: 'post:post_title' },
						publisher: { type: 'site', value: 'site:site_name' },
						author: { type: 'post', value: 'post:post_author' },
						name: { type: 'post', value: 'post:post_title' },
					};

					// Enable and map each required field
					requiredFields.forEach( ( field ) => {
						const $fieldRow = $( `.srk-meta-row:has([data-field="${ field }"])` );
						if ( ! $fieldRow.length ) {
							return;
						}

						const $checkbox = $fieldRow.find( '.srk-field-enable' );
						const $mappingContainer = $fieldRow.find( '.srk-schema-field-mapping' );
						
						// Check both selector types (some fields use global selector, others use group selector)
						let $selector = $mappingContainer.find( '.srk-global-selector' );
						if ( ! $selector.length ) {
							$selector = $mappingContainer.find( '.srk-group-selector' );
						}
						
						const $valueInput = $mappingContainer.find( '.srk-group-values, input[data-field="' + field + '"]' );

						// Only auto-enable if field is not already enabled or mapped
						if ( ! $checkbox.is( ':checked' ) && ( ! $valueInput.length || ! $valueInput.val() ) ) {
							// Enable the checkbox
							$checkbox.prop( 'checked', true ).trigger( 'change' );

							// Auto-map the field if we have a default mapping
							if ( defaultMappings[ field ] ) {
								const mapping = defaultMappings[ field ];
								const self = this;
								
								// Set selector type
								setTimeout( () => {
									if ( $selector.length ) {
										$selector.val( mapping.type );
										
										// Trigger change event to initialize the field
										if ( mapping.type === 'site' ) {
											// For site fields, trigger the global selector change handler
											self.handleGlobalSelectorChange( { target: $selector[0] } ).then( () => {
												// Value is set by handleGlobalSelectorChange
											} );
										} else {
											// For post fields, just trigger change and set value
											$selector.trigger( 'change' );
											
											setTimeout( () => {
												const $postValueInput = $mappingContainer.find( '.srk-group-values' );
												if ( $postValueInput.length ) {
													$postValueInput.val( mapping.value );
													$postValueInput.trigger( 'change' );
												}
											}, 300 );
										}
									}
								}, 400 );
							}
						}
					} );
				}
			} catch ( error ) {
				// Error auto-enabling required fields - continue silently
			}
		}

		/**
		 * Handle global selector change.
		 *
		 * @since 2.1.0
		 * @param {Event} e Change event.
		 */
		async handleGlobalSelectorChange( e ) {
			const $target = $( e.target );
			const field = $target.data( 'field' );
			const type = $target.val();
			const $valueContainer = $target.closest( '.srk-schema-field-mapping' ).find( '.srk-value-container' );

			// ✅ FIX: Always ensure value container is visible
			if ( $valueContainer.length ) {
				$valueContainer.show();
			}

			// ✅ FIX: Handle featured_image and site_logo options for image/logo fields
			// These need special handling to store the value properly
			if ( ( field === 'image' || field === 'logo' ) && ( type === 'featured_image' || type === 'site_logo' ) ) {
				// Store the value properly for these options
				const $existingInput = $valueContainer.find( 'input[name="meta_map[' + field + ']"]' );
				
				if ( $existingInput.length ) {
					// Update existing input value
					$existingInput.val( type );
				} else {
					// Create hidden input to store the value (for save method to find)
					const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
						name="meta_map[${ field }]" data-field="${ field }" value="${ type }">` );
					
					// Prepend hidden input to value container (initSourceSelector will handle the visible input)
					$valueContainer.prepend( $hiddenInput );
				}
				
				this.changesMade = true;
				this.updateJsonPreview();
				return; // Don't continue with normal processing - initSourceSelector handles the UI
			}

			// Wait for global options to load.
			await this.ensureGlobalOptionsLoaded();

			// Clear the value container.
			$valueContainer.empty();

			if ( type === 'site' ) {
				// Show loading state with proper styling.
				$valueContainer.html( '<div class="srk-loading" style="width:100%; padding:8px 12px; background:#f8f9fa; border:1px solid #ddd; border-radius:4px;">Loading site information...</div>' );

				// Instead of creating a dropdown, show the actual value directly.
				if ( this.globalOptions && this.globalOptions.options ) {
					// Get the default field mapping.
					const defaultKey = this.getDefaultFieldMapping( field );
					let displayValue = '';
					let storageValue = '';

					if ( defaultKey && this.globalOptions.options[ defaultKey ] ) {
						displayValue = this.globalOptions.options[ defaultKey ];
						storageValue = `site:${ defaultKey }`;
					} else {
						// Fallback to first available option.
						const firstKey = Object.keys( this.globalOptions.options )[ 0 ];
						if ( firstKey ) {
							displayValue = this.globalOptions.options[ firstKey ];
							storageValue = `site:${ firstKey }`;
						} else {
							displayValue = 'No site information available';
							storageValue = `site:${ field }`;
						}
					}

					// Create a hidden input to store the value and a styled span to display it.
					const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
						name="meta_map[${ field }]" data-field="${ field }" value="${ storageValue }">` );

					const $displaySpan = $( `<span class="srk-site-value-display" title="${ displayValue }">${ displayValue }</span>` );

					$valueContainer.empty().append( $hiddenInput ).append( $displaySpan );
				} else {
					// Fallback: If options still not available.
					const $errorSpan = $( `<span class="srk-site-value-display" style="color:#dc3545; background:#f8d7da; border-color:#f5c6cb;">Site information not available. Please save global settings first.</span>` );
					const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
						name="meta_map[${ field }]" data-field="${ field }" value="">` );

					$valueContainer.empty().append( $hiddenInput ).append( $errorSpan );
					return;
				}
			} else if ( type === 'custom' ) {
				// Create input field for custom values with consistent styling.
				const $input = $( `<input type="text" class="srk-group-values srk-form-input"
					name="meta_map[${ field }]" data-field="${ field }"
					placeholder="Enter custom value">` );

				// ✅ FIX: Ensure field is enabled for custom values
				$input.prop('disabled', false);
				$valueContainer.html( $input );
			} else if ( type === 'featured_image' ) {
				// ✅ FIX: Handle featured_image option
				// Store value in a hidden input for save method
				const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
					name="meta_map[${ field }]" data-field="${ field }" value="featured_image">` );

				const $displaySpan = $( `<span class="srk-site-value-display" title="Will use site logo from post">Will use site logo from post</span>` );
				
				// Also allow custom override with visible input
				const $customInput = $( `<input type="text" class="srk-group-values srk-form-input"
					name="meta_map[${ field }]_custom" data-field="${ field }" data-source="featured_image"
					placeholder="Or enter custom image URL" style="margin-top: 8px;">` );

				$valueContainer.empty().append( $hiddenInput ).append( $displaySpan ).append( $customInput );
			} else if ( type === 'site_logo' ) {
				// ✅ FIX: Handle site_logo option
				// Store value in a hidden input for save method
				const $hiddenInput = $( `<input type="hidden" class="srk-group-values"
					name="meta_map[${ field }]" data-field="${ field }" value="site_logo">` );

				const $displaySpan = $( `<span class="srk-site-value-display" title="Will use site logo">Will use site logo</span>` );
				
				// Also allow custom override with visible input
				const $customInput = $( `<input type="text" class="srk-group-values srk-form-input"
					name="meta_map[${ field }]_custom" data-field="${ field }" data-source="site_logo"
					placeholder="Or enter custom image URL" style="margin-top: 8px;">` );

				$valueContainer.empty().append( $hiddenInput ).append( $displaySpan ).append( $customInput );
			} else {
				// ✅ FIX: When "Select Source" is selected (empty value), show an input field but disable it
				// This ensures the field remains visible but not editable until a source is selected
				const $input = $( `<input type="text" class="srk-group-values srk-form-input"
					name="meta_map[${ field }]" data-field="${ field }"
					placeholder="Please select a source first" disabled>` );

				$valueContainer.html( $input );
			}

			// ✅ FIX: Ensure value container and its contents are visible
			$valueContainer.show().css( 'display', 'flex' );
			$valueContainer.find( '.srk-group-values, .srk-site-value-display, .srk-form-input' ).show();

			this.changesMade = true;
			this.updateJsonPreview();
		}

		/**
		 * Get the display value for site mappings.
		 *
		 * @since 2.1.0
		 * @param {string} mapping Field mapping.
		 * @return {string} Display value.
		 */
		getActualSiteValue( mapping ) {
			if ( ! mapping ) {
				return '';
			}

			// If it's already a display value (not a mapping), return as is.
			if ( ! mapping.includes( 'site:' ) && ! mapping.includes( 'custom:' ) ) {
				return mapping;
			}

			if ( mapping.includes( 'site:' ) ) {
				const key = mapping.replace( 'site:', '' );

				if ( this.globalOptions && this.globalOptions.options && this.globalOptions.options[ key ] ) {
					return this.globalOptions.options[ key ];
				}

				return key;
			}

			if ( mapping.includes( 'custom:' ) ) {
				return mapping.replace( 'custom:', '' );
			}

			return mapping;
		}

		/**
		 * Handle group selector change.
		 *
		 * @since 2.1.0
		 * @param {Event} e Change event.
		 */
		handleGroupSelectorChange( e ) {
			const $target = $( e.target );
			const field = $target.data( 'field' );
			const type = $target.val();
			const postType = $( `#assign-${ this.currentSchema }` ).val();

			if ( ! postType ) {
				return;
			}

			// ✅ FIX: Ensure the value select field is visible before loading options
			const $valueSelect = $( `select.srk-group-values[data-field="${ field }"]` );
			if ( $valueSelect.length ) {
				$valueSelect.show().css( 'display', '' ).css( 'visibility', 'visible' );
			}

			// ✅ PERFORMANCE: Use cached field data if available
			if ( this.fieldDataCache[ postType ] ) {
				const { post_defaults, post_meta, user_meta, taxonomies } = this.fieldDataCache[ postType ];
				this.loadFieldOptions( field, type, post_defaults, post_meta, user_meta, taxonomies );
				this.changesMade = true;
				return;
			}

			// ✅ PERFORMANCE: Cancel any pending request for this post type
			const cacheKey = `fields_${ postType }`;
			if ( this.activeAjaxRequests[ cacheKey ] ) {
				this.activeAjaxRequests[ cacheKey ].abort();
			}

			// ✅ PERFORMANCE: Use existing promise if available
			if ( this.fieldDataPromises[ postType ] ) {
				this.fieldDataPromises[ postType ].then( ( res ) => {
					if ( res.success ) {
						const { post_defaults, post_meta, user_meta, taxonomies } = res.data;
						this.loadFieldOptions( field, type, post_defaults, post_meta, user_meta, taxonomies );
						this.changesMade = true;
					}
				} );
				return;
			}

			// ✅ PERFORMANCE: Create new request and cache it
			const request = $.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_post_related_fields',
					post_type: postType,
				},
				( res ) => {
					if ( res.success ) {
						// ✅ PERFORMANCE: Cache the field data
						this.fieldDataCache[ postType ] = res.data;
						const { post_defaults, post_meta, user_meta, taxonomies } = res.data;
						this.loadFieldOptions( field, type, post_defaults, post_meta, user_meta, taxonomies );
						this.changesMade = true;
					}
					// Clean up
					delete this.activeAjaxRequests[ cacheKey ];
					delete this.fieldDataPromises[ postType ];
				}
			).fail( () => {
				delete this.activeAjaxRequests[ cacheKey ];
				delete this.fieldDataPromises[ postType ];
			} );

			// ✅ PERFORMANCE: Store request for potential cancellation
			this.activeAjaxRequests[ cacheKey ] = request;
			this.fieldDataPromises[ postType ] = request;
		}

		/**
		 * Load field options based on type.
		 *
		 * @since 2.1.0
		 * @param {string} field         Field name.
		 * @param {string} type          Field type.
		 * @param {Object} post_defaults Post default fields.
		 * @param {Object} post_meta     Post meta fields.
		 * @param {Object} user_meta     User meta fields.
		 * @param {Object} taxonomies    Taxonomy fields.
		 */
		loadFieldOptions( field, type, post_defaults, post_meta, user_meta, taxonomies ) {
			const $valueSelect = $( `select.srk-group-values[data-field="${ field }"]` );
			const previousValue = $valueSelect.val();

			// ✅ FIX: Ensure the select field is visible before making any changes
			if ( $valueSelect.length ) {
				$valueSelect.show().css( 'display', '' );
			}

			$valueSelect.empty().append( '<option value="">Select Field</option>' );

			let options = {};
			switch ( type ) {
				case 'post':
					options = post_defaults;
					if ( ! options.featured_image ) {
						options.featured_image = 'Site Logo';
					}
					if ( ! options.post_excerpt ) {
						options.post_excerpt = 'Post Excerpt';
					}
					break;
				case 'meta':
					options = post_meta;
					break;
				case 'user':
					options = user_meta;
					break;
				case 'tax':
					options = taxonomies;
					break;
			}

			// ✅ FIX: Only add options if type is valid and has options
			if ( type && Object.keys( options ).length > 0 ) {
				Object.keys( options ).forEach( ( key ) => {
					const optionValue = `${ type }:${ key }`;
					const optionText = options[ key ];
					$valueSelect.append( `<option value="${ optionValue }">${ optionText }</option>` );
				} );
			}

			// ✅ FIX: Ensure the select field remains visible even if type is empty or no options
			if ( $valueSelect.length ) {
				$valueSelect.show().css( 'display', '' ).css( 'visibility', 'visible' );
			}

			// If we're in edit mode, try to restore the saved selection.
			if ( this.isEditMode( this.currentSchema ) && this.savedMappings[ field ] ) {
				const savedValue = this.savedMappings[ field ];
				if ( savedValue && $valueSelect.find( `option[value="${ savedValue }"]` ).length > 0 ) {
					setTimeout( () => {
						$valueSelect.val( savedValue );
					}, 200 );
				} else {
					// If the saved value is not in the options, add it dynamically.
					setTimeout( () => {
						if ( savedValue && typeof savedValue === 'string' && savedValue.includes( ':' ) ) {
							const [ savedType, savedKey ] = savedValue.split( ':' );
							if ( savedType === type ) {
								const displayText = this.getOptionDisplayText( savedValue );
								$valueSelect.append( `<option value="${ savedValue }">${ displayText }</option>` );
								$valueSelect.val( savedValue );
							}
						}
					}, 300 );
				}
			} else if ( previousValue && $valueSelect.find( `option[value="${ previousValue }"]` ).length ) {
				// Try restore selection if still valid.
				$valueSelect.val( previousValue );
			} else if ( type === 'post' ) {
				// Auto select for post default.
				const autoMap = {
					name: 'post_title',
					title: 'post_title',
					headline: 'post_title',
					description: 'post_excerpt',
					articleBody: 'post_content',
					author: 'post_author',
					creator: 'post_author',
					datePublished: 'post_date',
					dateModified: 'post_modified',
					image: 'featured_image',
					thumbnailUrl: 'featured_image',
					url: 'post_url',
					mainEntityOfPage: 'post_url',
				};

				if ( autoMap[ field ] && options[ autoMap[ field ] ] ) {
					setTimeout( () => {
						$valueSelect.val( `post:${ autoMap[ field ] }` );
					}, 200 );
				}
			}

			this.updateJsonPreview();
		}

		/**
		 * Ensure proper selector values are set for all field types.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @param {Object} metaMap   Field mappings.
		 */
		ensureProperSelectorValues( schemaKey, metaMap ) {
			if ( ! metaMap || Object.keys( metaMap ).length === 0 ) {
				return;
			}

			const postType = $( `#assign-${ schemaKey }` ).val();
			if ( ! postType ) {
				return;
			}

			// ✅ PERFORMANCE: Use cached field data if available
			if ( this.fieldDataCache[ postType ] ) {
				const { post_defaults, post_meta, user_meta, taxonomies } = this.fieldDataCache[ postType ];
				for ( const [ field, savedValue ] of Object.entries( metaMap ) ) {
					const $fieldRow = $( `.srk-schema-field-mapping:has([data-field="${ field }"])` );

					if ( $fieldRow.length ) {
						const $selector = $fieldRow.find( '.srk-group-selector' );

						if ( $selector.length && savedValue ) {
							// Determine the type from the saved value.
							let sourceType = 'post';
							if ( savedValue && typeof savedValue === 'string' && savedValue.includes( ':' ) ) {
								sourceType = savedValue.split( ':' )[ 0 ];
							}

							// Set the selector value.
							$selector.val( sourceType );

							// ✅ FIX: Load options for the selected type immediately
							// This ensures that when selector is set to 'tax', 'meta', or 'user',
							// the options are loaded correctly
							this.loadFieldOptions( field, sourceType, post_defaults, post_meta, user_meta, taxonomies );
						}
					}
				}
				return;
			}

			// ✅ PERFORMANCE: Cancel any pending request for this post type
			const cacheKey = `fields_${ postType }`;
			if ( this.activeAjaxRequests[ cacheKey ] ) {
				this.activeAjaxRequests[ cacheKey ].abort();
			}

			// ✅ PERFORMANCE: Use existing promise if available
			if ( this.fieldDataPromises[ postType ] ) {
				this.fieldDataPromises[ postType ].then( ( res ) => {
					if ( ! res.success ) {
						return;
					}

					const { post_defaults, post_meta, user_meta, taxonomies } = res.data;

					for ( const [ field, savedValue ] of Object.entries( metaMap ) ) {
						const $fieldRow = $( `.srk-schema-field-mapping:has([data-field="${ field }"])` );

						if ( $fieldRow.length ) {
							const $selector = $fieldRow.find( '.srk-group-selector' );

							if ( $selector.length && savedValue ) {
								// Determine the type from the saved value.
								let sourceType = 'post';
								if ( savedValue && typeof savedValue === 'string' && savedValue.includes( ':' ) ) {
									sourceType = savedValue.split( ':' )[ 0 ];
								}

								// Set the selector value.
								$selector.val( sourceType );

								// ✅ FIX: Load options for the selected type immediately
								this.loadFieldOptions( field, sourceType, post_defaults, post_meta, user_meta, taxonomies );
							}
						}
					}
				} );
				return;
			}

			// ✅ PERFORMANCE: Create new request and cache it
			const request = $.post(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_post_related_fields',
					post_type: postType,
				},
				( res ) => {
					if ( ! res.success ) {
						delete this.activeAjaxRequests[ cacheKey ];
						delete this.fieldDataPromises[ postType ];
						return;
					}

					// ✅ PERFORMANCE: Cache the field data
					this.fieldDataCache[ postType ] = res.data;

					const { post_defaults, post_meta, user_meta, taxonomies } = res.data;

					for ( const [ field, savedValue ] of Object.entries( metaMap ) ) {
						const $fieldRow = $( `.srk-schema-field-mapping:has([data-field="${ field }"])` );

						if ( $fieldRow.length ) {
							const $selector = $fieldRow.find( '.srk-group-selector' );

							if ( $selector.length && savedValue ) {
								// Determine the type from the saved value.
								let sourceType = 'post';
								if ( savedValue && typeof savedValue === 'string' && savedValue.includes( ':' ) ) {
									sourceType = savedValue.split( ':' )[ 0 ];
								}

								// Set the selector value.
								$selector.val( sourceType );

								// ✅ FIX: Load options for the selected type immediately
								this.loadFieldOptions( field, sourceType, post_defaults, post_meta, user_meta, taxonomies );
							}
						}
					}

					// Clean up
					delete this.activeAjaxRequests[ cacheKey ];
					delete this.fieldDataPromises[ postType ];
				}
			).fail( () => {
				delete this.activeAjaxRequests[ cacheKey ];
				delete this.fieldDataPromises[ postType ];
			} );

			// ✅ PERFORMANCE: Store request for potential cancellation
			this.activeAjaxRequests[ cacheKey ] = request;
			this.fieldDataPromises[ postType ] = request;
		}

		/**
		 * Save schema settings.
		 *
		 * @since 2.1.0
		 * @param {Event} e Click event.
		 */
		saveSchemaSettings(e) {
			e.preventDefault();

			if (!this.currentSchema) {
				this.showAlert('Please select a schema first', 'error');
				return;
			}

			const isGlobal = this.GLOBAL_SCHEMAS && Array.isArray(this.GLOBAL_SCHEMAS) && this.GLOBAL_SCHEMAS.includes(this.currentSchema);
			const postType = isGlobal ? 'global' : $(`#assign-${this.currentSchema}`).val();

			if (!isGlobal && !postType) {
				this.showAlert('Please select a post type', 'error');
				return;
			}

			// Get enabled fields and their mappings
			const enabledFields = [];
			const metaMap = {};

			$('.srk-meta-row').each(function() {
				const $row = $(this);
				const $checkbox = $row.find('.srk-field-enable');
				const field = $checkbox.data('field');

				// ✅ FAQ EXCEPTION: Always consider fields as enabled
				const currentSchema = window.SRK?.SchemaManager?.currentSchema || '';
				
				// ✅ NEW: Always include required fields (even if somehow unchecked)
				const isRequired = $checkbox.attr('data-required') === 'true';
				const isChecked = $checkbox.is(':checked') || isRequired;
				
				// ✅ Handle address field with sub-fields
				if (field === 'address' && $row.find('.srk-address-subfields').length) {
					// Check if any address sub-field has a value
					const addressFields = ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'];
					let hasAddressData = false;
					
					addressFields.forEach(subField => {
						const $subInput = $row.find(`input[name="meta_map[${subField}]"]`);
						if ($subInput.length && $subInput.val() && $subInput.val().trim() !== '') {
							hasAddressData = true;
							metaMap[subField] = `custom:${$subInput.val()}`;
						}
					});
					
					// If any sub-field has data, enable address field
					if (hasAddressData) {
						enabledFields.push(field);
						// Ensure checkbox is checked
						$checkbox.prop('checked', true);
					}
					return; // Skip normal processing for address field
				}
				
				if (isChecked || currentSchema === 'faq') {
					if (currentSchema !== 'faq') {
						enabledFields.push(field);
					}

					const $valueInput = $row.find('.srk-group-values, input[name*="meta_map"], select[name*="meta_map"]');
					let rawValue = $valueInput.val();
					
					// ✅ Handle image field with special sources
					if (field === 'image') {
						const $globalSelector = $row.find('.srk-global-selector');
						if ($globalSelector.length) {
							const selectorType = $globalSelector.val();
							
							// Check for custom override input first
							const $customInput = $row.find('input[name="meta_map[image]_custom"]');
							if ($customInput.length && $customInput.val() && $customInput.val().trim() !== '') {
								// Use custom override if provided
								rawValue = `custom:${$customInput.val().trim()}`;
							} else if (selectorType === 'featured_image') {
								rawValue = 'featured_image';
							} else if (selectorType === 'site_logo') {
								rawValue = 'site_logo';
							} else if (selectorType === 'custom' && rawValue) {
								rawValue = `custom:${rawValue}`;
							} else if (selectorType === 'site') {
								rawValue = 'site:logo_url';
							} else if (rawValue === 'featured_image' || rawValue === 'site_logo') {
								// Preserve value if already set correctly
								// rawValue already set
							}
						} else {
							// Fallback: check if value is already set from hidden input
							const $hiddenInput = $row.find('input[type="hidden"].srk-group-values');
							if ($hiddenInput.length && ($hiddenInput.val() === 'featured_image' || $hiddenInput.val() === 'site_logo')) {
								rawValue = $hiddenInput.val();
							}
						}
					}
					// ✅ Handle logo field with special sources
					else if (field === 'logo') {
						const $globalSelector = $row.find('.srk-global-selector');
						if ($globalSelector.length) {
							const selectorType = $globalSelector.val();
							
							// Check for custom override input first
							const $customInput = $row.find('input[name="meta_map[logo]_custom"]');
							if ($customInput.length && $customInput.val() && $customInput.val().trim() !== '') {
								// Use custom override if provided
								rawValue = `custom:${$customInput.val().trim()}`;
							} else if (selectorType === 'site_logo') {
								rawValue = 'site_logo';
							} else if (selectorType === 'custom' && rawValue) {
								rawValue = `custom:${rawValue}`;
							} else if (rawValue === 'site_logo') {
								// Preserve value if already set correctly
								// rawValue already set
							}
						} else {
							// Fallback: check if value is already set from hidden input
							const $hiddenInput = $row.find('input[type="hidden"].srk-group-values');
							if ($hiddenInput.length && $hiddenInput.val() === 'site_logo') {
								rawValue = $hiddenInput.val();
							}
						}
					}
					else if (rawValue) {
						// Check if value already has a prefix (post:, meta:, site:, custom:)
						if (!rawValue.includes(':')) {
							// Check for global selector first (for site/custom fields)
							const $globalSelector = $row.find('.srk-global-selector');
							if ($globalSelector.length) {
								const selectorType = $globalSelector.val();
								if (selectorType === 'site') {
									rawValue = `site:${rawValue}`;
								} else if (selectorType === 'custom') {
									// For custom fields, add custom: prefix (handles date fields like validThrough)
									rawValue = `custom:${rawValue}`;
								}
							} else {
								// Check for group selector (for post/meta/user/tax fields)
								// Also check for hidden group selector (used for direct inputs like validThrough)
								const $groupSelector = $row.find('.srk-group-selector:visible, .srk-group-selector[type="hidden"]');
								if ($groupSelector.length) {
									const selectorType = $groupSelector.val();
									if (selectorType && selectorType !== '' && selectorType !== 'Select Source') {
										rawValue = `${selectorType}:${rawValue}`;
									} else if ($valueInput.is('input[type="date"]')) {
										// For direct date inputs (like validThrough), treat as custom
										rawValue = `custom:${rawValue}`;
									}
								} else if ($valueInput.is('input[type="date"]')) {
									// For direct date inputs without selector, treat as custom
									rawValue = `custom:${rawValue}`;
								}
							}
						}
					}

					if (rawValue) {
						metaMap[field] = rawValue;
					}
				}
			});

			// ✅ FAQ EXCEPTION: Skip enabled fields validation
			if (enabledFields.length === 0 && this.currentSchema !== 'faq') {
				this.showAlert('Please enable at least one field', 'error');
				return;
			}

			// ✅ Handle FAQ schema separately (from first version)
			if (this.currentSchema === 'faq') {
				const schemaClass = this.getSchemaClass(this.currentSchema);
				const faqData = schemaClass.getConfigData();

				if (!faqData.faq_items || faqData.faq_items.length === 0) {
					this.showAlert('Please add at least one FAQ item', 'error');
					return;
				}

				$.post(
					srk_ajax_object.ajax_url,
					{
						action: 'srk_save_schema_assignment_faq',
						schema: this.currentSchema,
						post_type: faqData.post_type,
						post_id: faqData.post_id,
						faq_items: faqData.faq_items,
						enabled_fields: [], // ✅ FAQ ke liye empty array bhejein
					},
					(response) => {
						if (response.success) {
							this.changesMade = false;
							this.showAlert('FAQ Schema saved successfully!');
							this.updateJsonPreview();
						} else {
							this.showAlert(response.data.message || 'Error saving FAQ schema', 'error');
						}
					}
				).fail(() => {
					this.showAlert('Server error occurred while saving FAQ schema', 'error');
				});

				return;
			}

			// ✅ FAQ: For this schema, send empty enabled_fields array
			const finalEnabledFields = (this.currentSchema === 'faq') ? [] : enabledFields;

			// ✅ NEW: Get authorType for author schema
			let authorTypeData = {};
			if (this.currentSchema === 'author') {
				const $typeSelector = $('.srk-author-type-select');
				if ($typeSelector.length && $typeSelector.val()) {
					authorTypeData.authorType = $typeSelector.val();
				} else if (this.tempAuthorType) {
					authorTypeData.authorType = this.tempAuthorType;
				} else {
					// Fallback to default
					authorTypeData.authorType = 'Person';
				}
			}

			// ✅ NEW: Validate schema before saving
			this.validateSchema(this.currentSchema, metaMap, finalEnabledFields).then((validationResult) => {
				if (!validationResult.valid) {
					// Show validation errors
					this.displayValidationErrors(validationResult.errors, validationResult.warnings);
					return;
				}

				// If validation passed, proceed with save
				// Build save data
				const saveData = {
					action: 'srk_save_schema_assignment',
					schema: this.currentSchema,
					post_type: postType,
					meta_map: metaMap,
					enabled_fields: finalEnabledFields,
					is_global: isGlobal ? 1 : 0,
					nonce: srk_ajax_object.schema_nonce || srk_ajax_object.nonce || '', // ✅ FIX: Add nonce for security
				};
				
				// Add authorType for author schema
				if (this.currentSchema === 'author' && authorTypeData.authorType) {
					saveData.authorType = authorTypeData.authorType;
				}
				
				$.post(
					srk_ajax_object.ajax_url,
					saveData,
					(response) => {
						if (response.success) {
							this.changesMade = false;
							this.showAlert('Settings saved successfully!');
							this.updateJsonPreview();
							this.loadSchemaMappings(this.currentSchema);
							// Clear validation errors on successful save
							this.clearValidationErrors();
						} else {
							// ✅ FIX: Check if response contains validation errors
							if (response.data && (response.data.errors || response.data.error_messages)) {
								// Handle both error formats
								const errors = response.data.errors || [];
								const warnings = response.data.warnings || [];
								const errorMessages = response.data.error_messages || [];
								
								// If we have error_messages but not errors array, convert them
								if (errorMessages.length > 0 && errors.length === 0) {
									errorMessages.forEach((msg) => {
										errors.push({ message: msg, field: 'unknown' });
									});
								}
								
								this.displayValidationErrors(errors, warnings);
								
								// Also show the main error message
								if (response.data.message) {
									this.showAlert(response.data.message, 'error');
								}
							} else {
								this.showAlert(response.data && response.data.message ? response.data.message : 'Error saving settings', 'error');
							}
						}
					}
				).fail((xhr, status, error) => {
					// Better error handling for 500 errors
					let errorMessage = 'Server error occurred while saving';
					
					// Try to parse error response
					if (xhr.responseText) {
						try {
							const errorResponse = JSON.parse(xhr.responseText);
							if (errorResponse.data && errorResponse.data.message) {
								errorMessage = errorResponse.data.message;
							} else if (errorResponse.message) {
								errorMessage = errorResponse.message;
							}
						} catch (e) {
							// If not JSON, check for PHP errors
							if (xhr.responseText.includes('Fatal error') || xhr.responseText.includes('Parse error')) {
								errorMessage = 'A PHP error occurred. Please check your debug.log file for details.';
							}
						}
					}
					
					this.showAlert(errorMessage, 'error');
					
					// Display validation errors if present in error response
					if (xhr.responseText) {
						try {
							const errorResponse = JSON.parse(xhr.responseText);
							if (errorResponse.data && errorResponse.data.errors) {
								this.displayValidationErrors(errorResponse.data.errors, errorResponse.data.warnings || []);
							}
						} catch (e) {
							// Not JSON, ignore
						}
					}
				});
			}).catch(() => {
				this.showAlert('Validation error occurred', 'error');
			});
		}

		/**
		 * Pre-fill schema values with proper checkbox support
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @param {Object} metaMap Field mappings.
		 * @param {Array} enabledFields Enabled field IDs.
		 */
		prefillSchemaValues( schemaKey, metaMap, enabledFields = [] ) {
			// ✅ FAQ EXCEPTION: Skip for this schema (handled separately)
		if ( schemaKey === 'faq' ) {
			return;
		}

			if ( ! metaMap || Object.keys( metaMap ).length === 0 ) {
				return;
			}

			let fieldsProcessed = 0;
			const totalFields = Object.keys( metaMap ).length;

			// First: Set all checkbox states based on enabled_fields
			$( '.srk-field-enable' ).each( function() {
				const $checkbox = $( this );
				const field = $checkbox.data( 'field' );
				const $fieldRow = $checkbox.closest( '.srk-meta-row' );
				const $mappingContainer = $fieldRow.find( '.srk-schema-field-mapping' );

				if ( enabledFields.includes( field ) ) {
					$checkbox.prop( 'checked', true );
					$mappingContainer.css( 'opacity', '1' ).find( 'select, input' ).prop( 'disabled', false );
				} else {
					$checkbox.prop( 'checked', false );
					$mappingContainer.css( 'opacity', '0.5' ).find( 'select, input' ).prop( 'disabled', true );
				}
			} );

			// Second: Pre-fill values for enabled fields only
			for ( const [ field, savedValue ] of Object.entries( metaMap ) ) {
				// Find the selector and value inputs for this field
				const $fieldRow = $( `.srk-schema-field-mapping:has([data-field="${ field }"])` ).closest( '.srk-meta-row' );

				if ( $fieldRow.length ) {
					const $checkbox = $fieldRow.find( '.srk-field-enable' );
					const $selector = $fieldRow.find( '.srk-group-selector' );
					const $valueInput = $fieldRow.find( '.srk-group-values' );

					// Only pre-fill if field is enabled and has saved value
					if ( $checkbox.is( ':checked' ) && $selector.length && $valueInput.length && savedValue ) {
						// Determine the type from the saved value.
						let sourceType = 'post';
						if ( savedValue && typeof savedValue === 'string' && savedValue.includes( ':' ) ) {
							sourceType = savedValue.split( ':' )[ 0 ];
						}

						// Set the selector value.
						$selector.val( sourceType );

						// Trigger change to load appropriate options.
						$selector.trigger( 'change' );

						// Set the value after a short delay to allow options to load.
						setTimeout( () => {
							if ( $valueInput.is( 'select' ) ) {
								if ( $valueInput.find( `option[value="${ savedValue }"]` ).length > 0 ) {
									$valueInput.val( savedValue );
								} else {
									// For post meta, user meta, and taxonomy fields, we need to wait for options to load.
									setTimeout( () => {
										if ( $valueInput.find( `option[value="${ savedValue }"]` ).length > 0 ) {
											$valueInput.val( savedValue );
										} else {
											// If still not found, try to create the option dynamically.
											const optionText = this.getOptionDisplayText( savedValue );
											if ( optionText ) {
												$valueInput.append( `<option value="${ savedValue }">${ optionText }</option>` );
												$valueInput.val( savedValue );
											}
										}
										fieldsProcessed++;
										if ( fieldsProcessed === totalFields ) {
											this.updateJsonPreview();
										}
									}, 300 );
									return; // Don't increment fieldsProcessed here.
								}
							} else {
								// Handle direct inputs (e.g., JobPosting validThrough date field)
								let displayValue = savedValue;
								if (
									$valueInput.is( 'input[type="date"]' ) &&
									typeof savedValue === 'string' &&
									savedValue.indexOf( 'custom:' ) === 0
								) {
									// Remove the custom: prefix so the date input receives a clean date value.
									displayValue = savedValue.replace( /^custom:/, '' );
								}
								$valueInput.val( displayValue );
							}

							fieldsProcessed++;
							if ( fieldsProcessed === totalFields ) {
								this.updateJsonPreview();
							}
						}, 300 );
					} else {
						fieldsProcessed++;
					}
				} else {
					fieldsProcessed++;
				}
			}

			// Update preview when all fields are processed
			if ( fieldsProcessed > 0 ) {
				setTimeout( () => {
					this.updateJsonPreview();
				}, 800 );
			}
		}
		
		/**
		 * Update JSON preview.
		 * ✅ PERFORMANCE: Debounced to prevent excessive calls
		 * ✅ NEW: Shows loader while preview is being generated
		 *
		 * @since 2.1.0
		 */
		updateJsonPreview() {
			if ( ! this.currentSchema ) {
				return;
			}

			// ✅ NEW: Show loader when preview update starts
			const $previewContainer = $( '#srk-json-preview-container' );
			const $previewLoader = $( '#srk-json-preview-loader' );
			const $previewContent = $( '#srk-json-preview' );
			
			if ( $previewContainer.length ) {
				$previewContainer.show();
				$previewLoader.show();
				$previewContent.hide();
			}

			// ✅ PERFORMANCE: Clear existing timer
			if ( this.previewUpdateTimer ) {
				clearTimeout( this.previewUpdateTimer );
			}

			// ✅ PERFORMANCE: Debounce the preview update
			this.previewUpdateTimer = setTimeout( () => {
				// Get the schema class instance.
				const schemaClass = this.getSchemaClass( this.currentSchema );
				if ( schemaClass ) {
					schemaClass.generatePreview();
				}
				this.previewUpdateTimer = null;
			}, this.previewUpdateDelay );
		}

		/**
		 * Validate schema configuration
		 *
		 * @since 2.1.0
		 * @param {string} schemaType Schema type.
		 * @param {Object} metaMap Field mappings.
		 * @param {Array} enabledFields Enabled fields.
		 * @return {Promise} Promise that resolves with validation result.
		 */
		validateSchema(schemaType, metaMap, enabledFields) {
			return new Promise((resolve, reject) => {
				$.post(
					srk_ajax_object.ajax_url,
					{
						action: 'srk_validate_schema',
						schema: schemaType,
						meta_map: metaMap,
						enabled_fields: enabledFields,
					},
					(response) => {
						if (response.success) {
							resolve({
								valid: response.data.valid,
								errors: response.data.errors || [],
								warnings: response.data.warnings || [],
							});
						} else {
							resolve({
								valid: false,
								errors: response.data.errors || [],
								warnings: response.data.warnings || [],
							});
						}
					}
				).fail(() => {
					reject(new Error('Validation request failed'));
				});
			});
		}

		/**
		 * Display validation errors and warnings
		 *
		 * @since 2.1.0
		 * @param {Array} errors Validation errors.
		 * @param {Array} warnings Validation warnings.
		 */
		displayValidationErrors(errors, warnings) {
			// Remove existing validation messages completely
			$('#srk-validation-errors').remove();
			
			// Remove field highlighting
			$('.srk-field-error, .srk-field-warning').removeClass('srk-field-error srk-field-warning').css('border-left', '');

			// ✅ FIX: Always show validation messages inside the "JSON-LD Preview — Save & Validate" section.
			const $previewWrapper = $('.srk-preview-wrapper').first();
			let $validationContainer = $('<div id="srk-validation-errors" class="srk-validation-container" style="margin-top:15px; margin-bottom:15px;"></div>');

			if ($previewWrapper.length) {
				const $actions = $previewWrapper.find('.srk-actions').first();

				// Prefer to show just under the Save / Validate / Test buttons.
				if ($actions.length) {
					$actions.after($validationContainer);
				} else {
					// Fallback: append at the end of the preview wrapper.
					$previewWrapper.append($validationContainer);
				}
			} else {
				// As an absolute fallback, append to body (should rarely happen).
				$('body').append($validationContainer);
			}

			// ✅ FIX: Build complete HTML first, then set it all at once
			let fullHtml = '';

			// Display errors with concise information
			if (errors && errors.length > 0) {
				let errorHtml = '<div class="srk-validation-errors" style="background:#fee2e2; border:2px solid #dc3545; border-radius:8px; padding:15px; margin-bottom:15px;">';
				errorHtml += '<div class="srk-validation-header" style="margin-bottom:12px;">';
				errorHtml += '<h4 style="color:#dc3545; margin:0; display:flex; align-items:center; gap:8px; font-size:14px;">';
				errorHtml += '<span>⚠️</span>';
				errorHtml += '<span>Validation Errors (' + errors.length + ')</span>';
				errorHtml += '</h4>';
				errorHtml += '</div>';
				errorHtml += '<div class="srk-validation-errors-list">';
				
				errors.forEach((error, index) => {
					const field = error.field || 'unknown';
					const fieldLabel = error.field_label || field;
					const message = error.message || 'Unknown error';
					const suggestion = error.suggestion || '';
					
					errorHtml += `<div class="srk-error-item" data-field="${field}" style="background:#fff5f5; border-left:3px solid #dc3545; padding:12px; margin-bottom:10px; border-radius:4px; transition:all 0.2s;">`;
					
					// Enhanced error display with fix button - more concise and guided
					errorHtml += `<div style="display:flex; align-items:flex-start; gap:10px;">`;
					errorHtml += `<span style="color:#dc3545; font-size:18px; line-height:1.4; flex-shrink:0;">✗</span>`;
					errorHtml += `<div style="flex:1; min-width:0;">`;
					errorHtml += `<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; flex-wrap:wrap; gap:6px;">`;
					errorHtml += `<strong style="color:#721c24; font-size:13px; font-weight:600;">${this.escapeHtml(fieldLabel)}</strong>`;
					errorHtml += `<div style="display:flex; gap:6px;">`;
					// Add "Focus on Field" button for manual navigation
					errorHtml += `<button type="button" class="button button-small srk-focus-field" data-field="${field}" style="background:#3b82f6; color:#fff; border:none; padding:5px 10px; border-radius:4px; font-size:10px; cursor:pointer; font-weight:500; white-space:nowrap; transition:background 0.2s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'" title="Scroll to field">`;
					errorHtml += `📍 Focus`;
					errorHtml += `</button>`;
					// Add "Fix This Field" button - positioned inline for better UX
					errorHtml += `<button type="button" class="button button-small srk-auto-fix-field" data-field="${field}" style="background:#059669; color:#fff; border:none; padding:5px 10px; border-radius:4px; font-size:10px; cursor:pointer; font-weight:500; white-space:nowrap; transition:background 0.2s;" onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'" title="Automatically enable and map this field">`;
					errorHtml += `🔧 Auto-Fix`;
					errorHtml += `</button>`;
					errorHtml += `</div></div>`;
					errorHtml += `<p style="color:#721c24; margin:0 0 6px 0; font-size:12px; line-height:1.4;">${this.escapeHtml(message)}</p>`;
					
					// Show concise fix suggestion
					if (suggestion) {
						const shortSuggestion = suggestion.length > 75 ? suggestion.substring(0, 75) + '...' : suggestion;
						errorHtml += `<p style="color:#92400e; margin:0; font-size:11px; line-height:1.4; padding:5px 8px; background:#fef3c7; border-radius:3px; display:inline-block;">💡 ${this.escapeHtml(shortSuggestion)}</p>`;
					}
					
					errorHtml += `</div></div>`;
					errorHtml += `</div>`;
					
					// Highlight the field in the form
					const $fieldRow = $(`.srk-meta-row:has([data-field="${field}"])`);
					if ($fieldRow.length) {
						$fieldRow.addClass('srk-field-error');
						$fieldRow.css('border-left', '3px solid #dc3545');
					}
				});
				
				errorHtml += '</div></div>';
				fullHtml += errorHtml;
			}

			// Display warnings with concise information
			if (warnings && warnings.length > 0) {
				let warningHtml = '<div class="srk-validation-warnings" style="background:#fffbeb; border:2px solid #f59e0b; border-radius:8px; padding:15px; margin-top:15px;">';
				warningHtml += '<div class="srk-validation-header" style="margin-bottom:12px;">';
				warningHtml += '<h4 style="color:#f59e0b; margin:0; display:flex; align-items:center; gap:8px; font-size:14px;">';
				warningHtml += '<span>ℹ️</span>';
				warningHtml += '<span>Recommendations (' + warnings.length + ')</span>';
				warningHtml += '</h4>';
				warningHtml += '</div>';
				warningHtml += '<div class="srk-validation-warnings-list">';
				
				warnings.forEach((warning) => {
					const field = warning.field || 'unknown';
					const fieldLabel = warning.field_label || field;
					const message = warning.message || 'Unknown warning';
					
					warningHtml += `<div class="srk-warning-item" data-field="${field}" style="background:#fffbeb; border-left:3px solid #fbbf24; padding:8px 12px; margin-bottom:6px; border-radius:4px;">`;
					warningHtml += `<div style="display:flex; align-items:flex-start; gap:8px;">`;
					warningHtml += `<span style="color:#f59e0b; font-size:14px; line-height:1.4; flex-shrink:0;">ℹ️</span>`;
					warningHtml += `<div style="flex:1;">`;
					warningHtml += `<strong style="color:#92400e; font-size:12px; display:block; margin-bottom:2px;">${this.escapeHtml(fieldLabel)}</strong>`;
					warningHtml += `<p style="color:#92400e; margin:0; font-size:11px; line-height:1.4;">${this.escapeHtml(message)}</p>`;
					warningHtml += `</div></div></div>`;
					
					// Highlight the field in the form
					const $fieldRow = $(`.srk-meta-row:has([data-field="${field}"])`);
					if ($fieldRow.length) {
						$fieldRow.addClass('srk-field-warning');
						if (!$fieldRow.hasClass('srk-field-error')) {
							$fieldRow.css('border-left', '3px solid #ffc107');
						}
					}
				});
				
				warningHtml += '</div></div>';
				fullHtml += warningHtml;
			}

			// ✅ FIX: Set all HTML at once and ensure container is visible
			if (fullHtml) {
				$validationContainer.html(fullHtml);
				$validationContainer.css('display', 'block'); // Force visibility
				$validationContainer.slideDown(300); // Animate in
				
				// Scroll to validation errors container
				if ($validationContainer.length && $validationContainer.offset()) {
					$('html, body').animate({
						scrollTop: $validationContainer.offset().top - 100
					}, 500);
				}
			} else {
				// No errors or warnings, remove container
				$validationContainer.remove();
			}
			
			// Add click handler for focus buttons
			$validationContainer.on('click', '.srk-focus-field', (e) => {
				const field = $(e.target).data('field');
				this.focusOnField(field);
			});
			
			// Add click handler for auto-fix buttons
			$validationContainer.on('click', '.srk-auto-fix-field', (e) => {
				e.preventDefault();
				const field = $(e.target).data('field');
				const $button = $(e.target);
				
				// Disable button and show loading state
				$button.prop('disabled', true).text('⏳ Fixing...');
				
				// Auto-fix the field
				this.autoFixField(field).then(() => {
					$button.text('✅ Fixed!').css('background', '#10b981');
					setTimeout(() => {
						// Re-validate after fixing
						this.revalidateSchema();
					}, 500);
				}).catch(() => {
					$button.prop('disabled', false).text('🔧 Auto-Fix').css('background', '#059669');
				});
			});
		}
		
		/**
		 * Focus on a specific field in the form
		 *
		 * @since 2.1.0
		 * @param {string} field Field name.
		 */
		focusOnField(field) {
			const $fieldRow = $(`.srk-meta-row:has([data-field="${field}"])`);
			if ($fieldRow.length) {
				$('html, body').animate({
					scrollTop: $fieldRow.offset().top - 100
				}, 500);
				
				// Highlight the field briefly
				$fieldRow.css('background-color', '#fff5f5');
				setTimeout(() => {
					$fieldRow.css('background-color', '');
				}, 2000);
				
				// Focus on the field input/select
				const $input = $fieldRow.find('select, input').first();
				if ($input.length) {
					setTimeout(() => {
						$input.focus();
					}, 500);
				}
			}
		}

		/**
		 * Auto-fix a field by enabling it and applying default mapping
		 *
		 * @since 2.1.0
		 * @param {string} field Field name.
		 * @return {Promise} Promise that resolves when field is fixed.
		 */
		async autoFixField(field) {
			return new Promise((resolve, reject) => {
				const $fieldRow = $(`.srk-meta-row:has([data-field="${field}"])`);
				if (!$fieldRow.length) {
					reject(new Error('Field not found'));
					return;
				}

				const $checkbox = $fieldRow.find('.srk-field-enable');
				const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
				const $selector = $mappingContainer.find('.srk-group-selector, .srk-global-selector');
				const $valueInput = $mappingContainer.find('.srk-group-values, select[name*="meta_map"], input[name*="meta_map"]');

				// Default mappings for common fields
				const defaultMappings = {
					headline: { type: 'post', value: 'post:post_title', selectorType: 'group' },
					publisher: { type: 'site', value: 'site:site_name', selectorType: 'global' },
					provider: { type: 'site', value: 'site:site_name', selectorType: 'global' }, // Course provider
					author: { type: 'post', value: 'post:post_author', selectorType: 'group' },
					name: { type: 'post', value: 'post:post_title', selectorType: 'group' },
					title: { type: 'post', value: 'post:post_title', selectorType: 'group' },
					datePosted: { type: 'post', value: 'post:post_date', selectorType: 'group' },
					validThrough: { type: 'custom', value: '', selectorType: 'group', isDateField: true, isDirectInput: true },
					startDate: { type: 'post', value: 'post:post_date', selectorType: 'group' },
					startDate: { type: 'post', value: 'post:post_date', selectorType: 'group' },
					offers: { type: 'post', value: 'post:price', selectorType: 'group' },
				};

				// Enable the checkbox
				$checkbox.prop('checked', true).attr('data-required', 'true').trigger('change');
				$fieldRow.addClass('srk-field-required');

				// Enable mapping container
				$mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);

				// Apply default mapping if available
				if (defaultMappings[field]) {
					const mapping = defaultMappings[field];

					setTimeout(() => {
						if ($selector.length) {
							$selector.val(mapping.type).trigger('change');

							setTimeout(() => {
								if (mapping.type === 'site' && typeof this.handleGlobalSelectorChange === 'function') {
									this.handleGlobalSelectorChange({ target: $selector[0] });
									resolve();
								} else if (mapping.type === 'post' && $valueInput.is('select') && mapping.value) {
									// Wait for options to load
									const checkOptions = setInterval(() => {
										if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
											$valueInput.val(mapping.value).trigger('change');
											clearInterval(checkOptions);
											resolve();
										}
									}, 100);
									
									// Timeout after 2 seconds
									setTimeout(() => {
										clearInterval(checkOptions);
										if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
											$valueInput.val(mapping.value).trigger('change');
										} else if ($valueInput.find('option').length > 1) {
											// Use first available option as fallback
											$valueInput.val($valueInput.find('option:not([value=""])').first().val()).trigger('change');
										}
										resolve();
									}, 2000);
									return;
												} else if (mapping.type === 'custom' && $valueInput.is('input')) {
									$valueInput.prop('disabled', false);
									if (mapping.isDateField && field === 'validThrough') {
										const futureDate = new Date();
										futureDate.setDate(futureDate.getDate() + 90);
										const defaultDate = futureDate.toISOString().split('T')[0];
										if (!$valueInput.attr('type') || $valueInput.attr('type') !== 'date') {
											$valueInput.attr('type', 'date');
										}
										$valueInput.val(defaultDate);
										$valueInput.attr('placeholder', defaultDate + ' (default: 90 days from today)');
										$valueInput.trigger('change');
									}
								} else if (mapping.isDirectInput && field === 'validThrough') {
									// Handle direct date input for validThrough (Job Posting schema)
									const $directDateInput = $mappingContainer.find('input[type="date"][data-field="' + field + '"], input[data-field="' + field + '"]');
									if ($directDateInput.length) {
										if (!$directDateInput.attr('type') || $directDateInput.attr('type') !== 'date') {
											$directDateInput.attr('type', 'date');
										}
										$directDateInput.prop('disabled', false);
										const futureDate = new Date();
										futureDate.setDate(futureDate.getDate() + 90);
										const defaultDate = futureDate.toISOString().split('T')[0];
										$directDateInput.val(defaultDate);
										$directDateInput.trigger('change');
									}
								}

								// Update preview after fixing
								if (typeof this.updateJsonPreview === 'function') {
									setTimeout(() => {
										this.updateJsonPreview();
									}, 300);
								}

								resolve();
							}, 400);
						} else {
							resolve();
						}
					}, 300);
				} else {
					resolve();
				}

				// Scroll to field and highlight
				$('html, body').animate({
					scrollTop: $fieldRow.offset().top - 100
				}, 300);

				$fieldRow.css('background-color', '#dcfce7').css('border-left', '4px solid #10b981');
				setTimeout(() => {
					$fieldRow.css('background-color', '').removeClass('srk-field-error');
				}, 2000);
			});
		}

		/**
		 * Re-validate schema after auto-fixing fields
		 *
		 * @since 2.1.0
		 */
		revalidateSchema() {
			if (!this.currentSchema) {
				return;
			}

			// Collect current field mappings and enabled fields
			const metaMap = {};
			const enabledFields = [];

			$('.srk-schema-field-mapping').each(function() {
				const $mappingContainer = $(this);
				const $fieldRow = $mappingContainer.closest('.srk-meta-row');
				const $checkbox = $fieldRow.find('.srk-field-enable');
				
				if ($checkbox.is(':checked')) {
					const field = $checkbox.data('field');
					const $valueInput = $mappingContainer.find('.srk-group-values, select[name*="meta_map"], input[name*="meta_map"]');
					
					if (field && $valueInput.length && $valueInput.val()) {
						enabledFields.push(field);
						metaMap[field] = $valueInput.val();
					}
				}
			});

			// Re-validate
			this.validateSchema(this.currentSchema, metaMap, enabledFields).then((validationResult) => {
				this.displayValidationErrors(validationResult.errors, validationResult.warnings);
			});
		}
		
		/**
		 * Escape HTML to prevent XSS
		 *
		 * @since 2.1.0
		 * @param {string} text Text to escape.
		 * @return {string} Escaped text.
		 */
		escapeHtml(text) {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, (m) => map[m]);
		}

		/**
		 * Clear validation errors
		 *
		 * @since 2.1.0
		 */
		clearValidationErrors() {
			// ✅ FIX: Remove container completely instead of just hiding
			$('#srk-validation-errors').slideUp(300, function() {
				$(this).remove();
			});
			
			// Remove field highlighting
			$('.srk-field-error, .srk-field-warning').removeClass('srk-field-error srk-field-warning').css('border-left', '');
		}

		/**
		 * Show alert message.
		 *
		 * @since 2.1.0
		 * @param {string} message Alert message.
		 * @param {string} type     Alert type (success/error).
		 */
		showAlert( message, type = 'success' ) {
			const alertClass = type === 'error' ? 'srk-alert-error' : 'srk-alert-success';
			const $alert = $( `<div class="srk-alert ${ alertClass }">${ message }</div>` );
			$( '#srk-save-status' ).html( $alert ).fadeIn().delay( 3000 ).fadeOut();
		}

		/**
		 * Get saved schema settings for a specific schema.
		 *
		 * @since 2.1.1
		 * @param {string} schemaKey Schema key.
		 * @return {Object|null} Saved settings or null.
		 */
		getSavedSchemaSettings( schemaKey ) {
			if ( ! schemaKey ) {
				return null;
			}
			
			// For author schema, check type selector first (for dynamic updates)
			if ( schemaKey === 'author' ) {
				// Check if there's a temporary authorType from selector change
				if ( this.tempAuthorType ) {
					return { authorType: this.tempAuthorType };
				}
				
				// Check the type selector in the UI
				const $typeSelector = $( '.srk-author-type-select' );
				if ( $typeSelector.length && $typeSelector.val() ) {
					return { authorType: $typeSelector.val() };
				}
			}
			
			// Try to get from the modal's saved data
			const $modal = $( '#srk-schema-config-modal' );
			if ( $modal.length ) {
				const savedData = $modal.data( 'saved-data' );
				if ( savedData && savedData.schema_type === schemaKey ) {
					return savedData;
				}
			}
			
			// Fallback: return empty object with default authorType for author schema
			if ( schemaKey === 'author' ) {
				return { authorType: 'Person' };
			}
			
			return null;
		}

		/**
		 * Get default field mapping.
		 *
		 * @since 2.1.0
		 * @param {string} fieldId Field identifier.
		 * @return {string} Default field mapping.
		 */
		getDefaultFieldMapping( fieldId ) {
			const fieldMappings = {
				// Basic fields.
				name: 'site_name',
				description: 'site_description',
				image: 'logo_url',
				logo: 'logo_url',
				url: 'site_url',

				// Contact fields.
				telephone: 'telephone',
				email: 'admin_email',
				address: 'address',

				// Business fields.
				openingHours: 'opening_hours',
				priceRange: 'price_range',
				contactPoint: 'contact_point',

				// Social fields.
				sameAs: 'social_profiles',

				// Organization fields.
				founder: 'founder',
				foundingDate: 'founding_date',
				underName: 'under_name',

				// Review fields.
				itemReviewed: 'item_reviewed',
				author: 'author_name',
				reviewBody: 'review_body',
				reviewRating: 'review_rating',
				datePublished: 'date_published',

				// Product fields.
				price: 'price',
				priceCurrency: 'price_currency',
				availability: 'availability',
				itemCondition: 'item_condition',
				validFrom: 'valid_from',

				// Rating fields.
				ratingValue: 'rating_value',
				reviewCount: 'review_count',
				bestRating: 'best_rating',
				worstRating: 'worst_rating',

				// Medical fields.
				about: 'about',
				medicalSpecialty: 'medical_specialty',
				possibleTreatment: 'possible_treatment',
				possibleComplication: 'possible_complication',
				signOrSymptom: 'sign_or_symptom',

				// Website fields.
				searchAction: 'search_action',
				itemListElement: 'itemListElement',
			};

			return fieldMappings[ fieldId ] || 'site_name';
		}

		/**
		 * Check if schema has saved data and show indicator.
		 *
		 * @since 2.1.0
		 */
		updateSchemaStatusIndicators() {
			$.get(
				srk_ajax_object.ajax_url,
				{
					action: 'srk_get_assigned_schemas',
				},
				( response ) => {
					if ( response.success ) {
						response.data.forEach( ( schema ) => {
							const $status = $( `.assignment-status[data-schema="${ schema.type }"]` );
							if ( $status.length ) {
								$status.show();
								$status.find( '.badge' ).text( `✓ Assigned to: ${ schema.post_type }` );

								// Add checkmark to the schema checkbox.
								$( `.srk-schema-input[value="${ schema.type }"]` ).closest( '.srk-schema-item' ).addClass( 'has-settings' );
							}
						} );
					}
				}
			);
		}

		/**
		 * Get schema class instance.
		 *
		 * @since 2.1.0
		 * @param {string} schemaKey Schema key.
		 * @return {Object} Schema class instance.
		 */
		getSchemaClass( schemaKey ) {
			// Return the appropriate schema class based on the schema key.
			if ( ! this.schemaClasses[ schemaKey ] ) {
				// Dynamically create schema class instances.
				switch ( schemaKey ) {
					case 'organization':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_OrganizationSchema( this );
						break;
					case 'local_business':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_LocalBusinessSchema( this );
						break;
					case 'corporation':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_CorporationSchema( this );
						break;
					case 'author':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_AuthorSchema( this );
						break;
					case 'website':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_WebsiteSchema( this );
						break;
					// Content Schemas.
					case 'article':
						this.schemaClasses[ schemaKey ] = new window.SRK.ArticleSchema( this );
						break;
					case 'product':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_ProductSchema( this );
						break;
					case 'blog_posting':
						this.schemaClasses[ schemaKey ] = new window.SRK.BlogPostingSchema( this );
						break;
					case 'news_article':
						this.schemaClasses[ schemaKey ] = new window.SRK.NewsArticleSchema( this );
						break;
					case 'faq':
						this.schemaClasses[ schemaKey ] = new window.SRK.FaqSchema( this );
						break;
					// Coming Soon Schemas.
					case 'how_to':
						this.schemaClasses[ schemaKey ] = new window.SRK.HowToSchema( this );
						break;
					case 'video_object':
						this.schemaClasses[ schemaKey ] = new window.SRK.VideoObjectSchema( this );
						break;
					case 'reservation':
						this.schemaClasses[ schemaKey ] = new window.SRK.ReservationSchema( this );
						break;
					case 'medical_web_page':
						this.schemaClasses[ schemaKey ] = new window.SRK.MedicalWebPageSchema( this );
						break;
					case 'review':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_ReviewSchema( this );
						break;
					case 'event':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_EventSchema( this );
						break;
					case 'job_posting':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_JobPostingSchema( this );
						break;
					case 'course':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_CourseSchema( this );
						break;
					case 'recipe':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_RecipeSchema( this );
						break;
					case 'medical_condition':
						this.schemaClasses[ schemaKey ] = new window.SRK.SRK_MedicalConditionSchema( this );
						break;
					default:
						// Create a generic schema instance for types without specific classes.
						this.schemaClasses[ schemaKey ] = new window.SRK.BaseSchema( this );
						this.schemaClasses[ schemaKey ].schemaKey = schemaKey;
						break;
				}
			}

			return this.schemaClasses[ schemaKey ];
		}

	}

	// Initialize the schema manager.
	// ✅ FIX: Wait for jQuery and srk_ajax_object to be available before initializing
	(function() {
		function initSchemaManager() {
			// Check if dependencies are available
			if ( typeof jQuery === 'undefined' || typeof srk_ajax_object === 'undefined' ) {
				// Wait a bit and retry
				setTimeout( initSchemaManager, 100 );
				return;
			}
			
			// Initialize Schema Manager
			window.SRK.SchemaManager = new SchemaManager();
		}
		
		// Start initialization
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', initSchemaManager );
		} else {
			// DOM already loaded, but wait for scripts
			setTimeout( initSchemaManager, 0 );
		}
	})();

	// Initialize on page load.
	$( document ).ready( function() {
		$( '#srk-json-preview-container' ).hide();
		
		// ✅ FIX: Wait for SchemaManager to be initialized
		function updateIndicators() {
			if ( window.SRK && window.SRK.SchemaManager && typeof window.SRK.SchemaManager.updateSchemaStatusIndicators === 'function' ) {
				window.SRK.SchemaManager.updateSchemaStatusIndicators();
			} else {
				// Retry if not ready yet
				setTimeout( updateIndicators, 100 );
			}
		}
		
		updateIndicators();

		// Also update after saving.
		$( document ).ajaxSuccess( function( event, xhr, settings ) {
			if ( settings.data && settings.data.includes( 'action=srk_save_schema_assignment' ) ) {
				if ( window.SRK && window.SRK.SchemaManager && typeof window.SRK.SchemaManager.updateSchemaStatusIndicators === 'function' ) {
					window.SRK.SchemaManager.updateSchemaStatusIndicators();
				}
			}
		} );

		// ✅ Extend colorful line to match scrollable width
		function updateColorfulLineWidth() {
			const $wrapper = $( '#srk-schema-config-wrapper' );
			if ( $wrapper.length ) {
				const scrollWidth = $wrapper[0].scrollWidth;
				const clientWidth = $wrapper[0].clientWidth;
				
				// Only update if content is wider than visible area
				if ( scrollWidth > clientWidth ) {
					$wrapper.css( '--line-width', scrollWidth + 'px' );
				} else {
					$wrapper.css( '--line-width', '100%' );
				}
			}
		}

		// Update on load
		setTimeout( updateColorfulLineWidth, 100 );

		// Update on scroll (to handle dynamic content)
		$( '#srk-schema-config-wrapper' ).on( 'scroll', updateColorfulLineWidth );

		// Update when content changes (using MutationObserver)
		const $wrapper = $( '#srk-schema-config-wrapper' );
		if ( $wrapper.length ) {
			const observer = new MutationObserver( function() {
				setTimeout( updateColorfulLineWidth, 50 );
			} );
			observer.observe( $wrapper[0], {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: [ 'style', 'class' ]
			} );
		}

		// Update on window resize
		$( window ).on( 'resize', updateColorfulLineWidth );
	} );
} )( jQuery );