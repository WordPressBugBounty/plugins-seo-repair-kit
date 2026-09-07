<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link Scanner Email Report
 *
 * Builds and sends HTML email reports for scan results.
 * - Generates subjects and templates (broken / success)
 * - Shows limited broken link samples
 * - Parses recipients and sends via wp_mail
 *
 * Note: Scanning, scheduling, and storage are handled by the Automation class.
 * 
 * @since 2.1.7
 */

class SeoRepairKit_LinkScanner_Email_Report {

	/**
	 * Maximum broken link samples to show in email.
	 */
	const EMAIL_SAMPLE_LIMIT = 5;

	/**
	 * Send automation email report.
	 *
	 * @param array $result   Scan result.
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function send_report( $result, $settings ) {
		$recipients = self::parse_recipients(
			isset( $settings['email_recipients'] ) ? $settings['email_recipients'] : ''
		);

		if ( empty( $recipients ) ) {
			return array(
				'sent'              => false,
				'subject'           => '',
				'recipients'        => array(),
				'recipients_string' => '',
			);
		}

		$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$broken_links = isset( $result['brokenLinks'] ) ? (int) $result['brokenLinks'] : 0;

		$subject = $broken_links > 0
			? sprintf(
				/* translators: 1: site name, 2: broken links count */
				__( 'Action Needed: %2$d broken links found in %1$s', 'seo-repair-kit' ),
				$site_name,
				$broken_links
			)
			: sprintf(
				/* translators: %s: site name */
				__( 'All Clear: no broken links found in %s', 'seo-repair-kit' ),
				$site_name
			);

		$message = self::build_email_message( $result );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'Reply-To: SEO Repair Kit <ab@seorepairkit.com>',
		);

		$sent = wp_mail( $recipients, $subject, $message, $headers );

		return array(
			'sent'              => (bool) $sent,
			'subject'           => $subject,
			'recipients'        => $recipients,
			'recipients_string' => implode( ',', $recipients ),
			'message'           => $message,
		);
	}

	/**
	 * Build email body.
	 *
	 * @param array $result Scan result.
	 * @return string
	 */
	public static function build_email_message( $result ) {
		$broken_links = isset( $result['brokenLinks'] ) ? (int) $result['brokenLinks'] : 0;

		return $broken_links > 0
			? self::build_broken_email_message( $result )
			: self::build_success_email_message( $result );
	}

	/**
	 * Build broken links email.
	 *
	 * @param array $result Scan result.
	 * @return string
	 */
	private static function build_broken_email_message( $result ) {
		$site_name     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$total_links   = isset( $result['totalLinks'] ) ? (int) $result['totalLinks'] : 0;
		$broken_links  = isset( $result['brokenLinks'] ) ? (int) $result['brokenLinks'] : 0;
		$scope_counts  = self::get_scope_counts( $result );
		$samples       = self::build_broken_link_samples( $result );
		$dashboard_url = admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=alerts-notifications' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1.0" />
			<title><?php echo esc_html( $site_name ); ?></title>
		</head>
		<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f6;margin:0;padding:24px 12px;">
				<tr>
					<td align="center">
						<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:760px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(15,23,42,0.08);">
							<tr>
								<td style="background:#dc000b;padding:22px 24px;">
									<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
										<tr>
											<td style="width:66px;vertical-align:middle;">
												<div style="width:56px;height:56px;background:rgba(255,255,255,0.18);border-radius:12px;text-align:center;line-height:56px;font-size:30px;color:#ffffff;">⚠</div>
											</td>
											<td style="vertical-align:middle;">
												<div style="font-size:24px;line-height:1.2;font-weight:700;color:#ffffff;">
													<?php esc_html_e( 'Broken Links Detected', 'seo-repair-kit' ); ?>
												</div>
												<div style="font-size:14px;line-height:1.5;color:#ffffff;margin-top:4px;">
													<?php esc_html_e( 'SEO Repair Kit Alert', 'seo-repair-kit' ); ?>
												</div>
											</td>
										</tr>
									</table>
								</td>
							</tr>

							<tr>
								<td style="padding:34px 32px 30px;">
									<?php
									self::render_subject_block(
										sprintf(
											/* translators: 1: site name, 2: broken links count */
											__( 'Action Needed: %2$d broken links found in %1$s', 'seo-repair-kit' ),
											$site_name,
											$broken_links
										)
									);
									?>

									<div style="background:#fff6f6;border:1px solid #fecaca;border-radius:14px;padding:28px 20px;text-align:center;margin:0 0 34px;">
										<div style="width:72px;height:72px;background:#fee2e2;border-radius:50%;line-height:72px;text-align:center;font-size:34px;color:#dc2626;margin:0 auto 18px;">⚠</div>
										<div style="font-size:26px;line-height:1.25;font-weight:800;color:#0f172a;margin-bottom:12px;">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: broken links count */
													__( '%d Broken Links Found', 'seo-repair-kit' ),
													$broken_links
												)
											);
											?>
										</div>
										<div style="font-size:16px;line-height:1.6;color:#475569;margin-bottom:18px;">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: total links checked */
													__( 'We checked %d links and found some issues that need attention.', 'seo-repair-kit' ),
													$total_links
												)
											);
											?>
										</div>
										<div style="font-size:14px;line-height:1.6;color:#334155;">
											<span style="display:inline-block;width:9px;height:9px;background:#ef4444;border-radius:50%;margin-right:8px;"></span>
											<strong><?php echo esc_html( number_format_i18n( $scope_counts['internal_broken'] ) ); ?></strong>
											<?php esc_html_e( 'Internal', 'seo-repair-kit' ); ?>
											<span style="display:inline-block;color:#cbd5e1;padding:0 16px;">|</span>
											<span style="display:inline-block;width:9px;height:9px;background:#f97316;border-radius:50%;margin-right:8px;"></span>
											<strong><?php echo esc_html( number_format_i18n( $scope_counts['external_broken'] ) ); ?></strong>
											<?php esc_html_e( 'External', 'seo-repair-kit' ); ?>
										</div>
									</div>

									<div style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 16px;">
										<?php esc_html_e( 'Broken Links Found:', 'seo-repair-kit' ); ?>
									</div>

									<?php foreach ( $samples as $sample ) : ?>
										<?php self::render_link_card( $sample ); ?>
									<?php endforeach; ?>

									<div style="font-size:13px;line-height:1.6;color:#64748b;text-align:center;margin:18px 0 28px;">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: visible sample count, 2: total broken links count */
												__( 'Showing %1$d of %2$d broken links.', 'seo-repair-kit' ),
												count( $samples ),
												$broken_links
											)
										);
										?>
									</div>

									<div style="background:#eef5ff;border:1px solid #bfdbfe;border-radius:14px;padding:26px 20px;text-align:center;">
										<div style="font-size:16px;line-height:1.6;color:#1e293b;font-weight:700;margin-bottom:18px;">
											<?php esc_html_e( 'View complete details and fix these issues.', 'seo-repair-kit' ); ?>
										</div>
										<a href="<?php echo esc_url( $dashboard_url ); ?>" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;line-height:1;padding:16px 30px;border-radius:12px;">
											<?php esc_html_e( 'Open Dashboard', 'seo-repair-kit' ); ?>
										</a>
									</div>
								</td>
							</tr>

							<?php self::render_footer( __( 'You are receiving this because broken links were detected on your website.', 'seo-repair-kit' ) ); ?>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php

		return ob_get_clean();
	}

	/**
	 * Build no broken links email.
	 *
	 * @param array $result Scan result.
	 * @return string
	 */
	private static function build_success_email_message( $result ) {
		$site_name     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$total_links   = isset( $result['totalLinks'] ) ? (int) $result['totalLinks'] : 0;
		$scope_counts  = self::get_scope_counts( $result );
		$dashboard_url = admin_url( 'admin.php?page=seo-repair-kit-link-scanner&tab=alerts-notifications' );
		$next_scan     = self::get_next_scan_text();

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1.0" />
			<title><?php echo esc_html( $site_name ); ?></title>
		</head>
		<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f6;margin:0;padding:24px 12px;">
				<tr>
					<td align="center">
						<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:760px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(15,23,42,0.08);">
							<tr>
								<td style="background:#079639;padding:22px 24px;">
									<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
										<tr>
											<td style="width:66px;vertical-align:middle;">
												<div style="width:56px;height:56px;background:rgba(255,255,255,0.18);border-radius:12px;text-align:center;line-height:56px;font-size:32px;color:#ffffff;">✓</div>
											</td>
											<td style="vertical-align:middle;">
												<div style="font-size:24px;line-height:1.2;font-weight:700;color:#ffffff;">
													<?php esc_html_e( 'All Clear - No Broken Links', 'seo-repair-kit' ); ?>
												</div>
												<div style="font-size:14px;line-height:1.5;color:#dcfce7;margin-top:4px;">
													<?php esc_html_e( 'SEO Repair Kit Report', 'seo-repair-kit' ); ?>
												</div>
											</td>
										</tr>
									</table>
								</td>
							</tr>

							<tr>
								<td style="padding:34px 32px 30px;">
									<?php
									self::render_subject_block(
										sprintf(
											/* translators: %s: site name */
											__( 'All Clear: no broken links found in %s', 'seo-repair-kit' ),
											$site_name
										)
									);
									?>

									<div style="background:#ecfdf3;border:1px solid #bbf7d0;border-radius:14px;padding:32px 20px;text-align:center;margin:0 0 34px;">
										<div style="width:72px;height:72px;background:#dcfce7;border-radius:50%;line-height:72px;text-align:center;font-size:36px;color:#059669;margin:0 auto 18px;">✓</div>
										<div style="font-size:28px;line-height:1.25;font-weight:800;color:#0f172a;margin-bottom:12px;">
											<?php esc_html_e( 'All Links Working Perfectly!', 'seo-repair-kit' ); ?>
										</div>
										<div style="font-size:17px;line-height:1.6;color:#475569;margin-bottom:18px;">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: total links checked */
													__( 'We checked %d links and everything is running smoothly.', 'seo-repair-kit' ),
													$total_links
												)
											);
											?>
										</div>
										<div style="font-size:14px;line-height:1.6;color:#334155;">
											<span style="display:inline-block;width:9px;height:9px;background:#10b981;border-radius:50%;margin-right:8px;"></span>
											<strong><?php echo esc_html( number_format_i18n( $scope_counts['internal_checked'] ) ); ?></strong>
											<?php esc_html_e( 'Internal', 'seo-repair-kit' ); ?>
											<span style="display:inline-block;color:#cbd5e1;padding:0 16px;">|</span>
											<span style="display:inline-block;width:9px;height:9px;background:#10b981;border-radius:50%;margin-right:8px;"></span>
											<strong><?php echo esc_html( number_format_i18n( $scope_counts['external_checked'] ) ); ?></strong>
											<?php esc_html_e( 'External', 'seo-repair-kit' ); ?>
										</div>
									</div>

									<div style="background:#eef5ff;border:1px solid #bfdbfe;border-radius:14px;padding:24px 22px;margin:0 0 34px;">
										<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
											<tr>
												<td style="width:36px;vertical-align:top;">
													<div style="width:24px;height:24px;border:2px solid #2563eb;border-radius:50%;text-align:center;line-height:22px;color:#2563eb;font-size:15px;font-weight:700;">✓</div>
												</td>
												<td>
													<div style="font-size:18px;line-height:1.5;font-weight:800;color:#0f172a;margin-bottom:8px;">
														<?php esc_html_e( 'Your site is healthy', 'seo-repair-kit' ); ?>
													</div>
													<div style="font-size:16px;line-height:1.7;color:#334155;">
														<?php esc_html_e( 'We will continue monitoring your links and notify you if any issues are detected.', 'seo-repair-kit' ); ?>
													</div>
													<?php if ( $next_scan ) : ?>
														<div style="font-size:14px;line-height:1.7;color:#334155;margin-top:6px;">
															<?php
															echo esc_html(
																sprintf(
																	/* translators: %s: next scheduled scan date/time */
																	__( 'Next scheduled scan: %s', 'seo-repair-kit' ),
																	$next_scan
																)
															);
															?>
														</div>
													<?php endif; ?>
												</td>
											</tr>
										</table>
									</div>

									<div style="background:#ecfdf3;border:1px solid #bbf7d0;border-radius:14px;padding:26px 20px;text-align:center;">
										<div style="font-size:16px;line-height:1.6;color:#1e293b;font-weight:700;margin-bottom:18px;">
											<?php esc_html_e( 'View detailed scan history in your dashboard.', 'seo-repair-kit' ); ?>
										</div>
										<a href="<?php echo esc_url( $dashboard_url ); ?>" style="display:inline-block;background:#08a645;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;line-height:1;padding:16px 30px;border-radius:12px;">
											<?php esc_html_e( 'Open Dashboard', 'seo-repair-kit' ); ?> ↗
										</a>
									</div>
								</td>
							</tr>

							<?php self::render_footer( __( 'You are receiving this because your site passed the scheduled link scan.', 'seo-repair-kit' ) ); ?>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render subject block.
	 *
	 * @param string $subject Subject.
	 * @return void
	 */
	private static function render_subject_block( $subject ) {
		?>
		<div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;margin:0 0 12px;">
			<?php esc_html_e( 'Subject', 'seo-repair-kit' ); ?>
		</div>
		<div style="font-size:16px;line-height:1.5;font-weight:800;color:#0f172a;margin:0 0 24px;">
			<?php echo esc_html( $subject ); ?>
		</div>
		<div style="height:1px;background:#e5e7eb;margin:0 0 28px;"></div>
		<?php
	}

	/**
	 * Render one broken link card.
	 *
	 * @param array $sample Sample row.
	 * @return void
	 */
	private static function render_link_card( $sample ) {
		$post_title = ! empty( $sample['post_title'] ) ? $sample['post_title'] : __( '(Untitled)', 'seo-repair-kit' );
		$link       = ! empty( $sample['link'] ) ? $sample['link'] : '';
		$scope      = ! empty( $sample['scope'] ) && 'external' === $sample['scope'] ? 'external' : 'internal';
		$scope_text = 'external' === $scope ? __( 'External', 'seo-repair-kit' ) : __( 'Internal', 'seo-repair-kit' );
		$scope_css  = 'external' === $scope ? 'background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;' : 'background:#fff1f2;color:#dc2626;border:1px solid #fecaca;';
		?>
		<div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:0 0 12px;">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
				<tr>
					<td style="vertical-align:top;">
						<div style="font-size:15px;font-weight:800;line-height:1.5;color:#0f172a;margin-bottom:8px;">
							<?php echo esc_html( $post_title ); ?>
						</div>
						<?php if ( $link ) : ?>
							<div style="font-size:14px;line-height:1.6;word-break:break-word;">
								<span style="color:#94a3b8;margin-right:6px;">↗</span>
								<a href="<?php echo esc_url( $link ); ?>" style="color:#2563eb;text-decoration:none;">
									<?php echo esc_html( $link ); ?>
								</a>
							</div>
						<?php endif; ?>
					</td>
					<td align="right" style="vertical-align:top;width:90px;">
						<span style="display:inline-block;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800;<?php echo esc_attr( $scope_css ); ?>">
							<?php echo esc_html( $scope_text ); ?>
						</span>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get internal/external checked and broken counts.
	 *
	 * @param array $result Scan result.
	 * @return array
	 */
	private static function get_scope_counts( $result ) {
		$scope_breakdown = ! empty( $result['scope_breakdown'] ) && is_array( $result['scope_breakdown'] ) ? $result['scope_breakdown'] : array();

		return array(
			'internal_checked' => isset( $scope_breakdown['internal']['checked'] ) ? (int) $scope_breakdown['internal']['checked'] : 0,
			'external_checked' => isset( $scope_breakdown['external']['checked'] ) ? (int) $scope_breakdown['external']['checked'] : 0,
			'internal_broken'  => isset( $scope_breakdown['internal']['broken'] ) ? (int) $scope_breakdown['internal']['broken'] : 0,
			'external_broken'  => isset( $scope_breakdown['external']['broken'] ) ? (int) $scope_breakdown['external']['broken'] : 0,
		);
	}

	/**
	 * Get next scan text.
	 *
	 * @return string
	 */
	private static function get_next_scan_text() {
		if ( ! class_exists( 'SeoRepairKit_LinkScanner_Automation' ) ) {
			return '';
		}

		$next_ts = wp_next_scheduled( SeoRepairKit_LinkScanner_Automation::EVENT_HOOK );

		if ( ! $next_ts ) {
			return '';
		}

		return wp_date( 'M j, Y g:i A', $next_ts, wp_timezone() );
	}

	/**
	 * Render email footer.
	 *
	 * @param string $reason Footer reason.
	 * @return void
	 */
	private static function render_footer( $reason ) {
		?>
		<tr>
			<td style="background:#f8fafc;border-top:1px solid #e5e7eb;padding:26px 24px;text-align:center;">
				<div style="font-size:13px;line-height:1.6;color:#64748b;margin-bottom:6px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin version */
							__( 'This is an automated message from SEO Repair Kit v%s', 'seo-repair-kit' ),
							SEO_REPAIR_KIT_VERSION
						)
					);
					?>
				</div>
				<div style="font-size:13px;line-height:1.6;color:#64748b;">
					<?php echo esc_html( $reason ); ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Parse recipients.
	 *
	 * @param string $recipients Raw recipients.
	 * @return array
	 */
	private static function parse_recipients( $recipients ) {
		$emails = array_map(
			'sanitize_email',
			array_map( 'trim', explode( ',', (string) $recipients ) )
		);

		return array_values( array_filter( $emails, 'is_email' ) );
	}

	/**
	 * Build broken link samples.
	 *
	 * @param array $result Scan result.
	 * @return array
	 */
	private static function build_broken_link_samples( $result ) {
		$output = array();

		if ( empty( $result['records'] ) || ! is_array( $result['records'] ) ) {
			return $output;
		}

		foreach ( $result['records'] as $row ) {
			if ( empty( $row['is_broken'] ) ) {
				continue;
			}

			if ( count( $output ) >= self::EMAIL_SAMPLE_LIMIT ) {
				break;
			}

			$output[] = array(
				'post_title' => isset( $row['post_title'] ) ? $row['post_title'] : '',
				'link'       => isset( $row['link'] ) ? $row['link'] : '',
				'scope'      => isset( $row['scope'] ) ? $row['scope'] : 'internal',
			);
		}

		return $output;
	}
}
