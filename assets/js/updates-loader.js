/**
 * Loads the update and install scripts on first use.
 *
 * On the Dashboard, Themes, Plugins, Add Plugins, Update Core and Import
 * screens, updates.js, thickbox, plugin-install.js and media-upload.js are
 * printed into an inert template by la_print_updates_assets_template(). They
 * are only added to the page when one of their buttons is first used, so
 * that they do not delay screens that are mostly just looked at.
 *
 * Every one of those scripts only works on click: updates.js binds its
 * update, install, delete and auto-update buttons, thickbox binds its links,
 * and plugin-install.js binds the plugin details links. This loader
 * intercepts those clicks in the capture phase, loads the assets, and then
 * lets the click through by re-dispatching it, so the button works with
 * everything in place.
 *
 * The loader is also exposed as window.laLoadUpdates(), for anything else
 * that wants the cluster's assets on the page before calling wp.updates or
 * tb_show().
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'la-updates-assets';

	/*
	 * Every selector that one of the cluster's scripts binds a click to.
	 * From updates.js: the update and install buttons, the activate link in
	 * the plugin details footer, the importer install buttons, the update
	 * links in the plugins table, the update message links, the try-again
	 * button, the update button inside the plugin details iframe, the bulk
	 * action submit, the plugin delete links, the auto-update toggles and
	 * the dismiss button on plugin cards. From thickbox.js: the thickbox
	 * links. From plugin-install.js: the plugin details links.
	 */
	const CLICK_SELECTORS = [
		'.update-now',
		'.install-now',
		'.importer-item .install-now',
		'#plugin-information-footer .activate-now',
		'[data-plugin] .update-link',
		'.update-message a',
		'.try-again',
		'#plugin_update_from_iframe',
		'#bulk-action-form [type="submit"]',
		'[data-plugin] a.delete',
		'.toggle-auto-update',
		'.plugin-card .notice-dismiss',
		'a.thickbox',
		'area.thickbox',
		'input.thickbox',
		'.thickbox.open-plugin-details-modal',
	].join( ', ' );

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
	 * Adds the cluster's scripts and styles from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertUpdatesAssets( assets ) {
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
	 * Adds the cluster's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second click while the assets
	 * are still loading waits for the same load instead of starting another.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page.
	 */
	function loadUpdatesAssets() {
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
			await insertUpdatesAssets( template.content );
			/*
			 * The cluster's scripts bind their handlers inside jQuery's
			 * DOM-ready callbacks, which run asynchronously after the scripts
			 * execute. Wait for jQuery's ready queue, so a re-dispatched
			 * click finds the handlers bound; otherwise the click lands
			 * before plugin-install.js has replaced thickbox's tb_position,
			 * and its iframe handler throws on an unset window. The ready
			 * callbacks run in the order they were added, so one queued
			 * after the scripts loaded runs after theirs. Without jQuery,
			 * a turn of the event loop is the fallback.
			 */
			await new Promise( ( resolve ) => {
				if ( window.jQuery ) {
					window.jQuery( resolve );
				} else {
					setTimeout( resolve, 0 );
				}
			} );
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.laLoadUpdates = loadUpdatesAssets;

	/*
	 * thickbox.js defines tb_show() and tb_remove() as globals, and plugins
	 * call them directly instead of going through a thickbox link. Define
	 * them first when thickbox is not on the page yet, so those calls load
	 * the cluster and then run the real one. Once thickbox.js loads it
	 * overwrites these with its own definitions.
	 */
	if ( 'undefined' === typeof window.tb_show ) {
		const tbShowShim = function ( caption, url, imageGroup ) {
			loadUpdatesAssets().then( () => {
				if ( window.tb_show && window.tb_show !== tbShowShim ) {
					window.tb_show( caption, url, imageGroup );
				}
			} );
		};
		window.tb_show = tbShowShim;
	}

	if ( 'undefined' === typeof window.tb_remove ) {
		const tbRemoveShim = function () {
			loadUpdatesAssets().then( () => {
				if ( window.tb_remove && window.tb_remove !== tbRemoveShim ) {
					window.tb_remove();
				}
			} );
		};
		window.tb_remove = tbRemoveShim;
	}

	/**
	 * Tells whether the cluster is already on the page.
	 *
	 * @return {boolean} Whether wp.updates is defined or there is nothing to load.
	 */
	function clusterReady() {
		return Boolean( ( window.wp && window.wp.updates ) || ! document.getElementById( TEMPLATE_ID ) );
	}

	/*
	 * Intercept a click on one of the cluster's buttons before it reaches
	 * the handler that needs the cluster, load the assets, and then
	 * re-dispatch the click so the handler runs with everything in place.
	 * The re-dispatched click is synthetic, so this listener lets it
	 * through; a second real click while loading is swallowed, since the
	 * first one already does the job.
	 */
	document.addEventListener(
		'click',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const target = event.target.closest( CLICK_SELECTORS );

			if ( ! target ) {
				return;
			}

			if ( clusterReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadUpdatesAssets().then( () => {
				target.click();
			} );
		},
		true
	);

	/*
	 * The auto-update toggle also answers to the space bar: updates.js binds
	 * it on click and keyup, and on keyup only the space bar counts. A link
	 * does not fire a click for the space bar, so intercept that keyup the
	 * same way, and re-dispatch a click on the toggle after the load, which
	 * the toggle's click binding handles.
	 */
	document.addEventListener(
		'keyup',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			if ( 32 !== event.which && 32 !== event.keyCode ) {
				return;
			}

			const toggle = event.target.closest( '.toggle-auto-update' );

			if ( ! toggle ) {
				return;
			}

			if ( clusterReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadUpdatesAssets().then( () => {
				toggle.click();
			} );
		},
		true
	);
}( window, document ) );
