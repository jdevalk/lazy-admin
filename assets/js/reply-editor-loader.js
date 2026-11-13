/**
 * Opens the comment Reply editor, loading its assets on first use.
 *
 * On the Dashboard and the Comments screen, quicktags and wplink are
 * printed into an inert template by
 * la_print_reply_editor_assets_template(). They are only added to the page
 * when a Reply button is first used, or when the Quick Draft textarea is
 * first focused, so that they do not delay those screens.
 *
 * Core's reply handler opens the reply form straight away. This loader
 * intercepts that click in the capture phase, loads the assets, runs the
 * editor init the footer script would have run, and then lets the click
 * through to it by re-dispatching it, so the form opens with its toolbar
 * in place. The same goes for double-clicking a comment to reply.
 *
 * The Quick Draft textarea on the Dashboard is a plain textarea without
 * a quicktags toolbar (tinyMCEPreInit.qtInit only lists replycontent), so
 * focusing it needs no loader trigger.
 *
 * The loader is also exposed as window.laLoadReplyEditor(), for anything
 * else that wants the editor's assets on the page before using quicktags.
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'la-reply-editor-assets';
	const REPLY_BUTTON_SELECTOR = '.vim-r';

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
	 * Adds the Reply editor's scripts and styles from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertReplyEditorAssets( assets ) {
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
	 * Runs the editor init the footer script would have run.
	 *
	 * This mirrors class-wp-editor.php: the footer script calls quicktags()
	 * for every editor in tinyMCEPreInit.qtInit, but only when quicktags is
	 * defined. Deferring quicktags skips that call at page load, so it runs
	 * here instead, once the scripts are on the page.
	 */
	function initializeQuicktags() {
		if ( 'undefined' === typeof window.quicktags || ! window.tinyMCEPreInit || ! window.tinyMCEPreInit.qtInit ) {
			return;
		}

		for ( const id in window.tinyMCEPreInit.qtInit ) {
			window.quicktags( window.tinyMCEPreInit.qtInit[ id ] );

			if ( ! window.wpActiveEditor ) {
				window.wpActiveEditor = id;
			}
		}
	}

	/**
	 * Adds the Reply editor's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second click while the assets
	 * are still loading waits for the same load instead of starting another.
	 * The editor init runs once the scripts are on the page.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page and the editor is initialized.
	 */
	function loadReplyEditorAssets() {
		if ( loadingPromise ) {
			return loadingPromise;
		}

		const template = /** @type {HTMLTemplateElement|null} */ ( document.getElementById( TEMPLATE_ID ) );

		if ( ! template ) {
			initializeQuicktags();
			return Promise.resolve();
		}

		isLoading = true;
		loadingPromise = ( async () => {
			template.remove();
			await insertReplyEditorAssets( template.content );
			initializeQuicktags();
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.laLoadReplyEditor = loadReplyEditorAssets;

	/**
	 * Tells whether the Reply editor's assets are already on the page.
	 *
	 * @return {boolean} True when the editor's scripts are loaded.
	 */
	function replyEditorReady() {
		return ! document.getElementById( TEMPLATE_ID );
	}

	/*
	 * A Reply button's click handler lives in core's edit-comments.js (the
	 * comments list) or in the button's own onclick (the Dashboard Activity
	 * widget), and opens the reply form straight away. Intercept the click
	 * before it gets there, load the assets, and then re-dispatch the click
	 * so the handler runs with quicktags defined. The re-dispatched click
	 * is synthetic, so this listener lets it through; a second real click
	 * while loading is swallowed, since the first one already opens the form.
	 */
	document.addEventListener(
		'click',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const button = event.target.closest( REPLY_BUTTON_SELECTOR );

			if ( ! button ) {
				return;
			}

			if ( replyEditorReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadReplyEditorAssets().then( () => {
				button.click();
			} );
		},
		true
	);

	/*
	 * Double-clicking a comment also opens the reply form
	 * (edit-comments.js binds a dblclick on the comment text). Intercept it
	 * the same way, so the form opens with its toolbar in place.
	 */
	document.addEventListener(
		'dblclick',
		( event ) => {
			if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
				return;
			}

			const paragraph = event.target.closest( '#the-comment-list .column-comment > p' );

			if ( ! paragraph ) {
				return;
			}

			if ( replyEditorReady() ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			if ( isLoading ) {
				return;
			}

			loadReplyEditorAssets().then( () => {
				paragraph.dispatchEvent( new MouseEvent( 'dblclick', { bubbles: true, cancelable: true } ) );
			} );
		},
		true
	);
}( window, document ) );
