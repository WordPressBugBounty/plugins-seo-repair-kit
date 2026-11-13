/**
 * Organization Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
 
(function ($) {
    'use strict';
 
    /**
     * Organization Schema class.
     *
     * @since 2.1.0
     */
    class SRK_OrganizationSchema extends window.SRK.BaseSchema {
        constructor(manager) {
            super(manager);
            this.schemaKey = 'organization';
        }
 
        /**
         * Get specific fields for organization schema.
         *
         * @since 2.1.0
         * @return {Array} Specific fields.
         */
        getSpecificFields() {
            return [
                { id: 'url', name: 'URL', required: true, placeholder: 'https://yourorganization.com' },
                { id: 'contactPoint', name: 'Contact Point', type: 'object', placeholder: 'Customer service phone number' },
                { id: 'email', name: 'Email', placeholder: 'info@yourorganization.com' },
                { id: 'telephone', name: 'Telephone', placeholder: '+1 234 567 890' },
                { id: 'address', name: 'Address', type: 'address', placeholder: 'Complete address information' },
                { id: 'facebook_url', name: 'Facebook', placeholder: 'https://facebook.com/yourpage' },
                { id: 'twitter_url', name: 'Twitter', placeholder: 'https://twitter.com/yourhandle' },
                { id: 'instagram_url', name: 'Instagram', placeholder: 'https://instagram.com/yourprofile' },
                { id: 'youtube_url', name: 'YouTube', placeholder: 'https://youtube.com/yourchannel' },
            ];
        }
 
        /**
         * Apply schema specific preview for organization.
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
            } else if (jsonData.image && !jsonData.logo) {
                // Fallback: use image as logo if logo not specified
                jsonData.logo = typeof jsonData.image === 'object' ? jsonData.image.url : jsonData.image;
            }

            // Handle contact point
            if (metaMap.contactPoint) {
                const contactValue = this.manager.getActualSiteValue(metaMap.contactPoint);
                if (contactValue) {
                    jsonData.contactPoint = {
                        '@type': 'ContactPoint',
                        'contactType': 'customer service',
                        'telephone': contactValue,
                    };
                }
            }

            // Handle social profiles
            const sameAs = [];
            ['facebook_url', 'twitter_url', 'instagram_url', 'youtube_url'].forEach((platform) => {
                if (metaMap[platform]) {
                    const url = this.manager.getActualSiteValue(metaMap[platform]);
                    if (url && url !== '[undefined]') {
                        let formattedUrl = url.replace(/^\[+|\]+$/g, '');
                        if (!/^https?:\/\//i.test(formattedUrl)) {
                            formattedUrl = 'https://' + formattedUrl.replace(/^\/+/, '');
                        }
                        sameAs.push(formattedUrl.trim());
                    }
                }
            });

            if (sameAs.length > 0) {
                jsonData.sameAs = sameAs;
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
    window.SRK.SRK_OrganizationSchema = SRK_OrganizationSchema;
})(jQuery);