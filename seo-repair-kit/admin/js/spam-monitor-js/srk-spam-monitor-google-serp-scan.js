/* global srkSpamMonitor */
( function ( $ ) {
	'use strict';

	if ( typeof srkSpamMonitor === 'undefined' ) {
		return;
	}

	var ajaxUrl = srkSpamMonitor.ajaxUrl || '';
	var nonce   = srkSpamMonitor.serpNonce || '';
	var currentResultsScanId = 0;
	var currentResultsPerPage = 10;
	var providerLabels = {
		serpapi: 'SerpApi',
		dataforseo: 'DataForSEO',
		serper: 'Serper.dev'
	};
	var languageLabels = {
		aa: 'Afar', ab: 'Abkhazian', ae: 'Avestan', af: 'Afrikaans', ak: 'Akan', am: 'Amharic', an: 'Aragonese', ar: 'Arabic', as: 'Assamese', av: 'Avaric', ay: 'Aymara', az: 'Azerbaijani',
		ba: 'Bashkir', be: 'Belarusian', bg: 'Bulgarian', bh: 'Bihari languages', bi: 'Bislama', bm: 'Bambara', bn: 'Bengali', bo: 'Tibetan', br: 'Breton', bs: 'Bosnian',
		ca: 'Catalan', ce: 'Chechen', ch: 'Chamorro', co: 'Corsican', cr: 'Cree', cs: 'Czech', cu: 'Church Slavic', cv: 'Chuvash', cy: 'Welsh',
		da: 'Danish', de: 'German', dv: 'Divehi', dz: 'Dzongkha', ee: 'Ewe', el: 'Greek', en: 'English', eo: 'Esperanto', es: 'Spanish', et: 'Estonian', eu: 'Basque',
		fa: 'Persian', ff: 'Fulah', fi: 'Finnish', fj: 'Fijian', fo: 'Faroese', fr: 'French', fy: 'Western Frisian',
		ga: 'Irish', gd: 'Scottish Gaelic', gl: 'Galician', gn: 'Guarani', gu: 'Gujarati', gv: 'Manx',
		ha: 'Hausa', he: 'Hebrew', hi: 'Hindi', ho: 'Hiri Motu', hr: 'Croatian', ht: 'Haitian', hu: 'Hungarian', hy: 'Armenian', hz: 'Herero',
		ia: 'Interlingua', id: 'Indonesian', ie: 'Interlingue', ig: 'Igbo', ii: 'Sichuan Yi', ik: 'Inupiaq', io: 'Ido', is: 'Icelandic', it: 'Italian', iu: 'Inuktitut',
		ja: 'Japanese', jv: 'Javanese', ka: 'Georgian', kg: 'Kongo', ki: 'Kikuyu', kj: 'Kuanyama', kk: 'Kazakh', kl: 'Kalaallisut', km: 'Khmer', kn: 'Kannada', ko: 'Korean', kr: 'Kanuri', ks: 'Kashmiri', ku: 'Kurdish', kv: 'Komi', kw: 'Cornish', ky: 'Kirghiz',
		la: 'Latin', lb: 'Luxembourgish', lg: 'Ganda', li: 'Limburgan', ln: 'Lingala', lo: 'Lao', lt: 'Lithuanian', lu: 'Luba-Katanga', lv: 'Latvian',
		mg: 'Malagasy', mh: 'Marshallese', mi: 'Maori', mk: 'Macedonian', ml: 'Malayalam', mn: 'Mongolian', mr: 'Marathi', ms: 'Malay', mt: 'Maltese', my: 'Burmese',
		na: 'Nauru', nb: 'Norwegian Bokmal', nd: 'North Ndebele', ne: 'Nepali', ng: 'Ndonga', nl: 'Dutch', nn: 'Norwegian Nynorsk', no: 'Norwegian', nr: 'South Ndebele', nv: 'Navajo', ny: 'Nyanja',
		oc: 'Occitan', oj: 'Ojibwa', om: 'Oromo', or: 'Oriya', os: 'Ossetian', pa: 'Punjabi', pi: 'Pali', pl: 'Polish', ps: 'Pashto', pt: 'Portuguese',
		qu: 'Quechua', rm: 'Romansh', rn: 'Rundi', ro: 'Romanian', ru: 'Russian', rw: 'Kinyarwanda',
		sa: 'Sanskrit', sc: 'Sardinian', sd: 'Sindhi', se: 'Northern Sami', sg: 'Sango', si: 'Sinhala', sk: 'Slovak', sl: 'Slovenian', sm: 'Samoan', sn: 'Shona', so: 'Somali', sq: 'Albanian', sr: 'Serbian', ss: 'Swati', st: 'Southern Sotho', su: 'Sundanese', sv: 'Swedish', sw: 'Swahili',
		ta: 'Tamil', te: 'Telugu', tg: 'Tajik', th: 'Thai', ti: 'Tigrinya', tk: 'Turkmen', tl: 'Tagalog', tn: 'Tswana', to: 'Tonga', tr: 'Turkish', ts: 'Tsonga', tt: 'Tatar', tw: 'Twi', ty: 'Tahitian',
		ug: 'Uighur', uk: 'Ukrainian', ur: 'Urdu', uz: 'Uzbek', ve: 'Venda', vi: 'Vietnamese', vo: 'Volapuk', wa: 'Walloon', wo: 'Wolof', xh: 'Xhosa', yi: 'Yiddish', yo: 'Yoruba', za: 'Zhuang', zh: 'Chinese', zu: 'Zulu'
	};

	function text( value ) {
		return value === null || value === undefined || value === '' ? '-' : String( value );
	}

	function languageLabel( code ) {
		var normalized = String( code || '' ).toLowerCase().replace( /[^a-z-]/g, '' );
		return languageLabels[normalized] || String( code || '' ).toUpperCase();
	}

	function languageList( codes ) {
		if ( ! Array.isArray( codes ) ) {
			return '';
		}

		return codes.map( languageLabel ).filter( Boolean ).join( ', ' );
	}

	function languageIssueToText( issue ) {
		var evidence = issue.evidence || issue;
		var unexpected = languageList( evidence.unexpected_languages || [] );
		var spam = languageList( evidence.spam_languages || [] );
		var detected = languageList( evidence.detected_languages || [] );
		var parts = [];

		if ( unexpected ) {
			parts.push( 'Google appears to show unexpected language(s): ' + unexpected + '.' );
		}
		if ( spam ) {
			parts.push( 'These language(s) are also in your suspicious-language watchlist: ' + spam + '.' );
		}
		if ( ! parts.length && detected ) {
			parts.push( 'Detected language signal: ' + detected + '.' );
		}

		return parts.join( ' ' );
	}

	function expandLanguageCodesInText( value ) {
		return String( value || '' ).replace( /"(detected_languages|spam_languages|unexpected_languages|allowed_languages)"\s*:\s*\[([^\]]*)\]/g, function ( match, key, list ) {
			var labels = [];
			list.replace( /"([^"]+)"/g, function ( itemMatch, code ) {
				labels.push( '"' + languageLabel( code ) + '"' );
				return itemMatch;
			} );
			return '"' + key + '":[' + labels.join( ',' ) + ']';
		} );
	}

	function notice( message, type ) {
		var $status = $( '#srk-serp-status' );
		if ( ! $status.length ) {
			return;
		}

		$status
			.removeClass( 'srk-sm-serp-status--success srk-sm-serp-status--error srk-sm-serp-status--loading' )
			.addClass( 'srk-sm-serp-status--' + ( type || 'success' ) )
			.text( message )
			.show();
	}

	function clearTable( selector, cols, message ) {
		var $tbody = $( selector ).find( 'tbody' );
		$tbody.empty();
		$( '<tr>' )
			.append( $( '<td>' ).attr( 'colspan', cols ).text( message ) )
			.appendTo( $tbody );
	}

	function renderSummary( result, scanId ) {
		var fields = [
			[ 'Provider', result.provider_used ],
			[ 'Engine', result.engine_used ],
			[ 'Received Results', result.received_results ],
			[ 'Unique Results', result.unique_results ],
			[ 'SERP Requests Used', result.serp_requests_used ],
			[ 'Overall Risk Score', result.overall_risk_score ],
			[ 'Clean Count', result.clean_count ],
			[ 'Suspicious Count', result.suspicious_count ],
			[ 'Spam Count', result.spam_count ],
			[ 'Critical Count', result.critical_count ],
			[ 'Saved Scan ID', scanId ]
		];

		var $wrap = $( '#srk-serp-summary' );
		$wrap.empty().prop( 'hidden', false );

		fields.forEach( function ( field ) {
			$( '<div>' )
				.addClass( 'srk-sm-serp-summary-card' )
				.append( $( '<span>' ).addClass( 'srk-sm-serp-summary-label' ).text( field[0] ) )
				.append( $( '<strong>' ).text( text( field[1] ) ) )
				.appendTo( $wrap );
		} );
	}

	function issueToText( issue ) {
		if ( typeof issue === 'string' ) {
			return issue;
		}

		if ( ! issue || typeof issue !== 'object' ) {
			return '';
		}

		var parts = [];
		if ( issue.type === 'unexpected_language_signal' || issue.detected_languages || issue.spam_languages || issue.unexpected_languages ) {
			return languageIssueToText( issue ) || expandLanguageCodesInText( JSON.stringify( issue ) );
		}

		[ 'message', 'reason', 'label', 'type', 'rule_id', 'matched_keyword', 'detected_language', 'pattern' ].forEach( function ( key ) {
			if ( issue[key] ) {
				parts.push( key === 'detected_language' ? languageLabel( issue[key] ) : issue[key] );
			}
		} );

		if ( issue.evidence ) {
			if ( Array.isArray( issue.evidence ) ) {
				parts.push( issue.evidence.map( issueToText ).filter( Boolean ).join( ', ' ) );
			} else {
				parts.push( issueToText( issue.evidence ) );
			}
		}

		if ( parts.filter( Boolean ).length ) {
			return parts.filter( Boolean ).join( ': ' );
		}

		try {
			return expandLanguageCodesInText( JSON.stringify( issue ) );
		} catch ( e ) {
			return '';
		}
	}

	function formatIssues( issues ) {
		if ( typeof issues === 'string' ) {
			try {
				issues = JSON.parse( issues );
			} catch ( e ) {
				return expandLanguageCodesInText( issues ) || '-';
			}
		}

		if ( ! Array.isArray( issues ) || ! issues.length ) {
			return '-';
		}

		return issues.map( issueToText ).filter( Boolean ).join( ', ' ) || '-';
	}

	function riskClass( level ) {
		var normalized = String( level || 'clean' ).toLowerCase();
		if ( normalized === 'critical' ) {
			return 'srk-sm-serp-risk-badge srk-sm-serp-risk-badge--critical';
		}
		if ( normalized === 'spam' ) {
			return 'srk-sm-serp-risk-badge srk-sm-serp-risk-badge--spam';
		}
		if ( normalized === 'suspicious' ) {
			return 'srk-sm-serp-risk-badge srk-sm-serp-risk-badge--suspicious';
		}
		return 'srk-sm-serp-risk-badge srk-sm-serp-risk-badge--clean';
	}

	function renderResults( results, page, perPage ) {
		var $tbody = $( '#srk-serp-results-table tbody' );
		$tbody.empty();

		if ( ! Array.isArray( results ) || ! results.length ) {
			clearTable( '#srk-serp-results-table', 8, 'No Google results were returned for this domain.' );
			return;
		}

		page = parseInt( page || 1, 10 );
		perPage = parseInt( perPage || 10, 10 );
		results.forEach( function ( row, idx ) {
			var url   = row.url || '';
			var score = parseInt( row.risk_score || 0, 10 );
			var scoreCls = score >= 81 ? 'srk-serp-score--critical' : ( score >= 61 ? 'srk-serp-score--spam' : ( score > 30 ? 'srk-serp-score--suspicious' : '' ) );

			var $link = $( '<a>' ).attr( { href: url, target: '_blank', rel: 'noopener noreferrer' } )
				.css( { color: 'var(--srk-sm-primary)', textDecoration: 'none', fontWeight: '500', wordBreak: 'break-all' } )
				.text( url );

			var issueText = formatIssues( row.issues );
			var $issues = $( '<div>' ).css( { display: 'flex', flexWrap: 'wrap', gap: '3px' } );
			if ( issueText && issueText !== '-' ) {
				issueText.split( ', ' ).slice( 0, 3 ).forEach( function ( iss ) {
					$( '<span>' ).addClass( 'srk-serp-issue-chip' ).text( iss ).appendTo( $issues );
				} );
			} else {
				$issues.text( '-' );
			}

			var $inspect = $( '<a>' )
				.attr( { href: 'https://search.google.com/search-console/inspect?resource_id=sc-domain:' + encodeURIComponent( url ), target: '_blank', rel: 'noopener noreferrer' } )
				.addClass( 'srk-dash-action-btn srk-dash-action-btn--ghost' )
				.text( 'Inspect' );

			$( '<tr>' )
				.append( $( '<td>' ).css( { color: 'var(--srk-sm-muted)', fontWeight: '600', fontSize: '12px' } ).text( ( ( page - 1 ) * perPage ) + idx + 1 ) )
				.append( $( '<td>' ).css( { maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } ).append( $link ) )
				.append( $( '<td>' ).css( { maxWidth: '180px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } ).text( text( row.google_title ) ) )
				.append( $( '<td>' ).css( { maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--srk-sm-muted)', fontSize: '12px' } ).text( text( row.google_snippet ) ) )
				.append( $( '<td>' ).append( $( '<span>' ).addClass( riskClass( row.risk_level ) ).text( text( row.risk_level ) ) ) )
				.append( $( '<td>' ).append( $( '<span>' ).addClass( 'srk-serp-score ' + scoreCls ).text( text( row.risk_score ) ) ) )
				.append( $( '<td>' ).append( $issues ) )
				.append( $( '<td>' ).append( $inspect ) )
				.appendTo( $tbody );
		} );
	}

	function renderResultsPagination( pagination ) {
		var $wrap = $( '#srk-serp-results-pagination' ).empty();
		var totalPages = parseInt( pagination && pagination.total_pages || 0, 10 );
		var page = parseInt( pagination && pagination.page || 1, 10 );
		var total = parseInt( pagination && pagination.total || 0, 10 );
		if ( total < 1 ) {
			return;
		}
		var perPage = parseInt( pagination.per_page || 10, 10 );
		currentResultsPerPage = perPage;
		var start = ( ( page - 1 ) * perPage ) + 1;
		var end = Math.min( page * perPage, total );
		var $container = $( '<div>' ).addClass( 'srk-sm-pagination srk-pagination-wrapper' );
		$( '<div>' ).addClass( 'srk-pagination-info' ).text( 'Showing ' + start + ' to ' + end + ' of ' + total + ' records' ).appendTo( $container );
		var $nav = $( '<nav>' ).addClass( 'srk-pagination' ).attr( 'aria-label', 'SERP result pagination' ).appendTo( $container );
		function pageButton( label, target, className, disabled ) {
			return $( '<button>' ).attr( { type: 'button', 'data-page': target, disabled: !! disabled } )
				.addClass( className + ' srk-serp-results-page' + ( disabled ? ' srk-pagination-disabled' : '' ) ).html( label );
		}
		$nav.append( pageButton( '<span class="srk-pagination-arrow">&lsaquo;</span>Previous', page - 1, 'srk-pagination-link srk-pagination-prev', page <= 1 ) );
		var $pageWrap = $( '<div>' ).addClass( 'srk-pagination-pages' ).appendTo( $nav );
		var pages = [];
		for ( var i = 1; i <= totalPages; i++ ) {
			if ( i === 1 || i === totalPages || Math.abs( i - page ) <= 2 ) {
				pages.push( i );
			}
		}
		pages.forEach( function ( i, index ) {
			if ( index && i - pages[ index - 1 ] > 1 ) {
				$( '<span>' ).addClass( 'srk-pagination-dots' ).text( '...' ).appendTo( $pageWrap );
			}
			$( '<button>' ).attr( 'type', 'button' )
					.addClass( 'srk-pagination-page srk-serp-results-page' + ( i === page ? ' srk-pagination-current' : '' ) )
					.attr( 'aria-current', i === page ? 'page' : null )
					.prop( 'disabled', i === page )
					.attr( 'data-page', i ).text( i )
					.appendTo( $pageWrap );
		} );
		$nav.append( pageButton( 'Next<span class="srk-pagination-arrow">&rsaquo;</span>', page + 1, 'srk-pagination-link srk-pagination-next', page >= totalPages ) );
		$( '<div>' ).addClass( 'srk-pagination-per-page' )
			.append( $( '<label>' ).text( 'Per page:' ) )
			.append( $( '<select>' ).addClass( 'srk-per-page-select srk-serp-results-per-page' ).attr( 'aria-label', 'Records per page' ) )
			.appendTo( $container );
		[ 10, 20, 30, 50, 100 ].forEach( function ( option ) {
			$( '<option>' ).val( option ).text( option ).prop( 'selected', option === perPage ).appendTo( $container.find( '.srk-serp-results-per-page' ) );
		} );
		$container.appendTo( $wrap );
	}

	function loadStoredResults( scanId, page, perPage ) {
		currentResultsScanId = parseInt( scanId || 0, 10 );
		currentResultsPerPage = parseInt( perPage || currentResultsPerPage || 10, 10 );
		return $.post( ajaxUrl, {
			action: 'srk_spam_monitor_get_serp_scan_results',
			nonce: nonce,
			scan_id: currentResultsScanId,
			page: page || 1,
			per_page: currentResultsPerPage
		} ).done( function ( res ) {
			if ( ! res.success ) {
				notice( ( res.data && res.data.message ) || 'Could not load stored scan results.', 'error' );
				return;
			}
			var scan = res.data.scan || {};
			var pagination = res.data.pagination || { page: page || 1 };
			renderSummary( scan, scan.id );
			renderResults( res.data.results || [], pagination.page, pagination.per_page );
			renderResultsPagination( pagination );
		} ).fail( function ( xhr ) {
			notice( ajaxErrorMessage( xhr, 'Could not load stored scan results.' ), 'error' );
		} );
	}

	function renderRecent( scans ) {
		var $tbody = $( '#srk-serp-recent-table tbody' );
		$tbody.empty();

		if ( ! Array.isArray( scans ) || ! scans.length ) {
			clearTable( '#srk-serp-recent-table', 8, 'No SERP scans saved yet.' );
			return;
		}

		scans.forEach( function ( scan ) {
			var scanId = scan.id || 0;
			var score  = parseInt( scan.overall_risk_score || 0, 10 );
			var source = scan.scan_source === 'scheduled' ? 'scheduled' : 'manual';
			var scoreCls = score >= 81 ? 'srk-serp-score--critical' : ( score >= 61 ? 'srk-serp-score--spam' : ( score > 30 ? 'srk-serp-score--suspicious' : '' ) );

			var $viewBtn = $( '<button>' )
				.attr( 'type', 'button' )
				.addClass( 'srk-dash-action-btn srk-dash-action-btn--ghost srk-serp-view-scan' )
				.data( 'scan-id', scanId )
				.text( 'View' );

			$( '<tr>' )
				.attr( 'data-scan-id', scanId )
				.append( $( '<td>' ).css( { fontWeight: '700', color: 'var(--srk-sm-muted)' } ).text( 'SCN-' + scanId ) )
				.append( $( '<td>' ).text( text( scan.domain ) ) )
				.append( $( '<td>' ).append( $( '<span>' ).addClass( 'srk-sm-source-badge srk-sm-source-badge--' + source ).text( source.charAt( 0 ).toUpperCase() + source.slice( 1 ) ) ) )
				.append( $( '<td>' ).text( text( scan.received_results ) ) )
				.append( $( '<td>' ).text( text( scan.serp_requests_used ) ) )
				.append( $( '<td>' ).append( $( '<span>' ).addClass( 'srk-serp-score ' + scoreCls ).text( text( scan.overall_risk_score ) ) ) )
				.append( $( '<td>' ).css( { fontSize: '12px', color: 'var(--srk-sm-muted)', whiteSpace: 'nowrap' } ).text( text( scan.created_at ) ) )
				.append( $( '<td>' ).append( $viewBtn ) )
				.appendTo( $tbody );
		} );
	}

	function hasActiveRecentFilters() {
		var params = new URLSearchParams( window.location.search );
		return [ 'srk_serp_filter_domain', 'srk_serp_filter_risk', 'srk_serp_filter_id' ].some( function ( key ) {
			return !! params.get( key );
		} );
	}

	function ajaxErrorMessage( xhr, fallback ) {
		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			return xhr.responseJSON.data.message;
		}

		if ( xhr && xhr.status === 0 ) {
			return 'Python SERP engine is not reachable. Please start the Python server and try again.';
		}

		if ( xhr && xhr.status === 403 ) {
			return 'Permission denied.';
		}

		return fallback || 'Something went wrong. Please try again.';
	}

	function renderRulesStatus( status ) {
		var $wrap = $( '#srk-serp-rules-sync-status' );
		if ( ! $wrap.length ) {
			return;
		}

		status = status || {};
		var synced = !! status.synced && status.hash_matches !== false;
		var statusClass = synced ? 'is-synced' : 'is-not-synced';
		var label = synced ? 'Synced' : 'Not Synced';

		$wrap.attr( 'data-status', statusClass );
		$wrap.find( '[data-field="status"]' )
			.removeClass( 'is-synced is-not-synced' )
			.addClass( statusClass )
			.text( label );
		$wrap.find( '[data-field="last_synced_at"]' ).text( text( status.last_synced_at ) );
		$wrap.find( '[data-field="rules_hash"]' ).text( text( status.rules_hash ) );
	}

	function renderTrialStatus( status ) {
		var $wrap = $( '#srk-serp-trial-status' );
		if ( ! $wrap.length ) {
			return;
		}

		status = status || {};
		var active = !! status.trial_active;
		var statusClass = active ? 'is-synced' : 'is-not-synced';
		var label = active ? 'Active' : ( status.status === 'exhausted' ? 'Exhausted' : 'Available' );
		var allocated = parseInt( status.allocated_requests || status.trial_requests || 5, 10 ) || 5;
		var used = parseInt( status.used_requests || 0, 10 ) || 0;
		var remaining = status.remaining_requests;

		if ( remaining === undefined || remaining === null || remaining === '' ) {
			remaining = active ? Math.max( 0, allocated - used ) : allocated;
		}

		$wrap.attr( 'data-status', statusClass );
		$wrap.find( '[data-field="status"]' )
			.removeClass( 'is-synced is-not-synced' )
			.addClass( statusClass )
			.text( label );
		$wrap.find( '[data-field="remaining_requests"]' ).text( text( remaining ) );
		$wrap.find( '[data-field="used_requests"]' ).text( text( used ) );
		$wrap.find( '[data-field="allocated_requests"]' ).text( text( allocated ) );

		if ( status.message ) {
			$( '.srk-sm-serp-trial-card [data-field="message"]' ).text( status.message );
		}
	}

	function getSelectedProvider() {
		return $( '#srk-serp-provider-select' ).val() || 'serper';
	}

	function updateProviderFields() {
		var provider = getSelectedProvider();
		$( '[data-provider-fieldset="dataforseo"]' ).prop( 'hidden', provider !== 'dataforseo' );
		$( '[data-provider-fieldset="api_key"]' ).prop( 'hidden', provider === 'dataforseo' );
		$( '[data-provider-guide]' ).prop( 'hidden', true );
		$( '[data-provider-guide="' + provider + '"]' ).prop( 'hidden', false );
	}

	function collectProviderPayload() {
		var provider = getSelectedProvider();
		var payload = {
			action: '',
			nonce: nonce,
			provider: provider
		};

		if ( provider === 'dataforseo' ) {
			payload.login = $( '#srk-serp-provider-login' ).val();
			payload.password = $( '#srk-serp-provider-password' ).val();
		} else {
			payload.api_key = $( '#srk-serpapi-key-input' ).val();
		}

		return payload;
	}

	function clearProviderInputs() {
		$( '#srk-serpapi-key-input' ).val( '' );
		$( '#srk-serp-provider-login' ).val( '' );
		$( '#srk-serp-provider-password' ).val( '' );
	}

	function renderSerpProviderStatus( status ) {
		var $wrap = $( '#srk-serpapi-key-status' );
		if ( ! $wrap.length ) {
			return;
		}

		status = status || {};
		var connected = !! status.connected;
		var statusClass = connected ? 'is-synced' : 'is-not-synced';
		var label = connected ? 'Connected' : 'Not Connected';

		$wrap.attr( 'data-status', statusClass );
		$wrap.find( '[data-field="status"]' )
			.removeClass( 'is-synced is-not-synced' )
			.addClass( statusClass )
			.text( label );
		$wrap.find( '[data-field="provider"]' ).text( providerLabels[ status.provider || 'serper' ] || status.provider || 'Serper.dev' );
		$wrap.find( '[data-field="masked_credentials"]' ).text( text( status.masked_credentials || status.masked_key ) );
		$wrap.find( '[data-field="provider_mode"]' ).text( text( status.provider_mode || 'internal_trial_key' ) );
		$wrap.find( '[data-field="last_tested_at"]' ).text( text( status.last_tested_at ) );
	}

	function estimateRequestsForResults( results ) {
		results = parseInt( results, 10 ) || 10;
		return Math.max( 1, Math.ceil( results / 10 ) );
	}

	function scanDepthLabel( results ) {
		results = parseInt( results, 10 ) || 10;
		if ( results <= 10 ) {
			return 'Fast Scan';
		}
		if ( results <= 50 ) {
			return 'Standard Scan';
		}
		return 'Deep Scan';
	}

	function updateSerpEstimate() {
		var results = parseInt( $( '#srk-serp-max-results' ).val(), 10 ) || 10;
		var requests = estimateRequestsForResults( results );
		$( '#srk-serp-max-requests' ).val( requests );
		$( '#srk-serp-estimated-requests' ).text( requests );
		$( '#srk-serp-estimated-credits' ).text( requests );
		$( '#srk-serp-scan-depth' ).text( scanDepthLabel( results ) );
	}

	function updateDeveloperModeMessage() {
		var enabled = $( '#srk-serp-developer-mode' ).is( ':checked' );
		var $message = $( '#srk-serp-dev-mode-message' );
		$message.prop( 'hidden', ! enabled );
		$message.find( '[data-field="scan_domain"]' ).text( text( $( '#srk-serp-domain' ).val() ) );
		$message.find( '[data-field="developer_mode"]' ).text( enabled ? 'Enabled' : 'Disabled' );
	}

	function refreshRulesStatus( showNotice ) {
		return $.post( ajaxUrl, {
			action: 'srk_spam_monitor_get_serp_rules_status',
			nonce: nonce
		} )
			.done( function ( res ) {
				if ( ! res.success ) {
					if ( showNotice ) {
						notice( ( res.data && res.data.message ) || 'Could not refresh rules sync status.', 'error' );
					}
					return;
				}

				renderRulesStatus( res.data || {} );
				if ( showNotice ) {
					notice( 'Rules sync status refreshed.', 'success' );
				}
			} )
			.fail( function ( xhr ) {
				if ( showNotice ) {
					notice( ajaxErrorMessage( xhr, 'Could not refresh rules sync status.' ), 'error' );
				}
			} );
	}

	$( document ).on( 'click', '#srk-serp-sync-rules', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		notice( 'Syncing Spam Rules to the Python SERP engine...', 'loading' );

		$.post( ajaxUrl, {
			action: 'srk_spam_monitor_sync_serp_rules',
			nonce: nonce
		} )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Rules sync failed.', 'error' );
					return;
				}

				renderRulesStatus( res.data || {} );
				notice( ( res.data && res.data.message ) || 'Spam rules synced.', 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Rules sync failed.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serp-refresh-rules-status', function () {
		var $btn = $( this ).prop( 'disabled', true );
		refreshRulesStatus( true ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '#srk-serp-activate-trial', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		notice( 'Activating free SERP requests...', 'loading' );

		$.post( ajaxUrl, {
			action: 'srk_spam_monitor_activate_serp_trial',
			nonce: nonce
		} )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Free trial activation failed.', 'error' );
					return;
				}

				renderTrialStatus( res.data || {} );
				notice( ( res.data && res.data.message ) || 'Free Trial Activated.', 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Free trial activation failed.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serp-refresh-trial-status', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		notice( 'Refreshing free trial status...', 'loading' );

		$.post( ajaxUrl, {
			action: 'srk_spam_monitor_get_serp_trial_status',
			nonce: nonce
		} )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Could not refresh free trial status.', 'error' );
					return;
				}

				renderTrialStatus( res.data || {} );
				notice( 'Free trial status refreshed.', 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Could not refresh free trial status.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serpapi-refresh-status', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		notice( 'Refreshing SERP provider status...', 'loading' );

		$.post( ajaxUrl, {
			action: 'srk_spam_monitor_get_serp_provider_status',
			nonce: nonce
		} )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Could not refresh SERP provider status.', 'error' );
					return;
				}

				renderSerpProviderStatus( res.data || {} );
				notice( ( res.data && res.data.message ) || 'SERP provider status refreshed.', res.data && res.data.status === 'route_missing' ? 'error' : 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Could not refresh SERP provider status.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serpapi-test-key', function () {
		var $btn = $( this );
		var payload = collectProviderPayload();
		payload.action = 'srk_spam_monitor_test_serp_provider';
		$btn.prop( 'disabled', true );
		notice( 'Testing SERP provider credentials...', 'loading' );

		$.post( ajaxUrl, payload )
			.done( function ( res ) {
				if ( ! res.success || ! ( res.data && res.data.valid ) ) {
					notice( ( res.data && res.data.message ) || 'SERP provider credentials are invalid.', 'error' );
					return;
				}

				notice( res.data.message || 'SERP provider credentials are valid.', 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Could not test SERP provider credentials.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serpapi-save-key', function () {
		var $btn = $( this );
		var payload = collectProviderPayload();
		payload.action = 'srk_spam_monitor_save_serp_provider';
		$btn.prop( 'disabled', true );
		notice( 'Validating and saving SERP provider credentials...', 'loading' );

		$.post( ajaxUrl, payload )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Could not save SERP provider credentials.', 'error' );
					return;
				}

				clearProviderInputs();
				renderSerpProviderStatus( res.data || {} );
				notice( ( res.data && res.data.message ) || 'SERP provider connected successfully.', 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Could not save SERP provider credentials.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serpapi-remove-key', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		notice( 'Removing SERP provider credentials...', 'loading' );

		$.post( ajaxUrl, {
			action: 'srk_spam_monitor_remove_serp_provider',
			nonce: nonce
		} )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Could not remove SERP provider credentials.', 'error' );
					return;
				}

				clearProviderInputs();
				renderSerpProviderStatus( res.data || {} );
				notice( ( res.data && res.data.message ) || 'SERP provider credentials removed.', 'success' );
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Could not remove SERP provider credentials.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#srk-serp-run-scan', function () {
		updateSerpEstimate();
		var $btn = $( this );
		var payload = {
			action: 'srk_spam_monitor_run_serp_scan',
			nonce: nonce,
			domain: $( '#srk-serp-domain' ).val(),
			max_results: $( '#srk-serp-max-results' ).val(),
			max_serp_requests: $( '#srk-serp-max-requests' ).val(),
			include_subdomains: $( '#srk-serp-include-subdomains' ).is( ':checked' ) ? 1 : 0,
			developer_mode: $( '#srk-serp-developer-mode' ).is( ':checked' ) ? 1 : 0,
			recent_per_page: parseInt( $( '#srk-recent-serp-scans .srk-per-page-select option:selected' ).text(), 10 ) || 10
		};

		$btn.prop( 'disabled', true );
		notice( 'Running Google SERP scan. This can take up to 90 seconds...', 'loading' );

		$.post( ajaxUrl, payload )
			.done( function ( res ) {
				if ( ! res.success ) {
					notice( ( res.data && res.data.message ) || 'Scan failed. Please try again.', 'error' );
					return;
				}

				var data = res.data || {};
				var result = data.result || {};

				notice( data.message || 'Scan completed and saved successfully.', 'success' );
				if ( result.trial_status ) {
					renderTrialStatus( result.trial_status );
				}
				renderSummary( result, data.scan_id );
				loadStoredResults( data.scan_id, 1, 10 );
				if ( ! hasActiveRecentFilters() ) {
					renderRecent( data.recent_scans || [] );
				}
			} )
			.fail( function ( xhr ) {
				notice( ajaxErrorMessage( xhr, 'Scan failed. Please try again.' ), 'error' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.srk-serp-view-scan', function () {
		var scanId = $( this ).data( 'scan-id' );
		notice( 'Loading saved SERP scan results...', 'loading' );

		loadStoredResults( scanId, 1, 10 ).done( function ( res ) {
			if ( res.success ) { notice( 'Saved scan results loaded.', 'success' ); }
		} );
	} );

	$( document ).on( 'click', '.srk-serp-results-page', function () {
		if ( currentResultsScanId ) {
			loadStoredResults( currentResultsScanId, $( this ).data( 'page' ) );
		}
	} );

	$( document ).on( 'change', '.srk-serp-results-per-page', function () {
		if ( currentResultsScanId ) {
			loadStoredResults( currentResultsScanId, 1, $( this ).val() );
		}
	} );

	if ( $( '#srk-serp-rules-sync-status' ).length ) {
		refreshRulesStatus( false );
	}

	if ( $( '#srk-serpapi-key-status' ).length ) {
		$.post( ajaxUrl, {
			action: 'srk_spam_monitor_get_serp_provider_status',
			nonce: nonce
		} ).done( function ( res ) {
			if ( res.success ) {
				renderSerpProviderStatus( res.data || {} );
			}
		} );
	}

	$( document ).on( 'change', '#srk-serp-max-results', updateSerpEstimate );
	$( document ).on( 'change', '#srk-serp-provider-select', updateProviderFields );
	$( document ).on( 'change', '#srk-serp-developer-mode', updateDeveloperModeMessage );
	$( document ).on( 'input change', '#srk-serp-domain', updateDeveloperModeMessage );
	updateSerpEstimate();
	updateProviderFields();
	updateDeveloperModeMessage();
} )( jQuery );
