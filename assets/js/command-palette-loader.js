/**
 * Opens the Command Palette, loading its assets on first use.
 *
 * On screens that do not load the Command Palette up front, its scripts and
 * styles are printed into an inert template by
 * la_print_command_palette_assets_template(). They are only added to the page
 * when the palette is first requested, with the keyboard shortcut or the
 * toolbar button, so that they do not delay every admin page load.
 *
 * Once the page has loaded and the browser is idle, the assets are also
 * loaded in the background, so the first palette open is instant instead of
 * waiting for the download. This follows the suggestion on Trac #66642: the
 * on-demand load wins on blocking time, but the first open takes about
 * 1.8 seconds on a slow connection without it. Loading on idle keeps the
 * initial load fast and makes the first open fast too. The idle load can be
 * turned off with the la_prefetch_palette_on_idle filter.
 *
 * This is the loader from WordPress 7.3 (Trac #66642, WordPress/wordpress-develop
 * pull request #15871), shipped with this plugin so the same behavior is available
 * on WordPress 6.9 to 7.2. The plugin does nothing on 7.3 and later, where core
 * includes this file.
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'wp-command-palette-assets';
	const TOOLBAR_LINK_SELECTOR = '#wp-admin-bar-command-palette > .ab-item';
	const IS_APPLE_OS = /Mac|iPod|iPhone|iPad/.test( window.navigator.platform );

	let assetsPromise = null;
	let assetsLoaded = false;

	/**
	 * Tells whether a key press is the shortcut that opens the Command Palette.
	 *
	 * @param {KeyboardEvent} event The key press.
	 * @return {boolean} Whether the key press is Cmd+K on Apple devices or Ctrl+K elsewhere.
	 */
	function isOpenPaletteShortcut( event ) {
		const primaryModifier = IS_APPLE_OS ? event.metaKey : event.ctrlKey;

		return primaryModifier && ! event.altKey && ! event.shiftKey && 'k' === event.key?.toLowerCase();
	}

	/**
	 * Tells whether the Command Palette assets are still waiting to be added.
	 *
	 * @return {boolean} Whether the assets have not finished loading yet.
	 */
	function arePaletteAssetsPending() {
		return ! assetsLoaded;
	}

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
	 * Adds the Command Palette's scripts and styles from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertPaletteAssets( assets ) {
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
	 * Adds the Command Palette's scripts and styles from the template to the page.
	 *
	 * The first call starts the load; later calls wait for the same load, so the
	 * idle loader and the open handler never load the assets twice, and opening
	 * the palette while the idle load is still running waits for it instead of
	 * doing nothing.
	 *
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	function loadPaletteAssets() {
		if ( assetsPromise ) {
			return assetsPromise;
		}

		const template = /** @type {HTMLTemplateElement|null} */ ( document.getElementById( TEMPLATE_ID ) );

		if ( ! template ) {
			assetsLoaded = true;
			assetsPromise = Promise.resolve();
			return assetsPromise;
		}

		template.remove();
		assetsPromise = insertPaletteAssets( template.content ).then( () => {
			assetsLoaded = true;
		} );

		return assetsPromise;
	}

	/**
	 * Opens the Command Palette, adding its assets to the page first if needed.
	 */
	async function openPalette() {
		await loadPaletteAssets();

		window.wp.data.dispatch( 'core/commands' ).open();
	}

	/**
	 * Starts loading the Command Palette assets once the page has loaded and
	 * the browser is idle, so the first palette open is instant.
	 *
	 * This only downloads and runs the assets; it does not open the palette.
	 * It runs after the load event so it never competes with the initial page
	 * load, and it uses requestIdleCallback (falling back to a short timeout)
	 * so it runs when the browser has spare time. Set window.laPaletteIdleLoad
	 * to false before this script runs to turn it off.
	 */
	function loadPaletteAssetsWhenIdle() {
		if ( false === window.laPaletteIdleLoad ) {
			return;
		}

		const load = () => {
			if ( 'requestIdleCallback' in window ) {
				window.requestIdleCallback(
					() => {
						loadPaletteAssets();
					},
					{ timeout: 2000 }
				);
			} else {
				window.setTimeout( () => {
					loadPaletteAssets();
				}, 1000 );
			}
		};

		if ( 'complete' === document.readyState ) {
			load();
		} else {
			window.addEventListener( 'load', load );
		}
	}

	loadPaletteAssetsWhenIdle();

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.defaultPrevented || ! isOpenPaletteShortcut( event ) || ! arePaletteAssetsPending() ) {
			return;
		}

		event.preventDefault();
		openPalette();
	} );

	document.addEventListener( 'click', ( event ) => {
		if ( ! ( event.target instanceof Element ) || ! event.target.closest( TOOLBAR_LINK_SELECTOR ) ) {
			return;
		}

		event.preventDefault();
		openPalette();
	} );
}( window, document ) );
