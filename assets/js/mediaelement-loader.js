/**
 * Loads the media library's audio and video player on first use.
 *
 * On the media library, the MediaElement library, its migrate script, the
 * wp-mediaelement wrapper and both stylesheets are printed into an inert
 * template by la_print_mediaelement_assets_template(). They are only added
 * to the page when an audio or video attachment's details open, so that the
 * library does not delay views that only show images.
 *
 * The player is built in exactly one place: the attachment Details view's
 * render(), which calls new window.MediaElementPlayer() for each audio or
 * video element. This loader intercepts the actions that open that view, in
 * the capture phase, loads the assets, and then lets the action through by
 * re-dispatching it, so the view renders with the player in place. The
 * actions are: clicking an audio or video attachment in the grid or in the
 * modal's library, pressing Enter or Space on one, and moving to the
 * previous or next attachment inside the details modal, with its buttons or
 * the arrow keys.
 *
 * The loader is also exposed as window.laLoadMediaElement(), for anything
 * else that wants the player's assets on the page before building one.
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'la-mediaelement-assets';

	let isLoading = false;
	let loadingPromise = null;

	/**
	 * Adds a script to the page and waits until it has run.
	 *
	 * A script added with the DOM runs as soon as it is inserted when it is
	 * inline, but only after it has loaded when it has a source. Waiting for
	 * each one keeps the order in which they were printed.
	 *
	 * @param {HTMLScriptElement} templateScript The script as printed in the template.
	 * @return {Promise<void>} Resolves once the script has run or failed to load.
	 */
	function insertScript( templateScript ) {
		const script = document.createElement( 'script' );

		for ( const attribute of templateScript.attributes ) {
			script.setAttribute( attribute.name, attribute.value );
		}
		script.text = templateScript.text;

		if ( ! script.hasAttribute( 'src' ) ) {
			document.body.appendChild( script );
			return Promise.resolve();
		}

		return new Promise( ( resolve ) => {
			script.onload = () => resolve();
			script.onerror = () => resolve();
			document.body.appendChild( script );
		} );
	}

	/**
	 * Adds a stylesheet to the page and waits until it has loaded.
	 *
	 * @param {HTMLLinkElement|HTMLStyleElement} templateStyle The style as printed in the template.
	 * @return {Promise<void>} Resolves once the stylesheet has loaded or failed to load.
	 */
	function insertStyle( templateStyle ) {
		const style = document.importNode( templateStyle, true );

		if ( 'LINK' !== style.tagName ) {
			document.head.appendChild( style );
			return Promise.resolve();
		}

		return new Promise( ( resolve ) => {
			style.onload = () => resolve();
			style.onerror = () => resolve();
			document.head.appendChild( style );
		} );
	}

	/**
	 * Asks the browser to start downloading a script before it is inserted.
	 *
	 * @param {string} url The script URL.
	 */
	function preloadScript( url ) {
		const link = document.createElement( 'link' );

		link.rel = 'preload';
		link.as = 'script';
		link.href = url;
		document.head.appendChild( link );
	}

	/**
	 * Adds the player's scripts and styles from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertMediaElementAssets( assets ) {
		const scripts = Array.from( assets.querySelectorAll( 'script' ) );
		const styles = Array.from(
			assets.querySelectorAll( 'link[rel="stylesheet"], style' ),
			( style ) => /** @type {HTMLLinkElement|HTMLStyleElement} */ ( style )
		);

		for ( const script of scripts ) {
			const url = script.getAttribute( 'src' );

			if ( url ) {
				preloadScript( url );
			}
		}

		const stylesLoaded = Promise.all( styles.map( insertStyle ) );

		for ( const script of scripts ) {
			await insertScript( script );
		}

		await stylesLoaded;
	}

	/**
	 * Adds the player's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second action while the
	 * assets are still loading waits for the same load instead of starting
	 * another.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page.
	 */
	function loadMediaElementAssets() {
		if ( loadingPromise ) {
			return loadingPromise;
		}

		const template = /** @type {HTMLTemplateElement|null} */ ( document.getElementById( TEMPLATE_ID ) );

		if ( ! template ) {
			return Promise.resolve();
		}

		isLoading = true;
		loadingPromise = ( async () => {
			template.remove();
			await insertMediaElementAssets( template.content );
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.laLoadMediaElement = loadMediaElementAssets;

	/**
	 * Tells whether the player is already on the page.
	 *
	 * @return {boolean} Whether MediaElementPlayer is defined or there is nothing to load.
	 */
	function playerReady() {
		return Boolean( window.MediaElementPlayer || ! document.getElementById( TEMPLATE_ID ) );
	}

	/**
	 * Tells whether an attachment element holds audio or video.
	 *
	 * The grid and the modal library render each attachment's type on its
	 * preview element, as type-audio or type-video.
	 *
	 * @param {Element} attachment The attachment element.
	 * @return {boolean} Whether it is audio or video.
	 */
	function isAudioOrVideoAttachment( attachment ) {
		return Boolean( attachment.querySelector( '.type-audio, .type-video' ) );
	}

	/**
	 * Returns the attachment the details modal would move to.
	 *
	 * The modal's previous and next buttons, and the arrow keys inside it,
	 * move to the adjacent attachment in the library. When the modal is not
	 * open, or the adjacent attachment cannot be found, the type is unknown.
	 *
	 * @param {number} direction -1 for the previous attachment, 1 for the next.
	 * @return {string|null} The adjacent attachment's type, or null when it cannot be found.
	 */
	function modalTargetType( direction ) {
		const frame = window.wp && window.wp.media && window.wp.media.frames && window.wp.media.frames.edit;

		if ( ! frame || ! frame.library || 'function' !== typeof frame.getCurrentIndex ) {
			return null;
		}

		const hasNeighbour = -1 === direction
			? 'function' === typeof frame.hasPrevious && frame.hasPrevious()
			: 'function' === typeof frame.hasNext && frame.hasNext();

		if ( ! hasNeighbour ) {
			return null;
		}

		const model = frame.library.at( frame.getCurrentIndex() + direction );

		if ( ! model || 'function' !== typeof model.get ) {
			return null;
		}

		return model.get( 'type' );
	}

	/**
	 * Tells whether a keydown is an arrow key that would move inside the modal.
	 *
	 * @param {KeyboardEvent} event The keydown event.
	 * @return {number} -1 for the left arrow, 1 for the right arrow, 0 otherwise.
	 */
	function modalArrowDirection( event ) {
		if ( 37 === event.keyCode || 37 === event.which ) {
			return -1;
		}
		if ( 39 === event.keyCode || 39 === event.which ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Re-dispatches a keydown with its key code set.
	 *
	 * The key code is set as an own property, so the handler reads it even
	 * where the KeyboardEvent constructor does not take one.
	 *
	 * @param {KeyboardEvent} event The original keydown event.
	 * @param {Element} target The element to dispatch it on.
	 */
	function redispatchKeydown( event, target ) {
		const redispatched = new window.KeyboardEvent( 'keydown', {
			key: event.key,
			code: event.code,
			bubbles: true,
			cancelable: true,
		} );
		Object.defineProperty( redispatched, 'keyCode', { value: event.keyCode || event.which } );
		Object.defineProperty( redispatched, 'which', { value: event.which || event.keyCode } );
		target.dispatchEvent( redispatched );
	}

	/*
	 * Intercept a click that would open an audio or video attachment's
	 * details, load the player, and then re-dispatch the click so the view
	 * renders with it in place. The re-dispatched click is synthetic, so
	 * this listener lets it through; a second real click while loading is
	 * swallowed, since the first one already opens the view.
	 *
	 * Clicks on inputs and buttons inside an attachment are left alone,
	 * exactly as the attachment view's own handler does. The modal's
	 * previous and next buttons are handled the same way, but only when the
	 * attachment they move to is audio or video.
	 */
	document.addEventListener(
		'click',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			if ( playerReady() ) {
				return;
			}

			let target = null;

			const attachment = event.target.closest( '.attachment' );

			if ( attachment && isAudioOrVideoAttachment( attachment ) ) {
				if ( 'INPUT' === event.target.nodeName || 'BUTTON' === event.target.nodeName ) {
					return;
				}
				target = attachment;
			} else {
				const navButton = event.target.closest( '.left, .right' );

				if ( navButton && navButton.closest( '.edit-attachment-frame' ) ) {
					const direction = navButton.classList.contains( 'left' ) ? -1 : 1;
					const type = modalTargetType( direction );

					// When the target cannot be found, load anyway rather than risk a broken view.
					if ( null === type || 'audio' === type || 'video' === type ) {
						target = navButton;
					} else {
						return;
					}
				}
			}

			if ( ! target ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadMediaElementAssets().then( () => {
				target.click();
			} );
		},
		true
	);

	/*
	 * The attachment view also opens on Enter or Space, and the details
	 * modal moves with the arrow keys. Intercept those the same way: an
	 * Enter or Space on an audio or video attachment loads the player and
	 * re-dispatches a click, which the view's click binding handles, and an
	 * arrow key inside the modal loads the player when the attachment it
	 * moves to is audio or video, and re-dispatches the key.
	 */
	document.addEventListener(
		'keydown',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			if ( playerReady() ) {
				return;
			}

			const attachment = event.target.closest( '.attachment' );

			if ( attachment && isAudioOrVideoAttachment( attachment ) && ( 13 === event.keyCode || 32 === event.keyCode || 13 === event.which || 32 === event.which ) ) {
				if ( 'INPUT' === event.target.nodeName || 'BUTTON' === event.target.nodeName ) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();

				if ( isLoading ) {
					return;
				}

				loadMediaElementAssets().then( () => {
					attachment.click();
				} );
				return;
			}

			const direction = modalArrowDirection( event );

			if ( 0 !== direction && document.querySelector( '.media-modal' ) ) {
				const type = modalTargetType( direction );

				// When the target cannot be found, load anyway rather than risk a broken view.
				if ( null === type || 'audio' === type || 'video' === type ) {
					event.preventDefault();
					event.stopPropagation();

					if ( isLoading ) {
						return;
					}

					loadMediaElementAssets().then( () => {
						redispatchKeydown( event, event.target );
					} );
				}
			}
		},
		true
	);
}( window, document ) );
