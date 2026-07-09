/**
 * External Link Control — admin behaviour for the broken-link notice and the
 * dashboard widget.
 *
 * Two jobs core WordPress does not do for us:
 *
 *   1. Persist the notice dismissal. Core's `is-dismissible` X only removes the
 *      notice from the DOM for the current page load; it never tells the
 *      server. We listen for that click and POST to the dismiss action so the
 *      dismissal sticks until a later scan finds a link that wasn't dismissed.
 *   2. Back the widget buttons — Scan now, Ignore, Stop ignoring, Recheck.
 *
 * Vanilla JS, no jQuery dependency. Config arrives via wp_localize_script as
 * `window.timuElc` ({ ajaxUrl, nonce, i18n }).
 */
( function () {
	'use strict';

	if ( typeof window.timuElc === 'undefined' ) {
		return;
	}

	var cfg = window.timuElc;

	/**
	 * POST an action to admin-ajax and return the parsed JSON promise.
	 *
	 * @param {string} action  The wp_ajax_{action} name.
	 * @param {Object} [extra] Additional form fields.
	 * @return {Promise<Object>}
	 */
	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				body.set( key, extra[ key ] );
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	/* -------------------------------------------------------------------
	 * 1. Persist notice dismissal.
	 * ----------------------------------------------------------------- */
	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.timu-elc-broken-notice .notice-dismiss' );
		if ( ! btn ) {
			return;
		}
		// Core removes the notice from the DOM itself; we just record it.
		post( 'timu_elc_dismiss_notice' );
	} );

	/* -------------------------------------------------------------------
	 * 2. Dashboard widget actions (event-delegated so they survive AJAX
	 *    re-renders of the list).
	 * ----------------------------------------------------------------- */
	var widget = document.getElementById( 'timu-elc-broken-links' );
	if ( ! widget ) {
		return;
	}

	function setStatus( message ) {
		var el = widget.querySelector( '.timu-elc-status' );
		if ( el ) {
			el.textContent = message || '';
		}
	}

	widget.addEventListener( 'click', function ( event ) {
		var target = event.target;

		// Scan now.
		if ( target.closest( '.timu-elc-scan-now' ) ) {
			event.preventDefault();
			var scanBtn = target.closest( '.timu-elc-scan-now' );
			scanBtn.disabled = true;
			setStatus( cfg.i18n.scanning );
			post( 'timu_elc_scan_now' ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					setStatus( cfg.i18n.scanError );
					scanBtn.disabled = false;
				}
				// Leave the "scanning…" message; the owner refreshes to see results.
			} ).catch( function () {
				setStatus( cfg.i18n.scanError );
				scanBtn.disabled = false;
			} );
			return;
		}

		var row = target.closest( 'li[data-url]' );
		if ( ! row ) {
			return;
		}
		var url = row.getAttribute( 'data-url' );

		// Ignore.
		if ( target.closest( '.timu-elc-ignore' ) ) {
			event.preventDefault();
			post( 'timu_elc_ignore_link', { url: url } ).then( function ( res ) {
				if ( res && res.success ) {
					row.parentNode.removeChild( row );
				}
			} );
			return;
		}

		// Stop ignoring.
		if ( target.closest( '.timu-elc-unignore' ) ) {
			event.preventDefault();
			post( 'timu_elc_unignore_link', { url: url } ).then( function ( res ) {
				if ( res && res.success ) {
					row.parentNode.removeChild( row );
				}
			} );
			return;
		}

		// Recheck a single URL.
		if ( target.closest( '.timu-elc-recheck' ) ) {
			event.preventDefault();
			var codeEl = row.querySelector( '.timu-elc-code' );
			if ( codeEl ) {
				codeEl.textContent = cfg.i18n.rechecking;
			}
			post( 'timu_elc_recheck_link', { url: url } ).then( function ( res ) {
				if ( res && res.success ) {
					if ( res.data.verdict === 'ok' ) {
						// No longer a problem — drop the row.
						row.parentNode.removeChild( row );
					} else if ( codeEl ) {
						codeEl.textContent = 'HTTP ' + ( res.data.status || 'error' );
						row.setAttribute( 'data-verdict', res.data.verdict );
					}
				} else if ( codeEl ) {
					codeEl.textContent = '';
				}
			} );
			return;
		}
	} );
}() );


/* -------------------------------------------------------------------
 * 3. Domain rules table: Add row / Remove row.
 * ----------------------------------------------------------------- */
( function () {
	'use strict';

	var table  = document.querySelector( '.timu-elc-domain-rules tbody' );
	var addBtn = document.getElementById( 'timu-elc-add-row' );

	if ( ! table || ! addBtn ) {
		return;
	}

	// Remove row: clear domain field (blank = deleted on save) and grey out the row.
	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.timu-elc-remove-row' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var row = btn.closest( 'tr' );
		if ( ! row ) {
			return;
		}
		var domainInput = row.querySelector( 'input[type="text"]' );
		if ( domainInput ) {
			domainInput.value = '';
		}
		row.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
			cb.checked = false;
		} );
		row.classList.add( 'timu-elc-row-removed' );
	} );

	// Add row: clone the <template> element, replace __IDX__ placeholders.
	addBtn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		var template = document.getElementById( 'timu-elc-row-template' );
		if ( ! template ) {
			return;
		}
		var idx  = table.querySelectorAll( 'tr' ).length;
		var frag = template.content.cloneNode( true );
		var row  = frag.querySelector( 'tr' );

		// Replace __IDX__ in all id / name / for attributes and content.
		row.innerHTML = row.innerHTML.replace( /__IDX__/g, idx );

		table.appendChild( row );
		// Focus the new domain input.
		var newInput = table.querySelector( 'tr:last-child input[type="text"]' );
		if ( newInput ) {
			newInput.focus();
		}
	} );
}() );

/* -------------------------------------------------------------------
 * 4. Link inventory: load on demand via REST API.
 * ----------------------------------------------------------------- */
( function () {
	'use strict';

	if ( typeof window.timuElcAdminPage === 'undefined' ) {
		return;
	}

	var cfg        = window.timuElcAdminPage;
	var loadBtn    = document.getElementById( 'timu-elc-load-inventory' );
	var resultsEl  = document.getElementById( 'timu-elc-inventory-results' );
	var statusEl   = document.querySelector( '.timu-elc-inventory-status' );

	if ( ! loadBtn || ! resultsEl ) {
		return;
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	loadBtn.addEventListener( 'click', function () {
		loadBtn.disabled = true;
		if ( statusEl ) {
			statusEl.textContent = cfg.i18n.loading;
		}

		window.fetch( cfg.inventoryUrl + '?orderby=count&order=desc&limit=100', {
			headers: { 'X-WP-Nonce': cfg.restNonce },
			credentials: 'same-origin',
		} )
		.then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} )
		.then( function ( data ) {
			if ( statusEl ) {
				statusEl.textContent = '';
			}
			if ( ! data.domains || ! data.domains.length ) {
				resultsEl.innerHTML = '<p>' + cfg.i18n.noLinks + '</p>';
				resultsEl.style.display = '';
				return;
			}

			var ruledSet = {};
			if ( Array.isArray( cfg.ruledDomains ) ) {
				cfg.ruledDomains.forEach( function ( d ) { ruledSet[ d ] = true; } );
			}

			var rows = data.domains.map( function ( item ) {
				var hasRule    = !! ruledSet[ item.domain ];
				var ruleLabel  = hasRule ? cfg.i18n.hasRule : cfg.i18n.noRule;
				var ruleClass  = hasRule ? 'timu-elc-inv-has-rule' : 'timu-elc-inv-no-rule';
				return '<tr>'
					+ '<td><code>' + esc( item.domain ) + '</code></td>'
					+ '<td>' + item.link_count + '</td>'
					+ '<td class="' + ruleClass + '">' + esc( ruleLabel ) + '</td>'
					+ '</tr>';
			} ).join( '' );

			var summary = data.domain_count + ' domain' + ( data.domain_count !== 1 ? 's' : '' ) + ' found.';

			resultsEl.innerHTML =
				'<table class="widefat striped timu-elc-inventory-table">'
				+ '<thead><tr>'
				+ '<th>' + esc( cfg.i18n.domain ) + '</th>'
				+ '<th>Links</th>'
				+ '<th>Rule</th>'
				+ '</tr></thead>'
				+ '<tbody>' + rows + '</tbody>'
				+ '<tfoot><tr><td colspan="3" class="timu-elc-inv-summary">' + esc( summary ) + '</td></tr></tfoot>'
				+ '</table>';
			resultsEl.style.display = '';
			loadBtn.style.display = 'none';
		} )
		.catch( function () {
			if ( statusEl ) {
				statusEl.textContent = cfg.i18n.loadError;
			}
			loadBtn.disabled = false;
		} );
	} );
}() );
