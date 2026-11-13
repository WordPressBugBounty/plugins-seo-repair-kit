 /**
 * Receipe Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */
 ( function( $ ) {
    'use strict';
 
    /**
     * Recipe Schema for SEO Repair Kit
     *
     * @since 2.1.0
     */
    class SRK_RecipeSchema extends window.SRK.BaseSchema {
 
        /**
         * Initialize Recipe Schema.
         *
         * @since 2.1.0
         * @param {Object} manager Schema manager instance.
         */
        constructor( manager ) {
            super( manager );
            this.schemaKey = 'recipe';
        }
 
        /**
         * Get specific fields for recipe schema.
         *
         * @since 2.1.0
         * @return {Array} Specific fields.
         */
        getSpecificFields() {
            return [
                { id: 'recipeIngredient', name: 'Ingredients', type: 'array' },
                { id: 'recipeInstructions', name: 'Instructions', type: 'array' },
                { id: 'prepTime', name: 'Prep Time' },
                { id: 'cookTime', name: 'Cook Time' },
                { id: 'totalTime', name: 'Total Time' },
                { id: 'recipeYield', name: 'Yield' },
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
            jsonData['@type'] = 'Recipe';
 
            // Process each field based on its type.
            for ( const [field, value] of Object.entries( metaMap ) ) {
                if ( ! value ) continue;
 
                switch ( field ) {
                    case 'recipeIngredient':
                        if ( typeof value === 'string' ) {
                            jsonData.recipeIngredient = value.split( '\n' ).map( i => i.trim() ).filter( Boolean );
                        } else {
                            jsonData.recipeIngredient = value;
                        }
                        break;
 
                    case 'recipeInstructions':
                        if ( typeof value === 'string' ) {
                            const steps = value.split( '\n' ).map( i => i.trim() ).filter( Boolean );
                            jsonData.recipeInstructions = steps.map( ( step, index ) => ( {
                                '@type': 'HowToStep',
                                'position': index + 1,
                                'text': step
                            } ) );
                        } else {
                            jsonData.recipeInstructions = value;
                        }
                        break;
 
                    case 'prepTime':
                    case 'cookTime':
                    case 'totalTime':
                        jsonData[field] = this.convertToISO8601Duration( value );
                        break;
 
                    default:
                        jsonData[field] = value;
                        break;
                }
            }
        }
 
        /**
         * Convert time strings to ISO 8601 format.
         *
         * @since 2.1.0
         * @param {string} timeStr Time string to convert.
         * @return {string} ISO 8601 duration string.
         */
        convertToISO8601Duration( timeStr ) {
            if ( ! timeStr ) return timeStr;
 
            // If it's already in ISO format, return as is.
            if ( timeStr.match( /^PT\d+[HM]$/i ) ) {
                return timeStr;
            }
 
            // Try to parse human-readable format.
            const match = timeStr.match( /(\d+)\s*(minute|min|hour|hr|h)/i );
            if ( match ) {
                const num = parseInt( match[1], 10 );
                const unit = match[2].toLowerCase();
                if ( ['minute', 'min'].includes( unit ) ) return `PT${ num }M`;
                if ( ['hour', 'hr', 'h'].includes( unit ) ) return `PT${ num }H`;
            }
 
            return timeStr;
        }
    }
 
    // Register in global SRK namespace.
    if ( typeof window.SRK === 'undefined' ) window.SRK = {};
    window.SRK.SRK_RecipeSchema = SRK_RecipeSchema;
 
} )( jQuery );