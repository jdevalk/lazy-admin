<?php
/**
 * Plugin Name:       Lazy Command Palette
 * Plugin URI:        https://github.com/jdevalk/lazy-command-palette
 * Description:       Loads the Command Palette only when you open it. Classic admin screens skip the ~40 block editor scripts the palette needs, so they load noticeably faster.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Author:            Joost de Valk
 * Author URI:        https://joost.blog
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lazy-command-palette
 *
 * @package Lazy_Command_Palette
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCP_VERSION', '1.0.0' );

/*
 * The Command Palette only loads its scripts up front on every admin screen since
 * WordPress 6.9, and WordPress 7.3 loads it on demand itself (see Trac #66642).
 * On any other version there is nothing for this plugin to do.
 */
if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) || function_exists( 'wp_enqueue_command_palette_loader' ) ) {
	return;
}

add_action( 'init', 'lcp_init' );

/**
 * Replaces the Command Palette's up-front loading with loading it on demand.
 */
function lcp_init() {
	$priority = has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );
	if ( false !== $priority ) {
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets', $priority );
	}

	add_action( 'admin_enqueue_scripts', 'lcp_enqueue_command_palette_loader', 10 );
	add_action( 'admin_print_footer_scripts', 'lcp_print_command_palette_assets_template', 20 );

	/*
	 * Core's toolbar button only shows when wp-core-commands is enqueued up front,
	 * which is exactly what this plugin stops doing. Swap it for the loader's version
	 * when the admin bar menu runs; core registers its button during
	 * WP_Admin_Bar::initialize(), so this runs before it, at priority 0.
	 */
	add_action( 'admin_bar_menu', 'lcp_replace_admin_bar_command_palette_menu', 0 );

	/*
	 * On WordPress 7.2 and later the login screen prefetches the admin's scripts,
	 * including the palette's. Keep those out of the prefetch; the filter does not
	 * exist before 7.2, so this is a no-op there.
	 */
	add_filter( 'wp_prefetch_admin_assets', 'lcp_filter_prefetch_admin_assets', 10, 2 );
}

/**
 * Enqueues the Command Palette loader and the palette's settings.
 *
 * This mirrors wp_enqueue_command_palette_loader() from WordPress 7.3 (Trac #66642):
 * the settings are built exactly as wp_enqueue_command_palette_assets() builds them,
 * but only the 1.4 KB loader is enqueued. The loader adds the palette's scripts and
 * styles to the page when the palette is first opened, with the keyboard shortcut or
 * the toolbar button, so that they do not hold back every admin screen. Until then
 * they wait in the template printed by lcp_print_command_palette_assets_template().
 * A screen that already loads the commands package, such as the block editor, gets
 * the rest of the palette up front instead.
 *
 * @global array $menu
 * @global array $submenu
 */
function lcp_enqueue_command_palette_loader() {
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
		LCP_VERSION,
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
 * lcp_enqueue_command_palette_loader() adds it to the page when the palette is
 * first opened. Nothing is printed when the palette is already on the page.
 */
function lcp_print_command_palette_assets_template() {
	if ( ! wp_script_is( 'wp-command-palette-loader', 'done' ) || wp_script_is( 'wp-core-commands', 'done' ) ) {
		return;
	}

	$scripts                = wp_scripts();
	$styles                 = wp_styles();
	$scripts_queued_by_page = $scripts->queue;
	$styles_queued_by_page  = $styles->queue;

	/** This action is documented in lcp_enqueue_command_palette_loader(). */
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
function lcp_replace_admin_bar_command_palette_menu() {
	$priority = has_action( 'admin_bar_menu', 'wp_admin_bar_command_palette_menu' );
	if ( false === $priority ) {
		return;
	}

	remove_action( 'admin_bar_menu', 'wp_admin_bar_command_palette_menu', $priority );
	add_action( 'admin_bar_menu', 'lcp_admin_bar_command_palette_menu', $priority );
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
function lcp_admin_bar_command_palette_menu( WP_Admin_Bar $wp_admin_bar ): void {
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
function lcp_filter_prefetch_admin_assets( $resources, $next_screen ) {
	if ( ! is_array( $resources ) || lcp_is_editor_screen_url( $next_screen ) ) {
		return $resources;
	}

	$palette_paths = lcp_command_palette_asset_paths();
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
function lcp_is_editor_screen_url( $url ) {
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
function lcp_command_palette_asset_paths() {
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
