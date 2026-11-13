/**
 * Opens the media modal, loading its assets on first use.
 *
 * On screens that defer the media modal, its scripts, styles and HTML
 * templates are printed into an inert template by
 * lcp_print_media_assets_template(). They are only added to the page when the
 * Site Icon button is clicked, so that they do not delay the Settings screen.
 *
 * Core's site-icon.js is left untouched: it binds the button on page load and
 * calls wp.media() when clicked. This loader intercepts that click in the
 * capture phase, loads the assets, and then lets the click through to it by
 * re-dispatching it, so the modal opens with everything in place.
 *
 * The loader is also exposed as window.lcpLoadMediaAssets(), for anything else
 * that wants the modal's assets on the page before calling wp.media().
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'lcp-media-assets';
	const BUTTON_SELECTOR = '#choose-from-library-button';

	let isLoading = false;
	let loadingPromise = null;

	/**
	 * Adds a script to the page and waits until it has run.
	 *
	 * A script added with the DOM runs as soon as it is inserted when it is
	 * inline, but only after it has loaded when it has a source. Waiting for
	 * each one keeps the order in which they were printed. A script with
	 * type="text/html" is one of the modal's templates: it is inert, so adding
	 * it just puts the template in the document where wp.template() finds it.
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
	 * Adds the media modal's scripts, styles and templates from the template
	 * to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertMediaAssets( assets ) {
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
	 * Adds the media modal's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second click while the assets
	 * are still loading waits for the same load instead of starting another.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page.
	 */
	function loadMediaAssets() {
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
			await insertMediaAssets( template.content );
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.lcpLoadMediaAssets = loadMediaAssets;

	/*
	 * The Site Icon button's click handler lives in core's site-icon.js and
	 * calls wp.media() straight away. Intercept the click before it gets
	 * there, load the assets, and then re-dispatch the click so the handler
	 * runs with wp.media defined. The re-dispatched click is synthetic, so
	 * this listener lets it through; a second real click while loading is
	 * swallowed, since the first one already opens the modal.
	 */
	document.addEventListener(
		'click',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const button = event.target.closest( BUTTON_SELECTOR );

			if ( ! button ) {
				return;
			}

			if ( ( window.wp && window.wp.media ) || ! document.getElementById( TEMPLATE_ID ) ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadMediaAssets().then( () => {
				button.click();
			} );
		},
		true
	);
}( window, document ) );
