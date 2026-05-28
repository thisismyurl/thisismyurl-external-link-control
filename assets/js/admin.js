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
