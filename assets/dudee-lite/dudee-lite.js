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
	var TOUR_KEY = 'aicom-dudee-tour';     // which tour stop to resume at, if a sidebar click navigates away mid-tour
	var PAGE_STEP_KEY = 'aicom-dudee-pagestep'; // mid-page-tour step to resume, if a tab is a real link (full page reload, same ?page=)
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
		// .point overrides .hand's transform-box/-origin: it needs to swing
		// around the avatar's own body centre (view-box coords), not its own
		// bounding box, so --point-angle (set in JS, see place()) actually
		// orients it toward whatever he's circling.
		'.point{transform-box:view-box;transform-origin:50px 53px;transform:rotate(var(--point-angle,0deg))}' +
		'.pointing .point{display:inline;animation:poke .7s ease-in-out infinite alternate}' +
		'.panel{width:min(340px,calc(100vw - 32px));background:rgba(255,255,255,.72);color:#16181d;border:1px solid rgba(230,227,220,.8);border-radius:14px;padding:14px 16px 12px;' +
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
		'@keyframes poke{from{transform:rotate(var(--point-angle,0deg)) translateX(0)}to{transform:rotate(var(--point-angle,0deg)) translateX(-5px)}}' +
		'@keyframes pulse{50%{box-shadow:0 0 0 8px rgba(9,130,253,.08)}}' +
		'@media (prefers-reduced-motion:reduce){.me,.panel,.ring{transition:none}.me svg,.eyes,.hand,.ring{animation:none!important}}';

	/* ---------------------------------------------------------------- the widget ---------------------------------------------------------------- */

	var host, root, me, panel, ring;
	var target = null;  // the element he is pointing at
	var onClose = null; // what closing the panel means for the current message
	var preferAbove = false; // position from above the target, see place()

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
		// Set true only when his message ends up covering the straight line
		// from him to the section's own center (pinned to its left edge,
		// message to his right, inside a section too wide to clear) — his
		// pointing hand should aim around that, not straight through it.
		var pointBelowPanel = false;

		// Never let his message land on top of wp-admin's own sidebar — the
		// usual GAP from the screen edge isn't enough of a boundary there,
		// since the real "nothing to see here" zone ends wherever
		// #wpcontent actually starts (collapsed/expanded, mobile, etc).
		var wpcontent = document.getElementById( 'wpcontent' );
		var minX = Math.max( GAP, wpcontent ? wpcontent.getBoundingClientRect().left : GAP );

		if ( at ) {
			var r = at.getBoundingClientRect();
			ring.hidden = false;
			ring.style.left = ( r.left - 5 ) + 'px';
			ring.style.top = ( r.top - 5 ) + 'px';
			ring.style.width = ( r.width + 10 ) + 'px';
			ring.style.height = ( r.height + 10 ) + 'px';

			// For the placement math below (not the ring, which should
			// still trace the real edges): clamp to the visible viewport
			// first. A very tall section (e.g. a long table) can have real
			// edges far off-screen, where "space above/below" would be
			// meaningless negative numbers and every fallback would
			// collapse onto the same corner as him.
			var top = clamp( r.top, GAP, vh - GAP );
			var bottom = clamp( r.bottom, GAP, vh - GAP );

			if ( preferAbove ) {
				// Sections: he sits left-aligned to whatever he's circling,
				// so the section itself extends off to his right — his
				// message going there would sit right on top of the very
				// thing he's showing. Priority: above (reads best for a
				// card) → beside him, left (away from the section, nothing
				// to cover) → beside him, right, only if the left truly has
				// no room → stacked under/over as the very last resort.
				// "Beside" here means beside HIM (his own 56px width), not
				// beside the whole section — a wide card (a long table,
				// say) can span nearly the full viewport with no room past
				// either of ITS edges, even though there's plenty past his.
				ax = clamp( r.left, minX, vw - A - GAP );
				if ( top - 10 - A - 8 - ph >= GAP ) {
					px = clamp( r.left, minX, vw - pw - GAP );
					ay = top - 10 - A;
					py = ay - 8 - ph;
				} else if ( ax - 10 - pw >= minX ) {
					ay = clamp( top + ( bottom - top ) / 2 - A / 2, GAP, vh - A - GAP );
					px = ax - 10 - pw;
					py = clamp( ay - 6, GAP, vh - ph - GAP );
				} else if ( ax + A + 10 >= r.right && ax + A + 10 + pw <= vw - GAP ) {
					// Beside him, right — only when that lands past the
					// section's own right edge. Pinned to its left edge, he
					// sits inside it; a wide section (most of them) would
					// have his message start well short of getting clear,
					// covering its content instead of standing beside it.
					ay = clamp( top + ( bottom - top ) / 2 - A / 2, GAP, vh - A - GAP );
					px = ax + A + 10;
					py = clamp( ay - 6, GAP, vh - ph - GAP );
				} else {
					// Last resort: under it, or over it when that fits
					// better — never both independently clamped against the
					// viewport (two separate clamps can each legally land
					// on GAP and put the panel exactly on top of him
					// instead of above or below him); py is always derived
					// from the already-placed ay.
					px = clamp( r.left, minX, vw - pw - GAP );
					if ( bottom + 10 + A + 8 + ph <= vh - GAP || vh - bottom >= top ) {
						// Under: the second half of that condition is only a
						// "more room below than above" preference, not proof
						// the full stack actually fits below `bottom` — so
						// py still needs its own clamp here, with ay then
						// re-derived from it (not independently clamped)
						// so a cramped fit never pushes the panel off the
						// bottom of the screen.
						ay = clamp( bottom + 10, GAP, vh - A - GAP );
						py = clamp( ay + A + 8, GAP, vh - ph - GAP );
						ay = py - A - 8;
					} else if ( ax + A + 10 + pw <= vw - GAP ) {
						// Under doesn't fit either, and that only happens
						// when the section is too close to the top for
						// "over" to have room — cramming his message
						// against the very top of the screen, over the
						// page's own title, looks worse than beside him,
						// even though that covers part of the section here.
						ay = clamp( top + ( bottom - top ) / 2 - A / 2, GAP, vh - A - GAP );
						px = ax + A + 10;
						py = clamp( ay - 6, GAP, vh - ph - GAP );
						pointBelowPanel = true;
					} else {
						// Over: truly nowhere else left. py is derived
						// first and clamped to fit, then ay is placed below
						// it — never the other way around, or a tall panel
						// that doesn't fit above can get its py clamped to
						// the screen edge while ay stays put, overlapping
						// him with his own message.
						py = clamp( top - 10 - A - 8 - ph, GAP, vh - ph - GAP );
						ay = Math.min( py + ph + 8, vh - A - GAP );
					}
				}
			} else if ( r.right + 14 + A + 10 + pw <= vw - GAP ) {
				// Tabs etc: beside the real target edges (tight to it, e.g.
				// a narrow tab button) — right first, then left, then
				// stacked under/over as the last resort.
				ax = r.right + 14;
				ay = clamp( top + ( bottom - top ) / 2 - A / 2, GAP, vh - A - GAP );
				px = ax + A + 10;
				py = clamp( ay - 6, GAP, vh - ph - GAP );
			} else if ( r.left - 14 - A - 10 - pw >= minX ) {
				ax = r.left - 14 - A;
				ay = clamp( top + ( bottom - top ) / 2 - A / 2, GAP, vh - A - GAP );
				px = ax - 10 - pw;
				py = clamp( ay - 6, GAP, vh - ph - GAP );
			} else {
				ax = clamp( r.left, minX, vw - A - GAP );
				px = clamp( r.left, minX, vw - pw - GAP );
				if ( bottom + 10 + A + 8 + ph <= vh - GAP || vh - bottom >= top ) {
					ay = clamp( bottom + 10, GAP, vh - A - GAP );
					py = clamp( ay + A + 8, GAP, vh - ph - GAP );
					ay = py - A - 8;
				} else {
					py = clamp( top - 10 - A - 8 - ph, GAP, vh - ph - GAP );
					ay = Math.min( py + ph + 8, vh - A - GAP );
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
		if ( at ) {
			// Orient his pointing hand toward the actual target, wherever it
			// ended up relative to him (above, beside, below...), instead of
			// it always poking the same fixed direction. 0deg = its native
			// unrotated pose (left) — see the .point CSS rule.
			var acx = ax + A / 2, acy = ay + A / 2;
			var tcx = r.left + r.width / 2;
			var tcy = pointBelowPanel ? clamp( py + ph + 16, r.top, r.bottom ) : r.top + r.height / 2;
			var angle = Math.atan2( tcy - acy, tcx - acx ) * 180 / Math.PI;
			me.style.setProperty( '--point-angle', ( angle - 180 ) + 'deg' );
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
	 * Say something. o: { text, at (element to show), above (position from above it instead of beside/under), step: [i, n],
	 * buttons: [[label, fn, primary]], links: [[label, fn]], close (what the × means; default: just close), happy, wave,
	 * focus (move focus to the first button: only when asked) }.
	 */
	function say( o ) {
		mount();
		target = o.at || null;
		onClose = o.close || null;
		preferAbove = !! o.above;
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
		var buttons = [ [ T.tourYes, function () { tour( 0 ); }, true ] ];
		buttons.push( connected ? [ T.menuOpen, openApp ] : [ T.menuConnect, connect ] );
		buttons.push( [ T.notNow, quiet ] );
		say( {
			text: T.greet,
			wave: true,
			happy: true,
			buttons: buttons,
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

	/** Ends the whole tour (cross-page stops and any in-page walkthrough).
	 * The only thing that stops it short of its natural end — everything
	 * else (finishing a page's steps, navigating between stops) continues
	 * on to the next part automatically. */
	function stopTour() {
		session.del( TOUR_KEY );
		save( 'tour_stopped' );
		quiet();
	}

	/** The tour: one stop per item of AICOM's own menu, ending at AICOMBase. */
	function tour( i ) {
		var stops = TOUR.filter( function ( s ) { return menuLink( s ); } );
		// Skip any stop that IS the current page — there's nowhere to
		// navigate to there, we're already on it. Without this, "Next" on
		// the very first stop (shown while already on the Dashboard, which
		// is stops[0]) would just reload the same page instead of actually
		// moving on.
		while ( i < stops.length && stops[ i ] === C.page ) {
			i++;
		}
		var n = stops.length + 1;
		if ( i < stops.length ) {
			// The stop he's pointing at is a real sidebar link. "Next" (like
			// clicking that link directly, or just refreshing) reloads the
			// browser — remember this same step, and which page it was shown
			// on, so the next page load picks up right here: resumes on this
			// same stop if nothing actually changed page (refresh), or flows
			// into that page's own walkthrough if it really navigated. Pages
			// and tabs behave the same way on purpose — no discrepancy
			// between "Next" moving within a page vs. across pages.
			session.set( TOUR_KEY, i + '|' + C.page );
			var link = menuLink( stops[ i ] );
			say( {
				text: T.tour[ stops[ i ] ],
				at: link,
				step: [ i + 1, n ],
				buttons: [ [ T.next, function () { window.location.assign( link.href ); }, true ] ],
				links: [ [ T.stop, stopTour ] ],
				close: stopTour,
			} );
			return;
		}
		session.del( TOUR_KEY );
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

	/** Finds what to circle for a page-tour step: a tab (by its button label,
	 * e.g. "Generate New Key", which has no section heading of its own), or
	 * a whole section by its heading/card title text — never just the
	 * heading alone, so the ring wraps everything in it. */
	function pageSpot( text ) {
		var scope = document.getElementById( 'wpbody-content' ) || document;

		// Sections first: a tab's own label and its section's heading are
		// often the exact same text (e.g. "Connection Info" names both),
		// and the section — the bigger, more informative target — should
		// win that match. Tabs are only the fallback, for the ones with no
		// section heading of their own (e.g. "Generate New Key").
		var candidates = scope.querySelectorAll( 'h1, h2, h3, .aicom-card-title' );
		for ( var k = 0; k < candidates.length; k++ ) {
			if ( candidates[ k ].textContent.trim() === text ) {
				return revealTab( sectionOf( candidates[ k ] ) );
			}
		}

		// Tabs come in two flavours: JS buttons that swap content in place
		// (API Keys), and plain links that reload the page with a different
		// ?tab= (Snapshots). Only the button kind is safe to click here —
		// clicking a real link navigates away immediately, which pageTour()
		// must set up a resume for first, not just fire blindly.
		var tabs = scope.querySelectorAll( '.aicom-tab-btn' );
		for ( var t = 0; t < tabs.length; t++ ) {
			if ( tabs[ t ].textContent.trim() === text ) {
				if ( tabs[ t ].tagName === 'BUTTON' ) {
					tabs[ t ].click();
				}
				return tabs[ t ];
			}
		}
		return null;
	}

	/** The whole visual section a heading belongs to (its card, or
	 * equivalent wrapper), so the ring can circle everything in it instead
	 * of just the title text. Falls back to the heading itself if it isn't
	 * inside a recognisable wrapper. */
	function sectionOf( heading ) {
		if ( ! heading.closest ) {
			return heading;
		}
		return heading.closest( '.aicom-card' ) || heading.closest( '.aicom-help-recipe-step' ) || heading.closest( 'section' ) || heading;
	}

	/** If el lives inside an inactive tab panel (API Keys' Generate/Connection
	 * Info/Existing Keys tabs), switch to that tab first so he can actually
	 * point at it. No-op for anything not inside a tabbed page. */
	function revealTab( el ) {
		var panel = el.closest && el.closest( '.aicom-tab-panel' );
		if ( ! panel || panel.classList.contains( 'is-active' ) ) {
			return el;
		}
		var tab = panel.id.replace( 'aicom-tab-', '' );
		var btn = document.querySelector( '.aicom-tab-btn[data-tab="' + tab + '"]' );
		if ( btn ) {
			btn.click();
		}
		return el;
	}

	/** Steps through this page's own sections, one at a time. After the last
	 * one, "Next" leads on to the next cross-page tour stop (points him at
	 * the next sidebar link) instead of stopping — the tour only ends when
	 * the admin explicitly stops it. tourIndex is this page's own position
	 * in the cross-page TOUR, so that handoff knows where to continue. */
	function pageTour( steps, j, tourIndex ) {
		var last = j >= steps.length - 1;
		var el = pageSpot( steps[ j ].at );

		// A real <a href> tab (Snapshots' Dashboard/Cleanup Settings/Backup
		// Snapshots tabs reload the page instead of swapping via JS) needs
		// an actual navigation, not a click-and-hope — remember which step
		// to resume at once we're back, same idea as the cross-page tour's
		// own TOUR_KEY, so he picks up exactly here instead of just
		// vanishing when the page reloads out from under him.
		if ( el && el.tagName === 'A' && el.getAttribute( 'href' ) ) {
			session.set( PAGE_STEP_KEY, j + '|' + tourIndex + '|' + C.page );
			window.location.assign( el.href );
			return;
		}

		// Tabs are thin targets in a single bar — "beside" (the default)
		// suits them. Sections are wide cards — present from above those.
		var isTab = el && el.classList && el.classList.contains( 'aicom-tab-btn' );
		say( {
			text: steps[ j ].text,
			at: el,
			above: ! isTab,
			step: [ j + 1, steps.length ],
			buttons: [ [ T.next, last ? function () { tour( tourIndex + 1 ); } : function () { pageTour( steps, j + 1, tourIndex ); }, true ] ],
			links: [ [ T.stop, stopTour ] ],
			close: stopTour,
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
		// Resume a mid-page-tour step after a real-link tab reload (see
		// pageTour()'s PAGE_STEP_KEY comment) — checked first since it's
		// more specific than the cross-page TOUR_KEY below, and both could
		// in principle be set at once.
		var pageResume = session.get( PAGE_STEP_KEY );
		if ( pageResume !== null ) {
			session.del( PAGE_STEP_KEY );
			var pParts = pageResume.split( '|' );
			var pStep = parseInt( pParts[ 0 ], 10 ) || 0;
			var pTourIndex = parseInt( pParts[ 1 ], 10 ) || 0;
			var pPage = pParts[ 2 ] || '';
			var pSteps = T.page && T.page[ pPage ];
			if ( pPage === C.page && pSteps && pStep < pSteps.length ) {
				pageTour( pSteps, pStep, pTourIndex );
				return;
			}
		}
		// Resume an in-progress tour at the same step if the page reloaded
		// (refresh, or the admin clicked the real sidebar link Dudee was
		// pointing at instead of his "Next" button) — see tour()'s comment
		// on TOUR_KEY. A plain refresh (same page) just resumes the same
		// stop; actually arriving at a new page flows straight into that
		// page's own walkthrough, if it has one.
		var resume = session.get( TOUR_KEY );
		if ( resume !== null ) {
			session.del( TOUR_KEY );
			var parts = resume.split( '|' );
			var i = parseInt( parts[ 0 ], 10 ) || 0;
			var shownOn = parts[ 1 ] || '';
			var stops = TOUR.filter( function ( s ) { return menuLink( s ); } );
			if ( i <= stops.length ) {
				var pageSteps = T.page && T.page[ C.page ];
				if ( shownOn !== C.page && pageSteps && pageSteps.length ) {
					pageTour( pageSteps, 0, i );
				} else {
					tour( i );
				}
				return;
			}
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
