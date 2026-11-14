( function () {
	'use strict';
	var root = document.getElementById( 'hzb-root' );
	if ( ! root || ! window.HZB ) { return; }

	var key = 'hzb_sid_' + HZB.listingId;
	var sid = '';
	try { sid = sessionStorage.getItem( key ) || ''; } catch ( e ) {}
	var tz = '';
	try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( e ) {}
	var busy = false;
	var loadedAt = Date.now();

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text ) { n.textContent = text; }
		return n;
	}

	/* ---------- build the card ---------- */
	var card = el( 'section', 'hzb-card' );
	card.setAttribute( 'aria-label', 'Book a viewing' );

	var head = el( 'header', 'hzb-head' );
	var avatar = el( 'div', 'hzb-avatar' );
	if ( HZB.agentPhoto ) {
		var img = el( 'img' );
		img.src = HZB.agentPhoto; img.alt = ''; img.loading = 'lazy';
		avatar.appendChild( img );
	} else {
		avatar.textContent = ( HZB.agentName || 'A' ).trim().charAt( 0 ).toUpperCase();
	}
	var who = el( 'div', 'hzb-who' );
	who.appendChild( el( 'div', 'hzb-name', HZB.agentName ) );
	var status = el( 'div', 'hzb-status' );
	status.appendChild( el( 'span', 'hzb-dot' ) );
	status.appendChild( document.createTextNode( 'AI booking assistant · online' ) );
	who.appendChild( status );
	head.appendChild( avatar ); head.appendChild( who );

	var log = el( 'div', 'hzb-log' );
	log.setAttribute( 'aria-live', 'polite' );
	var chips = el( 'div', 'hzb-chips' );

	var form = el( 'form', 'hzb-form' );
	var input = el( 'input', 'hzb-input' );
	input.type = 'text'; input.maxLength = 500; input.autocomplete = 'off';
	input.placeholder = 'Type a message…';
	input.setAttribute( 'aria-label', 'Your message' );
	var send = el( 'button', 'hzb-send' );
	send.type = 'submit';
	send.setAttribute( 'aria-label', 'Send' );
	send.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>';
	form.appendChild( input ); form.appendChild( send );

	// Honeypot: invisible to people, tempting to bots.
	var trap = el( 'input', 'hzb-trap' );
	trap.type = 'text'; trap.name = 'website'; trap.tabIndex = -1; trap.autocomplete = 'off';
	trap.setAttribute( 'aria-hidden', 'true' );
	form.appendChild( trap );

	var note = el( 'p', 'hzb-note', 'AI assistant. Times come from the agent’s live calendar.' );

	card.appendChild( head ); card.appendChild( log ); card.appendChild( chips ); card.appendChild( form ); card.appendChild( note );
	root.appendChild( card );

	/* ---------- helpers ---------- */
	// Text with https:// links as anchors, built from DOM nodes (no innerHTML for message content).
	function fill( node, text ) {
		var re = /(https:\/\/[^\s]+)/g, last = 0, m;
		while ( ( m = re.exec( text ) ) ) {
			node.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
			var url = m[1].replace( /[.,)]+$/, '' );
			var a = el( 'a', '', url );
			a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
			node.appendChild( a );
			last = m.index + url.length;
			re.lastIndex = last;
		}
		node.appendChild( document.createTextNode( text.slice( last ) ) );
	}

	function add( who, text ) {
		var m = el( 'div', 'hzb-msg hzb-' + who );
		fill( m, text );
		log.appendChild( m );
		log.scrollTop = log.scrollHeight;
		return m;
	}

	function setChips( list ) {
		chips.textContent = '';
		( list || [] ).forEach( function ( c ) {
			var b = el( 'button', 'hzb-chip', c.label );
			b.type = 'button';
			b.addEventListener( 'click', function () { submit( c.text, c.label ); } );
			chips.appendChild( b );
		} );
	}

	function typingDots() {
		var m = el( 'div', 'hzb-msg hzb-bot hzb-typing' );
		m.innerHTML = '<i></i><i></i><i></i>';
		log.appendChild( m );
		log.scrollTop = log.scrollHeight;
		return m;
	}

	function submit( text, shown ) {
		text = ( text || '' ).trim();
		if ( ! text || busy ) { return; }
		busy = true; send.disabled = true;
		setChips( [] );
		add( 'me', shown || text );
		var typing = typingDots();
		var ctl = new AbortController();
		var t = setTimeout( function () { ctl.abort(); }, 110000 );
		fetch( HZB.chatUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { session: sid, listing_id: HZB.listingId, message: text, tz: tz, booking_token: HZB.bookingToken, website: trap.value, ms: Date.now() - loadedAt } ),
			signal: ctl.signal
		} ).then( function ( r ) { return r.json(); } ).then( function ( d ) {
			if ( d.session ) {
				sid = d.session;
				try { sessionStorage.setItem( key, sid ); } catch ( e ) {}
			}
			typing.remove();
			add( 'bot', d.reply || 'Sorry, something went wrong. Please try again.' );
			setChips( d.suggestions );
		} ).catch( function () {
			typing.remove();
			add( 'bot', 'Connection problem. Please try again.' );
		} ).then( function () {
			clearTimeout( t );
			busy = false; send.disabled = false;
		} );
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var v = input.value;
		input.value = '';
		submit( v );
	} );

	/* ---------- opening state: the agent introduces itself, with suggestions ---------- */
	add( 'bot', HZB.greeting );
	setChips( HZB.chips );

	/* ---------- placement: Houzez sidebar on desktop, under the description on mobile ---------- */
	function place() {
		root.hidden = false;
		if ( HZB.inline ) { return; }
		var sidebar = document.getElementById( 'sidebar' );
		var desc = document.getElementById( 'property-description-wrap' );
		var mobile = window.matchMedia( '(max-width: 991px)' ).matches;
		if ( sidebar && ! mobile ) {
			sidebar.insertBefore( root, sidebar.firstChild );
		} else if ( desc ) {
			desc.parentNode.insertBefore( root, desc.nextSibling );
		} else if ( sidebar ) {
			sidebar.insertBefore( root, sidebar.firstChild );
		}
	}
	place();
	if ( HZB.bookingToken ) { root.scrollIntoView( { block: 'center' } ); }
}() );
