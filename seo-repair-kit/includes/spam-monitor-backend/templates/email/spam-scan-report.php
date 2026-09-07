<?php
/**
 * Spam Monitor scan report email.
 *
 * @var array $template_data Sanitized report data.
 *
 * @package Seo_Repair_Kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$risk_colors = array(
	'clean'      => array( '#ecfdf3', '#027a48' ),
	'critical'   => array( '#f4ebff', '#6941c6' ),
	'spam'       => array( '#fee4e2', '#b42318' ),
	'suspicious' => array( '#fef0c7', '#b54708' ),
	'unknown'    => array( '#f2f4f7', '#475467' ),
);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="color-scheme" content="light only">
	<title><?php echo esc_html( sprintf( __( 'Spam Monitor report for %s', 'seo-repair-kit' ), $template_data['domain'] ) ); ?></title>
	<style>
		@media only screen and (max-width:620px) {
			.srk-shell { width:100% !important; }
			.srk-pad { padding-left:18px !important; padding-right:18px !important; }
			.srk-metric { display:inline-block !important; width:48% !important; box-sizing:border-box !important; margin-bottom:8px !important; }
			.srk-finding-pad { padding:17px !important; }
			.srk-button { display:block !important; text-align:center !important; }
		}
	</style>
</head>
<body style="margin:0;padding:0;background-color:#f3f5f7;color:#18202a;font-family:Arial,'Helvetica Neue',sans-serif;-webkit-text-size-adjust:100%;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: result count, 2: flagged URL count. */
				__( 'Your SEO Repair Kit scan checked %1$d Google results and found %2$d URLs that need review.', 'seo-repair-kit' ),
				absint( $template_data['total_result_count'] ),
				absint( $template_data['flagged_count'] )
			)
		);
		?>
	</div>
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#f3f5f7;">
		<tr>
			<td align="center" style="padding:28px 12px;">
				<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" class="srk-shell" style="width:640px;max-width:640px;background-color:#ffffff;border:1px solid #dde2e7;border-radius:8px;overflow:hidden;">
					<tr>
						<td class="srk-pad" style="padding:24px 32px;background-color:#12181f;border-bottom:3px solid #ff7420;">
							<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
								<td width="100%" valign="middle" style="color:#ffffff;font-size:17px;font-weight:700;">SEO Repair Kit<br><span style="color:#aeb7c1;font-size:12px;font-weight:400;"><?php esc_html_e( 'Spam Monitor', 'seo-repair-kit' ); ?></span></td>
							</tr></table>
						</td>
					</tr>
					<tr>
						<td class="srk-pad" style="padding:32px 32px 22px;">
							<p style="margin:0 0 8px;color:#e55d0a;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;"><?php esc_html_e( 'Scan complete', 'seo-repair-kit' ); ?></p>
							<h1 style="margin:0 0 10px;color:#18202a;font-size:27px;line-height:1.25;"><?php esc_html_e( 'Your spam scan report is ready', 'seo-repair-kit' ); ?></h1>
							<p style="margin:0;color:#5f6975;font-size:15px;line-height:1.6;">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: scanned domain. */
										__( 'We checked Google results for <strong style="color:#18202a;">%s</strong>. Review the findings below and investigate URLs you do not recognize.', 'seo-repair-kit' ),
										esc_html( $template_data['domain'] )
									)
								);
								?>
							</p>
							<p style="margin:12px 0 0;color:#89929c;font-size:12px;"><?php echo esc_html( sprintf( __( 'Generated %s', 'seo-repair-kit' ), $template_data['generated_at'] ) ); ?></p>
						</td>
					</tr>
					<tr>
						<td class="srk-pad" style="padding:0 24px 24px;">
							<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;border-spacing:8px 0;"><tr>
								<?php
								$metrics = array(
									__( 'Results checked', 'seo-repair-kit' ) => $template_data['received_results'],
									__( 'Clean', 'seo-repair-kit' )           => $template_data['clean_count'],
									__( 'Suspicious', 'seo-repair-kit' )      => $template_data['suspicious_count'],
									__( 'Spam / critical', 'seo-repair-kit' ) => $template_data['spam_count'] + $template_data['critical_count'],
								);
								foreach ( $metrics as $label => $value ) :
									?>
									<td class="srk-metric" width="25%" valign="top" style="padding:14px 10px;background-color:#f7f8fa;border:1px solid #e4e7eb;border-radius:6px;text-align:center;">
										<strong style="display:block;color:#18202a;font-size:22px;line-height:1.1;"><?php echo esc_html( (string) $value ); ?></strong>
										<span style="display:block;margin-top:5px;color:#6f7883;font-size:11px;line-height:1.3;"><?php echo esc_html( $label ); ?></span>
									</td>
								<?php endforeach; ?>
							</tr></table>
						</td>
					</tr>
					<?php if ( ! empty( $template_data['settings_snapshot'] ) ) : ?>
						<tr>
							<td class="srk-pad" style="padding:0 32px 22px;">
								<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #e4e7eb;border-radius:6px;background-color:#fbfcfd;">
									<tr><td style="padding:18px 20px 10px;"><h2 style="margin:0;color:#18202a;font-size:17px;line-height:1.4;"><?php esc_html_e( 'Settings snapshot', 'seo-repair-kit' ); ?></h2><p style="margin:6px 0 0;color:#6f7883;font-size:12px;line-height:1.5;"><?php esc_html_e( 'The Spam Rules and scan settings used for this report.', 'seo-repair-kit' ); ?></p></td></tr>
									<?php foreach ( $template_data['settings_snapshot'] as $label => $value ) : ?>
										<tr><td style="padding:10px 20px;border-top:1px solid #edf0f3;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
											<td valign="top" style="width:180px;color:#6f7883;font-size:12px;line-height:1.45;"><?php echo esc_html( $label ); ?></td>
											<td valign="top" style="color:#18202a;font-size:13px;line-height:1.55;font-weight:600;"><?php echo esc_html( $value ); ?></td>
										</tr></table></td></tr>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<td class="srk-pad" style="padding:4px 32px 14px;">
							<h2 style="margin:0;color:#18202a;font-size:19px;line-height:1.4;"><?php esc_html_e( 'Scan results', 'seo-repair-kit' ); ?></h2>
							<p style="margin:5px 0 0;color:#6f7883;font-size:13px;line-height:1.5;">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: total result count, 2: flagged result count. */
										__( '%1$d Google results scanned. %2$d need review.', 'seo-repair-kit' ),
										absint( $template_data['total_result_count'] ),
										absint( $template_data['flagged_count'] )
									)
								);
								?>
							</p>
						</td>
					</tr>
					<?php if ( empty( $template_data['results'] ) ) : ?>
						<tr><td class="srk-pad" style="padding:8px 32px 28px;"><div style="padding:20px;background-color:#f2fbf6;border:1px solid #bce5cc;border-radius:6px;color:#246b40;font-size:14px;line-height:1.55;"><strong><?php esc_html_e( 'No Google results were returned.', 'seo-repair-kit' ); ?></strong></div></td></tr>
					<?php else : ?>
						<?php foreach ( $template_data['results'] as $result ) : ?>
							<?php $colors = $risk_colors[ $result['risk'] ] ?? $risk_colors['unknown']; ?>
							<tr>
								<td class="srk-pad" style="padding:8px 32px;">
									<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #e0e4e8;border-radius:6px;"><tr><td class="srk-finding-pad" style="padding:20px;">
										<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
											<td><span style="display:inline-block;padding:5px 9px;background-color:<?php echo esc_attr( $colors[0] ); ?>;border-radius:4px;color:<?php echo esc_attr( $colors[1] ); ?>;font-size:11px;font-weight:700;text-transform:uppercase;"><?php echo esc_html( $result['risk'] ); ?></span></td>
											<td align="right" style="color:#6f7883;font-size:12px;"><?php if ( $result['position'] ) : ?><?php echo esc_html( sprintf( __( 'Position %d', 'seo-repair-kit' ), $result['position'] ) ); ?> &nbsp; | &nbsp; <?php endif; ?><strong style="color:#18202a;"><?php echo esc_html( sprintf( __( 'Risk score %d/100', 'seo-repair-kit' ), $result['score'] ) ); ?></strong></td>
										</tr></table>
										<h3 style="margin:14px 0 6px;color:#18202a;font-size:16px;line-height:1.45;"><?php echo esc_html( $result['title'] ); ?></h3>
										<a href="<?php echo esc_url( $result['url'] ); ?>" style="color:#e55d0a;font-size:12px;line-height:1.45;text-decoration:none;word-break:break-all;"><?php echo esc_html( $result['url'] ); ?></a>
										<?php if ( '' !== $result['snippet'] ) : ?><p style="margin:12px 0 0;color:#5f6975;font-size:13px;line-height:1.55;"><?php echo esc_html( $result['snippet'] ); ?></p><?php endif; ?>
										<?php if ( ! empty( $result['reason_lines'] ) ) : ?>
											<div style="margin-top:14px;padding:12px 14px;background-color:#f7f8fa;border-left:3px solid #ff7420;color:#4f5965;font-size:12px;line-height:1.6;">
												<strong style="display:block;margin-bottom:6px;color:#18202a;"><?php esc_html_e( 'Why it was flagged', 'seo-repair-kit' ); ?></strong>
												<ul style="margin:0;padding-left:18px;">
													<?php foreach ( (array) $result['reason_lines'] as $reason_line ) : ?>
														<li style="margin:0 0 4px;"><?php echo esc_html( $reason_line ); ?></li>
													<?php endforeach; ?>
												</ul>
											</div>
										<?php elseif ( '' !== $result['reason'] && '-' !== $result['reason'] ) : ?>
											<div style="margin-top:14px;padding:12px 14px;background-color:#f7f8fa;border-left:3px solid #ff7420;color:#4f5965;font-size:12px;line-height:1.6;">
												<strong style="display:block;margin-bottom:6px;color:#18202a;"><?php esc_html_e( 'Why it was flagged', 'seo-repair-kit' ); ?></strong>
												<div><?php echo esc_html( $result['reason'] ); ?></div>
											</div>
										<?php endif; ?>
									</td></tr></table>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( ! empty( $template_data['remaining_result_count'] ) ) : ?>
						<tr><td class="srk-pad" style="padding:12px 32px 8px;color:#6f7883;font-size:13px;line-height:1.5;"><?php echo esc_html( sprintf( __( '%d additional results are available in the WordPress dashboard.', 'seo-repair-kit' ), absint( $template_data['remaining_result_count'] ) ) ); ?></td></tr>
					<?php endif; ?>
					<tr>
						<td class="srk-pad" style="padding:28px 32px 32px;">
							<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#12181f;border-radius:6px;"><tr><td style="padding:24px;">
								<h2 style="margin:0 0 7px;color:#ffffff;font-size:18px;line-height:1.4;"><?php esc_html_e( 'Keep your website protected', 'seo-repair-kit' ); ?></h2>
								<p style="margin:0 0 18px;color:#b8c0c9;font-size:13px;line-height:1.55;"><?php esc_html_e( 'Review scan history, alerts, Spam Rules, and the guided cleanup workflow in SEO Repair Kit.', 'seo-repair-kit' ); ?></p>
								<a class="srk-button" href="<?php echo esc_url( $template_data['dashboard_url'] ); ?>" style="display:inline-block;padding:12px 18px;background-color:#ff7420;border-radius:5px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;"><?php echo esc_html( $template_data['cta_label'] ); ?></a>
							</td></tr></table>
						</td>
					</tr>
					<tr><td class="srk-pad" style="padding:18px 32px;background-color:#fbfcfd;border-top:1px solid #e4e7eb;text-align:center;color:#89929c;font-size:11px;line-height:1.5;"><?php esc_html_e( 'SEO Repair Kit', 'seo-repair-kit' ); ?> &middot; <a href="<?php echo esc_url( 'https://seorepairkit.com/' ); ?>" style="color:#667085;text-decoration:none;">https://seorepairkit.com/</a></td></tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
