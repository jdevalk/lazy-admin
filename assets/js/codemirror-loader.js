/**
 * Loads CodeMirror on demand in the Customizer.
 *
 * On the Customizer, the Additional CSS section (and the Custom HTML widget)
 * load the whole code editor up front, for a section that starts collapsed.
 * Its scripts and styles are printed into an inert template by
 * la_print_codemirror_assets_template(). They are only added to the page when
 * the section is first opened, so that they do not delay the Customizer.
 *
 * Core's code editor control already waits for its section to expand before
 * it initializes the editor. This loader intercepts that expand: a click on
 * the section title loads the assets first and then lets the click through,
 * so the editor initializes with CodeMirror in place. Wrapping the control's
 * init is the safety net for sections that are already expanded, or opened
 * without a click, and for the Custom HTML widget, whose form opens the
 * editor the same way.
 *
 * The loader is also exposed as window.laLoadCodeMirror(), for anything else
 * that wants the code editor's assets on the page before calling
 * wp.codeEditor.initialize().
 */
( function ( window, document ) {
	const TEMPLATE_ID = 'la-codemirror-assets';

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
	 * Adds the code editor's scripts and styles from the template to the page.
	 *
	 * All scripts are preloaded at once and then run one after the other.
	 *
	 * @param {DocumentFragment} assets The template content.
	 * @return {Promise<void>} Resolves once every script has run and every stylesheet has loaded.
	 */
	async function insertCodeMirrorAssets( assets ) {
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
	 * Adds the code editor's assets to the page, the first time it is called.
	 *
	 * Later calls return the same promise, so a second expand while the assets
	 * are still loading waits for the same load instead of starting another.
	 *
	 * @return {Promise<void>} Resolves once the assets are on the page.
	 */
	function loadCodeMirror() {
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
			await insertCodeMirrorAssets( template.content );
			isLoading = false;
		} )();

		return loadingPromise;
	}

	window.laLoadCodeMirror = loadCodeMirror;

	/**
	 * Tells whether CodeMirror is on the page already.
	 *
	 * @return {boolean} Whether wp.CodeMirror is defined.
	 */
	function isCodeMirrorLoaded() {
		return !! ( window.wp && window.wp.CodeMirror );
	}

	/*
	 * Safety net: the code editor control initializes its editor when its
	 * section expands, which calls wp.CodeMirror.fromTextArea() straight away.
	 * If CodeMirror is not on the page yet, load it first and then run the
	 * original init. This runs in the footer, before the Customizer
	 * initializes on DOM ready, so it is in place before any control's
	 * ready() runs. Prototype methods are looked up at call time, so it
	 * applies to every control instance, whenever it is created.
	 */
	( function wrapCodeEditorControl() {
		const api = window.wp && window.wp.customize;

		if ( ! api || ! api.CodeEditorControl ) {
			return;
		}

		const prototype = api.CodeEditorControl.prototype;

		if ( prototype.laWrapped ) {
			return;
		}
		prototype.laWrapped = true;

		const originalInitEditor = prototype.initEditor;

		prototype.initEditor = function () {
			const control = this;

			if ( isCodeMirrorLoaded() ) {
				return originalInitEditor.call( control );
			}

			return loadCodeMirror().then( function () {
				originalInitEditor.call( control );
			} );
		};
	} )();

	/**
	 * Wraps the Custom HTML widget's editor init the same way, when its
	 * script is on the page. Its form opens the editor when the widget is
	 * shown, which is after page load, so the wrap applies to every widget
	 * instance created after it.
	 */
	function wrapCustomHtmlWidget() {
		const component = window.wp && window.wp.customHtmlWidgets;

		if ( ! component || ! component.CustomHtmlWidgetControl ) {
			return;
		}

		const prototype = component.CustomHtmlWidgetControl.prototype;

		if ( prototype.laWrapped ) {
			return;
		}
		prototype.laWrapped = true;

		const originalInitializeEditor = prototype.initializeEditor;

		prototype.initializeEditor = function () {
			const control = this;

			if ( isCodeMirrorLoaded() ) {
				return originalInitializeEditor.call( control );
			}

			return loadCodeMirror().then( function () {
				originalInitializeEditor.call( control );
			} );
		};
	}

	/**
	 * Installs the click interceptor for sections that hold a code editor.
	 *
	 * A click on such a section's title loads CodeMirror first and then lets
	 * the click through, so the section expands with the editor ready. The
	 * re-dispatched click is synthetic, so this listener lets it through; a
	 * second real click while loading is swallowed, since the first one
	 * already opens the section.
	 */
	function installInterceptor() {
		const api = window.wp && window.wp.customize;

		if ( ! api ) {
			return;
		}

		const codeEditorSections = {};

		api.control.each( function ( control ) {
			if ( control.params && 'code_editor' === control.params.type ) {
				const sectionId = control.section && control.section();

				if ( sectionId ) {
					codeEditorSections[ sectionId ] = true;
				}
			}
		} );

		if ( 0 === Object.keys( codeEditorSections ).length ) {
			return;
		}

		document.addEventListener(
			'click',
			( event ) => {
				if ( ! event.isTrusted || ! ( event.target instanceof Element ) ) {
					return;
				}

				// The help toggle inside the title opens the help, not the section.
				if ( event.target.closest( '.customize-help-toggle' ) ) {
					return;
				}

				/*
				 * The clickable header is the button inside the section's
				 * title (or the title itself when it has a tabindex). The
				 * .customize-section-title element is the inner title shown
				 * once the section is open, not the header.
				 */
				const title = event.target.closest( '.accordion-section-title' );

				if ( ! title ) {
					return;
				}

				const container = title.closest( '.accordion-section' );

				if ( ! container ) {
					return;
				}

				const sectionId = container.id.replace( 'accordion-section-', '' );

				if ( ! codeEditorSections[ sectionId ] ) {
					return;
				}

				const section = api.section( sectionId );

				if ( ! section || section.expanded() ) {
					return;
				}

				if ( isCodeMirrorLoaded() || ! document.getElementById( TEMPLATE_ID ) ) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();

				if ( isLoading ) {
					return;
				}

				loadCodeMirror().then( () => {
					event.target.click();
				} );
			},
			true
		);
	}

	/*
	 * The section and widget setup needs the Customizer to be initialized,
	 * which happens on DOM ready. The initEditor wrap above is already in
	 * place by then.
	 */
	if ( window.wp && window.wp.customize ) {
		window.wp.customize.bind( 'ready', function () {
			wrapCustomHtmlWidget();
			installInterceptor();
		} );
	}

	/*
	 * A widget added later loads its script after ready; wrap its editor init
	 * then too. This is a jQuery event, so it needs jQuery's event system.
	 */
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'widget-added', wrapCustomHtmlWidget );
	}
}( window, document ) );
