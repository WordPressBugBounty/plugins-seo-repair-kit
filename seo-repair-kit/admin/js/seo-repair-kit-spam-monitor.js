/* global srkSpamMonitor */
( function ( $ ) {
	'use strict';

	if ( typeof srkSpamMonitor === 'undefined' ) {
		return;
	}

	var cfg = srkSpamMonitor;
	var allowedTabs = [ 'dashboard', 'spam-rules', 'google-serp-scan', 'gsc-cleanup', 'alerts', 'settings' ];
	var paginationArgs = [
		'srk_risky_page', 'srk_risky_per_page', 'srk_risky_search', 'srk_risky_risk',
		'srk_recent_alert_page', 'srk_recent_alert_per_page',
		'srk_serp_scans_page', 'srk_serp_scans_per_page', 'srk_serp_filter_domain', 'srk_serp_filter_risk', 'srk_serp_filter_id',
		'srk_cleanup_page', 'srk_cleanup_per_page',
		'srk_alert_history_page', 'srk_alert_history_per_page'
	];
	var tabPaginationArgs = {
		dashboard: [ 'srk_risky_page', 'srk_risky_per_page', 'srk_risky_search', 'srk_risky_risk', 'srk_recent_alert_page', 'srk_recent_alert_per_page' ],
		'google-serp-scan': [ 'srk_serp_scans_page', 'srk_serp_scans_per_page', 'srk_serp_filter_domain', 'srk_serp_filter_risk', 'srk_serp_filter_id' ],
		'gsc-cleanup': [ 'srk_cleanup_page', 'srk_cleanup_per_page' ],
		alerts: [ 'srk_alert_history_page', 'srk_alert_history_per_page' ]
	};
	var tabPaginationAnchors = {
		dashboard: [ 'srk-latest-risky-urls', 'srk-recent-alerts' ],
		'google-serp-scan': [ 'srk-recent-serp-scans' ],
		'gsc-cleanup': [ 'srk-spam-url-review' ],
		alerts: [ 'srk-alert-history' ]
	};

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function showNotice( $area, message, type ) {
		var cls = 'srk-sm-notice srk-sm-notice--' + ( type || 'success' );
		$area.html( '<div class="' + cls + '">' + escapeHtml( message ) + '</div>' ).show();
		clearTimeout( $area.data( 'timer' ) );
		$area.data( 'timer', setTimeout( function () {
			$area.fadeOut( 400, function () {
				$( this ).empty().show();
			} );
		}, 6000 ) );
	}

	function setActiveTab( tab, updateUrl ) {
		if ( allowedTabs.indexOf( tab ) === -1 ) {
			tab = 'dashboard';
		}

		var $button = $( '.srk-tab-button[data-tab="' + tab + '"]' );
		var $panel = $( '#srk-tab-' + tab );
		if ( ! $button.length || ! $panel.length ) {
			return;
		}

		if ( '0' === String( $panel.attr( 'data-loaded' ) ) ) {
			if ( $button.data( 'tab-url' ) ) {
				window.location.href = $button.data( 'tab-url' );
			}
			return;
		}

		$( '.srk-tab-button' ).removeClass( 'active' );
		$button.addClass( 'active' );
		$( '.srk-tab-content' ).removeClass( 'active' ).hide();
		$panel.addClass( 'active' ).show();

		if ( updateUrl && window.history && window.history.replaceState ) {
			var url = new URL( window.location.href );
			var hadPaginationState = paginationArgs.some( function ( parameter ) {
				return url.searchParams.has( parameter );
			} ) || Object.keys( tabPaginationAnchors ).some( function ( tabKey ) {
				return tabPaginationAnchors[ tabKey ].indexOf( url.hash.substring( 1 ) ) !== -1;
			} );
			url.searchParams.set( 'page', 'seo-repair-kit-spam-monitor' );
			paginationArgs.forEach( function ( parameter ) {
				url.searchParams.delete( parameter );
			} );
			url.hash = '';
			if ( 'dashboard' === tab ) {
				url.searchParams.delete( 'tab' );
			} else {
				url.searchParams.set( 'tab', tab );
			}
			if ( hadPaginationState ) {
				window.location.assign( url.toString() );
				return;
			}
			window.history.replaceState( {}, '', url.toString() );
		}
	}

	function normalizeActiveTabUrl( tab ) {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var url = new URL( window.location.href );
		var allowedParameters = tabPaginationArgs[ tab ] || [];
		var allowedAnchors = tabPaginationAnchors[ tab ] || [];
		paginationArgs.forEach( function ( parameter ) {
			if ( allowedParameters.indexOf( parameter ) === -1 ) {
				url.searchParams.delete( parameter );
			}
		} );
		if ( url.hash && allowedAnchors.indexOf( url.hash.substring( 1 ) ) === -1 ) {
			url.hash = '';
		}
		window.history.replaceState( {}, '', url.toString() );
	}

	$( document ).on( 'click', '.srk-tab-button', function ( e ) {
		e.preventDefault();
		setActiveTab( $( this ).data( 'tab' ), true );
	} );

	( function () {
		var initialTab = cfg.currentTab || 'dashboard';
		var queryTab = new URLSearchParams( window.location.search ).get( 'tab' );
		var hashTab = window.location.hash ? window.location.hash.replace( '#', '' ) : '';

		if ( allowedTabs.indexOf( hashTab ) !== -1 ) {
			initialTab = hashTab;
		}
		if ( allowedTabs.indexOf( queryTab ) !== -1 ) {
			initialTab = queryTab;
		}

		setActiveTab( initialTab, false );
		normalizeActiveTabUrl( initialTab );
	}() );

	$( document ).on( 'click', '#srk-rules-save-btn', function () {
		var $button = $( this ).prop( 'disabled', true );
		var $form = $( '#srk-rules-form' );
		var $notice = $( '#srk-rules-notices' );
		var formData = $form.serializeArray();

		formData.push( { name: 'action', value: 'srk_sm_save_rules' } );

		$.post( cfg.ajaxUrl, formData )
			.done( function ( response ) {
				var ok = !! response.success;
				var message = ok ? ( response.data.message || cfg.strings.completed ) : ( response.data.message || cfg.strings.failed );
				showNotice( $notice, message, ok ? 'success' : 'error' );
			} )
			.fail( function () {
				showNotice( $notice, cfg.strings.failed, 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-rules-reset-btn', function () {
		var $button = $( this );
		var $form = $( '#srk-rules-form' );
		var $notice = $( '#srk-rules-notices' );
		var formData = $form.serializeArray();

		if ( ! window.confirm( 'Reset Spam Rules to default settings? This will overwrite your saved Spam Rules.' ) ) {
			return;
		}

		$button.prop( 'disabled', true );
		formData.push( { name: 'action', value: 'srk_sm_reset_rules' } );

		$.post( cfg.ajaxUrl, formData )
			.done( function ( response ) {
				var ok = !! response.success;
				var message = ok ? ( response.data.message || cfg.strings.completed ) : ( response.data.message || cfg.strings.failed );
				showNotice( $notice, message, ok ? 'success' : 'error' );
				if ( ok ) {
					window.setTimeout( function () {
						window.location.reload();
					}, 700 );
				}
			} )
			.fail( function () {
				showNotice( $notice, cfg.strings.failed, 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	function parseLanguageCodes( value ) {
		return ( value || '' )
			.split( /[\r\n,]+/ )
			.map( function ( item ) {
				return item.trim().toLowerCase();
			} )
			.filter( Boolean );
	}

	function updateLanguageSelectAll( $picker ) {
		var total = $picker.find( '.srk-sm-language-checkbox' ).length;
		var checked = $picker.find( '.srk-sm-language-checkbox:checked' ).length;
		$picker.find( '.srk-sm-language-count' ).text( checked + ' of ' + total + ' selected' );
	}

	function renderLanguageTags( $input ) {
		var selector = '#' + $input.attr( 'id' );
		var values = parseLanguageCodes( $input.val() );
		var $tags = $( '.srk-rules-lang-tags[data-language-tags-for="' + selector + '"]' );

		if ( ! $tags.length ) {
			return;
		}

		$tags.empty();
		if ( ! values.length ) {
			$( '<span>' ).addClass( 'srk-rules-lang-tags-empty' ).text( 'No languages selected' ).appendTo( $tags );
			return;
		}

		values.slice( 0, 12 ).forEach( function ( code ) {
			$( '<button>' )
				.attr( { type: 'button', 'data-language-code': code, 'aria-label': 'Remove ' + code } )
				.addClass( 'srk-rules-lang-tag srk-rules-lang-tag-remove' + ( selector === '#srk-lang-allowed' ? ' srk-rules-lang-tag--green' : '' ) )
				.text( code + ' ×' )
				.appendTo( $tags );
		} );
		if ( values.length > 12 ) {
			$( '<span>' ).addClass( 'srk-rules-lang-tag-more' ).text( '+' + ( values.length - 12 ) + ' more selected' ).appendTo( $tags );
		}
	}

	function filterLanguagePicker( $picker ) {
		var query = ( $picker.find( '.srk-sm-language-search' ).val() || '' ).toLowerCase().trim();
		var visible = 0;

		$picker.find( '.srk-sm-language-checkbox-item' ).each( function () {
			var $item = $( this );
			var searchText = ( $item.data( 'language-search' ) || $item.text() || '' ).toString().toLowerCase();
			var matched = ! query || searchText.indexOf( query ) !== -1;

			$item.toggle( matched );

			if ( matched ) {
				visible += 1;
			}
		} );

		$picker.find( '.srk-sm-language-empty' ).prop( 'hidden', visible > 0 );
	}

	function syncLanguageInputFromPicker( $picker ) {
		var target = $picker.data( 'target' );
		var $input = $( target );
		var values = [];

		if ( ! $input.length ) {
			return;
		}

		$picker.find( '.srk-sm-language-checkbox:checked' ).each( function () {
			values.push( ( $( this ).val() || '' ).toLowerCase() );
		} );

		$input.val( values.join( ', ' ) ).trigger( 'change' );
		updateLanguageSelectAll( $picker );
		renderLanguageTags( $input );
	}

	function syncLanguagePickerFromInput( $input ) {
		var selector = '#' + $input.attr( 'id' );
		var values = parseLanguageCodes( $input.val() );
		var selected = {};
		var $picker = $( '.srk-sm-language-checkbox-picker[data-target="' + selector + '"]' );

		values.forEach( function ( value ) {
			selected[ value ] = true;
		} );

		$picker.find( '.srk-sm-language-checkbox' ).each( function () {
			$( this ).prop( 'checked', !! selected[ ( $( this ).val() || '' ).toLowerCase() ] );
		} );

		updateLanguageSelectAll( $picker );
		renderLanguageTags( $input );
	}

	function closeLanguagePicker( $picker ) {
		if ( ! $picker || ! $picker.length ) {
			return;
		}
		$picker.prop( 'hidden', true );
		$( '.srk-rules-browse-btn[aria-controls="' + $picker.attr( 'id' ) + '"]' ).attr( 'aria-expanded', 'false' );
	}

	$( document ).on( 'click', '.srk-rules-browse-btn', function () {
		var $button = $( this );
		var $picker = $( '#' + $button.data( 'language-picker' ) );
		var opening = $picker.prop( 'hidden' );

		$( '.srk-sm-language-checkbox-picker' ).each( function () {
			closeLanguagePicker( $( this ) );
		} );
		if ( opening ) {
			$picker.prop( 'hidden', false );
			$button.attr( 'aria-expanded', 'true' );
			$picker.find( '.srk-sm-language-search' ).trigger( 'focus' );
		}
	} );

	$( document ).on( 'click', '.srk-sm-language-done-btn', function () {
		closeLanguagePicker( $( this ).closest( '.srk-sm-language-checkbox-picker' ) );
	} );

	$( document ).on( 'click', '.srk-sm-language-select-all-btn', function () {
		var $picker = $( this ).closest( '.srk-sm-language-checkbox-picker' );
		$picker.find( '.srk-sm-language-checkbox' ).prop( 'checked', true );
		syncLanguageInputFromPicker( $picker );
	} );

	$( document ).on( 'click', '.srk-sm-language-clear-btn', function () {
		var $picker = $( this ).closest( '.srk-sm-language-checkbox-picker' );
		$picker.find( '.srk-sm-language-checkbox' ).prop( 'checked', false );
		syncLanguageInputFromPicker( $picker );
	} );

	$( document ).on( 'click', '.srk-rules-lang-tag-remove', function () {
		var $tag = $( this );
		var $tags = $tag.closest( '[data-language-tags-for]' );
		var $picker = $( '.srk-sm-language-checkbox-picker[data-target="' + $tags.attr( 'data-language-tags-for' ) + '"]' );
		$picker.find( '.srk-sm-language-checkbox[value="' + $tag.data( 'language-code' ) + '"]' ).prop( 'checked', false );
		syncLanguageInputFromPicker( $picker );
	} );

	$( document ).on( 'click', function ( event ) {
		if ( ! $( event.target ).closest( '.srk-sm-language-checkbox-picker, .srk-rules-browse-btn' ).length ) {
			$( '.srk-sm-language-checkbox-picker' ).each( function () { closeLanguagePicker( $( this ) ); } );
		}
	} );

	$( document ).on( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			$( '.srk-sm-language-checkbox-picker' ).each( function () { closeLanguagePicker( $( this ) ); } );
		}
	} );

	$( document ).on( 'change', '.srk-sm-language-checkbox', function () {
		syncLanguageInputFromPicker( $( this ).closest( '.srk-sm-language-checkbox-picker' ) );
	} );

	$( document ).on( 'input', '.srk-sm-language-search', function () {
		filterLanguagePicker( $( this ).closest( '.srk-sm-language-checkbox-picker' ) );
	} );

	$( document ).on( 'input change', '#srk-lang-expected, #srk-lang-allowed', function () {
		syncLanguagePickerFromInput( $( this ) );
	} );

	$( '.srk-sm-language-checkbox-picker' ).each( function () {
		var $picker = $( this );
		updateLanguageSelectAll( $picker );
		filterLanguagePicker( $picker );
	} );

	function updateKeywordCardState( $checkbox ) {
		$checkbox.closest( '.srk-rules-category-card' ).toggleClass( 'is-enabled', $checkbox.is( ':checked' ) );
	}

	$( document ).on( 'change', '.srk-rules-category-head input[type="checkbox"]', function () {
		updateKeywordCardState( $( this ) );
	} );

	$( '.srk-rules-category-head input[type="checkbox"]' ).each( function () {
		updateKeywordCardState( $( this ) );
	} );

	$( document ).on( 'click', '.srk-sm-clear-records', function () {
		var $button = $( this );
		var dataset = $button.data( 'dataset' );
		var message = dataset === 'alerts'
			? 'Are you sure you want to clear all Spam Monitor alert history? This action cannot be undone.'
			: 'Are you sure you want to clear all SERP scans, SERP results, cleanup records, and related scan alerts? This action cannot be undone.';

		if ( ! window.confirm( message ) ) {
			return;
		}

		$( '.srk-sm-clear-records[data-dataset="' + dataset + '"]' ).prop( 'disabled', true );
		$.post( cfg.ajaxUrl, {
			action: 'srk_sm_clear_records',
			nonce: cfg.recordsNonce,
			dataset: dataset
		} ).done( function ( response ) {
			if ( ! response.success ) {
				window.alert( ( response.data && response.data.message ) || 'Records could not be cleared.' );
				return;
			}
			window.alert( ( response.data && response.data.message ) || 'Records cleared successfully.' );
			window.location.reload();
		} ).fail( function ( xhr ) {
			var messageText = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
				? xhr.responseJSON.data.message
				: 'Records could not be cleared.';
			window.alert( messageText );
		} ).always( function () {
			$( '.srk-sm-clear-records[data-dataset="' + dataset + '"]' ).prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '#srk-gsc-copy-urls', function () {
		var target = $( this ).data( 'target' );
		var $field = $( target );
		var text = $field.val() || '';

		if ( ! text ) {
			return;
		}

		if ( window.navigator && window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text );
		} else {
			$field.trigger( 'focus' ).trigger( 'select' );
			document.execCommand( 'copy' );
		}

		$( this ).text( 'Copied' );
	} );

	$( document ).on( 'click', '#srk-gsc-refresh-sitemap-health', function () {
		var url = new URL( window.location.href.split( '#' )[0] );
		url.searchParams.set( 'page', 'seo-repair-kit-spam-monitor' );
		url.searchParams.set( 'tab', 'gsc-cleanup' );
		url.searchParams.set( 'srk_sm_refresh_sitemap', '1' );
		window.location.href = url.toString();
	} );

	$( document ).on( 'change', '.srk-gsc-cleanup-status', function () {
		var $row = $( this ).closest( '[data-cleanup-result-id]' );
		var $notice = $( '#srk-gsc-cleanup-notices' );

		$.post( cfg.ajaxUrl, {
			action: 'srk_sm_cleanup_update_status',
			nonce: cfg.serpNonce || '',
			result_id: $row.data( 'cleanup-result-id' ),
			status: $( this ).val()
		} )
			.done( function ( response ) {
				showNotice( $notice, response.success ? ( response.data.message || cfg.strings.completed ) : ( response.data.message || cfg.strings.failed ), response.success ? 'success' : 'error' );
			} )
			.fail( function () {
				showNotice( $notice, cfg.strings.failed, 'error' );
			} );
	} );

	function formatUrlState( analysis ) {
		var status = parseInt( analysis.http_status || 0, 10 );

		if ( ! status ) {
			return '-';
		}
		if ( status >= 500 ) {
			return 'Server Error';
		}
		if ( status === 404 || status === 410 ) {
			return 'Removed';
		}
		if ( status >= 300 && status < 400 ) {
			return 'Redirects';
		}
		if ( status >= 200 && status < 300 ) {
			return 'Exists';
		}

		return 'Check Failed';
	}

	function runCleanupUrlCheck( $button, silent ) {
		var originalText = $button.text();
		$button.prop( 'disabled', true ).text( silent ? 'Checking...' : originalText );
		var $row = $button.closest( '[data-cleanup-result-id]' );
		var $notice = $( '#srk-gsc-cleanup-notices' );

		$.post( cfg.ajaxUrl, {
			action: 'srk_sm_cleanup_analyze_url',
			nonce: cfg.serpNonce || '',
			result_id: $row.data( 'cleanup-result-id' )
		} )
			.done( function ( response ) {
				var analysis = response.data && response.data.analysis ? response.data.analysis : null;

				if ( response.success && analysis ) {
					$row.find( '.srk-gsc-url-state' ).text( formatUrlState( analysis ) );
					$row.find( '.srk-gsc-http-status' ).text( analysis.http_status || '-' );
					$row.find( '.srk-gsc-sitemap-state' ).text( analysis.in_sitemap ? 'Yes' : 'No' );
					$row.find( '.srk-gsc-recommendation' ).text( analysis.recommendation || '' );
					$row.attr( 'data-needs-check', '0' );
				}

				if ( ! silent ) {
					showNotice( $notice, response.success ? ( response.data.message || cfg.strings.completed ) : ( response.data.message || cfg.strings.failed ), response.success ? 'success' : 'error' );
				}
			} )
			.fail( function () {
				if ( ! silent ) {
					showNotice( $notice, cfg.strings.failed, 'error' );
				}
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( originalText );
			} );
	}

	$( document ).on( 'click', '.srk-gsc-analyze-url', function () {
		runCleanupUrlCheck( $( this ), false );
	} );

	$( function () {
		$( '[data-cleanup-result-id][data-needs-check="1"] .srk-gsc-analyze-url' ).slice( 0, 10 ).each( function ( index ) {
			var $button = $( this );
			setTimeout( function () {
				runCleanupUrlCheck( $button, true );
			}, index * 450 );
		} );
	} );

	$( document ).on( 'submit', '#srk-alerts-settings-form', function ( e ) {
		e.preventDefault();

		var $form = $( this );
		var $button = $( '#srk-alerts-save-btn' ).prop( 'disabled', true );
		var $notice = $( '#srk-alerts-notices' );
		var formData = $form.serializeArray();

		formData.push( { name: 'action', value: 'srk_sm_save_alert_settings' } );
		formData.push( { name: 'nonce', value: cfg.alertsNonce || '' } );

		$.post( cfg.ajaxUrl, formData )
			.done( function ( response ) {
				var ok = !! response.success;
				var message = ok ? ( response.data.message || cfg.strings.completed ) : ( response.data.message || cfg.strings.failed );
				showNotice( $notice, message, ok ? 'success' : 'error' );
			} )
			.fail( function () {
				showNotice( $notice, cfg.strings.failed, 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-alerts-test-btn', function () {
		var $button = $( this ).prop( 'disabled', true );
		var $notice = $( '#srk-alerts-notices' );
		var email = ( $( '#srk-alert-recipients' ).val() || '' ).split( /[\r\n,]+/ )[0].trim();

		$.post( cfg.ajaxUrl, {
			action: 'srk_sm_test_alert_email',
			nonce: cfg.alertsNonce || '',
			recipient: email
		} )
			.done( function ( response ) {
				var ok = !! response.success;
				var message = ok ? ( response.data.message || cfg.strings.completed ) : ( response.data.message || cfg.strings.failed );
				showNotice( $notice, message, ok ? 'success' : 'error' );
			} )
			.fail( function () {
				showNotice( $notice, cfg.strings.failed, 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );
}( jQuery ) );
