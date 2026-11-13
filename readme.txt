=== Lazy Command Palette ===
Contributors: joostdevalk
Tags: admin, performance, command palette, javascript
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Loads the Command Palette and the media modal only when you open them, so classic admin screens skip the ~40 block editor scripts the palette needs, and Settings > General skips the media library scripts the Site Icon picker needs.

== Description ==

Since WordPress 6.9, every classic screen in wp-admin loads about 40 block editor scripts before you can click anything: React, the component library, the block editor, the data layer and more. They are there for one feature, the Command Palette, the search box that opens with Ctrl/Cmd+K. The palette is useful. But it only needs those scripts once someone opens it, and on most admin page views nobody does.

This plugin loads the palette on demand instead. Admin screens get a 1.4 KB loader instead of the whole palette. The palette's scripts and styles wait in an inert template at the end of the page. On the first Ctrl/Cmd+K or a click on the toolbar button, the loader adds them and opens the palette. The first open takes 100 to 250 ms. After that it opens instantly, as before.

The post editor and the site editor load these scripts anyway, so they keep the palette up front, unchanged.

This is the patch from [Trac #66642](https://core.trac.wordpress.org/ticket/66642), packaged as a plugin for WordPress 6.9 to 7.2. WordPress 7.3 loads the palette on demand itself, so on 7.3 and later the palette part of this plugin does nothing.

The same trick is applied to the media modal on Settings > General. That screen loads the whole media library stack, about 400 KB of scripts plus 57 KB of modal templates, for one button: the Site Icon picker. This plugin holds those assets back until the button is clicked, then loads them and opens the modal. The post editor, the media library and the customizer need the modal up front, so they are left alone. Unlike the palette part, the media deferral runs on every version, including 7.3 and later.

There are no settings. Activate it and every classic admin screen gets faster.

== Frequently Asked Questions ==

= Does the Command Palette still work? =

Yes. It opens with Ctrl/Cmd+K or the toolbar button, exactly as before. The first open loads its scripts (100 to 250 ms), after that it opens instantly.

= Does it work in the post editor and the site editor? =

Yes, and unchanged: those screens load the palette's scripts up front anyway, so the plugin leaves them alone.

= What about plugins that add their own commands to the palette? =

They keep working. This plugin fires the same `enqueue_command_palette_assets` action WordPress 7.3 fires, so commands enqueued on that action load together with the palette when it opens.

= Why does the plugin do nothing on WordPress 7.3 and later? =

Because WordPress 7.3 includes this change in core. The plugin checks for it and stays out of the way, so you can safely remove it after updating. The media modal deferral keeps working on 7.3 and later, since core never defers that.

= Does the Site Icon picker still work? =

Yes. Click "Choose image" and the media modal opens as before. The first click loads the modal's scripts first, so it takes a moment longer; after that it opens instantly. Removing the icon works unchanged, without loading anything.

= How much faster is it? =

Measured on WordPress 7.2-beta4 (see the article this plugin is based on): Settings > General went from 80 scripts to 39, the Dashboard after login from 6.7 MB downloaded to 1.4 MB, and DOMContentLoaded on the Dashboard from 282 ms to 155 ms (696 ms to 226 ms on a 4x slower CPU). Your numbers will vary, but the scripts that are gone are gone on every classic admin screen.

== Changelog ==

= 1.1.0 =
* Loads the media modal on demand on Settings > General: the media library scripts and modal templates wait until the Site Icon button is clicked. This runs on every version, including 7.3 and later.
* The palette part is unchanged and still only runs on WordPress 6.9 to 7.2.

= 1.0.0 =
* Initial release. Loads the Command Palette on demand on WordPress 6.9 to 7.2.
