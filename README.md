# Lazy Admin

WordPress loads a lot of JavaScript in wp-admin that you only need some of the time. This plugin holds it back until you actually use the feature it belongs to, so admin screens load faster and with less to parse. It also trims the admin CSS bundle to the stylesheets each screen uses, instead of one stylesheet per screen you never visit. There are no settings. Activate it and every classic admin screen gets faster.

## Savings

Total JavaScript and CSS downloaded per screen, WordPress 7.1.4, measured with the plugin off and on:

| Screen | Before | After | Saved | Saved % |
| --- | --- | --- | --- | --- |
| Dashboard (`/wp-admin/`) | 4.0 MB | 0.7 MB | 3.3 MB | 83% |
| Posts list (`/wp-admin/edit.php`) | 3.8 MB | 0.3 MB | 3.5 MB | 92% |
| Settings > General (`/wp-admin/options-general.php`) | 4.3 MB | 0.3 MB | 4.0 MB | 92% |
| Media library (`/wp-admin/upload.php`) | 4.3 MB | 0.7 MB | 3.6 MB | 84% |
| Post editor (`/wp-admin/post-new.php`) | 8.5 MB | 8.4 MB | 0.1 MB | 2% |
| Customizer (`/wp-admin/customize.php`) | 1.7 MB | 0.6 MB | 1.2 MB | 68% |

Gzipped, the same screens save about 1.0 to 1.2 MB each on the wire, except the Post editor (about 26 KB) and the Customizer (about 0.3 MB). The Post editor barely changes because it genuinely needs its scripts; the saving there is the CSS trim alone.

## What it defers

| Feature | Where it loads | Loads when | Saved |
| --- | --- | --- | --- |
| Command Palette | Every classic admin screen | Ctrl/Cmd+K or the toolbar button | About 40 scripts |
| Media modal | Settings > General | The Site Icon button is clicked | About 400 KB of scripts, plus 57 KB of modal templates |
| CodeMirror editor | Customizer | The Additional CSS section opens | About 600 KB |
| Update and install scripts | Dashboard, Themes, Plugins, Add Plugins, Update Core, Import | An update, install, details or auto-update button is used | About 66 KB, on six screens |
| MediaElement player | Media library | An audio or video attachment opens | About 170 KB |
| Media modal stack | Customizer | A media control's button is used | About 400 KB |
| Reply editor | Dashboard, Comments | A Reply button is used | About 50 KB |
| Quick Edit | Posts and Pages lists | A Quick Edit link is clicked, or Bulk Edit with Edit | About 20 KB |
| User profile scripts | Writing Settings | Never; the page has no profile or password field, so they are not loaded there at all | About 9 KB |
| Admin CSS bundle | Every admin screen | Always; the per-screen stylesheets are removed from the shared bundle and re-enqueued only on the screen that needs them | About 175 KB of the shared bundle |

Everything still works exactly as before. The first use loads the scripts first, so it takes a moment longer; after that it works instantly. If you never use a feature, its scripts never load. The admin still looks the same: the CSS trim only removes stylesheets for screens you are not on.

## How it works

Each deferred feature gets a small loader (around 1 KB) instead of its scripts. The scripts and styles wait in an inert `<template>` at the end of the page. On first use, the loader adds them to the page and then lets your click through, so the feature opens with everything in place. The loader waits for the feature's own setup to finish before the click lands, so handlers are in place when it arrives.

Two details are worth knowing:

- The Command Palette part is the patch from [Trac #66642](https://core.trac.wordpress.org/ticket/66642) ([PR #15871](https://github.com/WordPress/wordpress-develop/pull/15871)), packaged for WordPress 6.9 to 7.2. WordPress 7.3 loads the palette on demand itself, so on 7.3 and later that part does nothing. Every other deferral runs on every version.
- The post editor and the site editor load these scripts anyway, so they keep everything up front, unchanged. Plugins that add their own palette commands get the same `enqueue_command_palette_assets` action WordPress 7.3 fires, so their commands load together with the palette.
- The CSS trim works differently from the script deferrals, because the bundle is cached for a year and the URL is identical across the screens that share it. A handle is removed from the `wp-admin` umbrella only when no screen sharing the URL uses it, and it is re-enqueued on the screens that do need it, so those screens keep their own URL. The list-tables stylesheet stays in the bundle, since it is what holds the shared URL together; it goes only on the post editor and the Customizer, which have their own URL and do not use it. A plugin that enqueues a style depending on a trimmed stylesheet gets it back through the dependency walk at print time.

## Numbers

Measured on WordPress 7.2-beta4, from [the article this plugin is based on](https://wp-staging.com/wordpress-admin-5-mb-javascript/):

| | WordPress today | With the patch |
| --- | --- | --- |
| Scripts on Settings > General | 80 | 39 |
| Downloaded for the Dashboard after login | 6.7 MB | 1.4 MB |
| Dashboard right after login | 282 ms | 155 ms |
| Settings, every script already cached | 98 ms | 49 ms |
| Dashboard right after login, 4x slower CPU | 696 ms | 226 ms |
| Settings, every script cached, 4x slower CPU | 362 ms | 123 ms |

Measured on this plugin (WordPress 7.1.4, executed scripts per screen):

| Screen | Before | After |
| --- | --- | --- |
| Settings > General | 76 | 13 |
| Customizer | 33 | 22 |
| Dashboard | 41 | 35 |
| Comments | 22 | 20 |
| Media library | 34 | 32 |
| Post editor | 96 | 96 (unchanged) |

Shared stylesheet bundle only (load-styles.php, minified KB, WordPress 7.1.4). This is the CSS trim on its own, not counting the styles that are deferred with their scripts; the total per screen is in the table at the top of this readme.

| Screen | Before | After | Saved | Saved % |
| --- | --- | --- | --- | --- |
| Dashboard (`/wp-admin/`) | 421 | 273 | 148 | 35% |
| Posts list (`/wp-admin/edit.php`) | 416 | 245 | 171 | 41% |
| Settings > General (`/wp-admin/options-general.php`) | 416 | 267 | 149 | 36% |
| Media library (`/wp-admin/upload.php`) | 451 | 316 | 135 | 30% |
| Post editor (`/wp-admin/post-new.php`) | 1183 | 1028 | 155 | 13% |
| Customizer (`/wp-admin/customize.php`) | 470 | 327 | 143 | 30% |

The Posts list row is the shared bundle: the Posts and Pages lists, Comments, Users, Plugins, Tools, Export, Import and the settings screens all load the same stylesheet URL, so they share one cache entry. Gzipped, the same six screens save 24 to 31 KB each on the wire.

## Development

No build step. The loader in `assets/js/command-palette-loader.js` is copied verbatim from the core patch; the PHP mirrors `wp_enqueue_command_palette_loader()` and `wp_print_command_palette_assets_template()` from WordPress 7.3. Each other loader follows the same pattern for its feature:

- `media-loader.js` for the media modal on Settings > General.
- `codemirror-loader.js` for the Customizer's code editor, with an extra wrap around the control's init for sections that are already expanded.
- `updates-loader.js` for the update, install and details scripts, with shims for `tb_show` and `tb_remove` so plugins that call thickbox directly still work.
- `mediaelement-loader.js` for the media library player, stripping the player from the media views dependency chain so it never loads up front.
- `customizer-media-loader.js` for the Customizer's media modal, stripping the media stack from the Customizer views dependency chain and keeping the attachment models up front so a control with a value already set still loads its thumbnail.
- `reply-editor-loader.js` for the comment reply editor, running the editor init the footer script would have run once the scripts load.
- `quick-edit-loader.js` for the inline editor, waiting for its jQuery ready setup before the re-dispatched click opens the editor.

The CSS trim needs no loader: the per-screen stylesheets are removed from the `wp-admin` umbrella's dependency list, and re-enqueued directly on the screens that need them.

The Writing Settings fix needs no loader: the two scripts are simply not enqueued there.
