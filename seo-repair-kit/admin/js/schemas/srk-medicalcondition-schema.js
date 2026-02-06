/**
 * Medical Condition Schema for SEO Repair Kit
 *
 * @package     SEO_Repair_Kit
 * @subpackage  Schema
 * @since       2.1.0
 */

( function( $ ) {
	'use strict';

	if ( typeof window.SRK === 'undefined' ) {
		window.SRK = {};
	}

	/**
	 * Medical Condition Schema class.
	 *
	 * @since 2.1.0
	 */
	class SRK_MedicalConditionSchema extends window.SRK.BaseSchema {

		/**
		 * Initialize Medical Condition Schema.
		 *
		 * @since 2.1.0
		 * @param {Object} manager Schema manager instance.
		 */
		constructor( manager ) {
			super( manager );
			this.schemaKey = 'medical_condition';
		}

		/**
		 * Fields list (only supported schema.org ones).
		 *
		 * @since 2.1.0
		 * @return {Array} Specific fields.
		 */
		getSpecificFields() {
			return [
				{ id: 'signOrSymptom', name: 'Signs or Symptoms', type: 'array' },
				{ id: 'possibleTreatment', name: 'Treatment Options', type: 'array' },
				{ id: 'possibleComplication', name: 'Complications', type: 'array' },
				{ id: 'riskFactor', name: 'Risk Factors', type: 'array' },
				{ id: 'epidemiology', name: 'Diagnosis / Epidemiology', type: 'array' },
				{ id: 'pathophysiology', name: 'Causes / Pathophysiology', type: 'array' },
				{ id: 'primaryPrevention', name: 'Prevention', type: 'array' },
			];
		}

		/**
		 * Schema preview.
		 *
		 * @since 2.1.0
		 * @param {Object} jsonData JSON data object.
		 * @param {Object} metaMap  Meta mapping object.
		 */
		applySchemaSpecificPreview( jsonData, metaMap ) {
			jsonData[ '@type' ] = 'MedicalCondition';

			// Process each field based on its type.
			for ( const [ field, value ] of Object.entries( metaMap ) ) {
				if ( ! value ) {
					continue;
				}

				switch ( field ) {
					case 'signOrSymptom':
						jsonData.signOrSymptom = this.arrayToSchema( value, 'MedicalSignOrSymptom' );
						break;

					case 'possibleTreatment':
						jsonData.possibleTreatment = this.arrayToSchema( value, 'MedicalTherapy' );
						break;

					case 'riskFactor':
						jsonData.riskFactor = this.arrayToSchema( value, 'MedicalRiskFactor' );
						break;

					case 'primaryPrevention':
						jsonData.primaryPrevention = this.arrayToSchema( value, 'MedicalTherapy' );
						break;

					case 'possibleComplication':
					case 'epidemiology':
					case 'pathophysiology':
						// For these fields, use the value as is (could be string or array).
						jsonData[ field ] = value;
						break;

					default:
						jsonData[ field ] = value;
						break;
				}
			}
		}

		/**
		 * Convert array to schema.
		 *
		 * @since 2.1.0
		 * @param {string|Array} value     Value to convert.
		 * @param {string}       typeName  Schema type name.
		 * @return {string|Array} Converted value.
		 */
		arrayToSchema( value, typeName ) {
			if ( ! value ) {
				return value;
			}

			// If it's a string, split by newlines.
			if ( typeof value === 'string' ) {
				value = value.split( '\n' ).map( ( v ) => v.trim() ).filter( ( v ) => v !== '' );
			}

			// If it's an array, convert to schema objects.
			if ( Array.isArray( value ) ) {
				return value.map( ( item ) => ( {
					'@type': typeName,
					name: item,
				} ) );
			}

			return value;
		}
	}

	window.SRK.SRK_MedicalConditionSchema = SRK_MedicalConditionSchema;
} )( jQuery );