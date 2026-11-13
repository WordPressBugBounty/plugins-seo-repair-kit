/**
 * Product Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
( function( $ ) {
	'use strict';

	/**
	 * Product Schema for SEO Repair Kit
	 *
	 * @since 2.1.0
	 */
	class SRK_ProductSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Product Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'product';
		}

		/**
		 * Get specific fields for product schema.
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'offers', name: 'Offers', required: true },
				{ id: 'sku', name: 'SKU' },
				{ id: 'brand', name: 'Brand', type: 'object' },
				{ id: 'price', name: 'Price' },
				{ id: 'regular_price', name: 'Regular Price' },
				{ id: 'sale_price', name: 'Sale Price' },
				{ id: 'stock_status', name: 'Stock Status' },
				{ id: 'category', name: 'Category' },
				{ id: 'tags', name: 'Tags' },
				{ id: 'product_short_description', name: 'Short Description' }
			];
		}

		/**
		 * Apply schema-specific preview transformations.
		 *
		 * @since 2.1.0
		 * @param {Object} jsonData JSON data object.
		 * @param {Object} metaMap Meta mapping object.
		 */
		applySchemaSpecificPreview( jsonData, metaMap ) {
			jsonData['@type'] = 'Product';

			// Handle brand as Brand object.
			if ( jsonData.brand && typeof jsonData.brand === 'string' ) {
				// Clean up meta: prefix or taxonomy terms.
				let brandValue = jsonData.brand;
				if ( brandValue.indexOf( 'meta:' ) === 0 || brandValue.indexOf( 'tax:' ) === 0 ) {
					brandValue = brandValue.replace( /^(meta|tax):/, '' );
				}
				
				jsonData.brand = {
					'@type': 'Brand',
					'name': brandValue
				};
			}

			// Collect price-related fields for offers.
			let price = jsonData.price || '';
			let regularPrice = jsonData.regular_price || '';
			let salePrice = jsonData.sale_price || '';
			let stockStatus = jsonData.stock_status || '';
			let offersMapping = jsonData.offers || '';

			// Clean up meta: prefixes from price values.
			price = typeof price === 'string' && price.indexOf( 'meta:' ) === 0 ? price.replace( 'meta:', '' ) : price;
			regularPrice = typeof regularPrice === 'string' && regularPrice.indexOf( 'meta:' ) === 0 ? regularPrice.replace( 'meta:', '' ) : regularPrice;
			salePrice = typeof salePrice === 'string' && salePrice.indexOf( 'meta:' ) === 0 ? salePrice.replace( 'meta:', '' ) : salePrice;
			stockStatus = typeof stockStatus === 'string' && stockStatus.indexOf( 'meta:' ) === 0 ? stockStatus.replace( 'meta:', '' ) : stockStatus;
			offersMapping = typeof offersMapping === 'string' && offersMapping.indexOf( 'meta:' ) === 0 ? offersMapping.replace( 'meta:', '' ) : offersMapping;

			// Build offers object from price fields.
			const offerPrice = salePrice || price || regularPrice || offersMapping;
			
			if ( offerPrice || stockStatus || offersMapping ) {
				// Map stock status to schema.org URLs.
				let availability = 'https://schema.org/InStock';
				if ( stockStatus ) {
					const statusLower = stockStatus.toLowerCase();
					if ( statusLower === 'outofstock' || statusLower === 'out of stock' ) {
						availability = 'https://schema.org/OutOfStock';
					} else if ( statusLower === 'onbackorder' || statusLower === 'on backorder' ) {
						availability = 'https://schema.org/PreOrder';
					}
				}

				// Extract numeric price.
				let numericPrice = offerPrice;
				if ( typeof offerPrice === 'string' ) {
					const priceMatch = offerPrice.match( /(\d+(?:\.\d+)?)/ );
					numericPrice = priceMatch ? parseFloat( priceMatch[1] ) : 0;
				}

				jsonData.offers = {
					'@type': 'Offer',
					'url': window.location.href || '#',
					'priceCurrency': 'USD', // Will be replaced by actual currency in PHP.
					'price': numericPrice || 0,
					'availability': availability,
					'itemCondition': 'https://schema.org/NewCondition',
					'seller': {
						'@type': 'Organization',
						'name': document.querySelector( 'meta[property="og:site_name"]' )?.content || window.location.hostname || ''
					}
				};

				// Add priceValidUntil (recommended by Google).
				const oneYearFromNow = new Date();
				oneYearFromNow.setFullYear( oneYearFromNow.getFullYear() + 1 );
				jsonData.offers.priceValidUntil = oneYearFromNow.toISOString().split( 'T' )[0];

				// Add PriceSpecification if we have regular price.
				if ( regularPrice ) {
					const regularPriceNum = typeof regularPrice === 'string' ? parseFloat( regularPrice.match( /(\d+(?:\.\d+)?)/ )?.[1] || 0 ) : regularPrice;
					jsonData.offers.priceSpecification = {
						'@type': 'PriceSpecification',
						'priceCurrency': 'USD',
						'price': regularPriceNum || numericPrice
					};
				}
			}

			// Remove price-related fields from Product level (they should only be in offers).
			delete jsonData.price;
			delete jsonData.regular_price;
			delete jsonData.sale_price;
			delete jsonData.stock_status;

			// Handle category - can be array or string.
			if ( jsonData.category ) {
				if ( typeof jsonData.category === 'string' && jsonData.category.indexOf( 'tax:' ) === 0 ) {
					jsonData.category = jsonData.category.replace( 'tax:', '' );
				} else if ( Array.isArray( jsonData.category ) ) {
					jsonData.category = jsonData.category.join( ', ' );
				}
			}

			// Handle tags - remove if not a Product property, or convert to array.
			if ( jsonData.tags ) {
				if ( typeof jsonData.tags === 'string' && jsonData.tags.indexOf( 'tax:' ) === 0 ) {
					jsonData.tags = jsonData.tags.replace( 'tax:', '' );
				}
				// Tags are not a standard Product property, so remove them or handle as keywords.
				delete jsonData.tags;
			}

			// Handle short_description - use disambiguatingDescription.
			if ( jsonData.product_short_description ) {
				jsonData.disambiguatingDescription = jsonData.product_short_description;
				delete jsonData.product_short_description;
			}

			// Clean up any remaining meta:/tax: prefixes.
			Object.keys( jsonData ).forEach( key => {
				if ( typeof jsonData[key] === 'string' && ( jsonData[key].indexOf( 'meta:' ) === 0 || jsonData[key].indexOf( 'tax:' ) === 0 ) ) {
					jsonData[key] = jsonData[key].replace( /^(meta|tax):/, '' );
				}
			});
		}
	}

	// Register with global namespace.
	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}
	window.SRK.SRK_ProductSchema = SRK_ProductSchema;

	// Manager me schema turant register karo.
	jQuery( function() {
		if ( window.SRK.Manager ) {
			window.SRK.Manager.registerSchemaType( 'product', SRK_ProductSchema );
		}
	} );

} )( jQuery );