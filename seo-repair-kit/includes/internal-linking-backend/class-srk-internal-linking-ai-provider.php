<?php
/**
 * AI provider transport for Internal Linking.
 *
 * Supports:
 * - OpenRouter
 * - OpenAI directly
 * - Custom OpenAI-compatible endpoints
 *
 * This class handles ONLY provider/API communication.
 * Semantic matching and opportunity logic remain inside the AI Engine.
 *
 * @package SEO_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Internal_Linking_AI_Provider {

	const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';
	const OPENAI_BASE_URL     = 'https://api.openai.com/v1';
    const GEMINI_BASE_URL     = 'https://generativelanguage.googleapis.com/v1beta';

	const OPENROUTER_FREE_CHAT_MODEL =
	'openrouter/free';

	const OPENROUTER_FREE_EMBEDDING_MODEL =
		'nvidia/nemotron-3-embed-1b:free';

	/**
	 * Detect a known AI provider from its API key format.
	 *
	 * @param string $api_key API key.
	 * @return string
	 */
	private static function detect_provider_from_key_format( $api_key ) {

		$api_key = trim(
			(string) $api_key
		);

		if ( '' === $api_key ) {
			return '';
		}

		if (
			0 === strpos(
				$api_key,
				'sk-or-'
			)
		) {
			return 'openrouter';
		}

		if (
			0 === strpos(
				$api_key,
				'AIza'
			)
		) {
			return 'gemini';
		}

		if (
			0 === strpos(
				$api_key,
				'sk-'
			)
		) {
			return 'openai';
		}

		return '';
	}	

	/**
	 * Get configured provider.
	 *
	 * @param string $override Optional provider override.
	 * @return string
	 */
	public static function get_provider( $override = '' ) {

		if ( '' !== trim( (string) $override ) ) {

			$provider =
				sanitize_key(
					$override
				);

			if (
				in_array(
					$provider,
					array(
						'openrouter',
						'openai',
						'gemini',
					),
					true
				)
			) {
				return $provider;
			}
		}

		$key =
			self::get_api_key();

		$provider =
			self::detect_provider_from_key_format(
				$key
			);

		return '' !== $provider
			? $provider
			: 'openrouter';
	}

	/**
	 * Get API key.
	 *
	 * We intentionally keep using the existing openrouter_api_key
	 * setting for backward compatibility. The field can simply be
	 * relabelled "AI API Key" in the UI.
	 *
	 * @param string $override Optional API key override.
	 * @return string
	 */
	public static function get_api_key( $override = '' ) {

		if ( '' !== trim( (string) $override ) ) {
			return sanitize_text_field(
				(string) $override
			);
		}

		$settings = SRK_Internal_Linking_Settings::get();

		return sanitize_text_field(
			$settings['openrouter_api_key'] ?? ''
		);
	}

	/**
	 * Resolve provider base URL.
	 *
	 * @param string $provider_override Provider override.
	 * @param string $base_url_override Base URL override.
	 * @return string
	 */
	public static function get_base_url( $provider_override = '', $base_url_override = '' ) {

		$provider = self::get_provider(
			$provider_override
		);

		if ( 'openrouter' === $provider ) {
			return self::OPENROUTER_BASE_URL;
		}

		if ( 'openai' === $provider ) {
			return self::OPENAI_BASE_URL;
		}

        if ( 'gemini' === $provider ) {
            return self::GEMINI_BASE_URL;
        }

		/*
		 * Custom OpenAI-compatible API.
		 */
		if ( '' !== trim( (string) $base_url_override ) ) {
			$base_url = esc_url_raw(
				$base_url_override
			);
		} else {
			$settings = SRK_Internal_Linking_Settings::get();

			$base_url = esc_url_raw(
				$settings['ai_base_url'] ?? ''
			);
		}

		return rtrim(
			$base_url,
			'/'
		);
	}

	/**
	 * Normalize model name for selected provider.
	 *
	 * OpenRouter commonly uses:
	 * openai/text-embedding-3-small
	 *
	 * OpenAI directly uses:
	 * text-embedding-3-small
	 *
	 * @param string $model Model.
	 * @param string $provider_override Provider.
	 * @return string
	 */
	public static function normalize_model(
		$model,
		$provider_override = ''
	) {

		$model = sanitize_text_field(
			(string) $model
		);

		$provider = self::get_provider(
			$provider_override
		);

		if (
			'openai' === $provider &&
			0 === strpos(
				$model,
				'openai/'
			)
		) {
			$model = substr(
				$model,
				strlen( 'openai/' )
			);
		}

		return $model;
	}

	/**
	 * Build embedding profile ID.
	 *
	 * The existing embeddings.model DB column can hold this value.
	 * This prevents embeddings from different providers/endpoints
	 * from being compared or incorrectly reused.
	 *
	 * Example:
	 * openai:a1b2c3d4:text-embedding-3-small
	 *
	 * @param string $model Model.
	 * @param string $provider_override Provider.
	 * @param string $base_url_override Base URL.
	 * @return string
	 */
	public static function get_embedding_profile(
		$model,
		$provider_override = '',
		$base_url_override = ''
	) {

		$provider = self::get_provider(
			$provider_override
		);

		$base_url = self::get_base_url(
			$provider,
			$base_url_override
		);

		$model = self::normalize_model(
			$model,
			$provider
		);

		$endpoint_hash = substr(
			hash(
				'sha256',
				$base_url
			),
			0,
			12
		);

		$profile = sprintf(
			'%s:%s:%s',
			$provider,
			$endpoint_hash,
			$model
		);

		/*
		 * DB column is VARCHAR(100).
		 */
		return substr(
			sanitize_text_field( $profile ),
			0,
			100
		);
	}

	/**
	 * Determine whether current provider is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {

		if ( '' === self::get_api_key() ) {
			return false;
		}

		$provider = self::get_provider();

		if (
			'custom' === $provider &&
			'' === self::get_base_url()
		) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the best embedding configuration available to the API key.
	 *
	 * The resolved configuration is cached so every post does not perform
	 * provider/model discovery again.
	 *
	 * @param string $api_key_override Optional API key.
	 * @param bool   $force_refresh Force provider/model discovery.
	 * @return array|WP_Error
	 */
	public static function resolve_embedding_config( $api_key_override = '', $force_refresh = false ) {

		$key =
			self::get_api_key(
				$api_key_override
			);

		if ( '' === $key ) {
			return new WP_Error(
				'srk_ai_missing_key',
				__(
					'AI API key is required.',
					'seo-repair-kit'
				)
			);
		}

		$fingerprint =
			substr(
				hash(
					'sha256',
					$key
				),
				0,
				24
			);

		$cache_key =
			'srk_il_ai_runtime_' .
			$fingerprint;

		if ( ! $force_refresh ) {

			$cached =
				get_transient(
					$cache_key
				);

			if (
				is_array( $cached ) &&
				! empty( $cached['provider'] ) &&
				! empty( $cached['model'] )
			) {
				return $cached;
			}
		}

		$provider =
			self::detect_provider_from_key_format(
				$key
			);

		/*
		* Unknown key format:
		* verify against the supported providers.
		*/
		if ( '' === $provider ) {

			$provider =
				self::probe_provider(
					$key
				);

			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
		}

		$base_url =
			self::get_base_url(
				$provider
			);

		switch ( $provider ) {

			case 'openai':

				$result =
					self::try_embedding_models(
						array(
							'text-embedding-3-large',
							'text-embedding-3-small',
						),
						$key,
						'openai',
						$base_url
					);

				break;

			case 'gemini':

				$result =
					self::try_embedding_models(
						array(
							'gemini-embedding-2',
							'gemini-embedding-001',
						),
						$key,
						'gemini',
						$base_url
					);

				break;

			case 'openrouter':

				$result =
					self::resolve_openrouter_embedding_model(
						$key
					);

				break;

			default:

				return new WP_Error(
					'srk_ai_unsupported_provider',
					__(
						'The AI API key provider is not supported.',
						'seo-repair-kit'
					)
				);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$config = array(
			'provider' =>
				$provider,

			'model' =>
				sanitize_text_field(
					$result['model']
				),

			'dimensions' =>
				absint(
					$result['dimensions']
						?? 0
				),

			'base_url' =>
				esc_url_raw(
					$base_url
				),
		);

		set_transient(
			$cache_key,
			$config,
			HOUR_IN_SECONDS
		);

		return $config;
	}

	/**
	 * Test embedding candidates and return the first model supported by the key.
	 *
	 * @param array  $models Models.
	 * @param string $key API key.
	 * @param string $provider Provider.
	 * @param string $base_url Base URL.
	 * @return array|WP_Error
	 */
	private static function try_embedding_models( $models, $key, $provider, $base_url ) {

		$last_error = null;

		foreach (
			array_values(
				array_unique(
					array_filter(
						(array) $models
					)
				)
			) as $model
		) {

			$result =
				self::create_embedding(
					'SEO Repair Kit embedding compatibility test.',
					$model,
					$key,
					$provider,
					$base_url,
					false
				);

			if ( ! is_wp_error( $result ) ) {

				return array(
					'model' =>
						$model,

					'dimensions' =>
						count(
							$result
						),
				);
			}

			$last_error =
				$result;
		}

		if ( is_wp_error( $last_error ) ) {
			return $last_error;
		}

		return new WP_Error(
			'srk_ai_no_embedding_model',
			__(
				'No compatible embedding model is available for this API key.',
				'seo-repair-kit'
			)
		);
	}

	/**
	 * Detect provider when the API key format is not recognized.
	 *
	 * @param string $key API key.
	 * @return string|WP_Error
	 */
	private static function probe_provider( $key ) {

		/*
		* OpenRouter provides a current-key endpoint,
		* so test it without consuming an embedding request.
		*/
		$openrouter =
			self::get_openrouter_key_info(
				$key
			);

		if ( ! is_wp_error( $openrouter ) ) {
			return 'openrouter';
		}

		/*
		* Cheap OpenAI compatibility probe.
		*/
		$openai =
			self::create_embedding(
				'SEO Repair Kit provider test.',
				'text-embedding-3-small',
				$key,
				'openai',
				self::OPENAI_BASE_URL,
				false
			);

		if ( ! is_wp_error( $openai ) ) {
			return 'openai';
		}

		/*
		* Gemini compatibility probe.
		*/
		$gemini =
			self::create_embedding(
				'SEO Repair Kit provider test.',
				'gemini-embedding-001',
				$key,
				'gemini',
				self::GEMINI_BASE_URL,
				false
			);

		if ( ! is_wp_error( $gemini ) ) {
			return 'gemini';
		}

		return new WP_Error(
			'srk_ai_unknown_provider',
			__(
				'SEO Repair Kit could not identify or connect to this AI API key.',
				'seo-repair-kit'
			)
		);
	}

	/**
	 * Get information for the current OpenRouter API key.
	 *
	 * @param string $key API key.
	 * @return array|WP_Error
	 */
	private static function get_openrouter_key_info( $key ) {

		$response =
			wp_safe_remote_get(
				self::OPENROUTER_BASE_URL .
				'/key',
				array(
					'timeout' =>
						20,

					'headers' =>
						self::build_headers(
							'openrouter',
							$key
						),
				)
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status =
			wp_remote_retrieve_response_code(
				$response
			);

		$body =
			json_decode(
				wp_remote_retrieve_body(
					$response
				),
				true
			);

		if (
			$status < 200 ||
			$status >= 300
		) {
			return new WP_Error(
				'srk_ai_openrouter_key_error',
				self::extract_error_message(
					$body,
					__(
						'Unable to verify the OpenRouter API key.',
						'seo-repair-kit'
					)
				)
			);
		}

		return is_array(
			$body['data'] ?? null
		)
			? $body['data']
			: array();
	}

	/**
	 * Resolve the best OpenRouter embedding model for this key.
	 *
	 * Paid keys prefer capable paid models.
	 * Free-tier keys use the best currently available free model.
	 *
	 * @param string $key OpenRouter API key.
	 * @return array|WP_Error
	 */
	private static function resolve_openrouter_embedding_model( $key ) {

		$key_info =
			self::get_openrouter_key_info(
				$key
			);

		if ( is_wp_error( $key_info ) ) {
			return $key_info;
		}

		$is_free_tier =
			! empty(
				$key_info['is_free_tier']
			);

		$url =
			add_query_arg(
				array(
					'output_modalities' =>
						'embeddings',

					'sort' =>
						'most-popular',
				),
				self::OPENROUTER_BASE_URL .
					'/models'
			);

		$response =
			wp_safe_remote_get(
				$url,
				array(
					'timeout' =>
						20,

					'headers' =>
						self::build_headers(
							'openrouter',
							$key
						),
				)
			);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status =
			wp_remote_retrieve_response_code(
				$response
			);

		$body =
			json_decode(
				wp_remote_retrieve_body(
					$response
				),
				true
			);

		if (
			$status < 200 ||
			$status >= 300 ||
			empty( $body['data'] ) ||
			! is_array( $body['data'] )
		) {
			return new WP_Error(
				'srk_ai_openrouter_models_error',
				__(
					'Unable to load OpenRouter embedding models.',
					'seo-repair-kit'
				)
			);
		}

		$paid_models =
			array();

		$free_models =
			array();

		foreach ( $body['data'] as $model ) {

			$model_id =
				sanitize_text_field(
					$model['id'] ?? ''
				);

			if ( '' === $model_id ) {
				continue;
			}

			$prompt_price =
				(float) (
					$model['pricing']['prompt']
						?? 0
				);

			$request_price =
				(float) (
					$model['pricing']['request']
						?? 0
				);

			if (
				$prompt_price <= 0 &&
				$request_price <= 0
			) {
				$free_models[] =
					$model_id;
			} else {
				$paid_models[] =
					$model_id;
			}
		}

		if ( $is_free_tier ) {

			$candidates =
				array_slice(
					$free_models,
					0,
					5
				);

		} else {

			$candidates =
				array();

			/*
			* OpenAI documents this as its most capable
			* embedding model, so prefer it when OpenRouter
			* exposes it to the paid account.
			*/
			if (
				in_array(
					'openai/text-embedding-3-large',
					$paid_models,
					true
				)
			) {
				$candidates[] =
					'openai/text-embedding-3-large';
			}

			/*
			* The OpenRouter API response is already sorted by
			* current popularity.
			*/
			$candidates =
				array_merge(
					$candidates,
					array_slice(
						$paid_models,
						0,
						4
					),

					/*
					* Always retain free fallbacks in case
					* purchased credits or key spending limit
					* are exhausted.
					*/
					array_slice(
						$free_models,
						0,
						3
					)
				);
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'srk_ai_no_openrouter_embedding_model',
				__(
					'No compatible OpenRouter embedding model is currently available for this API key.',
					'seo-repair-kit'
				)
			);
		}

		return self::try_embedding_models(
			$candidates,
			$key,
			'openrouter',
			self::OPENROUTER_BASE_URL
		);
	}

	/**
	 * Request embedding.
	 *
	 * @param string $text Input text.
	 * @param string $model Model.
	 * @param string $api_key_override API key override.
	 * @param string $provider_override Provider override.
	 * @param string $base_url_override Base URL override.
	 * @param bool   $use_cache Whether to use transient cache.
	 *
	 * @return array|WP_Error
	 */
	public static function create_embedding( $text, $model,$api_key_override = '', $provider_override = '', $base_url_override = '', $use_cache = true ) {
 
		$text = trim(
			(string) $text
		);

		if ( '' === $text ) {
			return new WP_Error(
				'srk_ai_empty_embedding_input',
				__(
					'Embedding input cannot be empty.',
					'seo-repair-kit'
				)
			);
		}

		/*
		* Resolve the provider before applying provider-specific model rules.
		*/
		$provider = self::get_provider(
			$provider_override
		);

		/*
		* Normalize the model for the selected provider.
		*/
		$model = self::normalize_model(
			$model,
			$provider
		);

		/*
		* OpenRouter free-only protection.
		*
		* Embedding requests must use an explicit :free embedding model.
		* This prevents legacy paid model settings from consuming credits.
		*/

		$key = self::get_api_key(
			$api_key_override
		);

		if ( '' === $key ) {
			return new WP_Error(
				'srk_ai_missing_key',
				__(
					'AI API key is required.',
					'seo-repair-kit'
				)
			);
		}

		$base_url = self::get_base_url(
			$provider,
			$base_url_override
		);

		if ( '' === $base_url ) {
			return new WP_Error(
				'srk_ai_missing_base_url',
				__(
					'AI API base URL is required.',
					'seo-repair-kit'
				)
			);
		}

		if ( '' === $model ) {
			return new WP_Error(
				'srk_ai_missing_embedding_model',
				__(
					'AI embedding model is required.',
					'seo-repair-kit'
				)
			);
		}

		$profile = self::get_embedding_profile(
			$model,
			$provider,
			$base_url
		);

		$cache_key =
			'srk_il_emb_' .
			md5(
				$profile .
				'|' .
				$text
			);

		if ( $use_cache ) {

			$cached = get_transient(
				$cache_key
			);

			if (
				is_array( $cached ) &&
				! empty( $cached )
			) {
				return $cached;
			}
		}

        if ( 'gemini' === $provider ) {

            return self::create_gemini_embedding(
                $text,
                $model,
                $key,
                $use_cache
            );
        }

		$url =
			rtrim(
				$base_url,
				'/'
			) .
			'/embeddings';

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 45,

				'headers' => self::build_headers(
					$provider,
					$key
				),

				'body' => wp_json_encode(
                    array(
                        'model' => $model,
                        'input' => $text,
                    )
                ),
                            )
		);

		if ( is_wp_error( $response ) ) {

			return new WP_Error(
				'srk_ai_embedding_request_failed',
				sprintf(
					/* translators: %s: provider request error */
					__(
						'AI embedding request failed: %s',
						'seo-repair-kit'
					),
					$response->get_error_message()
				)
			);
		}

		$status = wp_remote_retrieve_response_code(
			$response
		);

		$raw_body = wp_remote_retrieve_body(
			$response
		);

		$body = json_decode(
			$raw_body,
			true
		);

		if (
			$status < 200 ||
			$status >= 300
		) {

			return new WP_Error(
				'srk_ai_embedding_http_error',
				self::extract_error_message(
					$body,
					sprintf(
						__(
							'AI embedding provider returned HTTP %d.',
							'seo-repair-kit'
						),
						absint( $status )
					)
				),
				array(
					'http_status' => absint(
						$status
					),
					'provider' => $provider,
				)
			);
		}

		if (
			empty(
				$body['data'][0]['embedding']
			) ||
			! is_array(
				$body['data'][0]['embedding']
			)
		) {

			return new WP_Error(
				'srk_ai_invalid_embedding_response',
				__(
					'AI provider returned an invalid embedding response.',
					'seo-repair-kit'
				)
			);
		}

		$vector = array_map(
			'floatval',
			$body['data'][0]['embedding']
		);

		if ( empty( $vector ) ) {
			return new WP_Error(
				'srk_ai_empty_embedding',
				__(
					'AI provider returned an empty embedding vector.',
					'seo-repair-kit'
				)
			);
		}

		if ( $use_cache ) {
			set_transient(
				$cache_key,
				$vector,
				DAY_IN_SECONDS
			);
		}

		return $vector;
	}

	/**
	 * Chat completion.
	 *
	 * Currently optional in the Internal Linking pipeline.
	 *
	 * @param string $prompt Prompt.
	 * @param string $model Model.
	 *
	 * @return string|WP_Error
	 */
	public static function chat_completion( $prompt, $model ) {

		$provider = self::get_provider();

		$key = self::get_api_key();

		$base_url = self::get_base_url(
			$provider
		);

		if (
			'' === $key ||
			'' === $base_url
		) {
			return new WP_Error(
				'srk_ai_provider_not_configured',
				__(
					'AI provider is not configured.',
					'seo-repair-kit'
				)
			);
		}

		$model = self::normalize_model(
			$model,
			$provider
		);

		/*
		* OpenRouter free-only protection.
		*
		* Allow either:
		* - openrouter/free
		* - a specifically selected :free model.
		*
		* Any legacy paid model automatically falls back
		* to OpenRouter's free-model router.
		*/

		$url =
			rtrim(
				$base_url,
				'/'
			) .
			'/chat/completions';

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 45,

				'headers' => self::build_headers(
					$provider,
					$key
				),

				'body' => wp_json_encode(
					array(
						'model' => $model,

						'messages' => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),

						'temperature' => 0.2,
						'max_tokens'  => 300,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code(
			$response
		);

		$body = json_decode(
			wp_remote_retrieve_body(
				$response
			),
			true
		);

		if (
			$status < 200 ||
			$status >= 300
		) {
			return new WP_Error(
				'srk_ai_chat_error',
				self::extract_error_message(
					$body,
					__(
						'AI chat request failed.',
						'seo-repair-kit'
					)
				)
			);
		}

		return (string) (
			$body['choices'][0]['message']['content']
			?? ''
		);
	}

	/**
	 * Test provider using embedding endpoint.
	 *
	 * Cache is intentionally disabled so a new API key is actually tested.
	 *
	 * @param string $api_key API key override.
	 * @param string $provider Provider override.
	 * @param string $base_url Base URL override.
	 * @param string $model Embedding model.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection( $api_key = '' ) {

		$config =
			self::resolve_embedding_config(
				$api_key,
				true
			);

		if ( is_wp_error( $config ) ) {
			return $config;
		}

		return array(
			'ok' =>
				true,

			'provider' =>
				sanitize_key(
					$config['provider']
				),

			'model' =>
				sanitize_text_field(
					$config['model']
				),

			'dimensions' =>
				absint(
					$config['dimensions']
						?? 0
				),
		);
	}

	/**
	 * Build provider HTTP headers.
	 *
	 * @param string $provider Provider.
	 * @param string $key API key.
	 * @return array
	 */
	private static function build_headers( $provider, $key ) {

		$headers = array(
			'Authorization' =>
				'Bearer ' . $key,

			'Content-Type' =>
				'application/json',
		);

		/*
		 * OpenRouter-specific optional attribution headers.
		 */
		if ( 'openrouter' === $provider ) {

			$headers['HTTP-Referer'] =
				home_url( '/' );

			$headers['X-Title'] =
				'SEO Repair Kit Internal Linking';
		}

		return $headers;
	}

	/**
	 * Extract provider error message.
	 *
	 * @param array  $body Response body.
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	private static function extract_error_message( $body, $fallback ) {

		if (
			is_array( $body ) &&
			! empty(
				$body['error']['message']
			)
		) {
			return sanitize_text_field(
				$body['error']['message']
			);
		}

		if (
			is_array( $body ) &&
			! empty(
				$body['message']
			)
		) {
			return sanitize_text_field(
				$body['message']
			);
		}

		return sanitize_text_field(
			$fallback
		);
	}

    /**
     * Generate embedding using Google Gemini API.
     *
     * @param string $text      Input text.
     * @param string $model     Gemini embedding model.
     * @param string $api_key   Gemini API key.
     * @param bool   $use_cache Whether transient cache is enabled.
     *
     * @return array|WP_Error
     */
    private static function create_gemini_embedding( $text, $model, $api_key, $use_cache = true ) {

        $text = trim(
            (string) $text
        );

        $model = sanitize_text_field(
            (string) $model
        );

        $api_key = sanitize_text_field(
            (string) $api_key
        );

        if ( '' === $text ) {
            return new WP_Error(
                'srk_ai_gemini_empty_input',
                __(
                    'Gemini embedding input is empty.',
                    'seo-repair-kit'
                )
            );
        }

        if ( '' === $api_key ) {
            return new WP_Error(
                'srk_ai_gemini_missing_key',
                __(
                    'Gemini API key is required.',
                    'seo-repair-kit'
                )
            );
        }

        if ( '' === $model ) {
            $model = 'gemini-embedding-2';
        }

        /*
        * Gemini model names should not contain "models/" here
        * because we construct the resource path ourselves.
        */
        if (
            0 === strpos(
                $model,
                'models/'
            )
        ) {
            $model = substr(
                $model,
                strlen( 'models/' )
            );
        }

        $profile =
            self::get_embedding_profile(
                $model,
                'gemini'
            );

        $cache_key =
            'srk_il_emb_' .
            md5(
                $profile .
                '|' .
                $text
            );

        if ( $use_cache ) {

            $cached = get_transient(
                $cache_key
            );

            if (
                is_array( $cached ) &&
                ! empty( $cached )
            ) {
                return $cached;
            }
        }

        /*
        * For semantic comparison we want all posts generated using
        * exactly the same embedding configuration.
        *
        * Gemini Embedding 2 uses task instructions inside the text.
        */
        if ( 'gemini-embedding-2' === $model ) {

            $embedding_text =
                'task: sentence similarity | query: ' .
                $text;

        } else {

            $embedding_text = $text;
        }

        $url = sprintf(
            '%s/models/%s:embedContent',
            rtrim(
                self::GEMINI_BASE_URL,
                '/'
            ),
            rawurlencode(
                $model
            )
        );

        $body = array(
            'content' => array(
                'parts' => array(
                    array(
                        'text' => $embedding_text,
                    ),
                ),
            ),
        );

        /*
        * gemini-embedding-001 supports explicit semantic similarity
        * task configuration.
        */
        if ( 'gemini-embedding-001' === $model ) {
            $body['taskType'] =
                'SEMANTIC_SIMILARITY';
        }

        $response = wp_safe_remote_post(
            $url,
            array(
                'timeout' => 45,

                'headers' => array(
                    'Content-Type' =>
                        'application/json',

                    'x-goog-api-key' =>
                        $api_key,
                ),

                'body' => wp_json_encode(
                    $body
                ),
            )
        );

        if ( is_wp_error( $response ) ) {

            return new WP_Error(
                'srk_ai_gemini_request_failed',
                sprintf(
                    /* translators: %s: request error */
                    __(
                        'Gemini embedding request failed: %s',
                        'seo-repair-kit'
                    ),
                    $response->get_error_message()
                )
            );
        }

        $status =
            wp_remote_retrieve_response_code(
                $response
            );

        $raw_body =
            wp_remote_retrieve_body(
                $response
            );

        $decoded = json_decode(
            $raw_body,
            true
        );

        if (
            $status < 200 ||
            $status >= 300
        ) {

            $message = ! empty(
                $decoded['error']['message']
            )
                ? sanitize_text_field(
                    $decoded['error']['message']
                )
                : sprintf(
                    __(
                        'Gemini embedding request returned HTTP %d.',
                        'seo-repair-kit'
                    ),
                    absint( $status )
                );

            return new WP_Error(
                'srk_ai_gemini_http_error',
                $message,
                array(
                    'http_status' =>
                        absint( $status ),
                )
            );
        }

        if (
            empty(
                $decoded['embedding']['values']
            ) ||
            ! is_array(
                $decoded['embedding']['values']
            )
        ) {

            return new WP_Error(
                'srk_ai_gemini_invalid_embedding',
                __(
                    'Gemini returned a response but no valid embedding vector was found.',
                    'seo-repair-kit'
                ),
                array(
                    'response' => $decoded,
                )
            );
        }

        $vector = array_map(
            'floatval',
            $decoded['embedding']['values']
        );

        if ( empty( $vector ) ) {

            return new WP_Error(
                'srk_ai_gemini_empty_embedding',
                __(
                    'Gemini returned an empty embedding vector.',
                    'seo-repair-kit'
                )
            );
        }

        if ( $use_cache ) {

            set_transient(
                $cache_key,
                $vector,
                DAY_IN_SECONDS
            );
        }

        return $vector;
    }

    public static function get_default_embedding_model( $provider = '' ) {

		$provider = self::get_provider(
			$provider
		);

		switch ( $provider ) {

			case 'openai':
				return 'text-embedding-3-small';

			case 'gemini':
				return 'gemini-embedding-2';

			case 'openrouter':
				return self::OPENROUTER_FREE_EMBEDDING_MODEL;

			default:
				return '';
		}
	}
}