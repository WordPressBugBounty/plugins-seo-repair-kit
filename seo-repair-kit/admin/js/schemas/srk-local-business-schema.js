/**
 * Local Business Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
 
(function ($) {
    'use strict';
 
    class SRK_LocalBusinessSchema extends window.SRK.BaseSchema {
        constructor(manager) {
            super(manager);
            this.schemaKey = 'LocalBusiness';
        }
 
    /**
     * LocalBusiness fields (with placeholders).
     */
    getSpecificFields() {
        return [
            { id: 'alternateName', name: 'Alternative Name', type: 'text', placeholder: 'e.g., TorontoDigits SEO Experts' },
            { id: 'telephone', name: 'Telephone', type: 'text', placeholder: 'e.g., (408) 714-1489' },
            { id: 'priceRange', name: 'Price Range', type: 'text', placeholder: 'e.g., $$ or $$$' },
            { id: 'url', name: 'Business URL', type: 'text', placeholder: 'e.g., https://www.example.com' },
            { id: 'address', name: 'Address', type: 'address', placeholder: 'Complete address information' },
            { id: 'openingHours', name: 'Opening Hours', type: 'text', placeholder: 'e.g., Mo-Sa 11:00-14:30' },
            { id: 'latitude', name: 'Latitude', type: 'number', placeholder: 'e.g., 43.6532' },
            { id: 'longitude', name: 'Longitude', type: 'number', placeholder: 'e.g., -79.3832' },
            { id: 'ratingValue', name: 'Rating Value', type: 'text', placeholder: 'e.g., 4.5 (out of 5)' },
            { id: 'reviewCount', name: 'Review Count', type: 'text', placeholder: 'e.g., 250' },
            { id: 'keywords', name: 'Business Keywords', type: 'tags', placeholder: 'e.g., SEO, Digital Marketing' }
        ];
    }
 
    /**
     * Builds LocalBusiness schema preview.
     */
    applySchemaSpecificPreview(jsonData, metaMap) {
        jsonData['@context'] = 'https://schema.org';
        jsonData['@type'] = 'LocalBusiness';

        // Name
        if (metaMap.name) {
            const nameValue = this.getPreviewValue(metaMap.name);
            if (nameValue && nameValue.trim() !== '') {
                jsonData.name = nameValue;
            }
        }

        // Description
        if (metaMap.description) {
            const descValue = this.getPreviewValue(metaMap.description);
            if (descValue && descValue.trim() !== '') {
                jsonData.description = descValue;
            }
        }

        // Alternate Name
        if (metaMap.alternateName) {
            const altNameValue = this.getPreviewValue(metaMap.alternateName);
            if (altNameValue && altNameValue.trim() !== '') {
                jsonData.alternateName = altNameValue;
            }
        }

        // Telephone
        if (metaMap.telephone) {
            const phoneValue = this.getPreviewValue(metaMap.telephone);
            if (phoneValue && phoneValue.trim() !== '') {
                jsonData.telephone = phoneValue;
            }
        }

        // Price Range
        if (metaMap.priceRange) {
            const priceValue = this.getPreviewValue(metaMap.priceRange);
            if (priceValue && priceValue.trim() !== '') {
                jsonData.priceRange = priceValue;
            }
        }

        // URL
        if (metaMap.url) {
            const urlValue = this.getPreviewValue(metaMap.url);
            if (urlValue && urlValue.trim() !== '') {
                jsonData.url = urlValue;
            }
        }

        // Address (proper PostalAddress with all sub-fields)
        const streetAddress = metaMap.streetAddress ? this.getPreviewValue(metaMap.streetAddress) : '';
        const addressLocality = metaMap.addressLocality ? this.getPreviewValue(metaMap.addressLocality) : '';
        const addressRegion = metaMap.addressRegion ? this.getPreviewValue(metaMap.addressRegion) : '';
        const postalCode = metaMap.postalCode ? this.getPreviewValue(metaMap.postalCode) : '';
        const addressCountry = metaMap.addressCountry ? this.getPreviewValue(metaMap.addressCountry) : '';
        
        if (streetAddress || addressLocality || addressRegion || postalCode || addressCountry) {
            jsonData.address = {
                '@type': 'PostalAddress'
            };
            if (streetAddress && streetAddress.trim() !== '') {
                jsonData.address.streetAddress = streetAddress.trim();
            }
            if (addressLocality && addressLocality.trim() !== '') {
                jsonData.address.addressLocality = addressLocality.trim();
            }
            if (addressRegion && addressRegion.trim() !== '') {
                jsonData.address.addressRegion = addressRegion.trim();
            }
            if (postalCode && postalCode.trim() !== '') {
                jsonData.address.postalCode = postalCode.trim();
            }
            if (addressCountry && addressCountry.trim() !== '') {
                jsonData.address.addressCountry = {
                    '@type': 'Country',
                    'name': addressCountry.trim()
                };
            }
        }
        
        // Image (proper ImageObject with width/height)
        if (metaMap.image) {
            const imageValue = this.getPreviewValue(metaMap.image);
            if (imageValue && imageValue.trim() !== '' && !imageValue.includes('[')) {
                jsonData.image = {
                    '@type': 'ImageObject',
                    'url': imageValue
                };
                // Try to get image dimensions if it's a WordPress attachment
                if (imageValue.includes('wp-content/uploads')) {
                    jsonData.image.width = 1200; // Default, will be updated by backend
                    jsonData.image.height = 630; // Default, will be updated by backend
                }
            }
        }

        // Opening Hours (as string or array)
        if (metaMap.openingHours) {
            const val = this.getPreviewValue(metaMap.openingHours);
            if (val && val.trim() !== '') {
                // Check if comma-separated (array format)
                if (val.includes(',')) {
                    const arr = val
                        .split(',')
                        .map(v => v.trim())
                        .filter(v => v.length > 0);
                    if (arr.length > 0) {
                        jsonData.openingHours = arr;
                    }
                } else {
                    // Single string value - add as-is
                    // Schema.org accepts formats like:
                    // - "Mo-Fr 09:00-17:00"
                    // - "Monday-Friday 9:00 AM - 5:00 PM"
                    // - "07:08 - 04:30"
                    jsonData.openingHours = val.trim();
                }
            }
        }

        // Geo Coordinates
        const lat = metaMap.latitude ? this.getPreviewValue(metaMap.latitude) : '';
        const lon = metaMap.longitude ? this.getPreviewValue(metaMap.longitude) : '';
        if (lat && lon && String(lat).trim() !== '' && String(lon).trim() !== '') {
            // Clean values - remove invalid characters (keep digits, decimal, minus)
            const cleanLat = String(lat).replace(/[^0-9.\-]/g, '');
            const cleanLon = String(lon).replace(/[^0-9.\-]/g, '');
            
            // Check for multiple decimal points (invalid format)
            const latDecimalCount = (cleanLat.match(/\./g) || []).length;
            const lonDecimalCount = (cleanLon.match(/\./g) || []).length;
            
            // Validate format first
            if (latDecimalCount > 1 || lonDecimalCount > 1) {
                // Invalid format - add comment to help user
                jsonData._geoError = 'Invalid coordinates format (multiple decimal points detected)';
            } else if (cleanLat !== '' && cleanLon !== '') {
                const latNum = parseFloat(cleanLat);
                const lonNum = parseFloat(cleanLon);
                
                // Validate ranges: lat -90 to 90, lon -180 to 180
                if (isNaN(latNum) || isNaN(lonNum)) {
                    jsonData._geoError = 'Invalid coordinates (not a valid number)';
                } else if (latNum < -90 || latNum > 90) {
                    jsonData._geoError = 'Invalid latitude (must be between -90 and 90)';
                } else if (lonNum < -180 || lonNum > 180) {
                    jsonData._geoError = 'Invalid longitude (must be between -180 and 180)';
                } else {
                    // Valid coordinates!
                    jsonData.geo = {
                        '@type': 'GeoCoordinates',
                        'latitude': latNum,
                        'longitude': lonNum
                    };
                }
            }
        }

        // Aggregate Rating
        const ratingValue = metaMap.ratingValue ? this.getPreviewValue(metaMap.ratingValue) : '';
        const reviewCount = metaMap.reviewCount ? this.getPreviewValue(metaMap.reviewCount) : '';
        
        if (ratingValue && ratingValue.trim() !== '' && reviewCount && reviewCount.trim() !== '') {
            const rating = parseFloat(ratingValue);
            const count = parseInt(reviewCount);
            
            if (!isNaN(rating) && !isNaN(count) && rating > 0 && count > 0) {
                jsonData.aggregateRating = {
                    '@type': 'AggregateRating',
                    'ratingValue': rating.toString(),
                    'reviewCount': count.toString()
                };
            }
        }

        // Keywords
        if (metaMap.keywords) {
            const kw = this.getPreviewValue(metaMap.keywords);
            if (kw && kw.trim() !== '') {
                let arr = [];
                if (typeof kw === 'string') {
                    arr = kw.split(',').map(k => k.trim()).filter(k => k.length > 0);
                } else if (Array.isArray(kw)) {
                    arr = kw.filter(k => k && k.trim() !== '');
                }
                if (arr.length > 0) {
                    jsonData.keywords = arr;
                }
            }
        }

        return jsonData;
    }
 
 
    /**
     * Fetch mapped or literal preview value.
     */
    getPreviewValue(mapping) {
        if (!mapping) return '';

        // Handle empty strings
        if (typeof mapping === 'string' && mapping.trim() === '') return '';

        // Support for 'site:' and 'custom:'
        if (typeof mapping === 'string' && mapping.includes(':')) {
            const [type, key] = mapping.split(':', 2);
            if (type === 'custom') {
                // Return the actual custom value, not empty
                return key || '';
            }
            if (type === 'site') {
                // Return a placeholder for site values
                return `[${type}:${key}]`;
            }
            return `[${type}:${key}]`;
        }
        return mapping;
    }
 
        /**
         * Default field mappings (site defaults + placeholders for new).
         */
        getFieldConfiguration() {
            const config = super.getFieldConfiguration();
 
            // Site info defaults
            if (config.name && !config.name.mapping)
                config.name.mapping = 'site:site_name';
            if (config.description && !config.description.mapping)
                config.description.mapping = 'site:site_description';
            if (config.image && !config.image.mapping)
                config.image.mapping = 'site:site_logo';
 
            // Custom-only fields (with placeholder display for new ones)
            const customFields = [
                'alternateName', 'telephone', 'priceRange', 'url', 'address',
                'openingHours', 'latitude', 'longitude', 
                'ratingValue', 'reviewCount', 'keywords'
            ];

            customFields.forEach(field => {
                if (config[field] && !config[field].mapping)
                    config[field].mapping = 'custom:'; // placeholder appears automatically only for new
            });
 
            return config;
        }
 
    /**
     * When restoring saved data — remove placeholder if a real value exists.
     */
    prefillSchemaValues(metaMap) {
        const fields = this.getSpecificFields();

        fields.forEach(field => {
            const val = metaMap[field.id] || '';
            
            // Handle address field separately (composite field)
            if (field.id === 'address') {
                // Address has sub-fields, handle them
                const addressSubFields = ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'];
                addressSubFields.forEach(subField => {
                    const subVal = metaMap[subField] || '';
                    const $subInput = $(`[name="meta_map[${subField}]"]`);
                    
                    if ($subInput.length) {
                        if (subVal && subVal.trim() !== '') {
                            // Remove 'custom:' prefix if present
                            const cleanVal = subVal.replace('custom:', '');
                            $subInput.val(cleanVal);
                        }
                    }
                });
                return;
            }
            
            const $input = $(`[name="meta_map[${field.id}]"]`);

            if ($input.length) {
                if (val && val.trim() !== '') {
                    // ✅ Saved data → show only saved value (no placeholder)
                    const cleanVal = val.replace('custom:', '');
                    $input.val(cleanVal).attr('placeholder', '');
                    
                    // Enable the input field if it has a value
                    if ($input.prop('disabled')) {
                        $input.prop('disabled', false);
                    }
                } else {
                    // 🆕 No saved data → show placeholder
                    $input.attr('placeholder', field.placeholder || '');
                }
            }
        });
    }
    }
 
    if (typeof window.SRK === 'undefined') window.SRK = {};
    window.SRK.SRK_LocalBusinessSchema = SRK_LocalBusinessSchema;
 
})(jQuery);