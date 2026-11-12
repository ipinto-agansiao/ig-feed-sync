/**
 * IG Feed Sync: lightbox das publicações (vanilla JS, sem dependências).
 * Imagens e carrosséis abrem no site; vídeos abrem no embed do Instagram, que só carrega
 * com consentimento de marketing (cookies da Meta), e há sempre o link "Ver no Instagram".
 */
( function () {
	'use strict';

	var dialog, els, feed, index, slide, opener;

	function el( tag, cls, attrs ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		return node;
	}

	// Consentimento para o embed do Instagram. Um site sem Complianz pode definir window.igfsConsent().
	function consent() {
		try {
			if ( typeof window.igfsConsent === 'function' ) {
				return !! window.igfsConsent();
			}
			if ( typeof window.cmplz_has_consent === 'function' ) {
				return !! window.cmplz_has_consent( 'marketing' );
			}
		} catch ( e ) {}
		return true;
	}

	function build( t ) {
		dialog = el( 'dialog', 'igfs-lb', { 'aria-label': t.label, 'data-lenis-prevent': '', tabindex: '-1' } );
		els = {
			close: el( 'button', 'igfs-lb__close', { type: 'button', 'aria-label': t.close } ),
			prev: el( 'button', 'igfs-lb__nav igfs-lb__nav--prev', { type: 'button', 'aria-label': t.prev } ),
			next: el( 'button', 'igfs-lb__nav igfs-lb__nav--next', { type: 'button', 'aria-label': t.next } ),
			box: el( 'div', 'igfs-lb__box' ),
			media: el( 'div', 'igfs-lb__media' ),
			side: el( 'div', 'igfs-lb__side' ),
			head: el( 'div', 'igfs-lb__head' ),
			caption: el( 'p', 'igfs-lb__caption' ),
			date: el( 'p', 'igfs-lb__date' ),
			link: el( 'a', 'igfs-lb__link', { target: '_blank', rel: 'noopener' } ),
			// Anuncia ao leitor de ecrã a publicação e a imagem atuais.
			status: el( 'p', 'igfs-lb__status', { 'aria-live': 'polite' } )
		};
		// Ícone desenhado (o "×" de texto fica descentrado consoante a fonte).
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.innerHTML = '<path d="M5 5l14 14M19 5L5 19"/>';
		els.close.appendChild( svg );
		els.prev.textContent = '‹';
		els.next.textContent = '›';
		els.side.append( els.head, els.caption, els.date, els.link, els.status );
		els.box.append( els.media, els.side, els.close ); // O × fica dentro da caixa, junto à publicação.
		dialog.append( els.prev, els.next, els.box );
		document.body.appendChild( dialog );

		els.close.addEventListener( 'click', function () {
			dialog.close();
		} );
		els.prev.addEventListener( 'click', function () {
			show( index - 1 );
		} );
		els.next.addEventListener( 'click', function () {
			show( index + 1 );
		} );
		// Clique fora da caixa fecha.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				dialog.close();
			}
		} );
		// Setas do teclado: como o deslizar (primeiro as imagens do carrossel, depois a publicação).
		dialog.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowLeft' === e.key ) {
				step( -1 );
			} else if ( 'ArrowRight' === e.key ) {
				step( 1 );
			}
		} );
		dialog.addEventListener( 'close', function () {
			els.media.textContent = ''; // Pára o vídeo.
			document.documentElement.classList.remove( 'igfs-lb-open' );
			if ( opener ) {
				opener.focus();
			}
		} );
		// Deslizar no telemóvel muda de imagem (ou de publicação).
		var startX = null;
		els.media.addEventListener( 'touchstart', function ( e ) {
			startX = e.touches[ 0 ].clientX;
		}, { passive: true } );
		els.media.addEventListener( 'touchend', function ( e ) {
			if ( null === startX ) {
				return;
			}
			var dx = e.changedTouches[ 0 ].clientX - startX;
			startX = null;
			if ( Math.abs( dx ) > 40 ) {
				step( dx < 0 ? 1 : -1 );
			}
		} );
	}

	function step( dir ) {
		var item = feed.items[ index ];
		if ( 'Video' !== item.type && item.slides.length > 1 && slide + dir >= 0 && slide + dir < item.slides.length ) {
			renderSlide( slide + dir );
		} else {
			show( index + dir );
		}
	}

	// Primeira linha da legenda, para o texto alternativo.
	function altText( item ) {
		var line = ( item.caption || '' ).split( '\n' )[ 0 ].trim();
		return line.length > 120 ? line.slice( 0, 119 ) + '…' : line;
	}

	function announce() {
		var item = feed.items[ index ];
		var text = feed.i18n.post + ' ' + ( index + 1 ) + ' / ' + feed.items.length;
		if ( 'Video' !== item.type && item.slides.length > 1 ) {
			text += ', ' + feed.i18n.slide.toLowerCase() + ' ' + ( slide + 1 ) + ' / ' + item.slides.length;
		}
		els.status.textContent = text;
	}

	// Pré-carrega a imagem seguinte do carrossel e a 1.ª da publicação seguinte e da anterior.
	function preload() {
		var item = feed.items[ index ];
		var total = feed.items.length;
		var urls = [];
		if ( item.slides[ slide + 1 ] ) {
			urls.push( item.slides[ slide + 1 ][ 0 ] );
		}
		[ 1, -1 ].forEach( function ( d ) {
			var other = feed.items[ ( index + d + total ) % total ];
			if ( other.slides[ 0 ] ) {
				urls.push( other.slides[ 0 ][ 0 ] );
			}
		} );
		urls.forEach( function ( u ) {
			( new Image() ).src = u;
		} );
	}

	function renderSlide( n ) {
		var item = feed.items[ index ];
		var data = item.slides[ n ];
		if ( ! data ) {
			return; // Índice fora dos limites: mantém a imagem atual.
		}
		slide = n;
		els.media.textContent = '';
		var alt = altText( item ) + ( item.slides.length > 1 ? ' (' + feed.i18n.slide.toLowerCase() + ' ' + ( n + 1 ) + ' / ' + item.slides.length + ')' : '' );
		var img = el( 'img', 'igfs-lb__img', { src: data[ 0 ], width: data[ 1 ], height: data[ 2 ], alt: alt } );
		els.media.appendChild( img );
		if ( item.slides.length > 1 ) {
			var dots = el( 'div', 'igfs-lb__dots' );
			item.slides.forEach( function ( s, i ) {
				var dot = el( 'button', 'igfs-lb__dot' + ( i === n ? ' is-active' : '' ), {
					type: 'button',
					'aria-label': feed.i18n.slide + ' ' + ( i + 1 ),
					'aria-current': i === n ? 'true' : 'false'
				} );
				dot.addEventListener( 'click', function () {
					renderSlide( i );
				} );
				dots.appendChild( dot );
			} );
			var back = el( 'button', 'igfs-lb__slide igfs-lb__slide--prev', { type: 'button', 'aria-label': feed.i18n.slide + ' ' + n } );
			var fwd = el( 'button', 'igfs-lb__slide igfs-lb__slide--next', { type: 'button', 'aria-label': feed.i18n.slide + ' ' + ( n + 2 ) } );
			back.textContent = '‹';
			fwd.textContent = '›';
			back.hidden = 0 === n;
			fwd.hidden = n === item.slides.length - 1;
			back.addEventListener( 'click', function () {
				renderSlide( n - 1 );
			} );
			fwd.addEventListener( 'click', function () {
				renderSlide( n + 1 );
			} );
			els.media.append( back, fwd, dots );
		}
		announce();
		preload();
	}

	function renderVideo( item ) {
		els.media.textContent = '';
		if ( consent() && item.sc ) {
			els.media.appendChild( el( 'iframe', 'igfs-lb__video', {
				src: 'https://www.instagram.com/p/' + encodeURIComponent( item.sc ) + '/embed/',
				title: feed.i18n.label,
				allow: 'autoplay; encrypted-media; picture-in-picture',
				allowfullscreen: '',
				loading: 'lazy'
			} ) );
			return;
		}
		// Sem consentimento: capa e convite para ver no Instagram.
		if ( item.slides[ 0 ] ) {
			els.media.appendChild( el( 'img', 'igfs-lb__img', { src: item.slides[ 0 ][ 0 ], alt: altText( item ) } ) );
		}
		var note = el( 'div', 'igfs-lb__note' );
		var text = el( 'p' );
		var link = el( 'a', 'igfs-lb__link', { href: item.url, target: '_blank', rel: 'noopener' } );
		text.textContent = feed.i18n.consent;
		link.textContent = feed.i18n.open;
		note.append( text, link );
		els.media.appendChild( note );
	}

	function show( n ) {
		var total = feed.items.length;
		index = ( n + total ) % total;
		var item = feed.items[ index ];
		if ( 'Video' === item.type ) {
			renderVideo( item );
		} else {
			renderSlide( 0 );
		}
		els.head.textContent = '';
		if ( feed.avatar ) {
			els.head.appendChild( el( 'img', 'igfs-lb__avatar', { src: feed.avatar, alt: '', width: 32, height: 32 } ) );
		}
		var user = el( 'strong' );
		user.textContent = feed.user;
		els.head.appendChild( user );
		// Como no Instagram: nome da conta a negrito no início da legenda.
		var who = el( 'strong' );
		who.textContent = feed.user + ' ';
		els.caption.textContent = '';
		els.caption.appendChild( who );
		// Links da legenda clicáveis (só http/https; o resto fica como texto).
		( item.caption || '' ).split( /(https?:\/\/[^\s]+)/ ).forEach( function ( part, i ) {
			if ( i % 2 ) {
				var a = el( 'a', '', { href: part, target: '_blank', rel: 'noopener nofollow' } );
				a.textContent = part;
				els.caption.appendChild( a );
			} else if ( part ) {
				els.caption.appendChild( document.createTextNode( part ) );
			}
		} );
		els.date.textContent = item.date + ( 'Video' === item.type ? ' · ' + feed.i18n.video : '' );
		els.link.textContent = feed.i18n.open;
		els.link.href = item.url;
		els.prev.hidden = els.next.hidden = total < 2;
		els.caption.scrollTop = 0;
		els.box.scrollTop = 0;
		if ( 'Video' === item.type ) {
			slide = 0;
			announce();
			preload();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '.igfs__media[data-igfs-i]' ) : null;
		// Ctrl/Cmd/Shift/clique do meio mantêm o comportamento normal (abrir no Instagram).
		if ( ! link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
			return;
		}
		var root = link.closest( '.igfs' );
		var data = root && root.querySelector( '.igfs-data' );
		if ( ! data || typeof HTMLDialogElement !== 'function' ) {
			return;
		}
		try {
			feed = JSON.parse( data.textContent );
		} catch ( err ) {
			return;
		}
		e.preventDefault();
		if ( ! dialog ) {
			build( feed.i18n );
		}
		opener = link;
		// Cores e cantos do botão das definições (a janela fica fora da grelha e não as herda).
		var styles = getComputedStyle( root );
		[ '--igfs-accent', '--igfs-accent-text', '--igfs-btn-radius' ].forEach( function ( v ) {
			dialog.style.setProperty( v, styles.getPropertyValue( v ) );
		} );
		show( parseInt( link.getAttribute( 'data-igfs-i' ), 10 ) || 0 );
		document.documentElement.classList.add( 'igfs-lb-open' );
		dialog.showModal();
		// Foco na janela (sem contorno visível); o teclado chega ao × com Tab.
		dialog.focus();
	} );
}() );
