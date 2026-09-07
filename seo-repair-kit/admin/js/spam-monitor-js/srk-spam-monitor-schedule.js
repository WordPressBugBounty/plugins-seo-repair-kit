/* global srkSpamMonitor */
( function ( $ ) {
	'use strict';

	if ( typeof srkSpamMonitor === 'undefined' || ! srkSpamMonitor.scheduleNonce ) {
		return;
	}

	var $form = $( '#srk-sm-schedule-form' );
	var strings = srkSpamMonitor.strings || {};
	if ( ! $form.length ) {
		return;
	}

	function showNotice( message, type ) {
		$( '#srk-sm-schedule-notice' )
			.removeClass( 'is-success is-error is-loading' )
			.addClass( 'is-' + type )
			.text( message )
			.prop( 'hidden', false );
	}

	function errorMessage( xhr, fallback ) {
		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			return xhr.responseJSON.data.message;
		}
		return fallback;
	}

	function updateStatus( status ) {
		if ( ! status ) {
			return;
		}

		Object.keys( status ).forEach( function ( key ) {
			$( '[data-schedule-field="' + key + '"]' ).not( '[data-schedule-field="last_scan_link"]' ).text( status[key] === '' ? '-' : status[key] );
		} );

		var $pill = $( '[data-schedule-field="enabled_label"]' );
		$pill
			.toggleClass( 'srk-stg-pill--enabled', !! status.enabled )
			.toggleClass( 'srk-stg-pill--disabled', ! status.enabled )
			.text( status.enabled ? ( strings.enabled || 'Enabled' ) : ( strings.disabled || 'Disabled' ) );

		$( '#srk-sm-run-schedule-now' ).prop( 'disabled', ! status.enabled );
		$( '[data-schedule-field="last_scan_link"]' ).text( status.last_scan_id ? 'SCN-' + status.last_scan_id : ( strings.noScheduledScan || 'No scheduled scan yet' ) );
	}

	function syncDeveloperModeUi() {
		var enabled = $form.find( '[name="developer_mode"]' ).is( ':checked' );
		var $field = $( '#srk-sm-schedule-domain-field' ).toggleClass( 'is-disabled', ! enabled );
		$field.find( '[name="domain"]' ).prop( 'disabled', ! enabled );
	}

	function syncFrequencyUi() {
		var isTesting = $form.find( '[name="frequency"]' ).val() === 'every_10_minutes';
		var $field = $( '#srk-sm-schedule-run-time-field' ).toggleClass( 'is-disabled', isTesting );
		var $help = $field.find( 'small' );

		$field.find( '[name="run_time"]' ).prop( 'disabled', isTesting );
		$help.text( isTesting ? ( strings.testingFrequencyHelp || 'Testing mode: runs about every 10 minutes when WP-Cron receives traffic. The Run Time field is not used.' ) : $help.data( 'default-timezone' ) );
	}

	function applySettings( status ) {
		if ( ! status ) {
			return;
		}

		$form.find( '[name="enabled"]' ).prop( 'checked', !! status.enabled );
		$form.find( '[name="frequency"]' ).val( status.frequency );
		$form.find( '[name="serp_requests"]' ).val( String( status.serp_requests ) );
		$form.find( '[name="run_time"]' ).val( status.run_time );
		$form.find( '[name="include_subdomains"]' ).prop( 'checked', !! status.include_subdomains );
		$form.find( '[name="developer_mode"]' ).prop( 'checked', !! status.developer_mode );
		$form.find( '[name="domain"]' ).val( status.domain || '' );
		syncFrequencyUi();
		syncDeveloperModeUi();
	}

	function requestData( action ) {
		return {
			action: action,
			nonce: srkSpamMonitor.scheduleNonce,
			enabled: $form.find( '[name="enabled"]' ).is( ':checked' ) ? 1 : 0,
			frequency: $form.find( '[name="frequency"]' ).val(),
			serp_requests: $form.find( '[name="serp_requests"]' ).val(),
			run_time: $form.find( '[name="run_time"]' ).val(),
			include_subdomains: $form.find( '[name="include_subdomains"]' ).is( ':checked' ) ? 1 : 0,
			developer_mode: $form.find( '[name="developer_mode"]' ).is( ':checked' ) ? 1 : 0,
			domain: $form.find( '[name="domain"]' ).val()
		};
	}

	$form.on( 'submit', function ( event ) {
		event.preventDefault();
		var $button = $( '#srk-sm-save-schedule' ).prop( 'disabled', true );
		showNotice( strings.scheduleSaving || 'Saving scheduled scan settings…', 'loading' );

		$.post( srkSpamMonitor.ajaxUrl, requestData( 'srk_sm_save_schedule' ) )
			.done( function ( response ) {
				if ( response && response.success ) {
					applySettings( response.data.status );
					updateStatus( response.data.status );
					showNotice( response.data.message, 'success' );
					return;
				}
				showNotice( strings.scheduleSaveError || 'Scheduled scan settings could not be saved.', 'error' );
			} )
			.fail( function ( xhr ) {
				showNotice( errorMessage( xhr, strings.scheduleSaveError || 'Scheduled scan settings could not be saved.' ), 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$form.on( 'change', '[name="frequency"]', syncFrequencyUi );
	$form.on( 'change', '[name="developer_mode"]', syncDeveloperModeUi );
	syncFrequencyUi();
	syncDeveloperModeUi();

	$( '#srk-sm-reset-schedule' ).on( 'click', function () {
		if ( ! window.confirm( strings.scheduleResetConfirm || 'Reset Scheduled Spam Monitoring to its defaults? Existing scan records will be preserved.' ) ) {
			return;
		}

		var $button = $( this ).prop( 'disabled', true );
		showNotice( strings.scheduleResetting || 'Resetting scheduled scan settings…', 'loading' );

		$.post( srkSpamMonitor.ajaxUrl, {
			action: 'srk_sm_reset_schedule',
			nonce: srkSpamMonitor.scheduleNonce
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					applySettings( response.data.status );
					updateStatus( response.data.status );
					showNotice( response.data.message, 'success' );
					return;
				}
				showNotice( strings.scheduleResetError || 'Scheduled scan settings could not be reset.', 'error' );
			} )
			.fail( function ( xhr ) {
				showNotice( errorMessage( xhr, strings.scheduleResetError || 'Scheduled scan settings could not be reset.' ), 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$( '#srk-sm-run-schedule-now' ).on( 'click', function () {
		var $button = $( this ).prop( 'disabled', true );
		showNotice( strings.scheduleRunning || 'Running the saved scheduled scan…', 'loading' );

		$.post( srkSpamMonitor.ajaxUrl, {
			action: 'srk_sm_run_schedule_now',
			nonce: srkSpamMonitor.scheduleNonce
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					updateStatus( response.data.status );
					showNotice( response.data.message, 'success' );
					return;
				}
				showNotice( strings.scheduleRunError || 'The scheduled scan could not be completed.', 'error' );
			} )
			.fail( function ( xhr ) {
				if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.status ) {
					updateStatus( xhr.responseJSON.data.status );
				}
				showNotice( errorMessage( xhr, strings.scheduleRunError || 'The scheduled scan could not be completed.' ), 'error' );
			} )
			.always( function () {
				$button.prop( 'disabled', ! $form.find( '[name="enabled"]' ).is( ':checked' ) );
			} );
	} );
}( jQuery ) );
