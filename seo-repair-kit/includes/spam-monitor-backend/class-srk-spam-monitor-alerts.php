<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SERP alert settings, templates, delivery, and history helpers.
 */
class SRK_Spam_Monitor_Alerts {

	/**
	 * Get alert settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::get_alert_settings() : array();
		$defaults = array(
			'alerts_schema_version'  => 2,
			'alerts_enabled'         => 1,
			'recipient_emails'       => array(),
			'alert_risk_levels'      => array(
				'clean'      => 0,
				'suspicious' => 1,
				'spam'       => 1,
				'critical'   => 1,
			),
			'send_scan_summary'      => 0,
			'alert_history_limit'    => 100,
			// Deprecated manual cleanup email toggles. Cleanup status changes should not email by default.
			'notify_cleanup_completed' => 0,
			'notify_sitemap_issue'      => 0,
		);

		if ( empty( $saved ) || empty( $saved['alerts_schema_version'] ) ) {
			$settings = wp_parse_args(
				array(
					'recipient_emails' => $saved['recipient_emails'] ?? array(),
				),
				$defaults
			);

			return $settings;
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Save alert settings.
	 *
	 * @param array $raw Raw request data.
	 * @return bool
	 */
	public static function save_settings( array $raw ) {
		$emails_raw = $raw['recipient_emails'] ?? '';
		if ( is_array( $emails_raw ) ) {
			$emails_raw = implode( "\n", $emails_raw );
		}

		$emails = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $emails_raw ) as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		$levels = array();
		foreach ( array( 'clean', 'suspicious', 'spam', 'critical' ) as $level ) {
			$levels[ $level ] = ! empty( $raw[ 'alert_on_' . $level ] ) ? 1 : 0;
		}

		$settings = array(
			'alerts_schema_version'     => 2,
			'alerts_enabled'            => ! empty( $raw['alerts_enabled'] ) ? 1 : 0,
			'recipient_emails'          => array_values( array_unique( $emails ) ),
			'alert_risk_levels'         => $levels,
			'send_scan_summary'         => ! empty( $raw['send_scan_summary'] ) ? 1 : 0,
			'alert_history_limit'       => max( 25, min( 1000, absint( $raw['alert_history_limit'] ?? 100 ) ) ),
			'notify_cleanup_completed'  => 0,
			'notify_sitemap_issue'      => 0,
		);

		$saved = class_exists( 'SRK_Spam_Monitor_DB' ) ? SRK_Spam_Monitor_DB::save_alert_settings( $settings ) : false;
		if ( $saved && class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			SRK_Spam_Monitor_DB::trim_alert_history( $settings['alert_history_limit'] );
		}

		return $saved;
	}

	/**
	 * Resolve configured recipients with admin email fallback.
	 *
	 * @param array|null $settings Settings.
	 * @return array
	 */
	public static function resolve_recipients( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : self::get_settings();
		$emails   = array_filter( array_map( 'sanitize_email', (array) ( $settings['recipient_emails'] ?? array() ) ), 'is_email' );

		if ( empty( $emails ) ) {
			$admin = sanitize_email( get_option( 'admin_email' ) );
			if ( is_email( $admin ) ) {
				$emails[] = $admin;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Get current score thresholds from Spam Rules.
	 *
	 * @return array
	 */
	public static function get_thresholds() {
		return array(
			'clean_max'       => class_exists( 'SRK_Spam_Monitor_DB' ) ? absint( SRK_Spam_Monitor_DB::get_rule( 'score_clean_max', 30 ) ) : 30,
			'suspicious_max'  => class_exists( 'SRK_Spam_Monitor_DB' ) ? absint( SRK_Spam_Monitor_DB::get_rule( 'score_suspicious_max', 60 ) ) : 60,
			'spam_starts'     => class_exists( 'SRK_Spam_Monitor_DB' ) ? absint( SRK_Spam_Monitor_DB::get_rule( 'score_spam_min', 61 ) ) : 61,
			'critical_starts' => class_exists( 'SRK_Spam_Monitor_DB' ) ? absint( SRK_Spam_Monitor_DB::get_rule( 'score_critical_min', 81 ) ) : 81,
		);
	}

	/**
	 * Send SERP scan alert if settings allow it.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $result Python response.
	 * @return void
	 */
	public static function maybe_send_serp_scan_alert( $scan_id, array $result ) {
		if ( ! class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['alerts_enabled'] ) ) {
			return;
		}

		$alert_data = self::build_scan_alert_data( $scan_id, $result );
		if ( empty( $alert_data['should_alert'] ) && empty( $settings['send_scan_summary'] ) ) {
			return;
		}

		$levels = wp_parse_args( (array) ( $settings['alert_risk_levels'] ?? array() ), array( 'clean' => 0, 'suspicious' => 1, 'spam' => 1, 'critical' => 1 ) );
		if ( empty( $settings['send_scan_summary'] ) && empty( $levels[ sanitize_key( $alert_data['risk_level'] ) ] ) ) {
			return;
		}

		$recipients = self::resolve_recipients( $settings );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject = self::build_subject( $alert_data );
		$body    = self::build_body( $alert_data );
		$sent    = 0;

		$headers = self::get_email_headers();
		foreach ( $recipients as $email ) {
			if ( wp_mail( $email, $subject, $body, $headers ) ) {
				$sent++;
			}
		}

		SRK_Spam_Monitor_DB::save_alert_log(
			array(
				'scan_id'    => $scan_id,
				'domain'     => $alert_data['domain'],
				'alert_type' => $alert_data['alert_type'],
				'url'        => $alert_data['top_url'],
				'score'      => $alert_data['risk_score'],
				'risk_level' => $alert_data['risk_level'],
				'url_count'  => $alert_data['detected_url_count'],
				'recipient'  => implode( ', ', $recipients ),
				'subject'    => $subject,
				'status'     => $sent ? 'sent' : 'failed',
			)
		);
		SRK_Spam_Monitor_DB::trim_alert_history( $settings['alert_history_limit'] ?? 100 );
	}

	/**
	 * Send a test alert email.
	 *
	 * @param string $recipient Optional recipient.
	 * @return bool
	 */
	public static function send_test_email( $recipient = '' ) {
		$recipients = array();
		$recipient  = sanitize_email( $recipient );

		if ( is_email( $recipient ) ) {
			$recipients[] = $recipient;
		} else {
			$recipients = self::resolve_recipients();
		}

		if ( empty( $recipients ) ) {
			return false;
		}

		$subject = '[SEO Repair Kit] SERP Alert Test';
		$message = self::build_body(
			array(
				'alert_type'         => 'Spam Detected',
				'domain'             => home_url( '/' ),
				'risk_level'         => 'Critical',
				'risk_score'         => 92,
				'detected_url_count' => 3,
				'top_url'            => home_url( '/example-spam-url/' ),
				'issues_list'        => "Suspicious language detected\nSpam keyword detected\nSuspicious URL pattern detected",
				'risk_urls'          => array(
					array(
						'url'        => home_url( '/example-spam-url/' ),
						'risk_level' => 'Critical',
						'risk_score' => 92,
						'title'      => 'Example spam title',
					),
					array(
						'url'        => home_url( '/pharma-page/' ),
						'risk_level' => 'Spam',
						'risk_score' => 78,
						'title'      => 'Injected pharma page',
					),
				),
				'received_results'   => 25,
				'dashboard_url'      => self::get_dashboard_url( 'dashboard' ),
				'scan_date'          => current_time( 'mysql' ),
			)
		);

		$headers = self::get_email_headers();
		$sent = true;
		foreach ( $recipients as $email ) {
			$sent = wp_mail( $email, $subject, $message, $headers ) && $sent;
		}

		return $sent;
	}

	/**
	 * Send one operational warning after repeated scheduler failures.
	 *
	 * @param array $schedule Schedule state.
	 * @return bool
	 */
	public static function send_schedule_failure_alert( array $schedule ) {
		$settings = self::get_settings();
		if ( empty( $settings['alerts_enabled'] ) ) {
			return false;
		}

		$recipients = self::resolve_recipients( $settings );
		if ( empty( $recipients ) ) {
			return false;
		}

		$subject = __( '[SEO Repair Kit] Scheduled Spam Monitor needs attention', 'seo-repair-kit' );
		$message = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#111827;background:#f6f7fb;padding:24px;">';
		$message .= '<div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">';
		$message .= '<h1 style="margin:0 0 12px;font-size:22px;">' . esc_html__( 'Scheduled Spam Monitor needs attention', 'seo-repair-kit' ) . '</h1>';
		$message .= '<p>' . esc_html__( 'Three or more consecutive scheduled scans could not complete. Automatic scanning remains configured, but you should review provider quota, connectivity, and the saved schedule.', 'seo-repair-kit' ) . '</p>';
		$message .= '<p><strong>' . esc_html__( 'Website:', 'seo-repair-kit' ) . '</strong> ' . esc_html( home_url( '/' ) ) . '</p>';
		$message .= '<p><strong>' . esc_html__( 'Status:', 'seo-repair-kit' ) . '</strong> ' . esc_html( sanitize_key( $schedule['last_status'] ?? 'failed' ) ) . '</p>';
		$message .= '<p><strong>' . esc_html__( 'Error code:', 'seo-repair-kit' ) . '</strong> ' . esc_html( sanitize_key( $schedule['last_error_code'] ?? 'unknown' ) ) . '</p>';
		$message .= '<p><a href="' . esc_url( self::get_dashboard_url( 'settings' ) ) . '" style="display:inline-block;background:#0b1d51;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;">' . esc_html__( 'Review Spam Monitor Settings', 'seo-repair-kit' ) . '</a></p>';
		$message .= '</div></body></html>';

		$sent = true;
		foreach ( $recipients as $email ) {
			$sent = wp_mail( $email, $subject, $message, self::get_email_headers() ) && $sent;
		}

		if ( class_exists( 'SRK_Spam_Monitor_DB' ) ) {
			SRK_Spam_Monitor_DB::save_alert_log(
				array(
					'scan_id'    => 0,
					'domain'     => home_url( '/' ),
					'alert_type' => 'Schedule Failure',
					'url'        => '',
					'score'      => 0,
					'risk_level' => 'warning',
					'url_count'  => 0,
					'recipient'  => implode( ', ', $recipients ),
					'subject'    => $subject,
					'status'     => $sent ? 'sent' : 'failed',
				)
			);
		}

		return $sent;
	}

	private static function get_email_headers() {
		return array(
			'Content-Type: text/html; charset=UTF-8',
			'Reply-To: SEO Repair Kit <ab@seorepairkit.com>',
		);
	}

	/**
	 * Build alert data from a scan result.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $result Result.
	 * @return array
	 */
	private static function build_scan_alert_data( $scan_id, array $result ) {
		$thresholds = self::get_thresholds();
		$results    = is_array( $result['results'] ?? null ) ? $result['results'] : array();
		$summary    = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$scan_args  = is_array( $result['scan_args'] ?? null ) ? $result['scan_args'] : array();
		$top        = null;
		$risk_urls  = array();
		$email_results = array();

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}

			$level = sanitize_key( $row['risk_level'] ?? 'clean' );
			$email_results[] = array(
				'position'   => absint( $row['position'] ?? 0 ),
				'url'        => esc_url_raw( $row['url'] ?? '' ),
				'title'      => sanitize_text_field( $row['google_title'] ?? $row['title'] ?? '' ),
				'snippet'    => sanitize_textarea_field( $row['google_snippet'] ?? $row['snippet'] ?? '' ),
				'risk_level' => $level ? $level : 'clean',
				'risk_score' => min( 100, absint( $row['risk_score'] ?? 0 ) ),
				'reason'     => self::format_issues( $row['issues'] ?? array() ),
			);

			if ( ! in_array( $level, array( 'suspicious', 'spam', 'critical' ), true ) ) {
				continue;
			}
			$risk_urls[] = array(
				'position'   => absint( $row['position'] ?? 0 ),
				'url'        => esc_url_raw( $row['url'] ?? '' ),
				'risk_level' => sanitize_text_field( $row['risk_level'] ?? 'Suspicious' ),
				'risk_score' => absint( $row['risk_score'] ?? 0 ),
				'title'      => sanitize_text_field( $row['google_title'] ?? '' ),
				'snippet'    => sanitize_textarea_field( $row['google_snippet'] ?? '' ),
				'reason'     => self::format_issues( $row['issues'] ?? array() ),
			);
			if ( null === $top || absint( $row['risk_score'] ?? 0 ) > absint( $top['risk_score'] ?? 0 ) ) {
				$top = $row;
			}
		}

		usort(
			$risk_urls,
			static function( $left, $right ) {
				return (int) ( $right['risk_score'] ?? 0 ) <=> (int) ( $left['risk_score'] ?? 0 );
			}
		);

		$score    = absint( $top['risk_score'] ?? ( $result['overall_risk_score'] ?? 0 ) );
		$level    = self::level_from_score( $score, $thresholds );
		$url_count = 0;
		$risky_count = 0;
		foreach ( $results as $row ) {
			$row_level = sanitize_key( $row['risk_level'] ?? 'clean' );
			if ( in_array( $row_level, array( 'suspicious', 'spam', 'critical' ), true ) ) {
				$risky_count++;
			}
			if ( $row_level === sanitize_key( $level ) ) {
				$url_count++;
			}
		}

		$should_alert = null !== $top;
		$alert_type   = $should_alert ? 'Spam Detected' : 'Scan Summary';
		$top_url      = $should_alert ? esc_url_raw( $top['url'] ?? '' ) : '';
		$issues_list  = $should_alert
			? self::format_issues( $top['issues'] ?? array() )
			: __( 'No suspicious, spam, or critical indexed results were detected in this scan.', 'seo-repair-kit' );

		$normalized_summary = array(
			'clean_count'      => absint( $summary['clean_count'] ?? $result['clean_count'] ?? 0 ),
			'suspicious_count' => absint( $summary['suspicious_count'] ?? $result['suspicious_count'] ?? 0 ),
			'spam_count'       => absint( $summary['spam_count'] ?? $result['spam_count'] ?? 0 ),
			'critical_count'   => absint( $summary['critical_count'] ?? $result['critical_count'] ?? 0 ),
		);

		return array(
			'scan_id'            => absint( $scan_id ),
			'domain'             => sanitize_text_field( $result['domain'] ?? '' ),
			'alert_type'         => $alert_type,
			'risk_level'         => $level,
			'risk_score'         => $score,
			'detected_url_count' => $should_alert ? max( 1, $url_count ) : $risky_count,
			'top_url'            => $top_url,
			'issues_list'        => $issues_list,
			'risk_urls'          => array_slice( $risk_urls, 0, 10 ),
			'remaining_url_count'=> max( 0, count( $risk_urls ) - 10 ),
			'results'            => $email_results,
			'total_result_count' => count( $email_results ),
			'flagged_count'      => count( $risk_urls ),
			'received_results'   => absint( $result['received_results'] ?? 0 ),
			'summary'            => $normalized_summary,
			'settings_snapshot'  => self::build_settings_snapshot( $scan_args ),
			'dashboard_url'      => self::get_dashboard_url( 'dashboard' ),
			'scan_date'          => current_time( 'mysql' ),
			'should_alert'       => $should_alert,
			'scan_source'        => 'scheduled' === sanitize_key( $result['scan_source'] ?? 'manual' ) ? 'scheduled' : 'manual',
		);
	}

	/**
	 * Build the settings snapshot shown in scan-report emails.
	 *
	 * @param array $scan_args Normalized scan arguments.
	 * @return array
	 */
	private static function build_settings_snapshot( array $scan_args ) {
		$rules = class_exists( 'SRK_Spam_Monitor_Rules_Helper' )
			? SRK_Spam_Monitor_Rules_Helper::get_rules_for_serp_scan()
			: array();
		$language = is_array( $rules['language_rules'] ?? null ) ? $rules['language_rules'] : array();
		$keywords = is_array( $rules['spam_keyword_rules'] ?? null ) ? $rules['spam_keyword_rules'] : array();
		$url_rules = is_array( $rules['url_pattern_rules'] ?? null ) ? $rules['url_pattern_rules'] : array();
		$thresholds = is_array( $rules['score_thresholds'] ?? null ) ? $rules['score_thresholds'] : array();

		$category_labels = array(
			'gambling'             => __( 'Gambling', 'seo-repair-kit' ),
			'adult'                => __( 'Adult Content', 'seo-repair-kit' ),
			'pharma'               => __( 'Pharma', 'seo-repair-kit' ),
			'vape_tobacco_shisha'  => __( 'Vape & Tobacco', 'seo-repair-kit' ),
			'counterfeit_luxury'   => __( 'Counterfeit Products', 'seo-repair-kit' ),
			'jp_cn_ecommerce'      => __( 'Ecommerce Spam', 'seo-repair-kit' ),
		);
		$enabled_categories = array();
		foreach ( (array) ( $keywords['enabled_categories'] ?? array() ) as $key => $enabled ) {
			if ( $enabled ) {
				$enabled_categories[] = $category_labels[ $key ] ?? ucwords( str_replace( '_', ' ', sanitize_key( $key ) ) );
			}
		}

		$custom_keywords = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $keywords['custom_blocked_keywords'] ?? array() ) ) ) );
		$keyword_preview = self::format_preview_list( $custom_keywords, 10 );

		$url_monitoring = array();
		if ( ! empty( $url_rules['detect_fake_product_urls'] ) ) {
			$url_monitoring[] = __( 'Suspicious URLs', 'seo-repair-kit' );
		}
		if ( ! empty( $url_rules['detect_suspicious_url_slugs'] ) ) {
			$url_monitoring[] = __( 'Suspicious slugs', 'seo-repair-kit' );
		}
		$patterns = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $url_rules['blocked_url_patterns'] ?? array() ) ) ) );
		if ( ! empty( $patterns ) ) {
			$url_monitoring[] = sprintf(
				/* translators: %s: comma-separated URL patterns. */
				__( 'Patterns: %s', 'seo-repair-kit' ),
				implode( ', ', self::format_preview_list( $patterns, 5 ) )
			);
		}

		$requests = max( 1, absint( $scan_args['max_serp_requests'] ?? 1 ) );
		$results  = max( 10, absint( $scan_args['max_results'] ?? ( $requests * 10 ) ) );

		return array(
			__( 'Scan depth', 'seo-repair-kit' )          => sprintf(
				/* translators: 1: SERP requests, 2: maximum Google results. */
				__( '%1$d requests / up to %2$d Google results', 'seo-repair-kit' ),
				$requests,
				$results
			),
			__( 'Scan source', 'seo-repair-kit' )         => 'scheduled' === sanitize_key( $scan_args['scan_source'] ?? 'manual' ) ? __( 'Scheduled', 'seo-repair-kit' ) : __( 'Manual', 'seo-repair-kit' ),
			__( 'Threshold profile', 'seo-repair-kit' )   => __( 'Saved Spam Rules', 'seo-repair-kit' ),
			__( 'Threshold ranges', 'seo-repair-kit' )    => sprintf(
				/* translators: 1: clean max, 2: suspicious start, 3: suspicious max, 4: spam start, 5: spam max, 6: critical start. */
				__( 'Clean 0-%1$d | Suspicious %2$d-%3$d | Spam %4$d-%5$d | Critical %6$d-100', 'seo-repair-kit' ),
				absint( $thresholds['clean_max'] ?? 30 ),
				absint( $thresholds['clean_max'] ?? 30 ) + 1,
				absint( $thresholds['suspicious_max'] ?? 60 ),
				absint( $thresholds['spam_starts'] ?? 61 ),
				max( absint( $thresholds['spam_starts'] ?? 61 ), absint( $thresholds['critical_starts'] ?? 81 ) - 1 ),
				absint( $thresholds['critical_starts'] ?? 81 )
			),
			__( 'Website languages', 'seo-repair-kit' )   => self::format_language_list_for_snapshot( (array) ( $language['allowed_languages'] ?? array( 'en' ) ), 6 ),
			__( 'Suspicious languages', 'seo-repair-kit' )=> self::format_language_list_for_snapshot( (array) ( $language['expected_spam_languages'] ?? array() ), 8 ),
			__( 'Spam categories', 'seo-repair-kit' )     => ! empty( $enabled_categories ) ? implode( ', ', $enabled_categories ) : __( 'None', 'seo-repair-kit' ),
			__( 'Custom keywords', 'seo-repair-kit' )     => ! empty( $keyword_preview ) ? implode( ', ', $keyword_preview ) : __( 'None', 'seo-repair-kit' ),
			__( 'URL monitoring', 'seo-repair-kit' )      => ! empty( $url_monitoring ) ? implode( ' | ', $url_monitoring ) : __( 'Disabled', 'seo-repair-kit' ),
		);
	}

	/**
	 * Get risk level from score.
	 *
	 * @param int   $score Score.
	 * @param array $thresholds Thresholds.
	 * @return string
	 */
	private static function level_from_score( $score, array $thresholds ) {
		if ( $score >= absint( $thresholds['critical_starts'] ?? 81 ) ) {
			return 'Critical';
		}
		if ( $score >= absint( $thresholds['spam_starts'] ?? 61 ) ) {
			return 'Spam';
		}
		if ( $score > absint( $thresholds['clean_max'] ?? 30 ) ) {
			return 'Suspicious';
		}
		return 'Clean';
	}

	/**
	 * Build email subject.
	 *
	 * @param array $data Alert data.
	 * @return string
	 */
	private static function build_subject( array $data ) {
		if ( 'Scan Summary' === ( $data['alert_type'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: domain */
				__( '[SEO Repair Kit] Scan Summary for %s', 'seo-repair-kit' ),
				sanitize_text_field( $data['domain'] ?? home_url( '/' ) )
			);
		}

		return sprintf(
			/* translators: %s: risk level */
			__( '[SEO Repair Kit] %s Indexed Spam Detected', 'seo-repair-kit' ),
			sanitize_text_field( $data['risk_level'] ?? 'Spam' )
		);
	}

	/**
	 * Build email body.
	 *
	 * @param array $data Alert data.
	 * @return string
	 */
	private static function build_body( array $data ) {
		$summary = is_array( $data['summary'] ?? null ) ? $data['summary'] : array();
		$results = is_array( $data['results'] ?? null ) ? $data['results'] : array();
		if ( empty( $results ) && ! empty( $data['risk_urls'] ) && is_array( $data['risk_urls'] ) ) {
			$results = $data['risk_urls'];
		}

		$normalized_results = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || empty( $result['url'] ) ) {
				continue;
			}
			$reason_lines = self::format_issue_lines( $result['issues'] ?? $result['reason'] ?? array() );
			$normalized_results[] = array(
				'position' => absint( $result['position'] ?? 0 ),
				'url'      => esc_url_raw( $result['url'] ?? '' ),
				'title'    => sanitize_text_field( $result['title'] ?? $result['google_title'] ?? __( 'Untitled result', 'seo-repair-kit' ) ),
				'snippet'  => sanitize_textarea_field( $result['snippet'] ?? $result['google_snippet'] ?? '' ),
				'risk'     => sanitize_key( $result['risk_level'] ?? 'clean' ),
				'score'    => min( 100, absint( $result['risk_score'] ?? 0 ) ),
				'reason'   => sanitize_textarea_field( $result['reason'] ?? self::format_issues( $result['issues'] ?? array() ) ),
				'reason_lines' => $reason_lines,
			);
		}

		$result_limit = 25;
		$total_results = absint( $data['total_result_count'] ?? count( $normalized_results ) );
		$flagged_count = absint( $data['flagged_count'] ?? $data['detected_url_count'] ?? 0 );
		$scan_date = sanitize_text_field( $data['scan_date'] ?? current_time( 'mysql' ) );
		$timestamp = strtotime( $scan_date );
		$template_data = array(
			'domain'                 => sanitize_text_field( $data['domain'] ?? home_url( '/' ) ),
			'generated_at'           => $timestamp ? date_i18n( 'F j, Y \a\t H:i T', $timestamp ) : $scan_date,
			'received_results'       => absint( $data['received_results'] ?? $total_results ),
			'clean_count'            => absint( $summary['clean_count'] ?? 0 ),
			'suspicious_count'       => absint( $summary['suspicious_count'] ?? 0 ),
			'spam_count'             => absint( $summary['spam_count'] ?? 0 ),
			'critical_count'         => absint( $summary['critical_count'] ?? 0 ),
			'flagged_count'          => $flagged_count,
			'total_result_count'     => $total_results,
			'displayed_result_count' => min( count( $normalized_results ), $result_limit ),
			'remaining_result_count' => max( 0, count( $normalized_results ) - $result_limit ),
			'results'                => array_slice( $normalized_results, 0, $result_limit ),
			'settings_snapshot'      => is_array( $data['settings_snapshot'] ?? null ) ? $data['settings_snapshot'] : array(),
			'dashboard_url'          => esc_url( $data['dashboard_url'] ?? self::get_dashboard_url( 'dashboard' ) ),
			'cta_label'              => __( 'Open Spam Monitor Dashboard', 'seo-repair-kit' ),
			'is_summary'             => 'Scan Summary' === ( $data['alert_type'] ?? '' ),
		);

		$template = __DIR__ . '/templates/email/spam-scan-report.php';
		if ( ! is_readable( $template ) ) {
			return '';
		}

		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/**
	 * Build flagged URL table rows, capped to 10 entries.
	 *
	 * @param array $data Alert data.
	 * @return string
	 */
	private static function build_risk_url_table( array $data ) {
		$rows = is_array( $data['risk_urls'] ?? null ) ? $data['risk_urls'] : array();
		if ( empty( $rows ) ) {
			return '<p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#475467;">' . esc_html__( 'No flagged URLs were included in this email preview.', 'seo-repair-kit' ) . '</p>';
		}

		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border:1px solid #eaecf0;border-radius:12px;overflow:hidden;margin-bottom:16px;">';
		$html .= '<tr>';
		$html .= '<th align="left" style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #eaecf0;font-size:12px;text-transform:uppercase;color:#667085;">' . esc_html__( 'URL', 'seo-repair-kit' ) . '</th>';
		$html .= '<th align="left" style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #eaecf0;font-size:12px;text-transform:uppercase;color:#667085;">' . esc_html__( 'Risk', 'seo-repair-kit' ) . '</th>';
		$html .= '<th align="right" style="padding:12px 14px;background:#f8fafc;border-bottom:1px solid #eaecf0;font-size:12px;text-transform:uppercase;color:#667085;">' . esc_html__( 'Score', 'seo-repair-kit' ) . '</th>';
		$html .= '</tr>';
		foreach ( $rows as $row ) {
			$title = sanitize_text_field( $row['title'] ?? '' );
			$url   = esc_url( $row['url'] ?? '' );
			$html .= '<tr>';
			$html .= '<td style="padding:12px 14px;border-bottom:1px solid #eaecf0;font-size:14px;line-height:1.6;color:#101828;">';
			$html .= '<div style="font-weight:600;">' . ( $url ? '<a href="' . $url . '" style="color:#101828;text-decoration:none;">' . esc_html( self::truncate_url_for_email( $row['url'] ?? '' ) ) . '</a>' : '-' ) . '</div>';
			if ( '' !== $title ) {
				$html .= '<div style="margin-top:4px;font-size:12px;color:#667085;">' . esc_html( $title ) . '</div>';
			}
			$html .= '</td>';
			$html .= '<td style="padding:12px 14px;border-bottom:1px solid #eaecf0;font-size:14px;color:#101828;">' . esc_html( $row['risk_level'] ?? '' ) . '</td>';
			$html .= '<td align="right" style="padding:12px 14px;border-bottom:1px solid #eaecf0;font-size:14px;color:#101828;font-weight:700;">' . esc_html( (string) absint( $row['risk_score'] ?? 0 ) ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table>';

		if ( ! empty( $data['remaining_url_count'] ) ) {
			$html .= '<p style="margin:0 0 24px;font-size:13px;line-height:1.7;color:#667085;">' . sprintf( esc_html__( '%d more flagged URLs are available in the Spam Monitor dashboard.', 'seo-repair-kit' ), absint( $data['remaining_url_count'] ) ) . '</p>';
		}

		return $html;
	}

	/**
	 * Build admin dashboard URL.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function get_dashboard_url( $tab = 'google-serp-scan' ) {
		return admin_url( 'admin.php?page=seo-repair-kit-spam-monitor&tab=' . sanitize_key( $tab ) );
	}

	/**
	 * Shorten long URLs for email display.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function truncate_url_for_email( $url ) {
		$url = (string) $url;
		return strlen( $url ) > 90 ? substr( $url, 0, 87 ) . '...' : $url;
	}

	/**
	 * Format issue list.
	 *
	 * @param mixed $issues Issues.
	 * @return string
	 */
	private static function format_issues( $issues ) {
		if ( is_string( $issues ) ) {
			$decoded = json_decode( $issues, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return self::format_issues( $decoded );
			}

			return $issues ? self::expand_language_codes_in_text( sanitize_textarea_field( $issues ) ) : '-';
		}
		if ( ! is_array( $issues ) || empty( $issues ) ) {
			return '-';
		}

		return implode( "\n", self::format_issue_lines( $issues ) );
	}

	/**
	 * Build user-facing explanation lines from raw issue payloads.
	 *
	 * @param mixed $issues Raw issue payload.
	 * @return array
	 */
	private static function format_issue_lines( $issues ) {
		if ( is_string( $issues ) ) {
			$decoded = json_decode( $issues, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return self::format_issue_lines( $decoded );
			}

			$text = trim( self::expand_language_codes_in_text( sanitize_textarea_field( $issues ) ) );
			return self::is_placeholder_reason( $text ) ? array() : array( $text );
		}

		if ( ! is_array( $issues ) || empty( $issues ) ) {
			return array();
		}

		$lines = array();
		foreach ( $issues as $issue ) {
			if ( is_array( $issue ) ) {
				$line = self::describe_issue( $issue );
			} else {
				$line = self::expand_language_codes_in_text( sanitize_text_field( (string) $issue ) );
			}

			$line = trim( preg_replace( '/\s+/', ' ', (string) $line ) );
			if ( '' !== $line && ! self::is_placeholder_reason( $line ) ) {
				$lines[] = $line;
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Determine whether a reason string is only a placeholder.
	 *
	 * @param string $text Reason text.
	 * @return bool
	 */
	private static function is_placeholder_reason( $text ) {
		$text = trim( (string) $text );
		return '' === $text || '-' === $text || '—' === $text || '&ndash;' === $text || '&mdash;' === $text;
	}

	/**
	 * Convert one issue payload into plain-English text.
	 *
	 * @param array $issue Issue payload.
	 * @return string
	 */
	private static function describe_issue( array $issue ) {
		$type     = sanitize_key( $issue['type'] ?? '' );
		$message  = sanitize_text_field( $issue['message'] ?? '' );
		$evidence = $issue['evidence'] ?? array();

		if ( 'unexpected_language_signal' === $type ) {
			return self::describe_language_issue( $message, $evidence );
		}

		if ( 'spam_keyword' === $type ) {
			$keywords = self::format_preview_list( array_map( 'sanitize_text_field', (array) $evidence ), 8 );
			if ( ! empty( $keywords ) ) {
				return sprintf(
					/* translators: %s: keyword list. */
					__( 'Spam-related keyword(s) were found in the Google title, snippet, or URL: %s.', 'seo-repair-kit' ),
					implode( ', ', $keywords )
				);
			}
		}

		if ( 'suspicious_url_pattern' === $type ) {
			$patterns = self::format_preview_list( array_map( 'sanitize_text_field', (array) $evidence ), 8 );
			if ( ! empty( $patterns ) ) {
				return sprintf(
					/* translators: %s: suspicious URL patterns. */
					__( 'The indexed URL matched suspicious slug or blocked pattern(s): %s.', 'seo-repair-kit' ),
					implode( ', ', $patterns )
				);
			}
		}

		if ( '' !== $message ) {
			if ( ! empty( $evidence ) ) {
				return $message . ' ' . self::describe_generic_evidence( $evidence );
			}

			return $message;
		}

		return sanitize_text_field( $issue['type'] ?? __( 'Detected issue', 'seo-repair-kit' ) );
	}

	/**
	 * Format raw issue payloads for admin tables and CSV exports.
	 *
	 * @param mixed $issues Raw issues.
	 * @return string
	 */
	public static function format_issue_text_for_display( $issues ) {
		return self::format_issues( $issues );
	}

	/**
	 * Format one ISO language code for display outside this class.
	 *
	 * @param string $code ISO code.
	 * @return string
	 */
	public static function format_language_code_for_display( $code ) {
		return self::format_language_label( $code );
	}

	/**
	 * Describe language mismatch issues in plain English.
	 *
	 * @param string $message  Base message.
	 * @param mixed  $evidence Evidence.
	 * @return string
	 */
	private static function describe_language_issue( $message, $evidence ) {
		$evidence = is_array( $evidence ) ? $evidence : array();
		$detected = self::format_language_list_for_snapshot( (array) ( $evidence['detected_languages'] ?? array() ), 6 );
		$spam     = self::format_language_list_for_snapshot( (array) ( $evidence['spam_languages'] ?? array() ), 6 );
		$unexpected = self::format_language_list_for_snapshot( (array) ( $evidence['unexpected_languages'] ?? array() ), 6 );

		$parts = array();
		if ( '' !== $unexpected ) {
			$parts[] = sprintf(
				/* translators: %s: unexpected languages. */
				__( 'Google appears to show unexpected language(s): %s.', 'seo-repair-kit' ),
				$unexpected
			);
		}
		if ( '' !== $spam ) {
			$parts[] = sprintf(
				/* translators: %s: suspicious languages. */
				__( 'These language(s) are also in your suspicious-language watchlist: %s.', 'seo-repair-kit' ),
				$spam
			);
		}
		if ( empty( $parts ) && '' !== $detected ) {
			$parts[] = sprintf(
				/* translators: %s: detected languages. */
				__( 'Detected language signal: %s.', 'seo-repair-kit' ),
				$detected
			);
		}

		if ( empty( $parts ) && '' !== $message ) {
			return $message;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Describe generic evidence payloads.
	 *
	 * @param mixed $evidence Evidence payload.
	 * @return string
	 */
	private static function describe_generic_evidence( $evidence ) {
		if ( is_scalar( $evidence ) ) {
			return sprintf( __( 'Evidence: %s', 'seo-repair-kit' ), sanitize_text_field( (string) $evidence ) );
		}

		if ( is_array( $evidence ) ) {
			$flattened = array();
			foreach ( $evidence as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$flattened[] = sanitize_text_field( (string) $value );
				} elseif ( is_array( $value ) ) {
					$list = array_map( 'sanitize_text_field', array_filter( $value, 'is_scalar' ) );
					if ( in_array( sanitize_key( $key ), array( 'detected_languages', 'spam_languages', 'unexpected_languages', 'allowed_languages' ), true ) ) {
						$list = array_map( array( __CLASS__, 'format_language_label' ), $list );
					}
					$flattened[] = sanitize_text_field( $key ) . ': ' . implode( ', ', self::format_preview_list( $list, 6 ) );
				}
			}

			$flattened = array_filter( array_map( 'trim', $flattened ) );
			if ( ! empty( $flattened ) ) {
				return sprintf( __( 'Evidence: %s', 'seo-repair-kit' ), implode( '; ', $flattened ) );
			}
		}

		return '';
	}

	/**
	 * Format a preview list and append a concise "+N more" marker.
	 *
	 * @param array $items Raw items.
	 * @param int   $limit Item limit.
	 * @return array
	 */
	private static function format_preview_list( array $items, $limit = 8 ) {
		$items = array_values( array_filter( array_map( 'trim', array_map( 'strval', $items ) ) ) );
		$items = array_values( array_unique( $items ) );
		$limit = max( 1, absint( $limit ) );

		if ( count( $items ) <= $limit ) {
			return $items;
		}

		$preview   = array_slice( $items, 0, $limit );
		$preview[] = sprintf(
			/* translators: %d: remaining item count. */
			__( '+%d more', 'seo-repair-kit' ),
			count( $items ) - $limit
		);

		return $preview;
	}

	/**
	 * Format language codes for snapshot/output display.
	 *
	 * @param array $codes  ISO codes.
	 * @param int   $limit  Preview size.
	 * @return string
	 */
	private static function format_language_list_for_snapshot( array $codes, $limit = 8 ) {
		$formatted = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'sanitize_key', $codes ) ) ) ) as $code ) {
			$formatted[] = self::format_language_label( $code );
		}

		return implode( ', ', self::format_preview_list( $formatted, $limit ) );
	}

	/**
	 * Get user-friendly language label.
	 *
	 * @param string $code ISO code.
	 * @return string
	 */
	private static function format_language_label( $code ) {
		$code = sanitize_key( $code );
		$labels = array(
			'aa' => 'Afar',
			'ab' => 'Abkhazian',
			'ae' => 'Avestan',
			'af' => 'Afrikaans',
			'ak' => 'Akan',
			'am' => 'Amharic',
			'an' => 'Aragonese',
			'ar' => 'Arabic',
			'as' => 'Assamese',
			'av' => 'Avaric',
			'ay' => 'Aymara',
			'az' => 'Azerbaijani',
			'ba' => 'Bashkir',
			'be' => 'Belarusian',
			'bg' => 'Bulgarian',
			'bh' => 'Bihari languages',
			'bi' => 'Bislama',
			'bm' => 'Bambara',
			'bn' => 'Bengali',
			'bo' => 'Tibetan',
			'br' => 'Breton',
			'bs' => 'Bosnian',
			'ca' => 'Catalan',
			'ce' => 'Chechen',
			'ch' => 'Chamorro',
			'co' => 'Corsican',
			'cr' => 'Cree',
			'cs' => 'Czech',
			'cu' => 'Church Slavic',
			'cv' => 'Chuvash',
			'cy' => 'Welsh',
			'da' => 'Danish',
			'de' => 'German',
			'dv' => 'Divehi',
			'dz' => 'Dzongkha',
			'ee' => 'Ewe',
			'el' => 'Greek',
			'en' => 'English',
			'eo' => 'Esperanto',
			'es' => 'Spanish',
			'et' => 'Estonian',
			'eu' => 'Basque',
			'fa' => 'Persian',
			'ff' => 'Fulah',
			'fi' => 'Finnish',
			'fj' => 'Fijian',
			'fo' => 'Faroese',
			'fr' => 'French',
			'fy' => 'Western Frisian',
			'ga' => 'Irish',
			'gd' => 'Scottish Gaelic',
			'gl' => 'Galician',
			'gn' => 'Guarani',
			'gu' => 'Gujarati',
			'gv' => 'Manx',
			'ha' => 'Hausa',
			'he' => 'Hebrew',
			'hi' => 'Hindi',
			'ho' => 'Hiri Motu',
			'hr' => 'Croatian',
			'ht' => 'Haitian',
			'hu' => 'Hungarian',
			'hy' => 'Armenian',
			'hz' => 'Herero',
			'ia' => 'Interlingua',
			'id' => 'Indonesian',
			'ie' => 'Interlingue',
			'ig' => 'Igbo',
			'ii' => 'Sichuan Yi',
			'ik' => 'Inupiaq',
			'io' => 'Ido',
			'is' => 'Icelandic',
			'it' => 'Italian',
			'iu' => 'Inuktitut',
			'ja' => 'Japanese',
			'jv' => 'Javanese',
			'ka' => 'Georgian',
			'kg' => 'Kongo',
			'ki' => 'Kikuyu',
			'kj' => 'Kuanyama',
			'kk' => 'Kazakh',
			'kl' => 'Kalaallisut',
			'km' => 'Khmer',
			'kn' => 'Kannada',
			'ko' => 'Korean',
			'kr' => 'Kanuri',
			'ks' => 'Kashmiri',
			'ku' => 'Kurdish',
			'kv' => 'Komi',
			'kw' => 'Cornish',
			'ky' => 'Kirghiz',
			'la' => 'Latin',
			'lb' => 'Luxembourgish',
			'lg' => 'Ganda',
			'li' => 'Limburgan',
			'ln' => 'Lingala',
			'lo' => 'Lao',
			'lt' => 'Lithuanian',
			'lu' => 'Luba-Katanga',
			'lv' => 'Latvian',
			'mg' => 'Malagasy',
			'mh' => 'Marshallese',
			'mi' => 'Maori',
			'mk' => 'Macedonian',
			'ml' => 'Malayalam',
			'mn' => 'Mongolian',
			'mr' => 'Marathi',
			'ms' => 'Malay',
			'mt' => 'Maltese',
			'my' => 'Burmese',
			'na' => 'Nauru',
			'nb' => 'Norwegian Bokmal',
			'nd' => 'North Ndebele',
			'ne' => 'Nepali',
			'ng' => 'Ndonga',
			'nl' => 'Dutch',
			'nn' => 'Norwegian Nynorsk',
			'no' => 'Norwegian',
			'nr' => 'South Ndebele',
			'nv' => 'Navajo',
			'ny' => 'Nyanja',
			'oc' => 'Occitan',
			'oj' => 'Ojibwa',
			'om' => 'Oromo',
			'or' => 'Oriya',
			'os' => 'Ossetian',
			'pa' => 'Punjabi',
			'pi' => 'Pali',
			'pl' => 'Polish',
			'ps' => 'Pashto',
			'pt' => 'Portuguese',
			'qu' => 'Quechua',
			'rm' => 'Romansh',
			'rn' => 'Rundi',
			'ro' => 'Romanian',
			'ru' => 'Russian',
			'rw' => 'Kinyarwanda',
			'sa' => 'Sanskrit',
			'sc' => 'Sardinian',
			'sd' => 'Sindhi',
			'se' => 'Northern Sami',
			'sg' => 'Sango',
			'si' => 'Sinhala',
			'sk' => 'Slovak',
			'sl' => 'Slovenian',
			'sm' => 'Samoan',
			'sn' => 'Shona',
			'so' => 'Somali',
			'sq' => 'Albanian',
			'sr' => 'Serbian',
			'ss' => 'Swati',
			'st' => 'Southern Sotho',
			'su' => 'Sundanese',
			'sv' => 'Swedish',
			'sw' => 'Swahili',
			'ta' => 'Tamil',
			'te' => 'Telugu',
			'tg' => 'Tajik',
			'th' => 'Thai',
			'ti' => 'Tigrinya',
			'tk' => 'Turkmen',
			'tl' => 'Tagalog',
			'tn' => 'Tswana',
			'to' => 'Tonga',
			'tr' => 'Turkish',
			'ts' => 'Tsonga',
			'tt' => 'Tatar',
			'tw' => 'Twi',
			'ty' => 'Tahitian',
			'ug' => 'Uighur',
			'uk' => 'Ukrainian',
			'ur' => 'Urdu',
			'uz' => 'Uzbek',
			've' => 'Venda',
			'vi' => 'Vietnamese',
			'vo' => 'Volapuk',
			'wa' => 'Walloon',
			'wo' => 'Wolof',
			'xh' => 'Xhosa',
			'yi' => 'Yiddish',
			'yo' => 'Yoruba',
			'za' => 'Zhuang',
			'zh' => 'Chinese',
			'zu' => 'Zulu',
		);

		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}

		return strtoupper( $code );
	}

	/**
	 * Expand language-code arrays inside legacy text/JSON strings.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function expand_language_codes_in_text( $text ) {
		return preg_replace_callback(
			'/"(detected_languages|spam_languages|unexpected_languages|allowed_languages)"\s*:\s*\[([^\]]*)\]/',
			function ( $matches ) {
				preg_match_all( '/"([^"]+)"/', $matches[2], $codes );
				$labels = array();
				foreach ( $codes[1] as $code ) {
					$labels[] = self::format_language_label( $code );
				}

				return '"' . $matches[1] . '":[' . implode( ', ', array_map( 'wp_json_encode', $labels ) ) . ']';
			},
			$text
		);
	}
}
