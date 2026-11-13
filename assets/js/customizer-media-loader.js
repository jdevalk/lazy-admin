/**
 * Opens the Customizer's media modal, loading its assets on first use.
 *
 * On customize.php the media modal stack (media views, the uploader, the
 * image editor, the audio and video player) is printed into an inert
 * template by la_print_customizer_media_assets_template(). It is only added
 * to the page when a media control is first used, so that it does not delay
 * the Customizer.
 *
 * Core's MediaControl binds its buttons on page load and calls wp.media()
 * when one is clicked. This loader intercepts that click in the capture
 * phase, loads the assets, and then lets the click through to it by
 * re-dispatching it, so the modal opens with everything in place. The same
 * goes for media widgets, whose buttons open the modal through
 * wp.mediaWidgets.MediaWidgetControl.selectMedia().
 *
 * media-models stays up front on purpose: a media control with a value
 * already set fetches its attachment data through wp.media.attachment()
 * when the control is created, and that lives in media-models. Everything
 * the modal itself needs waits in the template.
 *
 * The loader is also exposed as window.laLoadCustomizerMedia(), for
 * anything else that wants the modal's assets on the page before calling
 * wp.media().
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'la-customizer-media-assets';

	/*
	 * Buttons that open the media modal in the Customizer. Media controls
	 * (Site Icon, logo, header image, background image) open it from the
	 * upload button or by clicking the thumbnail; media widgets open it from
	 * the select-media button or the placeholder.
	 */
	const BUTTON_SELECTOR = '.upload-button, .thumbnail-image img, .select-media, .placeholder';

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
	 * Adds the media modal's scripts and styles from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertCustomizerMediaAssets( assets ) {
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

		/*
		 * The deferred scripts bind their handlers in jQuery DOM-ready
		 * callbacks. Those callbacks run after the scripts do, so wait for
		 * them before resolving: the re-dispatched click must land after
		 * the modal's own handlers are in place.
		 */
		await new Promise( ( resolve ) => {
			if ( window.jQuery ) {
				window.jQuery( resolve );
			} else {
				setTimeout( resolve, 0 );
			}
		} );
	}

	/**
	 * Adds the media modal's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second click while the assets
	 * are still loading waits for the same load instead of starting another.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page.
	 */
	function loadCustomizerMediaAssets() {
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
			await insertCustomizerMediaAssets( template.content );
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.laLoadCustomizerMedia = loadCustomizerMediaAssets;

	/**
	 * Tells whether the media modal's assets are already on the page.
	 *
	 * media-models stays up front, so wp.media exists from the start; the
	 * modal itself (media-views) is what waits in the template.
	 *
	 * @return {boolean} True when the modal's scripts are loaded.
	 */
	function mediaReady() {
		return ! document.getElementById( TEMPLATE_ID );
	}

	/**
	 * Loads the assets and then re-dispatches the action that opened the modal.
	 *
	 * @param {Element} target The button that was used.
	 * @param {Function} redispatch How to repeat the action once loaded.
	 */
	function loadAndRedispatch( target, redispatch ) {
		if ( isLoading ) {
			return;
		}

		loadCustomizerMediaAssets().then( redispatch );
	}

	/*
	 * A media control's button click handler lives in core's
	 * customize-controls.js and calls wp.media() straight away. Intercept
	 * the click before it gets there, load the assets, and then re-dispatch
	 * the click so the handler runs with the modal's scripts defined. The
	 * re-dispatched click is synthetic, so this listener lets it through; a
	 * second real click while loading is swallowed, since the first one
	 * already opens the modal.
	 */
	document.addEventListener(
		'click',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const button = event.target.closest( BUTTON_SELECTOR );

			if ( ! button || ! button.closest( '#customize-controls' ) ) {
				return;
			}

			if ( mediaReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			loadAndRedispatch( button, () => {
				button.click();
			} );
		},
		true
	);

	/*
	 * The control also opens the modal on Enter (its keydown handler returns
	 * early for any other key). Space on a button fires a click natively, so
	 * the click interceptor above covers it; Enter does not, so it needs its
	 * own interceptor that re-dispatches the keydown once loaded.
	 */
	document.addEventListener(
		'keydown',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const key = event.keyCode || event.which;

			if ( 13 !== key ) {
				return;
			}

			const button = event.target.closest( BUTTON_SELECTOR );

			if ( ! button || ! button.closest( '#customize-controls' ) ) {
				return;
			}

			if ( mediaReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			loadAndRedispatch( button, () => {
				const redispatched = new KeyboardEvent( 'keydown', {
					bubbles: true,
					cancelable: true
				} );
				Object.defineProperty( redispatched, 'keyCode', { value: 13 } );
				Object.defineProperty( redispatched, 'which', { value: 13 } );
				button.dispatchEvent( redispatched );
			} );
		},
		true
	);
}( window, document ) );
