/*!
 * Dudee lite for AICOM. GPL-2.0-or-later, like the rest of the plugin.
 *
 * A small guide that lives only on AICOM's own admin pages (includes/class-dudee-lite.php decides where).
 * He says hello once, offers a short tour of the AICOM menu, and on the AICOMBase page shows how to connect.
 * Everything runs here, in the browser. The only request goes to this site's admin-ajax.php, to remember his
 * own state for the current user (said hello, tour finished, hidden).
 */
( function () {
	'use strict';

	var C = window.AICOM_DUDEE;
	if ( ! C || ! document.body || ! document.body.attachShadow ) {
		return;
	}
	var T = C.t;
	var RUN_KEY = 'aicom-dudee-run';       // a flow to pick up after a page load: "connect"
	var SNOOZE_KEY = 'aicom-dudee-snooze'; // "Not now" on the AICOMBase reminder: quiet until the browser closes
	var PAIRING_KEY = 'aicom-dudee-pairing'; // this tab saw "waiting for confirmation": a connection now is theirs to celebrate
	var BASE = 'aicom-base';
	var TOUR = [ 'aicom', 'aicom-api-keys', 'aicom-audit-logs', 'aicom-safety', 'aicom-backups', 'aicom-help' ];
	var A = 56; // avatar size, px
	var GAP = 16;

	var session = {
		get: function ( k ) { try { return sessionStorage.getItem( k ); } catch ( e ) { return null; } },
		set: function ( k, v ) { try { sessionStorage.setItem( k, v ); } catch ( e ) { /* private mode */ } },
		del: function ( k ) { try { sessionStorage.removeItem( k ); } catch ( e ) { /* private mode */ } },
	};

	function save( op ) {
		var fd = new FormData();
		fd.append( 'action', 'aicom_dudee_lite' );
		fd.append( 'nonce', C.nonce );
		fd.append( 'op', op );
		return fetch( C.ajax, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { if ( j && j.success ) { C.state = j.data; } return j; } )
			.catch( function () { return null; } );
	}

	var connected = C.base.status === 'connected';
	var unpaired = C.base.status === 'none' || C.base.status === 'revoked';

	/* ---------------------------------------------------------------- drawing ---------------------------------------------------------------- */

	var SVG =
		'<svg viewBox="-14 -4 128 108" aria-hidden="true" focusable="false">' +
		'<g class="hand wave"><circle cx="101" cy="42" r="10" fill="#0982fd" stroke="#0670dd" stroke-width="2"/></g>' +
		'<g class="hand point"><circle cx="-1" cy="58" r="10" fill="#0982fd" stroke="#0670dd" stroke-width="2"/></g>' +
		'<path d="M50 10C77 10 94 30 94 55C94 79 75 96 50 96C25 96 6 80 6 56C6 31 24 10 50 10Z" fill="#0982fd"/>' +
		'<ellipse cx="73" cy="24" rx="8" ry="5" transform="rotate(38 73 24)" fill="#fff"/>' +
		'<circle cx="83" cy="37" r="3" fill="#fff"/>' +
		'<rect x="19" y="45" width="62" height="31" rx="15.5" fill="#02287f"/>' +
		'<g class="eyes" fill="#fff"><rect x="33" y="52" width="9" height="16" rx="4.5"/><rect x="58" y="52" width="9" height="16" rx="4.5"/></g>' +
		'<g class="joy" fill="none" stroke="#fff" stroke-width="4.5" stroke-linecap="round"><path d="M32 64q5.5-8 11 0"/><path d="M57 64q5.5-8 11 0"/></g>' +
		'</svg>';

	var CSS =
		':host{all:initial}' +
		'*{box-sizing:border-box}' +
		'.me,.panel,.ring{position:fixed;z-index:99990}' +
		'.me{width:' + A + 'px;height:' + A + 'px;padding:0;border:0;background:none;cursor:pointer;left:0;top:0;transition:left .5s cubic-bezier(.3,1.2,.5,1),top .5s cubic-bezier(.3,1.2,.5,1);filter:drop-shadow(0 4px 10px rgba(9,40,127,.25))}' +
		'.me:focus-visible{outline:2px solid #0982fd;outline-offset:3px;border-radius:50%}' +
		'.me svg{width:100%;height:100%;overflow:visible;animation:bob 4s ease-in-out infinite}' +
		'.eyes{transform-box:fill-box;transform-origin:center;animation:blink 5s infinite}' +
		'.joy{display:none}.happy .eyes{display:none}.happy .joy{display:inline}' +
		'.hand{display:none;transform-box:fill-box;transform-origin:center}' +
		'.waving .wave{display:inline;animation:wave .5s ease-in-out 4 alternate}' +
		'.pointing .point{display:inline;animation:poke .7s ease-in-out infinite alternate}' +
		'.panel{width:min(340px,calc(100vw - 32px));background:#fff;color:#16181d;border:1px solid #e6e3dc;border-radius:14px;padding:14px 16px 12px;' +
		'box-shadow:0 10px 30px rgba(22,24,29,.14),0 2px 8px rgba(22,24,29,.06);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;' +
		'opacity:0;visibility:hidden;transform:translateY(4px);transition:opacity .2s,transform .2s,visibility .2s;pointer-events:none}' +
		'.panel.on{opacity:1;visibility:visible;transform:none;pointer-events:auto}' +
		'.who{display:flex;justify-content:space-between;align-items:center;margin:0 0 4px;font-size:12px;font-weight:600;color:#0670dd;letter-spacing:.02em}' +
		'.who .r{display:flex;align-items:center;gap:8px;font-weight:500;color:#6b6f78}' +
		'.x{all:unset;cursor:pointer;color:#6b6f78;font-size:18px;line-height:1;padding:0 2px}.x:hover{color:#16181d}.x:focus-visible{outline:2px solid #0982fd;border-radius:4px}' +
		'.msg{margin:0 0 12px;white-space:pre-line}' +
		'.btns{display:flex;flex-wrap:wrap;gap:8px}' +
		'.btns button{all:unset;cursor:pointer;padding:7px 13px;border-radius:8px;font-size:13px;font-weight:600;background:#f1ede4;color:#16181d}' +
		'.btns button:hover{background:#e6e3dc}' +
		'.btns button.primary{background:#0982fd;color:#fff}.btns button.primary:hover{background:#0670dd}' +
		'.btns button:focus-visible{outline:2px solid #0982fd;outline-offset:2px}' +
		'.foot{margin-top:10px}.foot:empty{display:none}' +
		'.foot button{all:unset;cursor:pointer;font-size:12px;color:#6b6f78;text-decoration:underline}.foot button:hover{color:#16181d}' +
		'.ring{pointer-events:none;border:2px solid #0982fd;border-radius:8px;box-shadow:0 0 0 4px rgba(9,130,253,.18);animation:pulse 1.6s ease-in-out infinite}' +
		'@keyframes bob{50%{transform:translateY(-3px)}}' +
		'@keyframes blink{0%,95%,100%{transform:scaleY(1)}97%{transform:scaleY(.1)}}' +
		'@keyframes wave{from{transform:rotate(-20deg) translateY(2px)}to{transform:rotate(25deg) translateY(-4px)}}' +
		'@keyframes poke{from{transform:translateX(0)}to{transform:translateX(-5px)}}' +
		'@keyframes pulse{50%{box-shadow:0 0 0 8px rgba(9,130,253,.08)}}' +
		'@media (prefers-reduced-motion:reduce){.me,.panel,.ring{transition:none}.me svg,.eyes,.hand,.ring{animation:none!important}}';

	/* ---------------------------------------------------------------- the widget ---------------------------------------------------------------- */

	var host, root, me, panel, ring;
	var target = null;  // the element he is pointing at
	var onClose = null; // what closing the panel means for the current message

	function mount() {
		if ( host ) {
			return;
		}
		host = document.createElement( 'div' );
		host.id = 'aicom-dudee';
		document.body.appendChild( host );
		root = host.attachShadow( { mode: 'open' } );
		root.innerHTML = '<style>' + CSS + '</style><div class="ring" hidden></div>' +
			'<div class="panel" role="dialog" aria-live="polite"><p class="who"></p><p class="msg"></p><div class="btns"></div><div class="foot"></div></div>' +
			'<button type="button" class="me">' + SVG + '</button>';
		me = root.querySelector( '.me' );
		panel = root.querySelector( '.panel' );
		ring = root.querySelector( '.ring' );
		me.setAttribute( 'aria-label', T.open );
		me.title = T.open;
		me.addEventListener( 'click', function () {
			if ( panel.classList.contains( 'on' ) ) {
				close();
			} else {
				menu( true );
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && panel.classList.contains( 'on' ) ) {
				close();
			}
		} );
		var queued = false;
		var again = function () {
			if ( ! queued ) {
				queued = true;
				requestAnimationFrame( function () { queued = false; place(); } );
			}
		};
		window.addEventListener( 'resize', again );
		window.addEventListener( 'scroll', again, true );
		place();
	}

	function unmount() {
		if ( host ) {
			host.remove();
		}
		host = root = me = panel = ring = target = null;
	}

	function visible( el ) {
		if ( ! el || ! el.isConnected ) {
			return false;
		}
		var r = el.getBoundingClientRect();
		return r.width > 0 && r.height > 0 && getComputedStyle( el ).visibility !== 'hidden';
	}

	function clamp( v, lo, hi ) {
		return Math.max( lo, Math.min( hi, v ) );
	}

	/** Where he stands: in the corner, or next to what he is showing, with his message beside him. */
	function place() {
		if ( ! me ) {
			return;
		}
		var vw = document.documentElement.clientWidth, vh = window.innerHeight;
		var pw = panel.offsetWidth, ph = panel.offsetHeight;
		var ax, ay, px, py;
		var at = visible( target ) ? target : null;

		if ( at ) {
			var r = at.getBoundingClientRect();
			ring.hidden = false;
			ring.style.left = ( r.left - 5 ) + 'px';
			ring.style.top = ( r.top - 5 ) + 'px';
			ring.style.width = ( r.width + 10 ) + 'px';
			ring.style.height = ( r.height + 10 ) + 'px';
			if ( r.right + 14 + A + 10 + pw <= vw - GAP ) {
				// Beside it, his message to his right.
				ax = r.right + 14;
				ay = clamp( r.top + r.height / 2 - A / 2, GAP, vh - A - GAP );
				px = ax + A + 10;
				py = clamp( ay - 6, GAP, vh - ph - GAP );
			} else {
				// Under it (or over it, near the bottom), his message under (over) him.
				ax = clamp( r.left, GAP, vw - A - GAP );
				px = clamp( r.left, GAP, vw - pw - GAP );
				if ( r.bottom + 10 + A + 8 + ph <= vh - GAP ) {
					ay = r.bottom + 10;
					py = ay + A + 8;
				} else {
					ay = clamp( r.top - 10 - A, GAP, vh - A - GAP );
					py = clamp( ay - 8 - ph, GAP, vh - ph - GAP );
				}
			}
		} else {
			ring.hidden = true;
			ax = vw - A - 20;
			ay = vh - A - 20;
			if ( ax - 10 - pw >= GAP ) {
				px = ax - 10 - pw;
				py = clamp( ay + A - ph, GAP, vh - ph - GAP );
			} else {
				px = vw - pw - GAP;
				py = clamp( ay - 10 - ph, GAP, vh - ph - GAP );
			}
		}
		me.style.left = ax + 'px';
		me.style.top = ay + 'px';
		me.classList.toggle( 'pointing', !! at );
		panel.style.left = px + 'px';
		panel.style.top = py + 'px';
	}

	function button( label, fn, primary ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.textContent = label;
		if ( primary ) {
			b.className = 'primary';
		}
		b.addEventListener( 'click', fn );
		return b;
	}

	/**
	 * Say something. o: { text, at (element to show), step: [i, n], buttons: [[label, fn, primary]], links: [[label, fn]],
	 * close (what the × means; default: just close), happy, wave, focus (move focus to the first button: only when asked) }.
	 */
	function say( o ) {
		mount();
		target = o.at || null;
		onClose = o.close || null;
		var who = root.querySelector( '.who' );
		var right = document.createElement( 'span' );
		right.className = 'r';
		if ( o.step ) {
			right.textContent = T.stepOf.replace( '%1$d', o.step[ 0 ] ).replace( '%2$d', o.step[ 1 ] );
		}
		var x = button( '×', close );
		x.className = 'x';
		x.setAttribute( 'aria-label', T.close );
		right.appendChild( x );
		who.textContent = T.name;
		who.appendChild( right );
		root.querySelector( '.msg' ).textContent = o.text;
		var btns = root.querySelector( '.btns' );
		var foot = root.querySelector( '.foot' );
		btns.textContent = '';
		foot.textContent = '';
		( o.buttons || [] ).forEach( function ( b ) { btns.appendChild( button( b[ 0 ], b[ 1 ], b[ 2 ] ) ); } );
		( o.links || [] ).forEach( function ( l ) { foot.appendChild( button( l[ 0 ], l[ 1 ] ) ); } );
		me.classList.toggle( 'happy', !! o.happy );
		me.classList.remove( 'waving' );
		if ( o.wave ) {
			void me.offsetWidth; // restart the wave
			me.classList.add( 'waving' );
		}
		if ( target && ! visible( target ) && target.scrollIntoView ) {
			target.scrollIntoView( { block: 'center' } );
		} else if ( target ) {
			var r = target.getBoundingClientRect();
			if ( r.top < 0 || r.bottom > window.innerHeight ) {
				target.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			}
		}
		panel.classList.remove( 'on' );
		place();
		requestAnimationFrame( function () {
			place();
			panel.classList.add( 'on' );
			if ( o.focus ) {
				var first = btns.querySelector( 'button' );
				if ( first ) {
					first.focus();
				}
			}
		} );
	}

	/** Back to his corner, message closed. */
	function quiet() {
		if ( ! panel ) {
			return;
		}
		panel.classList.remove( 'on' );
		me.classList.remove( 'happy', 'waving' );
		target = null;
		onClose = null;
		place();
	}

	function close() {
		var fn = onClose;
		quiet();
		if ( fn ) {
			fn();
		}
	}

	function hide() {
		save( 'hide' );
		say( { text: T.hidden } );
		setTimeout( unmount, 3500 );
	}

	/* ---------------------------------------------------------------- what he does ---------------------------------------------------------------- */

	function greet() {
		save( 'greeted' );
		say( {
			text: T.greet,
			wave: true,
			happy: true,
			buttons: [ [ T.tourYes, function () { tour( 0 ); }, true ], [ T.notNow, quiet ] ],
			links: [ [ T.never, hide ] ],
		} );
	}

	function menu( focus ) {
		var buttons = [ [ T.menuTour, function () { tour( 0 ); }, true ] ];
		if ( connected ) {
			buttons.push( [ T.menuOpen, openApp ] );
		} else {
			buttons.push( [ T.menuConnect, connect ] );
			buttons.push( [ T.menuWhy, why ] );
		}
		say( { text: T.menu, buttons: buttons, links: [ [ T.never, hide ] ], focus: focus } );
	}

	function menuLink( slug ) {
		return document.querySelector( '.aicom-sidenav-menu a[href$="page=' + slug + '"]' );
	}

	/** The tour: one stop per item of AICOM's own menu, ending at AICOMBase. */
	function tour( i ) {
		var stops = TOUR.filter( function ( s ) { return menuLink( s ); } );
		var n = stops.length + 1;
		var stopTour = function () { save( 'tour_stopped' ); quiet(); };
		if ( i < stops.length ) {
			say( {
				text: T.tour[ stops[ i ] ],
				at: menuLink( stops[ i ] ),
				step: [ i + 1, n ],
				buttons: [ [ T.next, function () { tour( i + 1 ); }, true ] ],
				links: [ [ T.stop, stopTour ] ],
				close: stopTour,
			} );
			return;
		}
		var done = function ( fn ) { return function () { save( 'tour_done' ); fn(); }; };
		say( connected ? {
			text: T.pitchDone,
			at: menuLink( BASE ),
			step: [ n, n ],
			happy: true,
			buttons: [ [ T.menuOpen, done( openApp ), true ], [ T.thanks, done( quiet ) ] ],
			close: done( function () {} ),
		} : {
			text: T.pitch + '\n\n' + T.pitchAsk,
			at: menuLink( BASE ),
			step: [ n, n ],
			happy: true,
			buttons: [ [ T.showHow, done( connect ), true ], [ T.later, done( quiet ) ] ],
			close: done( function () {} ),
		} );
	}

	function why() {
		say( {
			text: T.pitch + '\n\n' + T.pitchAsk,
			happy: true,
			buttons: [ [ T.showHow, connect, true ], [ T.later, snooze ] ],
			close: snooze,
		} );
	}

	function snooze() {
		session.set( SNOOZE_KEY, '1' );
		quiet();
	}

	function openApp() {
		quiet();
		window.open( C.base.app, '_blank', 'noopener' );
	}

	/** How to connect: from any page he first takes them to AICOMBase's page, then shows the step they are at. */
	function connect() {
		if ( C.page !== BASE ) {
			session.set( RUN_KEY, 'connect' );
			window.location.assign( C.base.page );
			return;
		}
		connectStep( true );
	}

	function connectStep( asked ) {
		if ( unpaired ) {
			var btn = document.getElementById( 'aicom-base-connect' );
			if ( btn ) {
				// After Connect the page reloads into "waiting for confirmation": he carries on there.
				btn.addEventListener( 'click', function () { session.set( RUN_KEY, 'connect' ); quiet(); }, { once: true } );
			}
			say( { text: T.connect1, at: btn, buttons: [ [ T.gotIt, quiet, true ] ], focus: asked } );
		} else if ( C.base.status === 'pairing' ) {
			say( { text: T.connect2, at: document.getElementById( 'aicom-base-open' ), buttons: [ [ T.gotIt, quiet, true ] ], focus: asked } );
		} else if ( connected ) {
			say( {
				text: T.connected,
				happy: true,
				wave: true,
				buttons: [ [ T.menuOpen, openApp, true ], [ T.thanks, quiet ] ],
			} );
		}
	}

	/** On the AICOMBase page, while the site is not connected: a reminder, and the way through it. */
	function remind() {
		say( {
			text: T.remind,
			at: document.getElementById( 'aicom-base-connect' ),
			buttons: [ [ T.showHow, function () { connectStep( true ); }, true ], [ T.whatGet, why ], [ T.notNow, snooze ] ],
			close: snooze,
		} );
	}

	/* ---------------------------------------------------------------- start ---------------------------------------------------------------- */

	// "Show Dudee again" (Help, the AICOMBase page) works even while he is hidden.
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest && e.target.closest( '[data-aicom-dudee-show]' );
		if ( ! a ) {
			return;
		}
		e.preventDefault();
		save( 'show' ).then( function () {
			document.querySelectorAll( '[data-aicom-dudee-show]' ).forEach( function ( l ) { l.remove(); } );
			C.state.hidden = false;
			if ( C.page === BASE && ! connected ) {
				connectStep( true );
			} else {
				menu( true );
			}
		} );
	} );

	function start() {
		if ( C.state.hidden ) {
			return;
		}
		if ( C.page === BASE ) {
			var run = session.get( RUN_KEY );
			session.del( RUN_KEY );
			if ( C.base.status === 'pairing' ) {
				session.set( PAIRING_KEY, '1' );
			}
			// Confirmed in AICOMBase: the waiting page came back connected (?base_msg=synced, which "Sync now" also uses).
			if ( connected && C.base.just && session.get( PAIRING_KEY ) ) {
				session.del( PAIRING_KEY );
				connectStep( false );
				return;
			}
			// Carrying on with "Show me how", or reminding them while the site is not connected.
			if ( ! connected && ( run || ( C.state.greeted && ! session.get( SNOOZE_KEY ) ) ) ) {
				if ( unpaired && ! run ) {
					remind();
				} else {
					connectStep( false );
				}
				return;
			}
		}
		if ( ! C.state.greeted ) {
			greet();
			return;
		}
		mount();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
