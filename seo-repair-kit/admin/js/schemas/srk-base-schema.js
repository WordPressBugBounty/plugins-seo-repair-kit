/**
 * Base Schema Class for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

(function($) {
    'use strict';

    // 🔒 FEATURE LOCK: read from localized data (server-controlled, cannot be faked to bypass server).
    const SRK_LOCK = (typeof window !== 'undefined' && window.srk_ajax_object && window.srk_ajax_object.feature_lock) ?
        window.srk_ajax_object.feature_lock :
        { enabled: false, can_save: true, can_preview: true, can_map: true, can_validate: true, reason: '' };
    
    // ✅ Ensure can_validate is defined (default to true if not set)
    if (typeof SRK_LOCK.can_validate === 'undefined') {
        SRK_LOCK.can_validate = true;
    }

    const SRK_SUBSCRIBE_URL = (window.srk_ajax_object && window.srk_ajax_object.subscribe_url) ? window.srk_ajax_object.subscribe_url : '#';

    // 🔐 NONCE for all AJAX calls
    const SRK_NONCE = (window.srk_ajax_object && window.srk_ajax_object.schema_nonce) ? window.srk_ajax_object.schema_nonce : '';

    /**
     * Helper that attaches nonce to payload.
     *
     * @since 2.1.0
     * @param {Object} payload AJAX payload.
     * @return {Object} Payload with nonce.
     */
    function withNonce(payload) {
        payload = payload || {};
        payload._srk_schema_nonce = SRK_NONCE;
        return payload;
    }

    /**
     * UI helper to show lock banner once.
     *
     * @since 2.1.0
     */
    function showLockNotice() {
        const $n = $('#srk-lock-notice');
        if ($n.length) {
            const reason = SRK_LOCK.reason ? ' (' + SRK_LOCK.reason + ')' : '';
            $n.html(
                '<div class="notice notice-warning" style="padding:10px;">' +
                'Schema Manager is locked' + reason + '. ' +
                '<a href="' + SRK_SUBSCRIBE_URL + '" target="_blank" rel="noopener" class="button button-primary" style="margin-left:8px;">Get Premium</a>' +
                '</div>'
            ).show();
        }
    }

    /**
     * Disable UI if locked (client-side UX only; server still enforces).
     *
     * @since 2.1.0
     */
    function applyClientLock() {
        if (!SRK_LOCK.enabled) {
            // ✅ Even if not locked, check can_validate flag for validation button
            if (SRK_LOCK.can_validate === false) {
                $('#srk-validate-schema').addClass('disabled').on('click', function(e) {
                    e.preventDefault();
                    showLockNotice();
                    return false;
                });
            }
            return;
        }

        // Disable all checkboxes & selectors in the left list and middle panel.
        $('.srk-schema-input').prop('disabled', true);
        $('#srk-schema-config-wrapper').css({ opacity: 0.5, pointerEvents: 'none' });

        // Disable Save, Preview, Validate.
        $('#srk-save-schema-settings').addClass('button-secondary').prop('disabled', true);
        $('#srk-validate-schema').addClass('disabled').on('click', function(e) {
            e.preventDefault();
        });

        // Hide preview JSON initially.
        $('#srk-json-preview-container').hide();
        $('#srk-json-preview-loader').hide();

        // Show callout.
        showLockNotice();
    }

    // Apply on DOM ready.
    $(function() {
        applyClientLock();

        // Additionally guard buttons even if DOM mutates later.
        $(document).on('click', '#srk-save-schema-settings', function(e) {
            if (SRK_LOCK.enabled || !SRK_LOCK.can_save) {
                e.preventDefault();
                showLockNotice();
                return false;
            }
        });
        $(document).on('click', '#srk-validate-schema', function(e) {
            // ✅ Check both enabled lock and can_validate flag for validation button
            if (SRK_LOCK.enabled || !SRK_LOCK.can_preview || SRK_LOCK.can_validate === false) {
                e.preventDefault();
                showLockNotice();
                return false;
            }
        });
        
        // ✅ REMOVED: Google Rich Results Test button lock check - now uses direct link like Schema Validator
    });

    /**
     * Base Schema class for all schema types.
     *
     * @since 2.1.0
     */
    class BaseSchema {

        /**
         * Initialize Base Schema.
         *
         * @since 2.1.0
         * @param {Object} manager Schema manager instance.
         */
        constructor(manager) {
            this.manager = manager;
            this.schemaKey = '';
            
            // ✅ UPDATE: Add LocalBusiness to global schemas
            this.GLOBAL_SCHEMAS = ['website', 'organization', 'corporation', 'LocalBusiness'];
            
            this.commonFields = [
                { id: 'name', name: 'Name', placeholder: 'Site Name' },
                { id: 'description', name: 'Description', placeholder: 'Site Description' },
                { id: 'image', name: 'Logo', placeholder: 'Site Logo' },
            ];
        }

        /**
         * Get fields for schema.
         *
         * @since 2.1.0
         * @return {Array} Schema fields.
         */
        getFields() {
            const excludeCommonFor = ['faq', 'job_posting', 'breadcrumb_list', 'offer', 'website', 'author'];

            if (excludeCommonFor.includes(this.schemaKey)) {
                return this.getSpecificFields();
            }

            return [...this.commonFields, ...this.getSpecificFields()];
        }

        /**
         * Get specific fields for schema.
         *
         * @since 2.1.0
         * @return {Array} Specific schema fields.
         */
        getSpecificFields() {
            return [];
        }

        /**
         * Handle schema selection.
         *
         * @since 2.1.0
         */
        handleSelection() {
            const container = $('#srk-schema-config-wrapper');
            // ✅ UPDATE: Use this.GLOBAL_SCHEMAS instead of this.manager.GLOBAL_SCHEMAS
            if (this.GLOBAL_SCHEMAS.includes(this.schemaKey)) {
                this.loadGlobalSchema(container);
            } else {
                this.loadPostTypeSchema(container);
            }
        }

        /**
         * Load global schema configuration.
         *
         * @since 2.1.0
         * @param {Object} container jQuery container object.
         */
        loadGlobalSchema(container) {
            container.html(`
                <div class="srk-schema-config-card">
                    <h3>${this.formatSchemaName(this.schemaKey)} Configuration</h3>
                    <div class="srk-global-notice">
                        <p>This schema will be applied globally to the entire site.</p>
                    </div>
                    <div class="srk-meta-placeholder"></div>
                </div>
            `);
            this.loadGlobalSchemaFields();
        }

        /**
         * Check and load saved configuration.
         *
         * @since 2.1.0
         * @param {string} schemaKey Schema key.
         */
        checkAndLoadSavedConfiguration(schemaKey) {
            const $postTypeSelect = $(`#assign-${schemaKey}`);

            // AJAX call to check if schema already has saved configuration.
            $.post(
                srk_ajax_object.ajax_url,
                {
                    action: 'srk_get_schema_configuration',
                    schema: schemaKey,
                },
                (response) => {
                    if (response.success && response.data.configured) {
                        const config = response.data;

                        // Auto-select the saved post type.
                        if (config.post_type && config.post_type !== 'global') {
                            $postTypeSelect.val(config.post_type);

                            // Trigger change event to load fields automatically.
                            setTimeout(() => {
                                $postTypeSelect.trigger('change');
                            }, 300);
                        }
                    }
                }
            );
        }

        /**
        * Load global schema fields with enhanced UI for new fields.
        *
        * @since 2.1.0
        */
        loadGlobalSchemaFields() {
            const $metaPlaceholder = $('.srk-meta-placeholder');
            const schemaFields = this.getFields();
            const schemaKey = this.schemaKey || ''; // detect schema type
        
            let tableHtml = `
                <div class="srk-meta-mapping">
                    <h4 class="srk-meta-heading">Field Mappings</h4>
                    <table class="srk-schema-meta-table">
                        <tbody>
            `;
        
            schemaFields.forEach((field) => {
                // Default placeholder
                const placeholder = field.placeholder ? field.placeholder : 'Enter value';
        
                // ✅ FIXED: Conditionally hide "Site Information" for LocalBusiness specific fields
                let siteOption = `<option value="site">Site Information</option>`;
                
                // For LocalBusiness schema, hide Site Info for specific fields
                if (schemaKey === 'LocalBusiness' || schemaKey === 'local_business') {
                    const localBusinessCustomFields = [
                        'alternateName', 'telephone', 'priceRange', 'address', 
                        'openingHours', 'latitude', 'longitude', 'keywords'
                    ];
                    
                    if (localBusinessCustomFields.includes(field.id)) {
                        siteOption = ''; // hide site info for these fields
                    }
                }
                // For Organization schema
                else if (schemaKey === 'organization') {
                    const orgCustomFields = [
                        'contactPoint', 'telephone', 'address', 'facebook_url',
                        'twitter_url', 'instagram_url', 'youtube_url'
                    ];
                    
                    if (orgCustomFields.includes(field.id)) {
                        siteOption = ''; // hide site info
                    }
                }
                // For Corporation schema  
                else if (schemaKey === 'corporation') {
                    const corpCustomFields = [
                        'contactPoint', 'founder', 'foundingDate'
                    ];
                    
                    if (corpCustomFields.includes(field.id)) {
                        siteOption = ''; // hide site info
                    }
                }
        
                // Start table row
                tableHtml += `
                    <tr class="srk-meta-row">
                        <td>
                            <input type="checkbox" class="srk-field-enable" data-field="${field.id}" checked>
                        </td>
                        <td class="srk-schema-meta-label">
                            ${field.name} ${field.required ? '<span class="srk-required">*</span>' : ''}
                        </td>
                        <td>
                            <div class="srk-schema-field-mapping">
                `;
        
                // Special handling for address field (structured PostalAddress)
                if (field.id === 'address' && (schemaKey === 'LocalBusiness' || schemaKey === 'local_business' || schemaKey === 'organization' || schemaKey === 'corporation' || schemaKey === 'author')) {
                    tableHtml += `
                        <div class="srk-address-fields-wrapper">
                            <select class="srk-global-selector srk-select" data-field="${field.id}">
                                <option value="">Select Source</option>
                                <option value="custom">Custom Value</option>
                            </select>
                            <div class="srk-address-subfields" style="margin-top: 10px;">
                                <div class="srk-form-group" style="margin-bottom: 8px;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Street Address:</label>
                                    <input type="text" class="srk-form-input srk-address-field" 
                                        name="meta_map[streetAddress]" data-field="streetAddress"
                                        placeholder="e.g., 100 Business Park Drive" style="width: 100%;">
                                </div>
                                <div class="srk-form-group" style="margin-bottom: 8px;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">City/Locality:</label>
                                    <input type="text" class="srk-form-input srk-address-field" 
                                        name="meta_map[addressLocality]" data-field="addressLocality"
                                        placeholder="e.g., Dallas" style="width: 100%;">
                                </div>
                                <div class="srk-form-group" style="margin-bottom: 8px;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">State/Region:</label>
                                    <input type="text" class="srk-form-input srk-address-field" 
                                        name="meta_map[addressRegion]" data-field="addressRegion"
                                        placeholder="e.g., Dallas" style="width: 100%;">
                                </div>
                                <div class="srk-form-group" style="margin-bottom: 8px;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Postal Code:</label>
                                    <input type="text" class="srk-form-input srk-address-field" 
                                        name="meta_map[postalCode]" data-field="postalCode"
                                        placeholder="e.g., TX 75201" style="width: 100%;">
                                </div>
                                <div class="srk-form-group" style="margin-bottom: 8px;">
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Country:</label>
                                    <input type="text" class="srk-form-input srk-address-field" 
                                        name="meta_map[addressCountry]" data-field="addressCountry"
                                        placeholder="e.g., USA" style="width: 100%;">
                                </div>
                            </div>
                        </div>
                    `;
                }
                // Special handling for image field
                else if (field.id === 'image' && (schemaKey === 'LocalBusiness' || schemaKey === 'local_business' || schemaKey === 'organization' || schemaKey === 'corporation')) {
                    tableHtml += `
                        <select class="srk-global-selector srk-select" data-field="${field.id}">
                            <option value="">Select Source</option>
                            ${siteOption}
                            <option value="featured_image">Site Logo</option>
                            <option value="site_logo">Site Icon</option>
                            <option value="custom">Custom Image URL</option>
                        </select>
                        <div class="srk-value-container">
                            <input type="text" class="srk-group-values srk-form-input srk-image-url-input"
                                name="meta_map[${field.id}]" data-field="${field.id}"
                                placeholder="Enter image URL or select site logo" disabled>
                        </div>
                    `;
                }
                // Special handling for logo field
                else if (field.id === 'logo' && (schemaKey === 'organization' || schemaKey === 'corporation')) {
                    tableHtml += `
                        <select class="srk-global-selector srk-select" data-field="${field.id}">
                            <option value="">Select Source</option>
                            <option value="site_logo">Site Logo</option>
                            <option value="custom">Custom Logo URL</option>
                        </select>
                        <div class="srk-value-container">
                            <input type="text" class="srk-group-values srk-form-input srk-logo-url-input"
                                name="meta_map[${field.id}]" data-field="${field.id}"
                                placeholder="Enter logo URL or select Site Logo" disabled>
                        </div>
                    `;
                }
                // Special handling for tags field
                else if (field.id === 'keywords' || field.type === 'tags') {
                    tableHtml += `
                        <select class="srk-global-selector srk-select" data-field="${field.id}">
                            <option value="">Select Source</option>
                            ${siteOption}
                            <option value="custom">Custom Value</option>
                        </select>
                        <div class="srk-value-container">
                            <input type="text" class="srk-group-values srk-form-input srk-tags-input"
                                name="meta_map[${field.id}]" data-field="${field.id}"
                                placeholder="${placeholder}" disabled>
                        </div>
                    `;
                }
                // Special handling for latitude field (geo coordinates)
                else if (field.id === 'latitude') {
                    tableHtml += `
                        <select class="srk-global-selector srk-select" data-field="${field.id}">
                            <option value="">Select Source</option>
                            <option value="custom">Custom Value</option>
                        </select>
                        <div class="srk-value-container">
                            <input type="number" step="any" min="-90" max="90" 
                                class="srk-group-values srk-form-input srk-latitude-input"
                                name="meta_map[${field.id}]" data-field="${field.id}"
                                placeholder="e.g., 43.6532" disabled>
                            <small class="srk-field-hint" style="color: #666; font-size: 11px; display: block; margin-top: 4px;">
                            </small>
                        </div>
                    `;
                }
                // Special handling for longitude field (geo coordinates)
                else if (field.id === 'longitude') {
                    tableHtml += `
                        <select class="srk-global-selector srk-select" data-field="${field.id}">
                            <option value="">Select Source</option>
                            <option value="custom">Custom Value</option>
                        </select>
                        <div class="srk-value-container">
                            <input type="number" step="any" min="-180" max="180" 
                                class="srk-group-values srk-form-input srk-longitude-input"
                                name="meta_map[${field.id}]" data-field="${field.id}"
                                placeholder="e.g., -79.3832" disabled>
                            <small class="srk-field-hint" style="color: #666; font-size: 11px; display: block; margin-top: 4px;">
                            </small>
                        </div>
                    `;
                } else {
                    // Other fields
                    tableHtml += `
                        <select class="srk-global-selector srk-select" data-field="${field.id}">
                            <option value="">Select Source</option>
                            ${siteOption}
                            <option value="custom">Custom Value</option>
                        </select>
                        <div class="srk-value-container">
                            <input type="text" class="srk-group-values srk-form-input"
                                name="meta_map[${field.id}]" data-field="${field.id}"
                                placeholder="${placeholder}" disabled>
                        </div>
                    `;
                }
        
                tableHtml += `
                            </div>
                        </td>
                    </tr>
                `;
            });
        
            tableHtml += `</tbody></table></div>`;
            $metaPlaceholder.html(tableHtml);
        
            // Load global options and initialize components
            this.loadAllGlobalOptions();
            this.initTagsInput();
            this.initFieldEnableToggle();
            
            // ✅ NEW: Initialize source selector functionality
            this.initSourceSelector();
            
            // ✅ NEW: Auto-enable and lock required fields
            this.autoEnableRequiredFields(schemaKey);
        
            // Lock UI if mapping is disabled
            if (typeof SRK_LOCK !== 'undefined' && (SRK_LOCK.enabled || !SRK_LOCK.can_map)) {
                $('#srk-schema-config-wrapper').css({ opacity: 0.5, pointerEvents: 'none' });
            }
        }
        
        /**
         * Auto-enable and lock required fields for schema type
         *
         * @since 2.1.0
         * @param {string} schemaKey Schema key
         */
        autoEnableRequiredFields(schemaKey) {
            // Skip for FAQ (handled separately)
            if (schemaKey === 'faq') {
                return;
            }
            
            // Default mappings for required fields (schema-agnostic)
            const defaultMappings = {
                headline: { type: 'post', value: 'post:post_title', selectorType: 'group' },
                publisher: { type: 'site', value: 'site:site_name', selectorType: 'global' },
                provider: { type: 'site', value: 'site:site_name', selectorType: 'global' }, // Course provider
                author: { type: 'post', value: 'post:post_author', selectorType: 'group' },
                name: { type: 'post', value: 'post:post_title', selectorType: 'group' },
                title: { type: 'post', value: 'post:post_title', selectorType: 'group' },
                startDate: { type: 'post', value: 'post:post_date', selectorType: 'group' },
                address: { type: 'custom', value: '', selectorType: 'global' },
                url: { type: 'post', value: 'post:post_url', selectorType: 'group' },
                offers: { type: 'post', value: 'post:price', selectorType: 'group' }, // For Product schema, auto-generated from price but needs mapping
                ratingValue: { type: 'custom', value: '', selectorType: 'global' },
                reviewCount: { type: 'custom', value: '', selectorType: 'global' },
                provider: { type: 'custom', value: '', selectorType: 'global' },
                itemReviewed: { type: 'post', value: 'post:post_title', selectorType: 'group' },
                reviewRating: { type: 'custom', value: '', selectorType: 'global' },
                datePosted: { type: 'post', value: 'post:post_date', selectorType: 'group' },
                validThrough: { type: 'custom', value: '', selectorType: 'group', isDateField: true, isDirectInput: true }, // Date field, typically 30-90 days from posting
            };

            // Website-specific defaults: for the global WebSite schema we want to pull from
            // site information (site name, URL, description) instead of post data.
            if (schemaKey === 'website') {
                defaultMappings.name = { type: 'site', value: 'site_name', selectorType: 'global' };
                defaultMappings.url = { type: 'site', value: 'site_url', selectorType: 'global' };
                // Description is recommended, not required, but if the field is already checked
                // and empty we will map it from the site description later in this method.
                defaultMappings.description = { type: 'site', value: 'site_description', selectorType: 'global' };
            }
            
            const self = this;
            
            // Get required fields from server
            $.post(
                srk_ajax_object.ajax_url,
                {
                    action: 'srk_get_required_fields',
                    schema: schemaKey,
                },
                (response) => {
                    if (response.success && response.data.required_fields) {
                        const requiredFields = response.data.required_fields;
                        
                        // Auto-enable, lock, and map required fields
                        requiredFields.forEach((fieldId) => {
                            const $checkbox = $(`.srk-field-enable[data-field="${fieldId}"]`);
                            const $fieldRow = $checkbox.closest('.srk-meta-row');
                            const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
                            
                            if ($checkbox.length && !$checkbox.prop('checked')) {
                                // Mark as required and enable
                                $checkbox
                                    .prop('checked', true)
                                    .attr('data-required', 'true')
                                    .trigger('change');
                                
                                // Enable mapping container
                                $mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);
                                
                                // Add visual indicator to label
                                const $label = $fieldRow.find('.srk-schema-meta-label');
                                if (!$label.find('.srk-required-marker').length) {
                                    $label.append(' <span class="srk-required-marker" style="color: #dc3545; font-weight: bold;" title="Required field">*</span>');
                                }
                                
                                // Add required class to row
                                $fieldRow.addClass('srk-field-required');
                                
                                                // Auto-map the field if we have a default mapping
                                if (defaultMappings[fieldId]) {
                                    const mapping = defaultMappings[fieldId];
                                    const $selector = $mappingContainer.find(
                                        mapping.selectorType === 'global' 
                                            ? '.srk-global-selector' 
                                            : '.srk-group-selector'
                                    );
                                    const $valueInput = $mappingContainer.find('.srk-group-values, select[name*="meta_map"]');
                                    
                                    if ($selector.length) {
                                        // Set selector value after a short delay to ensure DOM is ready
                                        setTimeout(() => {
                                            $selector.val(mapping.type).trigger('change');
                                            
                                            // Set mapping value after selector change processes
                                            setTimeout(() => {
                                                if (mapping.type === 'site') {
                                                    // For site fields, let handleGlobalSelectorChange handle it
                                                    if (self.manager && typeof self.manager.handleGlobalSelectorChange === 'function') {
                                                        self.manager.handleGlobalSelectorChange({ target: $selector[0] });
                                                    }
                                                } else if (mapping.type === 'post' && $valueInput.length && mapping.value) {
                                                    // For post fields, set the value directly
                                                    if ($valueInput.is('select')) {
                                                        // Check if the value exists in options, if not wait for options to load
                                                        if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
                                                            $valueInput.val(mapping.value);
                                                            $valueInput.trigger('change');
                                                        } else {
                                                            // Wait a bit more for options to load (e.g., for Product schema)
                                                            setTimeout(() => {
                                                                if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
                                                                    $valueInput.val(mapping.value);
                                                                    $valueInput.trigger('change');
                                                                } else {
                                                                    // If still not found, try alternative mapping for offers field
                                                                    if (fieldId === 'offers' && schemaKey === 'product') {
                                                                        // For Product offers, try mapping to price meta field
                                                                        $selector.val('meta').trigger('change');
                                                                        setTimeout(() => {
                                                                            // Try common WooCommerce price meta fields
                                                                            const priceOptions = ['meta:_price', 'meta:_regular_price', 'meta:price'];
                                                                            let mapped = false;
                                                                            priceOptions.forEach(priceOption => {
                                                                                if (!$mapped && $valueInput.find('option[value="' + priceOption + '"]').length > 0) {
                                                                                    $valueInput.val(priceOption);
                                                                                    $valueInput.trigger('change');
                                                                                    mapped = true;
                                                                                }
                                                                            });
                                                                            if (!mapped && $valueInput.find('option').length > 1) {
                                                                                // Use first available meta option as fallback
                                                                                $valueInput.val($valueInput.find('option:not([value=""])').first().val());
                                                                                $valueInput.trigger('change');
                                                                            }
                                                                        }, 300);
                                                                    }
                                                                }
                                                            }, 500);
                                                        }
                                                    }
                                                } else if (mapping.type === 'meta' && $valueInput.length && mapping.value) {
                                                    // For meta fields
                                                    if ($valueInput.is('select')) {
                                                        if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
                                                            $valueInput.val(mapping.value);
                                                            $valueInput.trigger('change');
                                                        }
                                                    }
                                                } else if (mapping.type === 'custom') {
                                                    // For custom fields - handle both input and select
                                                    if ($valueInput.length) {
                                                        if ($valueInput.is('input')) {
                                                            $valueInput.prop('disabled', false);
                                                            
                                                            // For date fields like validThrough, set a default placeholder date
                                                            if (mapping.isDateField && fieldId === 'validThrough') {
                                                                // Set default to 90 days from today (typical job posting expiry)
                                                                const futureDate = new Date();
                                                                futureDate.setDate(futureDate.getDate() + 90);
                                                                const defaultDate = futureDate.toISOString().split('T')[0]; // YYYY-MM-DD format
                                                                $valueInput.attr('placeholder', defaultDate + ' (default: 90 days from today)');
                                                                if (!$valueInput.attr('type') || $valueInput.attr('type') !== 'date') {
                                                                    $valueInput.attr('type', 'date');
                                                                }
                                                                $valueInput.val(defaultDate);
                                                                $valueInput.trigger('change');
                                                                
                                                                // Update preview after setting date
                                                                if (self.manager && typeof self.manager.updateJsonPreview === 'function') {
                                                                    setTimeout(() => {
                                                                        self.manager.updateJsonPreview();
                                                                    }, 300);
                                                                }
                                                            }
                                                        } else if ($valueInput.is('select')) {
                                                            // For select fields that should be custom, we might need to create an input
                                                            // But first check if selector supports custom option
                                                            if ($selector.length && $selector.find('option[value="custom"]').length > 0) {
                                                                // Selector supports custom, but value input is a select - might need special handling
                                                                // For now, just enable the field
                                                                $valueInput.prop('disabled', false);
                                                            }
                                                        }
                                                    } else if (mapping.isDirectInput && fieldId === 'validThrough') {
                                                        // Special handling: validThrough is a direct date input (Job Posting schema)
                                                        const $directDateInput = $mappingContainer.find('input[type="date"][data-field="' + fieldId + '"], input[data-field="' + fieldId + '"]');
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
                                                            
                                                            if (self.manager && typeof self.manager.updateJsonPreview === 'function') {
                                                                setTimeout(() => {
                                                                    self.manager.updateJsonPreview();
                                                                }, 300);
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                // Update preview after mapping (for all field types)
                                                if (self.manager && typeof self.manager.updateJsonPreview === 'function') {
                                                    setTimeout(() => {
                                                        self.manager.updateJsonPreview();
                                                    }, 300);
                                                }
                                            }, 400);
                                        }, 500);
                                    }
                                }
                            } else if ($checkbox.length && $checkbox.prop('checked')) {
                                // Field is already enabled, just ensure it's marked as required
                                $checkbox.attr('data-required', 'true');
                                $fieldRow.addClass('srk-field-required');
                                
                                // Check if mapping exists, if not, apply default
                                const $valueInput = $mappingContainer.find('.srk-group-values, select[name*="meta_map"], input[name*="meta_map"]');
                                if ($valueInput.length && (!$valueInput.val() || $valueInput.val() === '')) {
                                    if (defaultMappings[fieldId]) {
                                        const mapping = defaultMappings[fieldId];
                                        const $selector = $mappingContainer.find(
                                            mapping.selectorType === 'global' 
                                                ? '.srk-global-selector' 
                                                : '.srk-group-selector'
                                        );
                                        
                                        if ($selector.length) {
                                            setTimeout(() => {
                                                $selector.val(mapping.type).trigger('change');
                                                
                                                setTimeout(() => {
                                                    if (mapping.type === 'site' && self.manager && typeof self.manager.handleGlobalSelectorChange === 'function') {
                                                        self.manager.handleGlobalSelectorChange({ target: $selector[0] });
                                                    } else if (mapping.type === 'post' && $valueInput.is('select') && mapping.value) {
                                                        // Wait for options to load before setting value
                                                        const checkOptionsInterval = setInterval(() => {
                                                            if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0 || $valueInput.find('option').length > 1) {
                                                                if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
                                                                    $valueInput.val(mapping.value).trigger('change');
                                                                }
                                                                clearInterval(checkOptionsInterval);
                                                            }
                                                        }, 100);
                                                        
                                                        // Timeout after 2 seconds
                                                        setTimeout(() => {
                                                            clearInterval(checkOptionsInterval);
                                                            if ($valueInput.find('option[value="' + mapping.value + '"]').length > 0) {
                                                                $valueInput.val(mapping.value).trigger('change');
                                                            } else if ($valueInput.find('option').length > 1) {
                                                                // Use first available option as fallback
                                                                $valueInput.val($valueInput.find('option:not([value=""])').first().val()).trigger('change');
                                                            }
                                                        }, 2000);
                                                    } else if (mapping.type === 'custom' && $valueInput.is('input')) {
                                                        // For custom fields, especially date fields like validThrough
                                                        $valueInput.prop('disabled', false);
                                                        if (mapping.isDateField && fieldId === 'validThrough') {
                                                            const futureDate = new Date();
                                                            futureDate.setDate(futureDate.getDate() + 90);
                                                            const defaultDate = futureDate.toISOString().split('T')[0];
                                                            $valueInput.attr('placeholder', defaultDate + ' (default: 90 days from today)');
                                                            if (!$valueInput.attr('type') || $valueInput.attr('type') !== 'date') {
                                                                $valueInput.attr('type', 'date');
                                                            }
                                                            $valueInput.val(defaultDate);
                                                            $valueInput.trigger('change');
                                                        }
                                                    } else if (mapping.isDirectInput && fieldId === 'validThrough') {
                                                        // Handle direct date input for validThrough
                                                        const $directDateInput = $mappingContainer.find('input[type="date"][data-field="' + fieldId + '"], input[data-field="' + fieldId + '"]');
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
                                                    
                                                    if (self.manager && typeof self.manager.updateJsonPreview === 'function') {
                                                        setTimeout(() => {
                                                            self.manager.updateJsonPreview();
                                                        }, 200);
                                                    }
                                                }, 400);
                                            }, 300);
                                        }
                                    }
                                }
                            }
                        });
                    }
                }
            ).fail(() => {
                // Failed to load required fields for schema - continue silently
            });
        }
 
        /**
        * Initialize source selector functionality
        * 
        * @since 2.1.0
        */
        initSourceSelector() {
            const self = this;
            
            // Handle source selector change
            $(document).on('change', '.srk-global-selector', function() {
                const $selector = $(this);
                const fieldId = $selector.data('field');
                const selectedValue = $selector.val();
                const $valueContainer = $selector.closest('.srk-schema-field-mapping').find('.srk-value-container');
                const $inputField = $valueContainer.find('.srk-group-values');
                const $addressWrapper = $selector.closest('.srk-schema-field-mapping').find('.srk-address-fields-wrapper');
                
                // ✅ FIXED: Always ensure value container and input field are visible and enabled
                if ($valueContainer.length) {
                    $valueContainer.show();
                }
                if ($inputField.length) {
                    $inputField.prop('disabled', false).show();
                }
                
                // Handle address field (structured fields)
                if (fieldId === 'address' && $addressWrapper.length) {
                    const $addressFields = $addressWrapper.find('.srk-address-field');
                    if (selectedValue === 'custom') {
                        $addressFields.prop('disabled', false);
                    } else {
                        $addressFields.prop('disabled', true).val('');
                    }
                    if (self.manager) {
                        self.manager.changesMade = true;
                        self.manager.updateJsonPreview();
                    }
                    return;
                }
                
                // Handle image field
                if (fieldId === 'image') {
                    // ✅ FIXED: Always keep input field enabled and visible
                    if ($inputField.length) {
                        $inputField.prop('disabled', false).show();
                        $inputField.css('display', ''); // Remove any inline display:none
                        
                        if (selectedValue === 'featured_image') {
                            $inputField.attr('placeholder', 'Will use site logo from post (or enter custom URL)');
                            $inputField.attr('data-source', 'featured_image');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        } else if (selectedValue === 'site_logo') {
                            $inputField.attr('placeholder', 'Will use site logo (or enter custom URL)');
                            $inputField.attr('data-source', 'site_logo');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        } else if (selectedValue === 'custom') {
                            $inputField.attr('placeholder', 'Enter custom image URL');
                            $inputField.removeAttr('data-source');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        } else if (selectedValue === 'site') {
                            $inputField.attr('placeholder', 'Auto-filled from site information (or enter custom URL)');
                            $inputField.removeAttr('data-source');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        } else {
                            // "Select Source" or empty - disable field
                            $inputField.attr('placeholder', 'Please select a source first');
                            $inputField.removeAttr('data-source');
                            $inputField.prop('disabled', true); // ✅ FIX: Disable field when no source selected
                        }
                    }
                    if (self.manager) {
                        self.manager.changesMade = true;
                        self.manager.updateJsonPreview();
                    }
                    return;
                }
                
                // Handle logo field
                if (fieldId === 'logo') {
                    // ✅ FIXED: Always keep input field enabled and visible
                    if ($inputField.length) {
                        $inputField.prop('disabled', false).show();
                        $inputField.css('display', ''); // Remove any inline display:none
                        
                        if (selectedValue === 'site_logo') {
                            $inputField.attr('placeholder', 'Will use site logo (or enter custom URL)');
                            $inputField.attr('data-source', 'site_logo');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        } else if (selectedValue === 'custom') {
                            $inputField.attr('placeholder', 'Enter custom logo URL');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                            $inputField.removeAttr('data-source');
                            $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        } else {
                            // "Select Source" or empty - disable field
                            $inputField.attr('placeholder', 'Please select a source first');
                            $inputField.removeAttr('data-source');
                            $inputField.prop('disabled', true); // ✅ FIX: Disable field when no source selected
                        }
                    }
                    if (self.manager) {
                        self.manager.changesMade = true;
                        self.manager.updateJsonPreview();
                    }
                    return;
                }
                
                // Default handling for other fields
                // ✅ FIXED: Always keep input field enabled and visible, regardless of selection
                if ($inputField.length) {
                    $inputField.prop('disabled', false).show();
                    $inputField.css('display', ''); // Remove any inline display:none
                    
                    if (selectedValue === 'custom') {
                        // Enable input field for custom value
                        $inputField.attr('placeholder', 'Enter custom value');
                        $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                        $inputField.focus(); // Optional: focus on the input field
                    } else if (selectedValue === 'site') {
                        // Enable input field but show it's auto-filled from site info
                        $inputField.attr('placeholder', 'Auto-filled from site information (or enter custom value)');
                        $inputField.prop('disabled', false); // ✅ FIX: Explicitly enable field
                    } else {
                        // "Select Source" or empty - keep field visible but disable it
                        $inputField.attr('placeholder', 'Please select a source first');
                        $inputField.prop('disabled', true); // ✅ FIX: Disable field when no source selected
                    }
                }
                
                // Update preview if changes were made
                if (self.manager) {
                    self.manager.changesMade = true;
                    self.manager.updateJsonPreview();
                }
            });
            
            // Also handle field enable/disable to sync with source selector
            $(document).on('change', '.srk-field-enable', function() {
                const $checkbox = $(this);
                const isEnabled = $checkbox.is(':checked');
                const $fieldRow = $checkbox.closest('.srk-meta-row');
                const $selector = $fieldRow.find('.srk-global-selector');
                const $inputField = $fieldRow.find('.srk-group-values');
                
                // ✅ Special handling for address field
                if ($checkbox.data('field') === 'address') {
                    const $addressFields = $fieldRow.find('.srk-address-field');
                    if (!isEnabled) {
                        $selector.prop('disabled', true);
                        $addressFields.prop('disabled', true);
                    } else {
                        $selector.prop('disabled', false);
                        const selectedValue = $selector.val();
                        if (selectedValue === 'custom') {
                            $addressFields.prop('disabled', false);
                        } else {
                            $addressFields.prop('disabled', true);
                        }
                    }
                    return;
                }
                
                if (!isEnabled) {
                    // If field is disabled, also disable both selector and input
                    $selector.prop('disabled', true);
                    $inputField.prop('disabled', true);
                } else {
                    // If field is enabled, enable selector but control input based on selection
                    $selector.prop('disabled', false);
                    const selectedValue = $selector.val();
                    
                    if (selectedValue === 'custom' || selectedValue === 'site') {
                        $inputField.prop('disabled', false);
                    } else {
                        $inputField.prop('disabled', true);
                    }
                }
            });
            
            // ✅ Auto-enable address field when any sub-field is filled
            $(document).on('input blur', '.srk-address-field', function() {
                const $subField = $(this);
                const $addressRow = $subField.closest('.srk-meta-row');
                const $addressCheckbox = $addressRow.find('.srk-field-enable[data-field="address"]');
                const $addressSelector = $addressRow.find('.srk-global-selector[data-field="address"]');
                
                // Check if any address sub-field has a value
                let hasValue = false;
                $addressRow.find('.srk-address-field').each(function() {
                    if ($(this).val() && $(this).val().trim() !== '') {
                        hasValue = true;
                        return false; // break
                    }
                });
                
                if (hasValue) {
                    // Enable address field and set selector to custom
                    $addressCheckbox.prop('checked', true);
                    $addressSelector.val('custom').trigger('change');
                }
            });
            
            // Initialize current state for all fields
            $('.srk-global-selector').each(function() {
                const $selector = $(this);
                const selectedValue = $selector.val();
                const $inputField = $selector.closest('.srk-schema-field-mapping').find('.srk-group-values');
                
                if (selectedValue === 'custom' || selectedValue === 'site') {
                    $inputField.prop('disabled', false);
                } else {
                    $inputField.prop('disabled', true);
                }
            });
        }

        /**
         * Initialize tags input functionality.
         *
         * @since 2.1.0
         */
        initTagsInput() {
            const $tagsInput = $('.srk-tags-input');
        
            // Add real-time tag formatting
            $tagsInput.on('input', function() {
                const value = $(this).val();
                // Auto-format: remove extra spaces and ensure proper comma separation
                const formatted = value.replace(/\s*,\s*/g, ', ').replace(/,+/g, ',');
                if (value !== formatted) {
                    $(this).val(formatted);
                }
            });
        
            // Add keydown handler for Enter key
            $tagsInput.on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // Add comma when pressing Enter for better UX
                    const currentValue = $(this).val();
                    if (currentValue && !currentValue.endsWith(',')) {
                        $(this).val(currentValue + ', ');
                    }
                }
            });
        }
        
        /**
         * Get schema requirements message
         * 
         * @since 2.1.0
         * @param {string} schemaKey 
         * @return {string} Requirement message
         */
        getSchemaRequirements(schemaKey) {
            const requirements = {
                'event': 'This schema works best when you have events functionality or event management plugin. This schema requires any plugin like The Events Calendar, Events Manager, etc. to work properly.',
                'job_posting': 'This schema requires job listings or career post type to work properly. This schema requires any plugin like simple job board, etc. to work properly.',
                'product': 'This schema is optimized for e-commerce or product catalog functionality. This schema requires WooCommerce or other e-commerce plugins to work properly.',
                'course': 'This schema works with course management systems or learning platforms. This schema requires plugins like Tutor LMS, LearnDash, LifterLMS, etc. to work properly.',
                'review': 'This schema requires review system or rating functionality and it requires custom fields and custome post types to work properly. For example this schemas fields should like this: [Review Name, Review Description, Review Rating, Review Author, Review Date, Review Body, Item Reviewed], you can also use these plugins like CUBEWP Framework or ACF etc to create custom fields for this schema.',
                'recipe': 'This schema is designed for food blogs or recipe management and it requires custom fields and custome post types to work properly. For example this schemas fields should like this: [Recipe Name, Recipe Description, Recipe Ingredients, Recipe Instructions, Prep Time, Cook Time, Total Time, Recipe Yield], you can also use these plugins like CUBEWP Framework or ACF etc to create custom fields for this schema.',
                'medical_condition': 'This schema requires medical content or healthcare related post type and it requires custom fields and custome post types to work properly. For example this schemas fields should like this: [Name, Description, Symptoms, Treatments, Risk Factors, Primary Prevention, Possible Complication, Pathophysiology, Epidemiology], you can also use these plugins like CUBEWP Framework or ACF etc to create custom fields for this schema.'
            };
            
            return requirements[schemaKey] || '';
        }

        /**
         * Show schema requirements notice if needed
         * 
         * @since 2.1.0
         */
        showSchemaRequirements() {
            const message = this.getSchemaRequirements(this.schemaKey);
            
            if (message) {
                // Remove any existing notice first
                $('.srk-schema-requirements-notice').remove();
                
                // Add notice before the configuration
                const noticeHtml = `
                    <div class="srk-schema-requirements-notice notice notice-info" style="margin: 15px 0; padding: 10px 15px; border-left: 4px solid #F28500 !important;">
                        <p><strong>Note:</strong> ${message}</p>
                    </div>
                `;
                $('#srk-schema-config-wrapper').prepend(noticeHtml);
            }
        }

        /**
         * Load post type schema configuration.
         *
         * @since 2.1.0
         * @param {Object} container jQuery container object.
         */
        loadPostTypeSchema(container) {
            const requirementsMessage = this.getSchemaRequirements(this.schemaKey);
            let noticeHtml = '';
        
            if (requirementsMessage) {
                noticeHtml = `
                    <div class="srk-schema-requirements-notice notice notice-info" style="margin: 15px 0; padding: 10px 15px; border-left: 4px solid #F28500;">
                        <p><strong>Note:</strong> ${requirementsMessage}</p>
                    </div>
                `;
            }
        
            // ✅ CLEAR ASSIGNMENT GUIDANCE - GOOGLE RECOMMENDED
            let assignmentMessage = '';
            if (this.schemaKey === 'article') {
                assignmentMessage = `
                    <div class="notice notice-info" style="margin: 10px 0; padding: 8px 12px;">
                        <p>📝 <strong>Google Recommended:</strong> Assign to <strong>Posts</strong> with article content</p>
                    </div>
                `;
            } else if (this.schemaKey === 'blog_posting') {
                assignmentMessage = `
                    <div class="notice notice-info" style="margin: 10px 0; padding: 8px 12px;">
                        <p>📖 <strong>Google Recommended:</strong> Assign to <strong>Blog Posts</strong> only</p>
                    </div>
                `;
            } else if (this.schemaKey === 'news_article') {
                assignmentMessage = `
                    <div class="notice notice-info" style="margin: 10px 0; padding: 8px 12px;">
                        <p>📰 <strong>Google Recommended:</strong> Assign to <strong>News Posts</strong> only</p>
                    </div>
                `;
            }
        
            // Placeholder for dynamic article-type conflict warnings (Article / BlogPosting / NewsArticle).
            let articleWarningContainer = '';
            if (['article', 'blog_posting', 'news_article'].includes(this.schemaKey)) {
                articleWarningContainer = `
                    <div class="srk-article-type-warning" data-schema-key="${this.schemaKey}" style="display:none; margin: 6px 0 0 0;"></div>
                `;
            }
        
            // 🔐 NONCE added.
            $.post(
                srk_ajax_object.ajax_url,
                withNonce({
                    action: 'srk_get_post_types',
                }),
                (res) => {
                    if (res.success) {
                        container.html(`
                            <div class="srk-schema-config-card">
                                <h3>${this.formatSchemaName(this.schemaKey)} Configuration</h3>
                                ${noticeHtml}
                                ${assignmentMessage}
                                ${articleWarningContainer}
                                <div class="srk-form-group">
                                    <label for="assign-${this.schemaKey}">Assign to Post Type:</label>
                                    <select id="assign-${this.schemaKey}" class="srk-form-select">
                                        <option value="">Select Post Type</option>
                                        ${res.data}
                                    </select>
                                </div>
                                <div class="srk-meta-placeholder"></div>
                            </div>
                        `);
        
                        $('#srk-json-preview-container').show();
                        this.manager.updateJsonPreview();
        
                        // Check and load saved configuration automatically.
                        this.checkAndLoadSavedConfiguration(this.schemaKey);
        
                        if (SRK_LOCK.enabled || !SRK_LOCK.can_map) {
                            // Prevent selection when locked.
                            $('#srk-schema-config-wrapper').css({ opacity: 0.5, pointerEvents: 'none' });
                            showLockNotice();
                        }
                    }
                }
            );
        }

        /**
         * Ensure all fields properly loaded.
         *
         * @since 2.1.0
         */
        ensureAllFieldsProperlyLoaded() {
            $('.srk-global-selector').each((index, element) => {
                const $selector = $(element);
                const field = $selector.data('field');
                const $valueContainer = $selector.closest('.srk-schema-field-mapping').find('.srk-value-container');

                if ($selector.val() === '' && this.savedMappings && this.savedMappings[field]) {
                    const savedValue = this.savedMappings[field];

                    if (savedValue.includes('site:')) {
                        $selector.val('site');
                        this.handleGlobalSelectorChange({ target: element });
                        setTimeout(() => {
                            $valueContainer.find('.srk-group-values').val(savedValue);
                        }, 300);
                    } else if (savedValue.includes('custom:')) {
                        $selector.val('custom');
                        this.handleGlobalSelectorChange({ target: element });
                        setTimeout(() => {
                            const customValue = savedValue.replace('custom:', '');
                            $valueContainer.find('.srk-group-values').val(customValue);
                        }, 300);
                    }
                }
            });
        }

        /**
         * Convert to mapping format.
         *
         * @since 2.1.0
         * @param {Object} metaMap Meta mapping object.
         * @return {Object} Formatted meta mapping.
         */
        convertToMappingFormat(metaMap) {
            if (!metaMap) {
                return {};
            }
            const formattedMap = {};
            const siteKeys = [
                'site_name',
                'site_description',
                'logo_url',
                'site_url',
                'admin_email',
                'telephone',
                'opening_hours',
                'price_range',
                'address',
                'contact_point',
                'facebook_url',
                'twitter_url',
                'instagram_url',
                'youtube_url',
                'same_as',
                'founder',
                'founding_date',
                'rating_value',
                'review_count',
                'best_rating',
                'worst_rating',
            ];
            for (const [field, value] of Object.entries(metaMap)) {
                if (siteKeys.includes(value)) {
                    formattedMap[field] = `site:${value}`;
                } else if (value.includes('site:') || value.includes('custom:')) {
                    formattedMap[field] = value;
                } else {
                    formattedMap[field] = `custom:${value}`;
                }
            }
            return formattedMap;
        }

        /**
         * Load all global options.
         *
         * @since 2.1.0
         */
        loadAllGlobalOptions() {
            // 🔐 NONCE added.
            $.post(
                srk_ajax_object.ajax_url,
                withNonce({
                    action: 'srk_get_global_options',
                }),
                (response) => {
                    if (response.success) {
                        this.globalOptions = response.data;
                        if (window.SRK && window.SRK.SchemaManager && window.SRK.SchemaManager.isEditMode(this.schemaKey)) {
                            window.SRK.SchemaManager.prefillGlobalSchemaValues(this.schemaKey, window.SRK.SchemaManager.savedMappings);
                        }
                    }
                }
            );
        }

        /**
         * Load schema mappings.
         *
         * @since 2.1.0
         * @param {string} schemaKey Schema key.
         */
        loadSchemaMappings(schemaKey) {
            // 🔐 NONCE added.
            $.post(
                srk_ajax_object.ajax_url,
                withNonce({
                    action: 'srk_get_schema_configuration',
                    schema: schemaKey,
                }),
                (response) => {
                    if (response.success && response.data.configured) {
                        this.savedMappings = response.data.meta_map || {};
                    }
                }
            );
        }

        /**
         * Handle schema change.
         *
         * @since 2.1.0
         * @param {Event} e Change event.
         */
        handleSchemaChange(e) {
            const $target = $(e.target);
            const schemaKey = $target.val();
            const isChecked = $target.is(':checked');
            const container = $('#srk-schema-config-wrapper');

            if (isChecked && $target.data('configured')) {
                const postType = $target.data('post-type');
                this.showModal(
                    `This schema is already configured for "${postType}". Do you want to edit the existing configuration?`,
                    schemaKey,
                    postType
                );
                $target.prop('checked', false);
                return false;
            }

            if (this.currentSchema && this.currentSchema !== schemaKey && this.changesMade) {
                if (!confirm('You have unsaved changes. Switching schemas will lose these changes. Continue?')) {
                    $target.prop('checked', false);
                    return false;
                }
            }

            $('.srk-schema-input').not($target).prop('checked', false);
            container.empty();
            $('#srk-json-preview-container').hide();
        $('#srk-json-preview-loader').hide();

            if (!isChecked) {
                this.currentSchema = null;
                return;
            }

            this.currentSchema = schemaKey;
            this.changesMade = false;
            this.loadSchemaMappings(schemaKey);

            const schemaClass = this.getSchemaClass(schemaKey);
            if (schemaClass) {
                schemaClass.handleSelection();
            }
        }

        /**
         * Load post type fields.
         *
         * @since 2.1.0
         * @param {Object} fieldData Field data object.
         * @param {Object} $metaPlaceholder jQuery meta placeholder object.
         */
        loadPostTypeFields(fieldData, $metaPlaceholder) {
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
                tableHtml += `
                    <tr class="srk-meta-row">
                        <td>
                            <input type="checkbox" class="srk-field-enable" data-field="${field.id}" checked>
                        </td>
                        <td class="srk-schema-meta-label">
                            ${field.name} ${field.required ? '<span class="srk-required">*</span>' : ''}
                        </td>
                        <td>
                            <div class="srk-schema-field-mapping">
                                <select class="srk-group-selector srk-select" data-field="${field.id}">
                                    <option value="post">Post Default</option>
                                    <option value="meta">Post Meta</option>
                                    <option value="user">User Meta</option>
                                    <option value="tax">Taxonomy</option>
                                </select>
                                <select class="srk-group-values srk-select" name="meta_map[${field.id}]" data-field="${field.id}">
                                    <option value="">Select Field</option>
                                </select>
                            </div>
                        </td>
                    </tr>
                `;
            });

            tableHtml += `</tbody></table></div>`;
            $metaPlaceholder.html(tableHtml);

            schemaFields.forEach((field) => {
                this.manager.loadFieldOptions(
                    field.id,
                    'post',
                    post_defaults,
                    post_meta,
                    user_meta,
                    taxonomies
                );
            });

            this.initFieldEnableToggle();
            
            // ✅ NEW: Auto-enable and lock required fields (with delay to ensure DOM is ready)
            setTimeout(() => {
                this.autoEnableRequiredFields(this.schemaKey);
            }, 800);
            
            // Restore checkbox states after fields are loaded
            this.restoreCheckboxStates();
            
            this.manager.updateJsonPreview();

            if (SRK_LOCK.enabled || !SRK_LOCK.can_map) {
                $('#srk-schema-config-wrapper').css({ opacity: 0.5, pointerEvents: 'none' });
                showLockNotice();
            }
        }

        /**
         * Restore checkbox states from saved configuration
         *
         * @since 2.1.0
         */
        restoreCheckboxStates() {
            const self = this;
           
            // ✅ FAQ EXCEPTION: Skip checkbox restoration
            if (this.schemaKey === 'faq') {
                // Ensure all fields are enabled for these schemas
                $('.srk-field-enable').each(function() {
                    const $checkbox = $(this);
                    const $fieldRow = $checkbox.closest('.srk-meta-row');
                    const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
                   
                    $checkbox.prop('checked', true);
                    $mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);
                });
               
                return;
            }
           
            // Load saved configuration to get enabled_fields
            $.post(
                srk_ajax_object.ajax_url,
                {
                    action: 'srk_get_schema_configuration',
                    schema: this.schemaKey,
                },
                (response) => {
                    if (response.success && response.data.configured) {
                        const config = response.data;
                        const enabledFields = config.enabled_fields || [];
                       
                        // Set checkbox states based on saved enabled_fields
                        $('.srk-field-enable').each(function() {
                            const $checkbox = $(this);
                            const field = $checkbox.data('field');
                            const $fieldRow = $checkbox.closest('.srk-meta-row');
                            const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
                           
                            // Check if this field is in the enabled_fields array
                            const isEnabled = enabledFields.includes(field);
                           
                            $checkbox.prop('checked', isEnabled);
                           
                            if (isEnabled) {
                                $mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);
                            } else {
                                $mappingContainer.css('opacity', '0.5').find('select, input').prop('disabled', true);
                            }
                        });
                       
                        // Update preview after restoring states
                        setTimeout(() => {
                            self.manager.updateJsonPreview();
                        }, 500);
                    } else {
                        // If no saved configuration, keep all fields enabled (default state)
                        $('.srk-field-enable').each(function() {
                            const $checkbox = $(this);
                            const $fieldRow = $checkbox.closest('.srk-meta-row');
                            const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
                           
                            $checkbox.prop('checked', true);
                            $mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);
                        });
                    }
                }
            ).fail(function(jqXHR, textStatus, errorThrown) {
                // AJAX failed - continue silently
            });
        }

        /**
         * Initialize field enable/disable toggle functionality
         *
         * @since 2.1.0
         */
        initFieldEnableToggle() {
            const self = this;
            
            // Handle checkbox change
            $(document).on('change', '.srk-field-enable', function() {
                const $checkbox = $(this);
                
                // ✅ NEW: Prevent disabling required fields
                if ($checkbox.attr('data-required') === 'true') {
                    $checkbox.prop('checked', true);
                    return false;
                }
                
                const fieldId = $checkbox.data('field');
                const isEnabled = $checkbox.is(':checked');
                const $fieldRow = $checkbox.closest('.srk-meta-row');
                const $mappingContainer = $fieldRow.find('.srk-schema-field-mapping');
                
                if (isEnabled) {
                    $mappingContainer.css('opacity', '1').find('select, input').prop('disabled', false);
                } else {
                    $mappingContainer.css('opacity', '0.5').find('select, input').prop('disabled', true);
                }
                
                self.manager.changesMade = true;
                self.manager.updateJsonPreview();
            });
        }

        /**
         * Generate preview.
         *
         * @since 2.1.0
         */
        generatePreview() {
            // ✅ Check can_validate flag in addition to other checks
            if (SRK_LOCK.enabled || !SRK_LOCK.can_preview || SRK_LOCK.can_validate === false) {
                showLockNotice();
                return;
            }

            const schemaType = this.formatSchemaName(this.schemaKey);
            const metaMap = {};
            const self = this;

            $('.srk-schema-field-mapping').each(function() {
                const $mappingContainer = $(this);
                const $fieldRow = $mappingContainer.closest('.srk-meta-row');
                const $checkbox = $fieldRow.find('.srk-field-enable');
               
                // ✅ FAQ EXCEPTION: Always include fields for this schema
                const isSpecialSchema = window.SRK.SchemaManager.currentSchema === 'faq';
               
                // Skip if field is disabled (except for special schemas)
                if (!$checkbox.is(':checked') && !isSpecialSchema) {
                    return;
                }
               
                const field = $mappingContainer.find('.srk-group-selector, .srk-global-selector').data('field');
                const $sourceSelector = $mappingContainer.find('.srk-global-selector');
                const sourceType = $sourceSelector.val(); // 'custom', 'site', 'featured_image', etc.
                
                // ✅ FIX: Check for custom override input first (for featured_image/site_logo with custom override)
                const $customInput = $mappingContainer.find(`input[name="meta_map[${field}]_custom"]`);
                if ($customInput.length && $customInput.val() && $customInput.val().trim() !== '') {
                    // Use custom override if provided
                    metaMap[field] = 'custom:' + $customInput.val().trim();
                } else {
                    // Check for hidden input with featured_image or site_logo value
                    const $hiddenInput = $mappingContainer.find('input[type="hidden"].srk-group-values[name="meta_map[' + field + ']"]');
                    if ($hiddenInput.length && ($hiddenInput.val() === 'featured_image' || $hiddenInput.val() === 'site_logo')) {
                        // Use value from hidden input
                        metaMap[field] = $hiddenInput.val();
                    } else {
                        // Check regular value inputs
                        const valueSelect = $mappingContainer.find('.srk-group-values:not(input[type="hidden"])');
                        
                        if (valueSelect.length && valueSelect.val()) {
                            let fieldValue = valueSelect.val().trim();
                            
                            // ✅ FIXED: Format value based on source type
                            if (sourceType === 'custom' && fieldValue && !fieldValue.startsWith('custom:') && !fieldValue.startsWith('site:')) {
                                fieldValue = 'custom:' + fieldValue;
                            } else if (sourceType === 'site' && fieldValue && !fieldValue.startsWith('site:')) {
                                fieldValue = 'site:' + fieldValue;
                            } else if (sourceType === 'featured_image') {
                                fieldValue = 'featured_image';
                            } else if (sourceType === 'site_logo') {
                                fieldValue = 'site_logo';
                            }
                            
                            metaMap[field] = fieldValue;
                        } else if (sourceType === 'featured_image') {
                            // Special case: featured_image doesn't need a value in the input
                            metaMap[field] = 'featured_image';
                        } else if (sourceType === 'site_logo') {
                            // Special case: site_logo doesn't need a value in the input
                            metaMap[field] = 'site_logo';
                        }
                    }
                }
                
                // ✅ FIXED: Also collect address sub-fields for LocalBusiness/Organization schemas
                const $addressSubfields = $mappingContainer.find('.srk-address-field');
                if ($addressSubfields.length) {
                    $addressSubfields.each(function() {
                        const $subField = $(this);
                        const subFieldName = $subField.data('field');
                        const subFieldValue = $subField.val();
                        
                        // Collect value even if field is disabled (it may have been pre-filled)
                        if (subFieldName && subFieldValue && subFieldValue.trim() !== '') {
                            // Add custom: prefix for consistency with saved data format
                            metaMap[subFieldName] = 'custom:' + subFieldValue.trim();
                        }
                    });
                }
            });
            
            // ✅ ADDITIONAL FIX: Also collect address sub-fields from ALL address wrappers
            // This ensures we catch address fields even if they're not inside the checked mapping container
            const addressSubFieldNames = ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'];
            addressSubFieldNames.forEach(function(fieldName) {
                // Try multiple selectors to find the field
                let $subField = $(`input[name="meta_map[${fieldName}]"]`);
                if (!$subField.length) {
                    $subField = $(`.srk-address-field[data-field="${fieldName}"]`);
                }
                
                if ($subField.length) {
                    const subFieldValue = $subField.val();
                    // Only add if not already in metaMap and has a value
                    if (subFieldValue && subFieldValue.trim() !== '' && !metaMap[fieldName]) {
                        metaMap[fieldName] = 'custom:' + subFieldValue.trim();
                    }
                }
            });
 
            // ✅ FIX: Get selected post type and post ID for accurate preview
            const postType = $(`#assign-${this.schemaKey}`).val() || '';
            let postId = 0;
            
            // Try to get selected post ID from post selector dropdown (if exists)
            const $postSelector = $(`#assign-posts-${this.schemaKey}`);
            if ($postSelector.length && $postSelector.val()) {
                postId = parseInt($postSelector.val(), 10);
            }
            
            // If no post selected but post type is selected, get first post of that type
            // This ensures preview shows actual CPT data instead of sample 'post' data
            if (!postId && postType && postType !== 'global') {
                // We'll let PHP handle getting the first post if no specific post is selected
                // Just pass post_type to PHP
            }

            $.ajax({
                url: srk_ajax_object.ajax_url,
                type: 'POST',
                data: withNonce({
                    action: 'srk_get_preview_data',
                    schema_type: this.schemaKey,
                    meta_map: metaMap,
                    enabled_fields: this.getEnabledFields(),
                    post_type: postType, // ✅ NEW: Pass post type
                    post_id: postId, // ✅ NEW: Pass post ID (0 if not selected)
                }),
                success: (response) => {
                    // ✅ NEW: Hide loader and show preview when ready
                    $('#srk-json-preview-loader').hide();
                    
                    if (response.success) {
                        const formattedJson = JSON.stringify(response.data, null, 2);
                        $('#srk-json-preview').text(formattedJson).show();
                        $('#srk-json-preview-container').show();
                    } else {
                        this.generatePreviewWithPlaceholders(metaMap);
                    }
                },
                error: () => {
                    // ✅ NEW: Hide loader on error too
                    $('#srk-json-preview-loader').hide();
                    this.generatePreviewWithPlaceholders(metaMap);
                },
            });
        }

        /**
         * Get enabled fields array
         *
         * @since 2.1.0
         * @return {Array} Array of enabled field IDs
         */
        getEnabledFields() {
            const enabledFields = [];
            $('.srk-field-enable:checked').each(function() {
                enabledFields.push($(this).data('field'));
            });
            return enabledFields;
        }

        /**
         * Generate preview with placeholders.
         *
         * @since 2.1.0
         * @param {Object} metaMap Meta mapping object.
         */
        generatePreviewWithPlaceholders(metaMap) {
            const schemaType = this.formatSchemaName(this.schemaKey);
            const jsonData = {
                '@context': 'https://schema.org',
                '@type': schemaType,
            };

            Object.keys(metaMap).forEach((field) => {
                const mapping = metaMap[field];
                let value = '';

                if (mapping.includes('post:')) {
                    const fieldName = mapping.replace('post:', '');
                    value = `[${fieldName}]`;
                } else if (mapping.includes('custom:')) {
                    value = mapping.replace('custom:', '');
                } else {
                    value = mapping;
                }

                jsonData[field] = value;
            });

            const formattedJson = JSON.stringify(jsonData, null, 2);
            // ✅ NEW: Hide loader and show preview when ready
            $('#srk-json-preview-loader').hide();
            $('#srk-json-preview').text(formattedJson).show();
            $('#srk-json-preview-container').show();
        }

        /**
         * Process field for preview.
         *
         * @since 2.1.0
         * @param {Object} jsonData JSON data object.
         * @param {Object} field Field object.
         * @param {string} value Field value.
         * @param {string} source Field source.
         */
        processFieldForPreview(jsonData, field, value, source) {
            if (field.type === 'object') {
                if (field.id === 'reviewRating') {
                    jsonData.reviewRating = {
                        '@type': 'Rating',
                        ratingValue: value,
                        bestRating: '5',
                    };
                } else {
                    jsonData[field.id] = {
                        '@type': field.id === 'author' || field.id === 'founder' || field.id === 'underName' ? 'Person' :
                            field.id === 'address' ? 'PostalAddress' :
                                field.id === 'reservationFor' ? 'Event' :
                                    field.id === 'about' ? 'Thing' :
                                        field.id === 'itemReviewed' ? 'Product' :
                                            field.id.charAt(0).toUpperCase() + field.id.slice(1),
                        name: value,
                        '@comment': `Mapped from ${source}`,
                    };
                }
            } else if (
                field.id === 'facebook_url' ||
                field.id === 'twitter_url' ||
                field.id === 'instagram_url' ||
                field.id === 'youtube_url'
            ) {
                if (!jsonData.sameAs) {
                    jsonData.sameAs = [];
                }
                if (value) {
                    value = value.replace(/^\[+|\]+$/g, '');
                    if (!/^https?:\/\//i.test(value)) {
                        value = 'https://' + value.replace(/^\/+/, '');
                    }
                    jsonData.sameAs.push(value.trim());
                }
            } else {
                jsonData[field.id] = value;
            }
        }

        /**
         * Apply schema specific preview.
         *
         * @since 2.1.0
         * @param {Object} jsonData JSON data object.
         * @param {Object} metaMap Meta mapping object.
         */
        applySchemaSpecificPreview(jsonData, metaMap) {}

        /**
         * Format schema name.
         *
         * @since 2.1.0
         * @param {string} schemaKey Schema key.
         * @return {string} Formatted schema name.
         */
        formatSchemaName(schemaKey) {
            const mappings = {
                local_business: 'LocalBusiness',
                blog_posting: 'BlogPosting',
                news_article: 'NewsArticle',
                video_object: 'VideoObject',
                job_posting: 'JobPosting',
                medical_web_page: 'MedicalWebPage',
                critic_review: 'Review',
                breadcrumb_list: 'BreadcrumbList',
            };
            return mappings[schemaKey] ||
                schemaKey.split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join('');
        }
    }
    // Make it globally available.
    if (typeof window.SRK === 'undefined') {
        window.SRK = {};
    }
    window.SRK.BaseSchema = BaseSchema;

})(jQuery);
