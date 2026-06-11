/* HealthFest Registration — front-end behaviour. Vanilla JS, no dependencies. */
( function () {
	'use strict';

	if ( typeof window.HF_DATA === 'undefined' ) {
		return;
	}
	var DATA = window.HF_DATA;
	var S = DATA.strings || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var form = document.querySelector( '.hf-registration .hf-form' );
		if ( ! form ) {
			return;
		}
		refreshAvailability();
		form.addEventListener( 'submit', onSubmit );
	} );

	/* Re-fetch live seat counts so cached pages never show stale availability. */
	function refreshAvailability() {
		var body = new URLSearchParams();
		body.append( 'action', 'hf_availability' );

		fetch( DATA.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) { return; }
				var map = res.data || {};
				document.querySelectorAll( '.hf-workshop' ).forEach( function ( el ) {
					var id = el.getAttribute( 'data-workshop' );
					var info = map[ id ];
					if ( ! info ) { return; }
					applyAvailability( el, info );
				} );
			} )
			.catch( function () { /* keep server-rendered values on failure */ } );
	}

	function applyAvailability( el, info ) {
		var cb = el.querySelector( 'input[type="checkbox"]' );
		var seats = el.querySelector( '.hf-w-seats' );
		el.setAttribute( 'data-remaining', info.remaining );
		if ( info.is_full ) {
			el.classList.add( 'hf-is-full' );
			if ( cb ) { cb.disabled = true; cb.checked = false; }
			if ( seats ) {
				seats.innerHTML = '<span class="hf-badge hf-badge-full">' + esc( S.full || 'FULL' ) + '</span>';
			}
		} else {
			el.classList.remove( 'hf-is-full' );
			if ( cb ) { cb.disabled = false; }
			if ( seats ) {
				seats.innerHTML = '<span class="hf-badge">' + esc( info.remaining + ' ' + ( S.seats_left || '' ) ) + '</span>';
			}
		}
	}

	function onSubmit( e ) {
		e.preventDefault();
		var form = e.currentTarget;
		var msg = form.querySelector( '.hf-message' );
		var btn = form.querySelector( '.hf-submit' );

		var chosen = form.querySelectorAll( 'input[name="workshops[]"]:checked' );
		if ( chosen.length === 0 ) {
			return showMessage( msg, S.select_one, false );
		}
		var required = form.querySelectorAll( 'input[required]' );
		for ( var i = 0; i < required.length; i++ ) {
			var f = required[ i ];
			if ( f.type === 'checkbox' ) {
				if ( ! f.checked ) {
					return showMessage( msg, f.name === 'consent_privacy' ? S.must_accept_privacy : S.required_field, false );
				}
			} else if ( ! f.value.trim() ) {
				return showMessage( msg, S.required_field, false );
			}
		}

		var fd = new FormData( form );
		fd.append( 'action', 'hf_register' );
		fd.append( 'nonce', DATA.nonce );

		if ( btn ) { btn.disabled = true; }
		showMessage( msg, '…', null );

		fetch( DATA.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
			.then( function ( res ) {
				if ( btn ) { btn.disabled = false; }
				var d = res.body && res.body.data ? res.body.data : {};
				if ( res.body && res.body.success ) {
					var text = d.message || S.success;
					if ( d.failed && d.failed.length ) {
						text += ' (' + d.failed.map( function ( x ) { return x.title + ': ' + x.reason; } ).join( '; ' ) + ')';
					}
					showMessage( msg, text, true );
					form.reset();
					refreshAvailability();
				} else {
					showMessage( msg, ( d && d.message ) || S.error_generic, false );
					refreshAvailability();
				}
			} )
			.catch( function () {
				if ( btn ) { btn.disabled = false; }
				showMessage( msg, S.error_generic, false );
			} );
	}

	function showMessage( el, text, ok ) {
		if ( ! el ) { return; }
		el.textContent = text || '';
		el.classList.remove( 'hf-ok', 'hf-err' );
		if ( ok === true ) { el.classList.add( 'hf-ok' ); }
		else if ( ok === false ) { el.classList.add( 'hf-err' ); }
	}

	function esc( str ) {
		var d = document.createElement( 'div' );
		d.textContent = String( str );
		return d.innerHTML;
	}
} )();
