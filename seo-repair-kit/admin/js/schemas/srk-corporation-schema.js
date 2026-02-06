/**
 * Corporation Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
 
(function ($) {
    'use strict';
 
    /**
     * Corporation Schema class.
     *
     * @since 2.1.0
     */
    class SRK_CorporationSchema extends window.SRK.BaseSchema {
 
        /**
         * Initialize Corporation Schema.
         *
         * @since 2.1.0
         * @param {Object} manager Schema manager instance.
         */
        constructor(manager) {
            super(manager);
            this.schemaKey = 'corporation';
        }
 
        /**
         * Get specific fields for corporation schema.
         *
         * @since 2.1.0
         * @return {Array} Specific fields.
         */
        getSpecificFields() {
            return [
                { id: 'url', name: 'URL', placeholder: 'https://yourcorporation.com' },
                { id: 'logo', name: 'Logo', placeholder: 'https://yourcorporation.com/logo.png' },
                { id: 'contactPoint', name: 'Contact Point', type: 'object', placeholder: 'Customer support phone or contact info' },
                { id: 'address', name: 'Address', type: 'address', placeholder: 'Complete address information' },
                { id: 'founder', name: 'Founder', type: 'object', placeholder: 'Founder name or organization' },
                { id: 'foundingDate', name: 'Founding Date', placeholder: 'YYYY-MM-DD' },
            ];
        }
        
        /**
         * Apply schema specific preview for corporation.
         *
         * @since 2.1.0
         * @param {Object} jsonData JSON data object.
         * @param {Object} metaMap Meta mapping object.
         */
        applySchemaSpecificPreview(jsonData, metaMap) {
            // ✅ Address (proper PostalAddress with all sub-fields)
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
            
            // ✅ Image (proper ImageObject with width/height)
            if (metaMap.image) {
                const imageValue = this.getPreviewValue(metaMap.image);
                if (imageValue && imageValue.trim() !== '') {
                    jsonData.image = {
                        '@type': 'ImageObject',
                        'url': imageValue
                    };
                    // Try to get image dimensions if it's a WordPress attachment
                    if (imageValue.includes('wp-content/uploads')) {
                        jsonData.image.width = 1056; // Default, will be updated by backend
                        jsonData.image.height = 464; // Default, will be updated by backend
                    }
                }
            }
            
            // ✅ Logo (URL string or ImageObject)
            if (metaMap.logo) {
                const logoValue = this.getPreviewValue(metaMap.logo);
                if (logoValue && logoValue.trim() !== '') {
                    jsonData.logo = logoValue; // Simple URL string as per schema.org
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
    window.SRK.SRK_CorporationSchema = SRK_CorporationSchema;
 
})(jQuery);
 