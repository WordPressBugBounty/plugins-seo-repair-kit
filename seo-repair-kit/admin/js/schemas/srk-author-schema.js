/**
 * Author Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.1
 */
 
(function ($) {
    'use strict';

    /**
     * Author Schema class.
     * Supports both Organization and Person types.
     *
     * @since 2.1.1
     */
    class SRK_AuthorSchema extends window.SRK.BaseSchema {

        /**
         * Initialize Author Schema.
         *
         * @since 2.1.1
         * @param {Object} manager Schema manager instance.
         */
        constructor(manager) {
            super(manager);
            this.schemaKey = 'author';
            this.authorType = 'Person'; // Default to Person
        }

        /**
         * Override getFields to exclude common fields and return only essential Author fields.
         * This prevents duplicate fields from BaseSchema commonFields.
         * Dynamically gets author type from selector or saved settings.
         *
         * @since 2.1.1
         * @return {Array} Essential Author schema fields.
         */
        getFields() {
            // First check if manager has temporary authorType (from selector change)
            let authorType = this.manager?.tempAuthorType;
            
            // If not set, check the type selector in the UI
            if (!authorType) {
                const $typeSelector = $('.srk-author-type-select');
                if ($typeSelector.length && $typeSelector.val()) {
                    authorType = $typeSelector.val();
                }
            }
            
            // If still not set, get from saved settings
            if (!authorType) {
                const savedSettings = this.manager.getSavedSchemaSettings('author');
                authorType = savedSettings?.authorType || this.authorType || 'Person';
            }
            
            return this.getSpecificFields(authorType);
        }

        /**
         * Get specific fields for author schema.
         * Fields match Schema.org Organization and Person standards exactly.
         *
         * @since 2.1.1
         * @param {string} authorType Author type (Person or Organization) - optional, will be fetched if not provided.
         * @return {Array} Fields according to Schema.org standards.
         */
        getSpecificFields(authorType = null) {
            // Get author type if not provided - check from selector first, then saved settings
            if (!authorType) {
                // Try to get from the type selector in the UI
                const $typeSelector = $('.srk-author-type-select');
                if ($typeSelector.length && $typeSelector.val()) {
                    authorType = $typeSelector.val();
                } else {
                    const savedSettings = this.manager.getSavedSchemaSettings('author');
                    authorType = savedSettings?.authorType || this.authorType || 'Person';
                }
            }

            if (authorType === 'Organization') {
                // Organization fields according to Schema.org standards
                return [
                    { id: 'name', name: 'Name', required: true, placeholder: 'Organization name' },
                    { id: 'url', name: 'URL', placeholder: 'https://organization-website.com' },
                    { id: 'logo', name: 'Logo', placeholder: 'Organization logo URL' },
                    { id: 'email', name: 'Email', placeholder: 'contact@organization.com' },
                    { id: 'telephone', name: 'Telephone', placeholder: '+1 234 567 890' },
                    { id: 'address', name: 'Address', type: 'address', placeholder: 'Complete address information' },
                ];
            } else {
                // Person fields according to Schema.org standards
                return [
                    { id: 'name', name: 'Name', required: true, placeholder: 'Full name' },
                    { id: 'givenName', name: 'Given Name', placeholder: 'First name' },
                    { id: 'familyName', name: 'Family Name', placeholder: 'Last name' },
                    { id: 'jobTitle', name: 'Job Title', placeholder: 'Job title or position' },
                    { id: 'email', name: 'Email', placeholder: 'author@email.com' },
                    { id: 'telephone', name: 'Telephone', placeholder: '(425) 123-4567' },
                    { id: 'address', name: 'Address', type: 'address', placeholder: 'Complete address information' },
                    { id: 'image', name: 'Image', placeholder: 'Author image URL' },
                    { id: 'url', name: 'URL', placeholder: 'https://author-website.com' },
                ];
            }
        }

        /**
         * Override handleSelection to always show type selector first.
         * Author schema should always show type selector regardless of global/post-type.
         *
         * @since 2.1.1
         */
        handleSelection() {
            const container = $('#srk-schema-config-wrapper');
            const self = this;
            
            // Check if there's a saved configuration to determine if it's global or post-type
            $.post(
                srk_ajax_object.ajax_url,
                {
                    action: 'srk_get_schema_configuration',
                    schema: 'author',
                    nonce: srk_ajax_object.schema_nonce || '',
                },
                (response) => {
                    let savedPostType = '';
                    if (response.success && response.data.configured) {
                        savedPostType = response.data.post_type || '';
                    }
                    
                    // If there's a saved post_type, use post-type schema loader (which shows type selector first)
                    // Otherwise use global schema loader (which also shows type selector first)
                    if (savedPostType && savedPostType !== 'global') {
                        self.loadPostTypeSchema(container);
                    } else {
                        self.loadGlobalSchema(container);
                    }
                }
            ).fail(function() {
                // Fallback to global schema
                self.loadGlobalSchema(container);
            });
        }

        /**
         * Override loadPostTypeSchema to show type selector first.
         * This ensures type selector appears even if author is treated as post-type.
         *
         * @since 2.1.1
         * @param {Object} container jQuery container object.
         */
        loadPostTypeSchema(container) {
            const self = this;
            
            // First, check if there's a saved configuration
            $.post(
                srk_ajax_object.ajax_url,
                {
                    action: 'srk_get_schema_configuration',
                    schema: 'author',
                    nonce: srk_ajax_object.schema_nonce || '',
                },
                (response) => {
                    let savedAuthorType = '';
                    let savedPostType = '';
                    let hasSavedConfig = false;
                    
                    if (response.success && response.data.configured) {
                        hasSavedConfig = true;
                        savedAuthorType = response.data.authorType || '';
                        savedPostType = response.data.post_type || '';
                        // Store saved mappings for later use
                        self.savedMappings = response.data.meta_map || {};
                        self.savedEnabledFields = response.data.enabled_fields || [];
                    }
                    
                    // Get post types for the dropdown
                    $.post(
                        srk_ajax_object.ajax_url,
                        {
                            action: 'srk_get_post_types',
                            nonce: srk_ajax_object.schema_nonce || '',
                        },
                        (postTypesResponse) => {
                            let postTypeOptions = '<option value="">Select Post Type</option>';
                            if (postTypesResponse.success) {
                                postTypeOptions = postTypesResponse.data;
                            }
                            
                            // Render UI with type selector FIRST, then post type selector
                            container.html(`
                                <div class="srk-schema-config-card">
                                    <h3>${this.formatSchemaName(this.schemaKey)} Configuration</h3>
                                    <div class="srk-author-type-selector-wrapper" style="margin-bottom: 20px; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                                        <label style="display: block; margin-bottom: 12px; font-weight: 600; font-size: 14px; color: #1f2937;">
                                            Select Author Type <span style="color: #d63638;">*</span>
                                        </label>
                                        <select name="authorType" class="srk-author-type-select" style="width: 100%; max-width: 400px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #fff;">
                                            <option value="">-- Select Type --</option>
                                            <option value="Person" ${savedAuthorType === 'Person' ? 'selected' : ''}>Person</option>
                                            <option value="Organization" ${savedAuthorType === 'Organization' ? 'selected' : ''}>Organization</option>
                                        </select>
                                        <p style="margin-top: 10px; font-size: 13px; color: #6b7280; line-height: 1.5;">
                                            <strong>Person:</strong> For individual authors (e.g., blog writers, content creators)<br>
                                            <strong>Organization:</strong> For organizational authors (e.g., companies, institutions)
                                        </p>
                                    </div>
                                    <div class="srk-form-group" style="margin-bottom: 20px;">
                                        <label for="assign-author">Assign to Post Type:</label>
                                        <select id="assign-author" class="srk-form-select">
                                            ${postTypeOptions}
                                        </select>
                                    </div>
                                    <div class="srk-meta-placeholder"></div>
                                </div>
                            `);
                            
                            // Set saved post type if exists
                            if (savedPostType) {
                                $('#assign-author').val(savedPostType);
                            }
                            
                            // Use event delegation for type selector
                            $(document).off('change', '.srk-author-type-select').on('change', '.srk-author-type-select', function() {
                                const selectedType = $(this).val();
                                
                                if (!selectedType) {
                                    container.find('.srk-meta-placeholder').html('');
                                    return;
                                }
                                
                                self.authorType = selectedType;
                                if (self.manager) {
                                    self.manager.tempAuthorType = selectedType;
                                }
                                
                                // Only load fields if post type is also selected
                                const selectedPostType = $('#assign-author').val();
                                if (selectedPostType) {
                                    self.loadFieldsForType(selectedType, container);
                                } else {
                                    container.find('.srk-meta-placeholder').html(`
                                        <div style="padding: 20px; text-align: center; color: #6b7280; background: #f9fafb; border-radius: 8px;">
                                            <p style="margin: 0; font-size: 14px;">Please select a Post Type above to configure the fields.</p>
                                        </div>
                                    `);
                                }
                            });
                            
                            // Handle post type change - load fields if author type is also selected
                            $('#assign-author').on('change', function() {
                                const selectedPostType = $(this).val();
                                const selectedAuthorType = $('.srk-author-type-select').val();
                                
                                if (selectedPostType && selectedAuthorType) {
                                    self.loadFieldsForType(selectedAuthorType, container);
                                } else if (!selectedPostType) {
                                    container.find('.srk-meta-placeholder').html('');
                                } else {
                                    container.find('.srk-meta-placeholder').html(`
                                        <div style="padding: 20px; text-align: center; color: #6b7280; background: #f9fafb; border-radius: 8px;">
                                            <p style="margin: 0; font-size: 14px;">Please select an Author Type above to configure the fields.</p>
                                        </div>
                                    `);
                                }
                            });
                            
                            // If we have both saved author type and post type, load fields
                            if (hasSavedConfig && savedAuthorType && savedPostType) {
                                self.authorType = savedAuthorType;
                                if (self.manager) {
                                    self.manager.tempAuthorType = savedAuthorType;
                                }
                                setTimeout(() => {
                                    self.loadFieldsForType(savedAuthorType, container);
                                }, 300);
                            } else if (!savedAuthorType) {
                                container.find('.srk-meta-placeholder').html(`
                                    <div style="padding: 20px; text-align: center; color: #6b7280; background: #f9fafb; border-radius: 8px;">
                                        <p style="margin: 0; font-size: 14px;">Please select an Author Type above to configure the fields.</p>
                                    </div>
                                `);
                            }
                        }
                    );
                }
            );
        }

        /**
         * Unified method to load author schema with type selector.
         * Works for both global and post-type assignments.
         *
         * @since 2.1.1
         * @param {Object} container jQuery container object.
         */
        loadAuthorSchemaWithTypeSelector(container) {
            // This is the same as loadGlobalSchema but works for both cases
            this.loadGlobalSchema(container);
        }

        /**
         * Override loadGlobalSchema to show type selector first, then fields.
         *
         * @since 2.1.1
         * @param {Object} container jQuery container object.
         */
        loadGlobalSchema(container) {
            const self = this;
            
            // First, check if there's a saved configuration
            $.post(
                srk_ajax_object.ajax_url,
                {
                    action: 'srk_get_schema_configuration',
                    schema: 'author',
                    nonce: srk_ajax_object.schema_nonce || '',
                },
                (response) => {
                    let savedAuthorType = ''; // Start with empty - user must select
                    let hasSavedConfig = false;
                    
                    if (response.success && response.data.configured) {
                        hasSavedConfig = true;
                        savedAuthorType = response.data.authorType || '';
                        // Store saved mappings for later use
                        self.savedMappings = response.data.meta_map || {};
                        self.savedEnabledFields = response.data.enabled_fields || [];
                    }
                    
                    // Render the initial UI with type selector
                    container.html(`
                        <div class="srk-schema-config-card">
                            <h3>${this.formatSchemaName(this.schemaKey)} Configuration</h3>
                            <div class="srk-global-notice">
                                <p>This schema will be applied globally to the entire site.</p>
                            </div>
                            <div class="srk-author-type-selector-wrapper" style="margin-bottom: 20px; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                                <label style="display: block; margin-bottom: 12px; font-weight: 600; font-size: 14px; color: #1f2937;">
                                    Select Author Type <span style="color: #d63638;">*</span>
                                </label>
                                <select name="authorType" class="srk-author-type-select" style="width: 100%; max-width: 400px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #fff;">
                                    <option value="">-- Select Type --</option>
                                    <option value="Person" ${savedAuthorType === 'Person' ? 'selected' : ''}>Person</option>
                                    <option value="Organization" ${savedAuthorType === 'Organization' ? 'selected' : ''}>Organization</option>
                                </select>
                                <p style="margin-top: 10px; font-size: 13px; color: #6b7280; line-height: 1.5;">
                                    <strong>Person:</strong> For individual authors (e.g., blog writers, content creators)<br>
                                    <strong>Organization:</strong> For organizational authors (e.g., companies, institutions)
                                </p>
                            </div>
                            <div class="srk-meta-placeholder"></div>
                        </div>
                    `);
                    
                    // Use event delegation to handle type selector change (more reliable)
                    $(document).off('change', '.srk-author-type-select').on('change', '.srk-author-type-select', function() {
                        const selectedType = $(this).val();
                        
                        if (!selectedType) {
                            // Clear fields if no type selected
                            container.find('.srk-meta-placeholder').html('');
                            return;
                        }
                        
                        // Update author type
                        self.authorType = selectedType;
                        if (self.manager) {
                            self.manager.tempAuthorType = selectedType;
                        }
                        
                        // Load fields for selected type
                        self.loadFieldsForType(selectedType, container);
                    });
                    
                    // If we have a saved configuration with a type, automatically load fields
                    if (hasSavedConfig && savedAuthorType) {
                        // Set the author type
                        self.authorType = savedAuthorType;
                        if (self.manager) {
                            self.manager.tempAuthorType = savedAuthorType;
                        }
                        
                        // Small delay to ensure DOM is ready and event handlers are attached
                        setTimeout(() => {
                            self.loadFieldsForType(savedAuthorType, container);
                        }, 200);
                    } else {
                        // Show message to select type
                        container.find('.srk-meta-placeholder').html(`
                            <div style="padding: 20px; text-align: center; color: #6b7280; background: #f9fafb; border-radius: 8px;">
                                <p style="margin: 0; font-size: 14px;">Please select an Author Type above to configure the fields.</p>
                            </div>
                        `);
                    }
                }
            ).fail(function() {
                // Fallback if AJAX fails
                container.html(`
                    <div class="srk-schema-config-card">
                        <h3>${self.formatSchemaName(self.schemaKey)} Configuration</h3>
                        <div class="srk-global-notice">
                            <p>This schema will be applied globally to the entire site.</p>
                        </div>
                        <div class="srk-author-type-selector-wrapper" style="margin-bottom: 20px; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                            <label style="display: block; margin-bottom: 12px; font-weight: 600; font-size: 14px; color: #1f2937;">
                                Select Author Type <span style="color: #d63638;">*</span>
                            </label>
                            <select name="authorType" class="srk-author-type-select" style="width: 100%; max-width: 400px; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: #fff;">
                                <option value="">-- Select Type --</option>
                                <option value="Person">Person</option>
                                <option value="Organization">Organization</option>
                            </select>
                            <p style="margin-top: 10px; font-size: 13px; color: #6b7280; line-height: 1.5;">
                                <strong>Person:</strong> For individual authors (e.g., blog writers, content creators)<br>
                                <strong>Organization:</strong> For organizational authors (e.g., companies, institutions)
                            </p>
                        </div>
                        <div class="srk-meta-placeholder">
                            <div style="padding: 20px; text-align: center; color: #6b7280; background: #f9fafb; border-radius: 8px;">
                                <p style="margin: 0; font-size: 14px;">Please select an Author Type above to configure the fields.</p>
                            </div>
                        </div>
                    </div>
                `);
                
                // Attach event handler
                $(document).off('change', '.srk-author-type-select').on('change', '.srk-author-type-select', function() {
                    const selectedType = $(this).val();
                    if (selectedType) {
                        self.authorType = selectedType;
                        if (self.manager) {
                            self.manager.tempAuthorType = selectedType;
                        }
                        self.loadFieldsForType(selectedType, container);
                    }
                });
            });
        }
        
        /**
         * Load fields for a specific author type.
         *
         * @since 2.1.1
         * @param {string} authorType Author type (Person or Organization).
         * @param {Object} container jQuery container object.
         */
        loadFieldsForType(authorType, container) {
            const self = this;
            const $metaPlaceholder = container.find('.srk-meta-placeholder');
            
            if (!$metaPlaceholder.length) {
                console.error('Author Schema: Could not find .srk-meta-placeholder');
                return;
            }
            
            // Validate authorType
            if (!authorType || (authorType !== 'Person' && authorType !== 'Organization')) {
                $metaPlaceholder.html(`
                    <div style="padding: 20px; text-align: center; color: #d63638; background: #fef2f2; border-radius: 8px;">
                        <p style="margin: 0; font-size: 14px;">Invalid author type. Please select Person or Organization.</p>
                    </div>
                `);
                return;
            }
            
            // Show loading indicator
            $metaPlaceholder.html(`
                <div style="text-align: center; padding: 20px;">
                    <div class="spinner is-active" style="float: none; margin: 0 auto 10px;"></div>
                    <p style="color: #6b7280; font-size: 13px;">Loading fields for ${authorType}...</p>
                </div>
            `);
            
            // Set the type BEFORE calling getFields() - this is critical!
            self.authorType = authorType;
            if (self.manager) {
                self.manager.tempAuthorType = authorType;
            }
            
            // Small delay to ensure type is set, then load fields
            setTimeout(() => {
                try {
                    // Verify getFields() will return correct fields
                    const testFields = self.getFields();
                    if (!testFields || testFields.length === 0) {
                        console.error('Author Schema: getFields() returned no fields for type:', authorType);
                        $metaPlaceholder.html(`
                            <div style="padding: 20px; text-align: center; color: #d63638; background: #fef2f2; border-radius: 8px;">
                                <p style="margin: 0; font-size: 14px;">Error: Could not load fields for ${authorType}. Please try again.</p>
                            </div>
                        `);
                        return;
                    }
                    
                    // Load global schema fields - this will use getFields() which now has the correct type
                    self.loadGlobalSchemaFields();
                    
                    // After fields are loaded, restore saved mappings if they exist
                    if (self.savedMappings && Object.keys(self.savedMappings).length > 0) {
                        setTimeout(() => {
                            self.restoreSavedMappings();
                        }, 800);
                    }
                    
                    // Initialize field enable toggle and other features
                    setTimeout(() => {
                        // Show JSON preview container
                        $('#srk-json-preview-container').show();
                        
                        // Initialize source selector for global fields
                        if (typeof self.initSourceSelector === 'function') {
                            self.initSourceSelector();
                        }
                        
                        // Initialize field enable toggle
                        if (typeof self.initFieldEnableToggle === 'function') {
                            self.initFieldEnableToggle();
                        }
                        
                        // Auto-enable required fields
                        if (typeof self.autoEnableRequiredFields === 'function') {
                            self.autoEnableRequiredFields(self.schemaKey);
                        }
                        
                        // Restore checkbox states
                        if (typeof self.restoreCheckboxStates === 'function') {
                            self.restoreCheckboxStates();
                        }
                        
                        // Update JSON preview
                        if (self.manager && typeof self.manager.updateJsonPreview === 'function') {
                            self.manager.updateJsonPreview();
                        }
                    }, 1000);
                    
                } catch (error) {
                    console.error('Author Schema: Error loading fields:', error);
                    $metaPlaceholder.html(`
                        <div style="padding: 20px; text-align: center; color: #d63638; background: #fef2f2; border-radius: 8px;">
                            <p style="margin: 0; font-size: 14px;">Error loading fields. Please refresh the page and try again.</p>
                        </div>
                    `);
                }
            }, 100);
        }
        
        /**
         * Restore saved field mappings.
         *
         * @since 2.1.1
         */
        restoreSavedMappings() {
            if (!this.savedMappings) return;
            
            const self = this;
            const addressSubFields = ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'];
            
            Object.keys(this.savedMappings).forEach((fieldKey) => {
                const mapping = this.savedMappings[fieldKey];
                
                // Handle address sub-fields
                if (addressSubFields.includes(fieldKey)) {
                    const $addressInput = $(`input[name="meta_map[${fieldKey}]"]`);
                    if ($addressInput.length && mapping) {
                        // Extract value from custom:value format
                        let value = mapping;
                        if (mapping.includes(':')) {
                            const parts = mapping.split(':');
                            value = parts.slice(1).join(':');
                        }
                        $addressInput.val(value);
                    }
                    return;
                }
                
                const $fieldSelect = $(`select[name="meta_map[${fieldKey}]"]`);
                
                if ($fieldSelect.length && mapping) {
                    // Determine the source type
                    let sourceType = 'custom';
                    let sourceValue = mapping;
                    
                    if (mapping.includes(':')) {
                        const parts = mapping.split(':');
                        sourceType = parts[0];
                        sourceValue = parts.slice(1).join(':');
                    }
                    
                    // Set the group selector
                    const $groupSelector = $fieldSelect.closest('.srk-schema-field-mapping').find('.srk-group-selector, .srk-global-selector');
                    if ($groupSelector.length) {
                        if (sourceType === 'site') {
                            $groupSelector.val('site').trigger('change');
                        } else if (sourceType === 'custom') {
                            $groupSelector.val('custom').trigger('change');
                        }
                    }
                    
                    // Set the field value after a short delay to allow options to load
                    setTimeout(() => {
                        $fieldSelect.val(mapping);
                        if ($fieldSelect.val() !== mapping) {
                            // If select doesn't have the value, try setting it as custom
                            const $customInput = $fieldSelect.siblings('.srk-value-container').find('input');
                            if ($customInput.length && sourceType === 'custom') {
                                $customInput.val(sourceValue).prop('disabled', false);
                            }
                        }
                    }, 300);
                }
            });
            
            // Restore enabled fields
            if (this.savedEnabledFields && Array.isArray(this.savedEnabledFields)) {
                this.savedEnabledFields.forEach((fieldKey) => {
                    const $checkbox = $(`.srk-field-enable[data-field="${fieldKey}"]`);
                    if ($checkbox.length) {
                        $checkbox.prop('checked', true);
                        const $fieldRow = $checkbox.closest('.srk-meta-row');
                        const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
                        $mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);
                    }
                });
            }
        }
        
        /**
         * Render schema-specific UI elements.
         * This is called by BaseSchema but we handle UI in loadGlobalSchema instead.
         *
         * @since 2.1.1
         * @param {jQuery} $container Container element.
         * @param {Object} savedSettings Saved schema settings.
         */
        renderSchemaSpecificUI($container, savedSettings) {
            // UI is handled in loadGlobalSchema, so this can be empty
            // But we keep it for compatibility
        }
        
        /**
         * Apply schema specific preview for author.
         *
         * @since 2.1.1
         * @param {Object} jsonData JSON data object.
         * @param {Object} metaMap Meta mapping object.
         */
        applySchemaSpecificPreview(jsonData, metaMap) {
            // Get author type from selector first (current selection), then saved settings
            let authorType = 'Person'; // Default
            
            const $typeSelector = $('.srk-author-type-select');
            if ($typeSelector.length && $typeSelector.val()) {
                authorType = $typeSelector.val();
            } else if (this.manager && this.manager.tempAuthorType) {
                authorType = this.manager.tempAuthorType;
            } else {
                const savedSettings = this.manager.getSavedSchemaSettings('author');
                authorType = savedSettings?.authorType || 'Person';
            }
            
            // Set @type based on selection
            jsonData['@type'] = authorType;

            // Email and telephone are common to both types
            if (metaMap.email) {
                const emailValue = this.getPreviewValue(metaMap.email);
                if (emailValue && emailValue.trim() !== '') {
                    jsonData.email = emailValue;
                }
            }

            if (metaMap.telephone) {
                const telephoneValue = this.getPreviewValue(metaMap.telephone);
                if (telephoneValue && telephoneValue.trim() !== '') {
                    jsonData.telephone = telephoneValue;
                }
            }

            if (authorType === 'Organization') {
                // Organization-specific fields
                if (metaMap.logo) {
                    const logoValue = this.getPreviewValue(metaMap.logo);
                    if (logoValue && logoValue.trim() !== '') {
                        jsonData.logo = logoValue;
                    }
                }

                // Address for Organization
                const streetAddress = metaMap.streetAddress ? this.getPreviewValue(metaMap.streetAddress) : '';
                const addressLocality = metaMap.addressLocality ? this.getPreviewValue(metaMap.addressLocality) : '';
                const addressRegion = metaMap.addressRegion ? this.getPreviewValue(metaMap.addressRegion) : '';
                const postalCode = metaMap.postalCode ? this.getPreviewValue(metaMap.postalCode) : '';
                const addressCountry = metaMap.addressCountry ? this.getPreviewValue(metaMap.addressCountry) : '';
                
                if (streetAddress || addressLocality || addressRegion || postalCode || addressCountry) {
                    jsonData.address = {
                        '@type': 'PostalAddress'
                    };
                    if (streetAddress) jsonData.address.streetAddress = streetAddress;
                    if (addressLocality) jsonData.address.addressLocality = addressLocality;
                    if (addressRegion) jsonData.address.addressRegion = addressRegion;
                    if (postalCode) jsonData.address.postalCode = postalCode;
                    if (addressCountry) {
                        jsonData.address.addressCountry = {
                            '@type': 'Country',
                            'name': addressCountry
                        };
                    }
                }

                // Note: ContactPoint and social profiles removed - not essential for Author schema
                // Keeping backward compatibility: if old data exists, it will still work
            } else {
                // Person-specific fields
                if (metaMap.givenName) {
                    const givenName = this.getPreviewValue(metaMap.givenName);
                    if (givenName) jsonData.givenName = givenName;
                }

                if (metaMap.familyName) {
                    const familyName = this.getPreviewValue(metaMap.familyName);
                    if (familyName) jsonData.familyName = familyName;
                }

                if (metaMap.jobTitle) {
                    const jobTitle = this.getPreviewValue(metaMap.jobTitle);
                    if (jobTitle) jsonData.jobTitle = jobTitle;
                }

                // Image for Person (according to Schema.org Person example - simple URL string)
                if (metaMap.image) {
                    const imageValue = this.getPreviewValue(metaMap.image);
                    if (imageValue && imageValue.trim() !== '') {
                        jsonData.image = imageValue;
                    }
                }

                // Address for Person (according to Schema.org Person example)
                const streetAddress = metaMap.streetAddress ? this.getPreviewValue(metaMap.streetAddress) : '';
                const addressLocality = metaMap.addressLocality ? this.getPreviewValue(metaMap.addressLocality) : '';
                const addressRegion = metaMap.addressRegion ? this.getPreviewValue(metaMap.addressRegion) : '';
                const postalCode = metaMap.postalCode ? this.getPreviewValue(metaMap.postalCode) : '';
                const addressCountry = metaMap.addressCountry ? this.getPreviewValue(metaMap.addressCountry) : '';
                
                if (streetAddress || addressLocality || addressRegion || postalCode || addressCountry) {
                    jsonData.address = {
                        '@type': 'PostalAddress'
                    };
                    if (streetAddress) jsonData.address.streetAddress = streetAddress;
                    if (addressLocality) jsonData.address.addressLocality = addressLocality;
                    if (addressRegion) jsonData.address.addressRegion = addressRegion;
                    if (postalCode) jsonData.address.postalCode = postalCode;
                    if (addressCountry) {
                        jsonData.address.addressCountry = {
                            '@type': 'Country',
                            'name': addressCountry
                        };
                    }
                }

            }
        }
        
        /**
         * Fetch mapped or literal preview value.
         */
        getPreviewValue(mapping) {
            if (!mapping) return '';

            // Support for 'site:' and 'custom:'
            if (mapping.includes(':')) {
                const [type, key] = mapping.split(':');
                if (type === 'custom') return key || '';
                return `[${type}:${key}]`;
            }
            return mapping;
        }
    }

    // Register with global namespace.
    if (typeof window.SRK === 'undefined') {
        window.SRK = {};
    }
    window.SRK.SRK_AuthorSchema = SRK_AuthorSchema;

})(jQuery);
