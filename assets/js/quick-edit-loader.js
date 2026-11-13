/**
 * Opens Quick Edit, loading its assets on first use.
 *
 * On the Posts and Pages lists, inline-edit-post.js and its tag-suggestion
 * scripts are printed into an inert template by
 * la_print_quick_edit_assets_template(). They are only added to the page
 * when a Quick Edit link is first clicked, or when Bulk Edit is applied
 * with the Edit bulk action, so that they do not delay the list.
 *
 * Core's Quick Edit handler opens the inline editor straight away. This
 * loader intercepts that click in the capture phase, loads the assets,
 * and then lets the click through to it by re-dispatching it, so the
 * editor opens with everything in place. inline-edit-post.js initializes
 * itself in a jQuery DOM-ready callback, which fires as soon as the script
 * loads, so its setup runs before the re-dispatched click.
 *
 * The loader is also exposed as window.laLoadQuickEdit(), for anything else
 * that wants Quick Edit's assets on the page before using it.
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'la-quick-edit-assets';
	const QUICK_EDIT_SELECTOR = '.editinline';

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
	 * Adds Quick Edit's scripts from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run.
	 */
	async function insertQuickEditAssets( assets ) {
		const scripts = Array.from( assets.querySelectorAll( 'script' ) );

		for ( const script of scripts ) {
			const url = script.getAttribute( 'src' );

			if ( url ) {
				preloadScript( url );
			}
		}

		for ( const script of scripts ) {
			await insertScript( script );
		}

		/*
		 * inline-edit-post.js initializes itself in a jQuery DOM-ready
		 * callback. Those callbacks run after the scripts do, so wait for
		 * them before resolving: the re-dispatched click must land after
		 * the editor's own setup is in place.
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
	 * Adds Quick Edit's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second click while the assets
	 * are still loading waits for the same load instead of starting another.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page.
	 */
	function loadQuickEditAssets() {
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
			await insertQuickEditAssets( template.content );
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.laLoadQuickEdit = loadQuickEditAssets;

	/**
	 * Tells whether Quick Edit's assets are already on the page.
	 *
	 * @return {boolean} True when Quick Edit's scripts are loaded.
	 */
	function quickEditReady() {
		return ! document.getElementById( TEMPLATE_ID );
	}

	/**
	 * Tells whether the bulk action Apply button was clicked with Edit selected.
	 *
	 * Bulk Edit is a form submit, not a row click, so the Apply button is
	 * only intercepted when the matching bulk-action dropdown is set to
	 * Edit and at least one post is checked, which is exactly when core's
	 * handler opens the bulk editor.
	 *
	 * @param {Element} button The Apply button that was clicked.
	 * @return {boolean} True when this click opens the bulk editor.
	 */
	function isBulkEditApply( button ) {
		const suffix = 'doaction2' === button.id ? '2' : '';
		const select = document.querySelector( 'select[name="action' + suffix + '"]' );

		if ( ! select || 'edit' !== select.value ) {
			return false;
		}

		return !! document.querySelector( '#posts-filter .check-column input[type="checkbox"]:checked' );
	}

	/*
	 * A Quick Edit link's click handler lives in core's
	 * inline-edit-post.js and opens the inline editor straight away.
	 * Intercept the click before it gets there, load the assets, and then
	 * re-dispatch the click so the handler runs with the editor's scripts
	 * defined. The re-dispatched click is synthetic, so this listener lets
	 * it through; a second real click while loading is swallowed, since the
	 * first one already opens the editor.
	 */
	document.addEventListener(
		'click',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const quickEditButton = event.target.closest( QUICK_EDIT_SELECTOR );
			const bulkApplyButton = ! quickEditButton && event.target.closest( '#doaction, #doaction2' );
			const button = quickEditButton || ( bulkApplyButton && isBulkEditApply( bulkApplyButton ) ? bulkApplyButton : null );

			if ( ! button ) {
				return;
			}

			if ( quickEditReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadQuickEditAssets().then( () => {
				button.click();
			} );
		},
		true
	);
}( window, document ) );
