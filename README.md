# Lazy Admin

Loads the WordPress Command Palette, the media modal, the Customizer's code editor, the plugin update and install scripts, and the media library's audio and video player only when you open them, so classic admin screens skip the ~40 block editor scripts the palette needs, Settings > General skips the media library scripts the Site Icon picker needs, the Customizer skips the CodeMirror editor until the Additional CSS section opens, six screens skip the update and install scripts until an update, install or details button is used, and the media library skips the audio and video player until an audio or video attachment opens.

Since WordPress 6.9, every classic screen in wp-admin loads about 40 block editor scripts before you can click anything: React, the component library, the block editor, the data layer and more. They are there for one feature, the Command Palette (Ctrl/Cmd+K). The palette is useful, but it only needs those scripts once someone opens it, and on most admin page views nobody does.

This plugin is the patch from [Trac #66642](https://core.trac.wordpress.org/ticket/66642) ([PR #15871](https://github.com/WordPress/wordpress-develop/pull/15871)), packaged for WordPress 6.9 to 7.2. WordPress 7.3 loads the palette on demand itself, so on 7.3 and later the palette part of the plugin does nothing. The media modal deferral runs on every version: on Settings > General the media library scripts and modal templates wait until the Site Icon button is clicked. The code editor deferral also runs on every version: in the Customizer the CodeMirror editor waits until the Additional CSS section is first opened, or a Custom HTML widget form opens. The update scripts deferral also runs on every version: on the Dashboard, Themes, Plugins, Add Plugins, Update Core and Import screens the updates, thickbox, plugin-install and media-upload scripts wait until an update, install, details or auto-update button is used. The MediaElement player deferral also runs on every version: in the media library the player waits until an audio or video attachment is opened; image attachments never load it.

## How it works

- Admin screens get a 1.4 KB loader instead of the whole palette.
- The palette's scripts and styles wait in an inert `<template>` at the end of the page.
- On the first Ctrl/Cmd+K or a click on the toolbar button, the loader adds them and opens the palette. The first open takes 100 to 250 ms; after that it opens instantly, as before.
- The post editor and the site editor load these scripts anyway, so they keep the palette up front, unchanged.
- Plugins that add their own commands get the same `enqueue_command_palette_assets` action WordPress 7.3 fires, so their commands load together with the palette.

There are no settings. Activate it and every classic admin screen gets faster.

## Numbers

Measured on WordPress 7.2-beta4, from [the article this is based on](https://wp-staging.com/wordpress-admin-5-mb-javascript/):

| | WordPress today | With the patch |
| --- | --- | --- |
| Scripts on Settings > General | 80 | 39 |
| Downloaded for the Dashboard after login | 6.7 MB | 1.4 MB |
| Dashboard right after login | 282 ms | 155 ms |
| Settings, every script already cached | 98 ms | 49 ms |
| Dashboard right after login, 4× slower CPU | 696 ms | 226 ms |
| Settings, every script cached, 4× slower CPU | 362 ms | 123 ms |

## Development

No build step. The loader in `assets/js/command-palette-loader.js` is copied verbatim from the core patch; the PHP mirrors `wp_enqueue_command_palette_loader()` and `wp_print_command_palette_assets_template()` from WordPress 7.3. The media loader in `assets/js/media-loader.js` follows the same pattern for the media modal. The CodeMirror loader in `assets/js/codemirror-loader.js` follows the same pattern for the Customizer's code editor, with an extra wrap around the control's init for sections that are already expanded. The updates loader in `assets/js/updates-loader.js` follows the same pattern for the update, install and details scripts, with shims for `tb_show` and `tb_remove` so plugins that call thickbox directly still work. The MediaElement loader in `assets/js/mediaelement-loader.js` follows the same pattern for the media library player, stripping the player from the media views dependency chain so it never loads up front.
