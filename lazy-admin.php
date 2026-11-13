<?php
/**
 * Plugin Name:       Lazy Admin
 * Plugin URI:        https://github.com/jdevalk/lazy-admin
 * Description:       Loads the Command Palette, media modal, code editor, update scripts and media player only when you use them, and trims the admin CSS bundle to the stylesheets each screen needs. Classic admin screens load much faster.
 * Version:           0.5.2
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Author:            Joost de Valk
 * Author URI:        https://joost.blog
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lazy-admin
 *
 * @package Lazy_Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LA_VERSION', '0.5.2' );

add_action( 'init', 'la_init' );

/**
 * Replaces up-front loading with loading on demand, for the Command Palette and
 * for the media modal.
 */
function la_init() {
	/*
	 * The Command Palette only loads its scripts up front on every admin screen
	 * since WordPress 6.9, and WordPress 7.3 loads it on demand itself
	 * (see Trac #66642). Outside 6.9 to 7.2 there is nothing for the palette part
	 * of this plugin to do.
	 */
	if ( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) && ! function_exists( 'wp_enqueue_command_palette_loader' ) ) {
		$priority = has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );
		if ( false !== $priority ) {
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets', $priority );
		}

		add_action( 'admin_enqueue_scripts', 'la_enqueue_command_palette_loader', 10 );
		add_action( 'admin_print_footer_scripts', 'la_print_command_palette_assets_template', 20 );

		/*
		 * Core's toolbar button only shows when wp-core-commands is enqueued up front,
		 * which is exactly what this plugin stops doing. Swap it for the loader's version
		 * when the admin bar menu runs; core registers its button during
		 * WP_Admin_Bar::initialize(), so this runs before it, at priority 0.
		 */
		add_action( 'admin_bar_menu', 'la_replace_admin_bar_command_palette_menu', 0 );

		/*
		 * On WordPress 7.2 and later the login screen prefetches the admin's scripts,
		 * including the palette's. Keep those out of the prefetch; the filter does not
		 * exist before 7.2, so this is a no-op there.
		 */
		add_filter( 'wp_prefetch_admin_assets', 'la_filter_prefetch_admin_assets', 10, 2 );
	}

	/*
	 * The media modal is a separate deferral: wp_enqueue_media() loads the whole
	 * media library stack for a single picker on Settings > General, and core
	 * never defers it, on any version. Unlike the palette part above, this runs
	 * on 7.3 and later too.
	 */
	add_action( 'admin_enqueue_scripts', 'la_snapshot_admin_queues', 1000 );
	add_action( 'admin_footer', 'la_maybe_defer_media_assets', 9 );
	add_action( 'admin_print_footer_scripts', 'la_print_media_assets_template', 21 );

	/*
	 * CodeMirror is the same shape of win in the Customizer: the Additional CSS
	 * section (and the Custom HTML widget) load the whole code editor up front
	 * for a section that starts collapsed. These hooks only fire on
	 * customize.php, so no screen check is needed. Like the media part, this
	 * runs on every version. The dequeue runs at the end of the enqueue hook,
	 * not in the footer: the code editor is a head script, so by the footer
	 * it has already printed.
	 */
	add_action( 'customize_controls_enqueue_scripts', 'la_maybe_defer_codemirror_assets', 1000 );
	add_action( 'customize_controls_print_footer_scripts', 'la_print_codemirror_assets_template', 21 );

	/*
	 * The Customizer's media stack is the same shape of win: the media modal
	 * scripts (media views, the uploader, the image editor, the audio and
	 * video player) load up front for the Site Icon, logo, header image,
	 * background image and media widget controls, and they are only needed
	 * when one of those controls is used. These hooks only fire on
	 * customize.php, so no screen check is needed. Like the other deferrals,
	 * this runs on every version. media-models stays up front: a media
	 * control with a value already set fetches its attachment data through
	 * wp.media.attachment() when the control is created, and that lives in
	 * media-models. The dequeue runs at the end of the enqueue hook, not in
	 * the footer: the media scripts are head scripts, so by the footer they
	 * have already printed.
	 */
	add_action( 'customize_controls_enqueue_scripts', 'la_maybe_defer_customizer_media_assets', 1000 );
	add_action( 'customize_controls_print_footer_scripts', 'la_print_customizer_media_assets_template', 21 );

	/*
	 * The update and install scripts are the same shape of win on the screens
	 * that manage plugins, themes and updates: updates.js, thickbox,
	 * plugin-install.js and media-upload.js are loaded up front for buttons
	 * that are only used on click. Unlike the media modal, these screens
	 * enqueue them before the head prints, so the snapshot cannot tell them
	 * apart from the page's own scripts; the handles are named explicitly
	 * instead. The first pass runs at the end of the enqueue hook, before the
	 * head prints the thickbox stylesheet; the second pass in the footer
	 * catches anything a plugin enqueued while rendering.
	 */
	add_action( 'admin_enqueue_scripts', 'la_maybe_defer_updates_assets', 1000 );
	add_action( 'admin_footer', 'la_maybe_defer_updates_assets', 9 );
	add_action( 'admin_print_footer_scripts', 'la_print_updates_assets_template', 21 );

	/*
	 * The media library's audio and video player is the same shape of win:
	 * the 154 KB MediaElement library loads up front because media-views
	 * depends on the wp-mediaelement wrapper, but the player is only built
	 * when an audio or video attachment's details open. The wrapper goes with
	 * it: its initialize() would throw on load without the library, since it
	 * calls the jQuery plugin the library defines. The dependency is removed
	 * from media-views instead of dequeuing the wrapper alone, because
	 * media-views.js never references wp.mediaelement; the player is built
	 * from window.MediaElementPlayer, which the library defines.
	 */
	add_action( 'admin_enqueue_scripts', 'la_maybe_defer_mediaelement_assets', 1000 );
	add_action( 'admin_footer', 'la_maybe_defer_mediaelement_assets', 9 );
	add_action( 'admin_print_footer_scripts', 'la_print_mediaelement_assets_template', 21 );

	/*
	 * The comment Reply editor is the same shape of win on the Dashboard and
	 * the Comments screen: quicktags and wplink load up front for a reply
	 * form that only opens on click. The handles are named explicitly, like
	 * the updates cluster. The first pass runs at the end of the enqueue
	 * hook; the second pass in the footer catches anything enqueued while
	 * rendering. A third pass runs on admin_print_footer_scripts, after
	 * _WP_Editors re-enqueues the editor scripts for the footer (priority
	 * 1) and before the footer scripts print (priority 10): without it the
	 * re-enqueued quicktags would print as a regular footer script instead
	 * of waiting in the template.
	 */
	add_action( 'admin_enqueue_scripts', 'la_maybe_defer_reply_editor_assets', 1000 );
	add_action( 'admin_footer', 'la_maybe_defer_reply_editor_assets', 9 );
	add_action( 'admin_print_footer_scripts', 'la_maybe_defer_reply_editor_assets', 2 );
	add_action( 'admin_print_footer_scripts', 'la_print_reply_editor_assets_template', 21 );

	/*
	 * Quick Edit is the same shape of win on the Posts and Pages lists:
	 * inline-edit-post.js and its tag-suggestion scripts load up front for
	 * a Quick Edit link that is only used on click, and for Bulk Edit,
	 * which is a form submit. The handles are named explicitly, like the
	 * updates cluster. The first pass runs at the end of the enqueue hook;
	 * the second pass in the footer catches anything enqueued while
	 * rendering.
	 */
	add_action( 'admin_enqueue_scripts', 'la_maybe_defer_quick_edit_assets', 1000 );
	add_action( 'admin_footer', 'la_maybe_defer_quick_edit_assets', 9 );
	add_action( 'admin_print_footer_scripts', 'la_print_quick_edit_assets_template', 21 );

	/*
	 * Writing Settings loads the user profile scripts for nothing:
	 * options-writing.php enqueues user-profile (and with it
	 * password-strength-meter) even though the page has no profile or
	 * password field anywhere. Those two scripts are simply not enqueued
	 * there. The enqueue happens while the page file loads, after
	 * admin_enqueue_scripts, so this runs on admin_print_scripts, before
	 * the head prints.
	 */
	add_action( 'admin_print_scripts', 'la_maybe_drop_writing_profile_assets', 1 );

	/*
	 * The admin CSS bundle is the same shape of win, but the fix is
	 * different: WordPress concatenates every admin stylesheet into one
	 * load-styles.php request, and the list comes from the wp-admin
	 * umbrella handle, which pulls in 17 styles on every admin page: the
	 * base styles every screen needs, plus one stylesheet per screen
	 * (dashboard, edit, media, themes, about, nav-menus, widgets,
	 * revisions). Most screens load stylesheets for screens they never
	 * show. The bundle is cached for a year and the URL is identical
	 * across the screens that share it, so the trim must not split that
	 * shared URL: a handle is removed from the umbrella only when no
	 * screen sharing the URL uses it, and it is re-enqueued on the
	 * screens that do need it, so those screens keep their own URL.
	 * list-tables stays in the bundle everywhere: it is what the list
	 * screens share, and removing it from only some of them would split
	 * the URL. The editor and the Customizer have their own URL and do
	 * not use it, so it goes there too. This runs on every version.
	 * customize.php never fires admin_enqueue_scripts, so the trim hooks
	 * customize_controls_enqueue_scripts there as well.
	 */
	add_action( 'admin_enqueue_scripts', 'la_maybe_trim_admin_css', 1000 );
	add_action( 'customize_controls_enqueue_scripts', 'la_maybe_trim_admin_css', 1000 );
}

/**
 * Enqueues the Command Palette loader and the palette's settings.
 *
 * This mirrors wp_enqueue_command_palette_loader() from WordPress 7.3 (Trac #66642):
 * the settings are built exactly as wp_enqueue_command_palette_assets() builds them,
 * but only the 1.4 KB loader is enqueued. The loader adds the palette's scripts and
 * styles to the page when the palette is first opened, with the keyboard shortcut or
 * the toolbar button, so that they do not hold back every admin screen. Until then
 * they wait in the template printed by la_print_command_palette_assets_template().
 * A screen that already loads the commands package, such as the block editor, gets
 * the rest of the palette up front instead.
 *
 * @global array $menu
 * @global array $submenu
 */
function la_enqueue_command_palette_loader() {
	global $menu, $submenu;

	$command_palette_settings = array(
		'is_network_admin' => is_network_admin(),
	);

	/**
	 * Extracts root-level text nodes from HTML string.
	 *
	 * @ignore
	 * @param string $label HTML string to extract text from.
	 * @return string Extracted text content, trimmed.
	 */
	$extract_root_text = static function ( string $label ): string {
		if ( '' === $label ) {
			return '';
		}

		$processor  = new WP_HTML_Tag_Processor( $label );
		$text_parts = array();
		$depth      = 0;

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( '#text' === $token_type ) {
				if ( 0 === $depth ) {
					$text_parts[] = $processor->get_modifiable_text();
				}
				continue;
			}

			if ( '#tag' !== $token_type ) {
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				if ( $depth > 0 ) {
					--$depth;
				}
				continue;
			}

			$token_name = $processor->get_tag();
			if ( $token_name && ! WP_HTML_Processor::is_void( $token_name ) ) {
				++$depth;
			}
		}

		return trim( implode( '', $text_parts ) );
	};

	if ( $menu ) {
		$menu_commands = array();
		foreach ( $menu as $menu_item ) {
			if ( empty( $menu_item[0] ) || ! is_string( $menu_item[0] ) || ! empty( $menu_item[1] ) && ! current_user_can( $menu_item[1] ) ) {
				continue;
			}

			$menu_label = $extract_root_text( $menu_item[0] );
			$menu_url   = '';
			$menu_slug  = $menu_item[2];

			if ( preg_match( '/\.php($|\?)/', $menu_slug ) || wp_http_validate_url( $menu_slug ) ) {
				$menu_url = $menu_slug;
			} elseif ( ! empty( menu_page_url( $menu_slug, false ) ) ) {
				$menu_url = WP_HTML_Decoder::decode_attribute( menu_page_url( $menu_slug, false ) );
			}

			if ( $menu_url ) {
				$menu_commands[] = array(
					'label' => $menu_label,
					'url'   => $menu_url,
					'name'  => $menu_slug,
				);
			}

			if ( array_key_exists( $menu_slug, $submenu ) ) {
				foreach ( $submenu[ $menu_slug ] as $submenu_item ) {
					if ( empty( $submenu_item[0] ) || ! empty( $submenu_item[1] ) && ! current_user_can( $submenu_item[1] ) ) {
						continue;
					}

					$submenu_label = $extract_root_text( $submenu_item[0] );
					$submenu_url   = '';
					$submenu_slug  = $submenu_item[2];

					if ( preg_match( '/\.php($|\?)/', $submenu_slug ) || wp_http_validate_url( $submenu_slug ) ) {
						$submenu_url = $submenu_slug;
					} elseif ( ! empty( menu_page_url( $submenu_slug, false ) ) ) {
						$submenu_url = WP_HTML_Decoder::decode_attribute( menu_page_url( $submenu_slug, false ) );
					}
					if ( $submenu_url ) {
						$menu_commands[] = array(
							'label' => sprintf(
								/* translators: 1: Menu label, 2: Submenu label. */
								__( '%1$s > %2$s' ),
								$menu_label,
								$submenu_label
							),
							'url'   => $submenu_url,
							'name'  => $menu_slug . '-' . $submenu_item[2],
						);
					}
				}
			}
		}
		$command_palette_settings['menu_commands'] = $menu_commands;
	}

	wp_register_script(
		'wp-command-palette-loader',
		plugins_url( 'assets/js/command-palette-loader.js', __FILE__ ),
		array(),
		LA_VERSION,
		true
	);
	wp_enqueue_script( 'wp-command-palette-loader' );

	wp_add_inline_script(
		'wp-core-commands',
		sprintf(
			'wp.coreCommands.initializeCommandPalette( %s );',
			wp_json_encode( $command_palette_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
		)
	);

	if ( wp_script_is( 'wp-commands', 'enqueued' ) ) {
		wp_enqueue_style( 'wp-commands' );
		wp_enqueue_script( 'wp-core-commands' );

		/**
		 * Fires when the Command Palette's scripts and styles are enqueued.
		 *
		 * Scripts and styles that add commands to the palette should be enqueued on this
		 * action. On screens that load the palette when it is first opened, the action fires
		 * in the admin footer, and what is enqueued on it is loaded together with the palette.
		 *
		 * This is the same action WordPress 7.3 fires; plugins written for it work here too.
		 */
		do_action( 'enqueue_command_palette_assets' );
	}
}

/**
 * Prints the Command Palette's scripts and styles into an inert template.
 *
 * This mirrors wp_print_command_palette_assets_template() from WordPress 7.3
 * (Trac #66642). The template holds what the palette needs and the page has not
 * printed yet, including the scripts and styles enqueued on the
 * {@see 'enqueue_command_palette_assets'} action. The script enqueued by
 * la_enqueue_command_palette_loader() adds it to the page when the palette is
 * first opened. Nothing is printed when the palette is already on the page.
 */
function la_print_command_palette_assets_template() {
	if ( ! wp_script_is( 'wp-command-palette-loader', 'done' ) || wp_script_is( 'wp-core-commands', 'done' ) ) {
		return;
	}

	$scripts                = wp_scripts();
	$styles                 = wp_styles();
	$scripts_queued_by_page = $scripts->queue;
	$styles_queued_by_page  = $styles->queue;

	/** This action is documented in la_enqueue_command_palette_loader(). */
	do_action( 'enqueue_command_palette_assets' );

	$palette_scripts = array_merge( array( 'wp-core-commands' ), array_diff( $scripts->queue, $scripts_queued_by_page ) );
	$palette_styles  = array_merge( array( 'wp-commands' ), array_diff( $styles->queue, $styles_queued_by_page ) );

	ob_start();
	$styles->do_items( $palette_styles );
	$scripts->do_items( $palette_scripts );
	$assets = ob_get_clean();

	printf( "<template id=\"wp-command-palette-assets\">\n%s</template>\n", $assets );
}

/**
 * Swaps core's toolbar button for the loader's version.
 *
 * Core registers its button during WP_Admin_Bar::initialize(), at priority 55 on
 * admin_bar_menu, so this runs first, at priority 0, and swaps it before it can
 * be added. The button is the same, except it opens the palette through the loader
 * instead of an inline handler that needs wp-data on the page.
 */
function la_replace_admin_bar_command_palette_menu() {
	$priority = has_action( 'admin_bar_menu', 'wp_admin_bar_command_palette_menu' );
	if ( false === $priority ) {
		return;
	}

	remove_action( 'admin_bar_menu', 'wp_admin_bar_command_palette_menu', $priority );
	add_action( 'admin_bar_menu', 'la_admin_bar_command_palette_menu', $priority );
}

/**
 * Adds the command palette trigger button.
 *
 * Displays a button in the admin bar that shows the keyboard shortcut
 * for opening the command palette.
 *
 * This mirrors wp_admin_bar_command_palette_menu() from WordPress 7.3 (Trac #66642):
 * the button shows when the loader is enqueued, and the loader handles the click.
 *
 * @param WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
 */
function la_admin_bar_command_palette_menu( WP_Admin_Bar $wp_admin_bar ): void {
	if ( ! is_admin() || ! wp_script_is( 'wp-command-palette-loader', 'enqueued' ) ) {
		return;
	}

	$shortcut_labels = array(
		'appleOS' => _x( '⌘K', 'keyboard shortcut to open the command palette' ),
		'default' => _x( 'Ctrl+K', 'keyboard shortcut to open the command palette' ),
	);
	$apple_pattern   = 'Macintosh|Mac OS X|Mac_PowerPC';
	$is_apple_os     = (bool) preg_match( "/{$apple_pattern}/i", $_SERVER['HTTP_USER_AGENT'] ?? '' );
	$shortcut_label  = $is_apple_os ? $shortcut_labels['appleOS'] : $shortcut_labels['default'];
	$title           = sprintf(
		'<span class="ab-label"><kbd>%s</kbd><span class="screen-reader-text"> %s</span></span>',
		$shortcut_label,
		/* translators: Hidden accessibility text. */
		__( 'Open command palette' ),
	);
	/*
	 * Detect Apple OS via JavaScript for sites behind a CDN blocking the UA header.
	 *
	 * Running the script as the admin bar is rendered avoids a flash of incorrect content
	 * for users with Apple OS when the UA header is blocked. It also prevents the need for
	 * wp-i18n to be loaded as a dependency.
	 */
	$function = <<<'JS'
		( applePattern, appleOSLabel ) => {
			if ( ! ( new RegExp( applePattern, 'i' ) ).test( navigator.userAgent ) ) {
				return;
			}
			const kbd = document.querySelector( '#wp-admin-bar-command-palette .ab-label kbd' );
			if ( kbd ) {
				kbd.textContent = appleOSLabel;
			}
		}
	JS;
	$script   = sprintf(
		'( %s )( %s, %s );',
		$function,
		wp_json_encode( $apple_pattern, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
		wp_json_encode( $shortcut_labels['appleOS'], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
	);
	$script  .= "\n//# sourceURL=" . rawurlencode( __FUNCTION__ );
	$wp_admin_bar->add_node(
		array(
			'id'    => 'command-palette',
			'icon'  => 'core-admin/search',
			'title' => $title,
			'href'  => '#',
			'meta'  => array(
				'class' => 'hide-if-no-js',
				'html'  => wp_get_inline_script_tag( $script ),
			),
		)
	);
}

/**
 * Keeps the Command Palette out of the login screen prefetch.
 *
 * On WordPress 7.2 and later the login screen prefetches the scripts of the screen
 * the user is about to land on, including the palette's. Since this plugin loads the
 * palette on demand, prefetching it only wastes the download. The palette stays in
 * the prefetch when the next screen is an editor, where it loads up front anyway.
 *
 * @param array  $resources   Array of resources and their attributes to prefetch.
 * @param string $next_screen URL of the screen the assets are being prefetched for.
 * @return array Resources without the palette's scripts and styles, where applicable.
 */
function la_filter_prefetch_admin_assets( $resources, $next_screen ) {
	if ( ! is_array( $resources ) || la_is_editor_screen_url( $next_screen ) ) {
		return $resources;
	}

	$palette_paths = la_command_palette_asset_paths();
	if ( ! $palette_paths ) {
		return $resources;
	}

	return array_values(
		array_filter(
			$resources,
			static function ( $resource ) use ( $palette_paths ) {
				$href = isset( $resource['href'] ) && is_string( $resource['href'] ) ? $resource['href'] : '';
				if ( '' === $href ) {
					return true;
				}

				$path = (string) wp_parse_url( $href, PHP_URL_PATH );
				foreach ( $palette_paths as $palette_path ) {
					if ( '' !== $palette_path && substr( $path, -strlen( $palette_path ) ) === $palette_path ) {
						return false;
					}
				}

				return true;
			}
		)
	);
}

/**
 * Tells whether a URL points at a screen that loads the palette up front.
 *
 * @param string $url URL to check. May be relative.
 * @return bool Whether the URL is the post editor, the site editor, or the customizer.
 */
function la_is_editor_screen_url( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	$base = basename( $path );

	return in_array( $base, array( 'post.php', 'post-new.php', 'site-editor.php', 'customize.php', 'widgets.php' ), true );
}

/**
 * Returns the URL paths of the Command Palette's scripts and styles.
 *
 * The paths come from the script and style registries, so they follow the
 * dependencies declared in wp_default_scripts() and any version suffixes.
 *
 * @return string[] URL paths, without query strings.
 */
function la_command_palette_asset_paths() {
	$paths = array();

	$scripts = wp_scripts();
	if ( $scripts instanceof WP_Scripts ) {
		$handles = $scripts->all_deps( array( 'wp-commands', 'wp-core-commands' ) );
		foreach ( (array) $handles as $handle ) {
			if ( ! isset( $scripts->registered[ $handle ] ) ) {
				continue;
			}
			$src = $scripts->registered[ $handle ]->src;
			if ( $src ) {
				$paths[] = (string) wp_parse_url( $src, PHP_URL_PATH );
			}
		}
	}

	$styles = wp_styles();
	if ( $styles instanceof WP_Styles && isset( $styles->registered['wp-commands'] ) ) {
		$src = $styles->registered['wp-commands']->src;
		if ( $src ) {
			$paths[] = (string) wp_parse_url( $src, PHP_URL_PATH );
		}
	}

	return array_values( array_unique( array_filter( $paths ) ) );
}

/**
 * Records the scripts and styles the page itself enqueued.
 *
 * This runs last on admin_enqueue_scripts, before the page body is rendered.
 * Anything enqueued after this point, such as the media stack that
 * options-general.php loads while rendering the Site Icon setting, is new,
 * and la_maybe_defer_media_assets() can tell it apart from the page's own.
 */
function la_snapshot_admin_queues() {
	$GLOBALS['la_queued_scripts'] = wp_scripts()->queue;
	$GLOBALS['la_queued_styles']  = wp_styles()->queue;
}

/**
 * Returns a handle and everything it depends on, in dependency order.
 *
 * WP_Dependencies::all_deps() looks like the tool for this, but it returns a
 * boolean and rewrites the queue as a side effect, so it cannot be used to
 * inspect a closure. This walks the registry instead, without touching it.
 *
 * @param WP_Dependencies $dependencies The script or style registry.
 * @param string[]        $roots        Handles to start from.
 * @return string[] The roots and their dependencies, each listed once.
 */
function la_dependency_closure( $dependencies, $roots ) {
	$closure = array();

	$walk = static function ( $handles ) use ( &$walk, $dependencies, &$closure ) {
		foreach ( (array) $handles as $handle ) {
			if ( isset( $closure[ $handle ] ) || ! isset( $dependencies->registered[ $handle ] ) ) {
				continue;
			}
			$closure[ $handle ] = true;
			$walk( $dependencies->registered[ $handle ]->deps );
		}
	};

	$walk( $roots );

	return array_keys( $closure );
}

/**
 * Tells whether the current screen defers the media modal.
 *
 * Only Settings > General does today: it is the one core screen that loads the
 * whole media library stack for a single picker, the Site Icon button. The post
 * editor, the media library and the customizer need the modal up front, so they
 * are left alone.
 *
 * @return bool Whether the media modal is deferred on this screen.
 */
function la_screen_defers_media() {
	/**
	 * Filters the screens that load the media modal on demand.
	 *
	 * @param string[] $screens Screen IDs, as returned by get_current_screen()->id.
	 */
	$screens = apply_filters( 'la_defer_media_screens', array( 'options-general' ) );
	$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array( $screen->id, (array) $screens, true );
}

/**
 * Moves the media modal's scripts, styles and templates into the page footer.
 *
 * This runs on admin_footer, after the page body is rendered, so the media stack
 * that wp_enqueue_media() enqueued while rendering is in the queue but not yet
 * printed. Only handles that appeared after the snapshot, and that belong to the
 * media stack, are deferred; a handle another script on the page still needs
 * prints normally, because WordPress resolves it as that script's dependency.
 * The deferred assets wait in the template printed by
 * la_print_media_assets_template(), and the loader enqueued here adds them to
 * the page when the Site Icon button is clicked. Core's site-icon.js is left
 * exactly as it is, so its translations and its remove-button behavior are
 * untouched; the loader intercepts the click, loads the assets, and then lets
 * the click through to it.
 */
function la_maybe_defer_media_assets() {
	if ( ! la_screen_defers_media() ) {
		return;
	}

	/*
	 * The media modal's HTML templates print on admin_footer too. Keep them out
	 * of the page until the modal opens; the loader adds them with the scripts.
	 */
	if ( has_action( 'admin_footer', 'wp_print_media_templates' ) ) {
		remove_action( 'admin_footer', 'wp_print_media_templates' );
		$GLOBALS['la_defer_media_templates'] = true;
	}

	$scripts = wp_scripts();
	$styles  = wp_styles();

	$new_scripts = array_diff( $scripts->queue, $GLOBALS['la_queued_scripts'] ?? array() );
	$new_styles  = array_diff( $styles->queue, $GLOBALS['la_queued_styles'] ?? array() );

	$media_scripts = la_dependency_closure( $scripts, array( 'media-editor', 'media-audiovideo', 'mce-view', 'image-edit', 'plupload', 'wp-plupload' ) );
	$media_styles  = la_dependency_closure( $styles, array( 'media-views', 'imgareaselect', 'wp-mediaelement' ) );

	$defer_scripts = array_values( array_intersect( $new_scripts, $media_scripts ) );
	$defer_styles  = array_values( array_intersect( $new_styles, $media_styles ) );

	if ( ! $defer_scripts && ! $defer_styles && empty( $GLOBALS['la_defer_media_templates'] ) ) {
		return;
	}

	$GLOBALS['la_deferred_media_scripts'] = $defer_scripts;
	$GLOBALS['la_deferred_media_styles']  = $defer_styles;

	foreach ( $defer_scripts as $handle ) {
		wp_dequeue_script( $handle );
	}
	foreach ( $defer_styles as $handle ) {
		wp_dequeue_style( $handle );
	}

	wp_register_script(
		'la-media-loader',
		plugins_url( 'assets/js/media-loader.js', __FILE__ ),
		array(),
		LA_VERSION,
		true
	);
	wp_enqueue_script( 'la-media-loader' );
}

/**
 * Prints the media modal's scripts, styles and templates into an inert template.
 *
 * The loader adds the template's contents to the page when the Site Icon button
 * is clicked. Nothing is printed when the modal is already on the page, or when
 * there was nothing to defer.
 */
function la_print_media_assets_template() {
	$scripts   = $GLOBALS['la_deferred_media_scripts'] ?? array();
	$styles    = $GLOBALS['la_deferred_media_styles'] ?? array();
	$templates = ! empty( $GLOBALS['la_defer_media_templates'] );

	if ( ! $scripts && ! $styles && ! $templates ) {
		return;
	}

	ob_start();
	wp_styles()->do_items( $styles );
	wp_scripts()->do_items( $scripts );
	if ( $templates ) {
		wp_print_media_templates();
	}
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-media-assets\">\n%s</template>\n", $assets );
}

/**
 * Moves the Customizer's code editor into the page footer.
 *
 * This runs at the end of customize_controls_enqueue_scripts, after the
 * controls were registered and wp_enqueue_code_editor() put the code editor
 * in the queue, but before the head prints it. Only the
 * handles that belong to the code editor itself are deferred: the wrapper, the
 * CodeMirror library, its stylesheet, and the lint libraries, if any were
 * enqueued. Nothing else on the page uses those handles, and the base scripts
 * they depend on, jQuery and Underscore, are left alone. The deferred assets
 * wait in the template printed by la_print_codemirror_assets_template(), and
 * the loader enqueued here adds them to the page when the Additional CSS
 * section is first opened, or when a Custom HTML widget form opens. Core's
 * code editor control already waits for its section to expand before it
 * initializes the editor; the loader intercepts that expand, and wraps the
 * control's init as a safety net for sections that are already expanded.
 */
function la_maybe_defer_codemirror_assets() {
	$defer_scripts = array();
	foreach ( array( 'code-editor', 'wp-codemirror', 'csslint', 'jshint', 'htmlhint', 'htmlhint-kses', 'jsonlint', 'esprima' ) as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			$defer_scripts[] = $handle;
		}
	}

	$defer_styles = array();
	foreach ( array( 'code-editor', 'wp-codemirror' ) as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			$defer_styles[] = $handle;
		}
	}

	if ( ! $defer_scripts && ! $defer_styles ) {
		return;
	}

	$GLOBALS['la_deferred_codemirror_scripts'] = $defer_scripts;
	$GLOBALS['la_deferred_codemirror_styles']  = $defer_styles;

	foreach ( $defer_scripts as $handle ) {
		wp_dequeue_script( $handle );
	}
	foreach ( $defer_styles as $handle ) {
		wp_dequeue_style( $handle );
	}

	wp_register_script(
		'la-codemirror-loader',
		plugins_url( 'assets/js/codemirror-loader.js', __FILE__ ),
		array( 'customize-controls' ),
		LA_VERSION,
		true
	);
	wp_enqueue_script( 'la-codemirror-loader' );
}

/**
 * Prints the Customizer's code editor into an inert template.
 *
 * The loader adds the template's contents to the page when the Additional CSS
 * section is first opened, or when a Custom HTML widget form opens. Nothing is
 * printed when the code editor was never enqueued, for example when syntax
 * highlighting is turned off for the user.
 */
function la_print_codemirror_assets_template() {
	$scripts = $GLOBALS['la_deferred_codemirror_scripts'] ?? array();
	$styles  = $GLOBALS['la_deferred_codemirror_styles'] ?? array();

	if ( ! $scripts && ! $styles ) {
		return;
	}

	ob_start();
	wp_styles()->do_items( $styles );
	wp_scripts()->do_items( $scripts );
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-codemirror-assets\">\n%s</template>\n", $assets );
}

/**
 * Defers the Customizer's media stack until a media control is used.
 *
 * wp_enqueue_media() loads the whole media modal stack up front on
 * customize.php for the Site Icon, logo, header image, background image and
 * media widget controls. The stack is only needed when one of those controls
 * opens the modal, so the handles below wait in the template printed by
 * la_print_customizer_media_assets_template(), and the loader adds them when
 * a media control's button is first used.
 *
 * media-models stays up front on purpose. A media control with a value
 * already set fetches its attachment data through wp.media.attachment()
 * when the control is created (customize-controls.js, MediaControl.ready),
 * and that lives in media-models. The imgareaselect stylesheet also stays
 * up front: it is a dependency of the Customizer's own stylesheet, so
 * dequeuing it would pull it back in when that stylesheet prints. It is
 * 0.8 KB. The imgareaselect script is deferred; nothing up front depends
 * on it at load time.
 *
 * The handles are named explicitly, like the updates cluster: they are
 * enqueued during the controls' enqueue step, before the head prints, so a
 * snapshot cannot tell them apart from the page's own scripts.
 */
function la_maybe_defer_customizer_media_assets() {
	$defer_scripts = array();
	foreach ( array( 'media-views', 'media-editor', 'media-audiovideo', 'mce-view', 'image-edit', 'moxiejs', 'plupload', 'wp-plupload', 'wp-mediaelement', 'mediaelement', 'mediaelement-core', 'mediaelement-migrate', 'imgareaselect' ) as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			$defer_scripts[] = $handle;
		}
	}

	$defer_styles = array();
	foreach ( array( 'media-views', 'mediaelement', 'wp-mediaelement' ) as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			$defer_styles[] = $handle;
		}
	}

	if ( ! $defer_scripts && ! $defer_styles ) {
		return;
	}

	/*
	 * customize-views is enqueued up front and declares media-editor,
	 * media-views and imgareaselect as dependencies, so WordPress would
	 * print them in the head as its dependencies even after they are
	 * dequeued here. customize-views.js never references wp.media or
	 * imgareaselect (both are only used by the media modal and the image
	 * cropper), so those dependencies are removed from its dependency list
	 * instead. The media stack still reaches the page through the
	 * template, in dependency order, when a media control is first used.
	 */
	$scripts = wp_scripts();
	if ( isset( $scripts->registered['customize-views'] ) ) {
		$scripts->registered['customize-views']->deps = array_values(
			array_diff(
				$scripts->registered['customize-views']->deps,
				array( 'media-editor', 'media-views', 'imgareaselect' )
			)
		);
	}

	/*
	 * media-models and wp-api-request stay up front on purpose. A media
	 * control with a value already set fetches its attachment data through
	 * wp.media.attachment() when the control is created
	 * (customize-controls.js, MediaControl.ready), and that lives in
	 * media-models; the fetch itself uses wp.apiRequest. Without an
	 * explicit enqueue here, nothing up front would depend on them, so
	 * they would be pulled into the template as media-views dependencies
	 * instead and the control's ready() would throw.
	 */
	wp_enqueue_script( 'media-models' );
	wp_enqueue_script( 'wp-api-request' );

	$GLOBALS['la_deferred_customizer_media_scripts'] = $defer_scripts;
	$GLOBALS['la_deferred_customizer_media_styles']  = $defer_styles;

	foreach ( $defer_scripts as $handle ) {
		wp_dequeue_script( $handle );
	}
	foreach ( $defer_styles as $handle ) {
		wp_dequeue_style( $handle );
	}

	wp_register_script(
		'la-customizer-media-loader',
		plugins_url( 'assets/js/customizer-media-loader.js', __FILE__ ),
		array( 'customize-controls' ),
		LA_VERSION,
		true
	);
	wp_enqueue_script( 'la-customizer-media-loader' );
}

/**
 * Prints the Customizer's media stack into an inert template.
 *
 * The loader adds the template's contents to the page when a media control's
 * button is first used. Nothing is printed when the media stack was never
 * enqueued, for example on a Customizer screen without media controls.
 */
function la_print_customizer_media_assets_template() {
	$scripts = $GLOBALS['la_deferred_customizer_media_scripts'] ?? array();
	$styles  = $GLOBALS['la_deferred_customizer_media_styles'] ?? array();

	if ( ! $scripts && ! $styles ) {
		return;
	}

	ob_start();
	wp_styles()->do_items( $styles );
	wp_scripts()->do_items( $scripts );
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-customizer-media-assets\">\n%s</template>\n", $assets );
}

/**
 * Tells whether the current screen defers the update and install scripts.
 *
 * The Dashboard, Themes, Plugins, Add Plugins, Update Core and Import screens
 * load updates.js, thickbox, plugin-install.js and media-upload.js up front
 * for buttons that are only used on click. Other screens that load those
 * scripts, such as the post editor's media tabs, need them where they are.
 *
 * @return bool Whether the update and install scripts are deferred on this screen.
 */
function la_screen_defers_updates() {
	/**
	 * Filters the screens that load the update and install scripts on demand.
	 *
	 * @param string[] $screens Screen IDs, as returned by get_current_screen()->id.
	 */
	$screens = apply_filters( 'la_defer_updates_screens', array( 'dashboard', 'themes', 'plugins', 'plugin-install', 'update-core', 'import' ) );
	$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array( $screen->id, (array) $screens, true );
}

/**
 * Moves the update and install scripts and the thickbox stylesheet into the page footer.
 *
 * This runs at the end of admin_enqueue_scripts, after the screens above
 * enqueued the cluster, and again in the footer for anything a plugin
 * enqueued while rendering. Only the four scripts and the one stylesheet are
 * deferred: updates.js, thickbox, plugin-install.js and media-upload.js, and
 * thickbox.css. The libraries they depend on, jQuery, jQuery UI Core,
 * Shortcode and the rest, are left alone, so a plugin that uses one of them
 * without declaring it keeps working. The deferred assets wait in the
 * template printed by la_print_updates_assets_template(), and the loader
 * enqueued here adds them to the page when one of their buttons is first
 * used. A script that still needs one of them prints it normally, because
 * WordPress resolves it as that script's dependency.
 */
function la_maybe_defer_updates_assets() {
	if ( ! la_screen_defers_updates() ) {
		return;
	}

	$defer_scripts = $GLOBALS['la_deferred_updates_scripts'] ?? array();
	$defer_styles  = $GLOBALS['la_deferred_updates_styles'] ?? array();

	// plugin-install must come after media-upload: both overwrite
	// window.tb_position. media-upload.js does it synchronously in an IIFE,
	// plugin-install.js in a jQuery ready callback. If plugin-install.js
	// runs first, its ready callback fires while the next script is being
	// fetched and media-upload.js's IIFE then wins, leaving
	// plugin-install.js's tbWindow closure unset, so the plugin-details
	// modal throws on tbWindow.hasClass. Printing plugin-install last makes
	// its ready callback fire after media-upload.js ran, so window.tb_position
	// ends up as plugin-install.js's version, matching a normal page.
	foreach ( array( 'updates', 'thickbox', 'media-upload', 'plugin-install' ) as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) && ! in_array( $handle, $defer_scripts, true ) ) {
			$defer_scripts[] = $handle;
			wp_dequeue_script( $handle );
		}
	}

	foreach ( array( 'thickbox' ) as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) && ! in_array( $handle, $defer_styles, true ) ) {
			$defer_styles[] = $handle;
			wp_dequeue_style( $handle );
		}
	}

	if ( ! $defer_scripts && ! $defer_styles ) {
		return;
	}

	$GLOBALS['la_deferred_updates_scripts'] = $defer_scripts;
	$GLOBALS['la_deferred_updates_styles']  = $defer_styles;

	if ( ! wp_script_is( 'la-updates-loader', 'enqueued' ) && ! wp_script_is( 'la-updates-loader', 'done' ) ) {
		wp_register_script(
			'la-updates-loader',
			plugins_url( 'assets/js/updates-loader.js', __FILE__ ),
			array(),
			LA_VERSION,
			true
		);
		wp_enqueue_script( 'la-updates-loader' );
	}
}

/**
 * Prints the update and install scripts and the thickbox stylesheet into an inert template.
 *
 * The loader adds the template's contents to the page when one of the
 * update, install, details or auto-update buttons is first used. Nothing is
 * printed when the cluster was never enqueued on the screen.
 */
function la_print_updates_assets_template() {
	$scripts = $GLOBALS['la_deferred_updates_scripts'] ?? array();
	$styles  = $GLOBALS['la_deferred_updates_styles'] ?? array();

	if ( ! $scripts && ! $styles ) {
		return;
	}

	ob_start();
	wp_styles()->do_items( $styles );
	wp_scripts()->do_items( $scripts );
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-updates-assets\">\n%s</template>\n", $assets );
}

/**
 * Tells whether the current screen defers the media library's audio and video player.
 *
 * Only the media library does today: it is the one screen that loads the
 * MediaElement library for audio and video previews that most views never
 * open. The post editor and the customizer build audio and video players up
 * front, so they are left alone.
 *
 * @return bool Whether the audio and video player is deferred on this screen.
 */
function la_screen_defers_mediaelement() {
	/**
	 * Filters the screens that load the media library's audio and video player on demand.
	 *
	 * @param string[] $screens Screen IDs, as returned by get_current_screen()->id.
	 */
	$screens = apply_filters( 'la_defer_mediaelement_screens', array( 'upload' ) );
	$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array( $screen->id, (array) $screens, true );
}

/**
 * Moves the media library's audio and video player into the page footer.
 *
 * This runs at the end of admin_enqueue_scripts, after upload.php enqueued
 * the media stack, and again in the footer for anything enqueued while
 * rendering. The MediaElement library, its migrate script, the
 * wp-mediaelement wrapper and both stylesheets are deferred. The wrapper's
 * dependency is removed from media-views, so WordPress does not print the
 * library back as that script's dependency; media-views.js never references
 * wp.mediaelement, and the player is built from window.MediaElementPlayer,
 * which the library defines. The deferred assets wait in the template printed
 * by la_print_mediaelement_assets_template(), and the loader enqueued here
 * adds them to the page when an audio or video attachment's details open.
 */
function la_maybe_defer_mediaelement_assets() {
	if ( ! la_screen_defers_mediaelement() ) {
		return;
	}

	/*
	 * The player is never enqueued directly: it only arrives as a dependency
	 * of media-views, so wp_script_is() cannot see it. What matters is
	 * whether it is in the dependency closure of what the page enqueued.
	 */
	$scripts = wp_scripts();
	$styles  = wp_styles();

	$script_closure = la_dependency_closure( $scripts, $scripts->queue );
	$style_closure  = la_dependency_closure( $styles, $styles->queue );

	if ( ! in_array( 'wp-mediaelement', $script_closure, true ) && ! in_array( 'wp-mediaelement', $style_closure, true ) ) {
		return;
	}

	/*
	 * media-views depends on wp-mediaelement, so without this WordPress
	 * prints the library back as that script's dependency. The dependency is
	 * removed from the registry, not from the page: media-views.js never
	 * references wp.mediaelement.
	 */
	if ( isset( $scripts->registered['media-views'] ) ) {
		$scripts->registered['media-views']->deps = array_values(
			array_diff( $scripts->registered['media-views']->deps, array( 'wp-mediaelement' ) )
		);
	}

	if ( isset( $styles->registered['media-views'] ) ) {
		$styles->registered['media-views']->deps = array_values(
			array_diff( $styles->registered['media-views']->deps, array( 'wp-mediaelement' ) )
		);
	}

	/*
	 * The handles are named explicitly: they are dependencies, not queue
	 * entries, so dequeuing alone cannot keep them off the page. The
	 * template printer adds them from the registry instead.
	 */
	$defer_scripts = $GLOBALS['la_deferred_mediaelement_scripts'] ?? array();
	$defer_styles  = $GLOBALS['la_deferred_mediaelement_styles'] ?? array();

	foreach ( array( 'mediaelement', 'mediaelement-core', 'mediaelement-migrate', 'wp-mediaelement' ) as $handle ) {
		if ( ! in_array( $handle, $defer_scripts, true ) ) {
			$defer_scripts[] = $handle;
		}
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			wp_dequeue_script( $handle );
		}
	}

	foreach ( array( 'mediaelement', 'wp-mediaelement' ) as $handle ) {
		if ( ! in_array( $handle, $defer_styles, true ) ) {
			$defer_styles[] = $handle;
		}
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			wp_dequeue_style( $handle );
		}
	}

	$GLOBALS['la_deferred_mediaelement_scripts'] = $defer_scripts;
	$GLOBALS['la_deferred_mediaelement_styles']  = $defer_styles;

	if ( ! wp_script_is( 'la-mediaelement-loader', 'enqueued' ) && ! wp_script_is( 'la-mediaelement-loader', 'done' ) ) {
		wp_register_script(
			'la-mediaelement-loader',
			plugins_url( 'assets/js/mediaelement-loader.js', __FILE__ ),
			array(),
			LA_VERSION,
			true
		);
		wp_enqueue_script( 'la-mediaelement-loader' );
	}
}

/**
 * Prints the media library's audio and video player into an inert template.
 *
 * The loader adds the template's contents to the page when an audio or video
 * attachment's details open. Nothing is printed when the player was never
 * enqueued on the screen.
 */
function la_print_mediaelement_assets_template() {
	$scripts = $GLOBALS['la_deferred_mediaelement_scripts'] ?? array();
	$styles  = $GLOBALS['la_deferred_mediaelement_styles'] ?? array();

	if ( ! $scripts && ! $styles ) {
		return;
	}

	ob_start();
	wp_styles()->do_items( $styles );
	wp_scripts()->do_items( $scripts );
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-mediaelement-assets\">\n%s</template>\n", $assets );
}

/**
 * Tells whether the current screen defers the comment Reply editor.
 *
 * Only the Dashboard and the Comments screen do today: they are the two
 * screens that load quicktags and wplink up front for a reply form that
 * only opens on click, and for the Quick Draft toolbar that only matters
 * once the textarea is used. The post editor loads its own editor up
 * front and is left alone.
 *
 * @return bool Whether the Reply editor is deferred on this screen.
 */
function la_screen_defers_reply_editor() {
	/**
	 * Filters the screens that load the comment Reply editor on demand.
	 *
	 * @param string[] $screens Screen IDs, as returned by get_current_screen()->id.
	 */
	$screens = apply_filters( 'la_defer_reply_editor_screens', array( 'dashboard', 'edit-comments' ) );
	$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array( $screen->id, (array) $screens, true );
}

/**
 * Defers the comment Reply editor until a Reply button is used.
 *
 * quicktags and wplink load up front on the Dashboard and the Comments
 * screen for a reply form that only opens on click. The editor's own
 * footer init is guarded (class-wp-editor.php checks that quicktags is
 * defined before calling it), and admin-comments.js only touches QTags
 * behind the same guard, so deferring the scripts does not throw at page
 * load; it just leaves the toolbar unwired. The loader runs the init
 * itself after the scripts load. The handles are named explicitly, like
 * the updates cluster, and the second pass in the footer catches anything
 * enqueued while rendering.
 */
function la_maybe_defer_reply_editor_assets() {
	if ( ! la_screen_defers_reply_editor() ) {
		return;
	}

	$defer_scripts = array();
	foreach ( array( 'quicktags', 'wplink' ) as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			$defer_scripts[] = $handle;
		}
	}

	$defer_styles = array();
	foreach ( array( 'editor' ) as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) ) {
			$defer_styles[] = $handle;
		}
	}

	if ( ! $defer_scripts && ! $defer_styles ) {
		return;
	}

	/*
	 * admin-comments is enqueued up front and declares quicktags as a
	 * dependency, so WordPress would print it in the head as its
	 * dependency even after it is dequeued here. edit-comments.js only
	 * touches QTags behind a guard (if ( typeof QTags != 'undefined' )),
	 * so that dependency is removed from its dependency list instead.
	 * quicktags still reaches the page through the template, in
	 * dependency order, when a Reply button is first used.
	 */
	$scripts = wp_scripts();
	if ( isset( $scripts->registered['admin-comments'] ) ) {
		$scripts->registered['admin-comments']->deps = array_values(
			array_diff(
				$scripts->registered['admin-comments']->deps,
				array( 'quicktags' )
			)
		);
	}

	$GLOBALS['la_deferred_reply_editor_scripts'] = array_unique( array_merge( $GLOBALS['la_deferred_reply_editor_scripts'] ?? array(), $defer_scripts ) );
	$GLOBALS['la_deferred_reply_editor_styles']  = array_unique( array_merge( $GLOBALS['la_deferred_reply_editor_styles'] ?? array(), $defer_styles ) );

	foreach ( $defer_scripts as $handle ) {
		wp_dequeue_script( $handle );
	}
	foreach ( $defer_styles as $handle ) {
		wp_dequeue_style( $handle );
	}

	if ( ! wp_script_is( 'la-reply-editor-loader', 'enqueued' ) ) {
		wp_register_script(
			'la-reply-editor-loader',
			plugins_url( 'assets/js/reply-editor-loader.js', __FILE__ ),
			array( 'jquery' ),
			LA_VERSION,
			true
		);
		wp_enqueue_script( 'la-reply-editor-loader' );
	}
}

/**
 * Prints the comment Reply editor into an inert template.
 *
 * The loader adds the template's contents to the page when a Reply button
 * is first used, and then runs the editor init the footer script would
 * have run. Nothing is printed when the editor was never enqueued on the
 * screen.
 */
function la_print_reply_editor_assets_template() {
	$scripts = $GLOBALS['la_deferred_reply_editor_scripts'] ?? array();
	$styles  = $GLOBALS['la_deferred_reply_editor_styles'] ?? array();

	if ( ! $scripts && ! $styles ) {
		return;
	}

	ob_start();
	wp_styles()->do_items( $styles );
	wp_scripts()->do_items( $scripts );
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-reply-editor-assets\">\n%s</template>\n", $assets );
}

/**
 * Tells whether the current screen defers Quick Edit.
 *
 * Only the Posts and Pages lists do today: they are the screens that load
 * inline-edit-post.js and its tag-suggestion scripts up front for a Quick
 * Edit link that is only used on click. Other list tables that load those
 * scripts need them where they are.
 *
 * @return bool Whether Quick Edit is deferred on this screen.
 */
function la_screen_defers_quick_edit() {
	/**
	 * Filters the screens that load Quick Edit on demand.
	 *
	 * @param string[] $screens Screen IDs, as returned by get_current_screen()->id.
	 */
	$screens = apply_filters( 'la_defer_quick_edit_screens', array( 'edit-post', 'edit-page' ) );
	$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array( $screen->id, (array) $screens, true );
}

/**
 * Defers Quick Edit until a Quick Edit link is used or Bulk Edit is applied.
 *
 * inline-edit-post.js, tags-suggest.js and jquery-ui-autocomplete load up
 * front on the Posts and Pages lists for a Quick Edit link that is only
 * used on click. Nothing else on the page uses those three scripts; the
 * list table itself is server-rendered. The loader adds them when a Quick
 * Edit link is first clicked, or when Bulk Edit is applied with the Edit
 * bulk action. inline-edit-post.js initializes itself in a jQuery
 * DOM-ready callback, which fires as soon as the script loads, so its
 * setup runs before the re-dispatched click opens the editor.
 */
function la_maybe_defer_quick_edit_assets() {
	if ( ! la_screen_defers_quick_edit() ) {
		return;
	}

	$defer_scripts = array();
	foreach ( array( 'inline-edit-post', 'tags-suggest', 'jquery-ui-autocomplete' ) as $handle ) {
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			$defer_scripts[] = $handle;
		}
	}

	if ( ! $defer_scripts ) {
		return;
	}

	$GLOBALS['la_deferred_quick_edit_scripts'] = array_unique( array_merge( $GLOBALS['la_deferred_quick_edit_scripts'] ?? array(), $defer_scripts ) );

	foreach ( $defer_scripts as $handle ) {
		wp_dequeue_script( $handle );
	}

	if ( ! wp_script_is( 'la-quick-edit-loader', 'enqueued' ) ) {
		wp_register_script(
			'la-quick-edit-loader',
			plugins_url( 'assets/js/quick-edit-loader.js', __FILE__ ),
			array( 'jquery' ),
			LA_VERSION,
			true
		);
		wp_enqueue_script( 'la-quick-edit-loader' );
	}
}

/**
 * Prints Quick Edit into an inert template.
 *
 * The loader adds the template's contents to the page when a Quick Edit
 * link is first clicked, or when Bulk Edit is applied with the Edit bulk
 * action. Nothing is printed when Quick Edit was never enqueued on the
 * screen.
 */
function la_print_quick_edit_assets_template() {
	$scripts = $GLOBALS['la_deferred_quick_edit_scripts'] ?? array();

	if ( ! $scripts ) {
		return;
	}

	ob_start();
	wp_scripts()->do_items( $scripts );
	$assets = ob_get_clean();

	if ( '' === trim( $assets ) ) {
		return;
	}

	printf( "<template id=\"la-quick-edit-assets\">\n%s</template>\n", $assets );
}

/**
 * Drops the user profile scripts from Writing Settings.
 *
 * options-writing.php enqueues user-profile (and with it
 * password-strength-meter) even though the page has no profile or password
 * field anywhere; it looks like a leftover in core. Nothing on the page
 * uses either script, so they are simply not enqueued there. On
 * profile.php the same scripts are legitimately needed and are left
 * alone. There is no loader and no template: the scripts are never used
 * on this screen.
 */
function la_maybe_drop_writing_profile_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'options-writing' !== $screen->id ) {
		return;
	}

	wp_dequeue_script( 'user-profile' );
	wp_dequeue_script( 'password-strength-meter' );
}

/**
 * Stylesheets removed from the wp-admin umbrella on every admin screen.
 *
 * The wp-admin handle exists only to pull these in: it has no file of its
 * own, just the dependency list. Each of these is one screen's stylesheet,
 * used only on that screen (or on screens that already have their own
 * stylesheet URL). The base styles every screen needs, dashicons, common,
 * forms, admin-menu, site-icon, l10n, are not in this list and stay.
 *
 * @return string[] Style handles to remove from the umbrella.
 */
function la_trim_css_handles() {
	return apply_filters(
		'la_trim_css_handles',
		array(
			'dashboard',
			'edit',
			'media',
			'themes',
			'about',
			'nav-menus',
			'widgets',
			'revisions',
		)
	);
}

/**
 * Screens that need one of the trimmed stylesheets, and which ones.
 *
 * The handle is enqueued directly on that screen, so it lands in that
 * screen's stylesheet URL and nowhere else. The Customizer needs three:
 * its Menus panel uses the nav-menus stylesheet, its Widgets panel uses
 * the widgets stylesheet, and its theme panel uses the themes stylesheet.
 * Settings > General needs the media stylesheet for the Site Icon
 * picker's modal close button; the other settings screens do not.
 *
 * @return array<string, string[]> Screen id => style handles to re-enqueue.
 */
function la_trim_css_reenqueue_map() {
	return apply_filters(
		'la_trim_css_reenqueue',
		array(
			'dashboard'       => array( 'dashboard', 'edit' ),
			'upload'          => array( 'media' ),
			'themes'          => array( 'themes' ),
			'theme-install'   => array( 'themes' ),
			'about'           => array( 'about' ),
			'revision'        => array( 'revisions' ),
			'nav-menus'       => array( 'nav-menus', 'edit' ),
			'widgets'         => array( 'widgets' ),
			'options-general' => array( 'media' ),
			'customize'       => array( 'nav-menus', 'widgets', 'themes' ),
			'site-health'     => array( 'edit' ),
			'comment'         => array( 'edit' ),
			'link'            => array( 'edit' ),
		)
	);
}

/**
 * Trims the admin CSS bundle by removing per-screen stylesheets from the
 * wp-admin umbrella, and re-enqueues the ones the current screen needs.
 *
 * The umbrella's dependency list is what load-styles.php concatenates, so
 * removing a handle there removes it from the bundle. The removal happens
 * at the end of the enqueue hook, before the head prints, so the
 * dependency walk at print time only sees what is left. A plugin that
 * enqueues a style depending on a trimmed handle gets it back through
 * that same walk, so plugin UIs that genuinely need one of these
 * stylesheets keep working.
 *
 * list-tables is not in the trimmed list: the list screens share one
 * stylesheet URL, and it is the list-table stylesheet that holds that
 * URL together. It is removed only on the post editor and the Customizer,
 * which have their own URL and do not use it.
 *
 * The Dashboard keeps edit as well as dashboard: edit.css holds the
 * .postbox rule (white background, border, shadow) that the dashboard
 * widgets are, so stripping it leaves them flat and grey. The post
 * editor keeps edit and media through the base check below. Site Health
 * keeps edit because edit.css holds the .health-check-accordion and
 * .health-check-tab rules for its test results. The comment and link
 * editors and the menu editor keep edit because their forms use
 * postboxes too.
 */
function la_maybe_trim_admin_css() {
	if ( ! apply_filters( 'la_trim_css', true ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen ) {
		return;
	}

	global $wp_styles;

	if ( ! isset( $wp_styles->registered['wp-admin'] ) ) {
		return;
	}

	$strip = la_trim_css_handles();

	if ( 'post' === $screen->base || 'customize' === $screen->id ) {
		$strip[] = 'list-tables';
	}

	$wp_styles->registered['wp-admin']->deps = array_values(
		array_diff( $wp_styles->registered['wp-admin']->deps, $strip )
	);

	$reenqueue = la_trim_css_reenqueue_map();
	$keep      = isset( $reenqueue[ $screen->id ] ) ? $reenqueue[ $screen->id ] : array();

	if ( 'post' === $screen->base ) {
		$keep = array_merge( $keep, array( 'edit', 'media' ) );
	}

	foreach ( array_unique( $keep ) as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
		}
	}
}
