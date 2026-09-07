<?php
/**
 * Shared, query-string based pagination for Spam Monitor admin records.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SRK_Spam_Monitor_Admin_Pagination {
	const PER_PAGE = 10;
	const PER_PAGE_OPTIONS = array( 10, 20, 30, 50, 100 );

	public static function get_page( $query_arg ) {
		return max( 1, absint( $_GET[ $query_arg ] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function get_per_page( $query_arg ) {
		$per_page_arg = self::get_per_page_arg( $query_arg );
		$per_page = absint( $_GET[ $per_page_arg ] ?? self::PER_PAGE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : self::PER_PAGE;
	}

	public static function get_offset( $page, $per_page = self::PER_PAGE ) {
		return ( max( 1, absint( $page ) ) - 1 ) * max( 1, absint( $per_page ) );
	}

	private static function get_per_page_arg( $query_arg ) {
		return preg_replace( '/_page$/', '_per_page', sanitize_key( $query_arg ) );
	}

	public static function render( $query_arg, $page, $total, $anchor = '', $tab = 'dashboard', $per_page = self::PER_PAGE, array $extra_args = array() ) {
		$per_page = in_array( absint( $per_page ), self::PER_PAGE_OPTIONS, true ) ? absint( $per_page ) : self::PER_PAGE;
		$total_pages = (int) ceil( absint( $total ) / $per_page );
		if ( absint( $total ) < 1 ) {
			return;
		}

		$page   = min( max( 1, absint( $page ) ), $total_pages );
		$start  = ( ( $page - 1 ) * $per_page ) + 1;
		$end    = min( $page * $per_page, absint( $total ) );
		$base_args = array( 'page' => 'seo-repair-kit-spam-monitor' );
		$tab = sanitize_key( $tab );
		if ( $tab && 'dashboard' !== $tab ) {
			$base_args['tab'] = $tab;
		}
		foreach ( $extra_args as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' !== $key && '' !== (string) $value ) {
				$base_args[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		$per_page_arg = self::get_per_page_arg( $query_arg );
		$base_args[ $per_page_arg ] = $per_page;
		$base   = add_query_arg( $base_args, admin_url( 'admin.php' ) );
		$suffix = $anchor ? '#' . sanitize_html_class( $anchor ) : '';
		$url    = static function ( $target ) use ( $base, $query_arg, $suffix ) {
			return add_query_arg( $query_arg, absint( $target ), $base ) . $suffix;
		};
		?>
		<div class="srk-sm-pagination srk-pagination-wrapper">
			<div class="srk-pagination-info">
				<?php printf( esc_html__( 'Showing %1$d to %2$d of %3$d records', 'seo-repair-kit' ), absint( $start ), absint( $end ), absint( $total ) ); ?>
			</div>
			<nav class="srk-pagination" aria-label="<?php esc_attr_e( 'Table pagination', 'seo-repair-kit' ); ?>">
				<?php if ( $page > 1 ) : ?>
					<a class="srk-pagination-link srk-pagination-prev" href="<?php echo esc_url( $url( $page - 1 ) ); ?>"><span class="srk-pagination-arrow">&lsaquo;</span><?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?></a>
				<?php else : ?>
					<span class="srk-pagination-link srk-pagination-disabled"><span class="srk-pagination-arrow">&lsaquo;</span><?php esc_html_e( 'Previous', 'seo-repair-kit' ); ?></span>
				<?php endif; ?>
				<div class="srk-pagination-pages">
					<?php
					$pages = array_unique( array_filter( array( 1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $total_pages ), static function ( $number ) use ( $total_pages ) { return $number >= 1 && $number <= $total_pages; } ) );
					sort( $pages );
					$previous = 0;
					foreach ( $pages as $number ) {
						if ( $previous && $number - $previous > 1 ) {
							echo '<span class="srk-pagination-dots">...</span>';
						}
						if ( $number === $page ) {
							echo '<span class="srk-pagination-page srk-pagination-current" aria-current="page">' . esc_html( $number ) . '</span>';
						} else {
							echo '<a class="srk-pagination-page" href="' . esc_url( $url( $number ) ) . '">' . esc_html( $number ) . '</a>';
						}
						$previous = $number;
					}
					?>
				</div>
				<?php if ( $page < $total_pages ) : ?>
					<a class="srk-pagination-link srk-pagination-next" href="<?php echo esc_url( $url( $page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'seo-repair-kit' ); ?><span class="srk-pagination-arrow">&rsaquo;</span></a>
				<?php else : ?>
					<span class="srk-pagination-link srk-pagination-disabled"><?php esc_html_e( 'Next', 'seo-repair-kit' ); ?><span class="srk-pagination-arrow">&rsaquo;</span></span>
				<?php endif; ?>
			</nav>
			<div class="srk-pagination-per-page">
				<label><?php esc_html_e( 'Per page:', 'seo-repair-kit' ); ?></label>
				<select class="srk-per-page-select" onchange="window.location.href=this.value" aria-label="<?php esc_attr_e( 'Records per page', 'seo-repair-kit' ); ?>">
					<?php foreach ( self::PER_PAGE_OPTIONS as $option ) : ?>
						<option value="<?php echo esc_url( add_query_arg( array( $query_arg => 1, $per_page_arg => $option ), $base ) . $suffix ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( $option ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>
		<?php
	}
}
