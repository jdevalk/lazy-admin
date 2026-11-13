=== Lazy Admin ===
Contributors: joostdevalk
Tags: admin, performance, command palette, javascript
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Loads the Command Palette, the media modal and the Customizer's code editor only when you open them, so classic admin screens skip the ~40 block editor scripts the palette needs, Settings > General skips the media library scripts the Site Icon picker needs, and the Customizer skips the CodeMirror editor until the Additional CSS section opens.

== Description ==

Since WordPress 6.9, every classic screen in wp-admin loads about 40 block editor scripts before you can click anything: React, the component library, the block editor, the data layer and more. They are there for one feature, the Command Palette, the search box that opens with Ctrl/Cmd+K. The palette is useful. But it only needs those scripts once someone opens it, and on most admin page views nobody does.

This plugin loads the palette on demand instead. Admin screens get a 1.4 KB loader instead of the whole palette. The palette's scripts and styles wait in an inert template at the end of the page. On the first Ctrl/Cmd+K or a click on the toolbar button, the loader adds them and opens the palette. The first open takes 100 to 250 ms. After that it opens instantly, as before.

The post editor and the site editor load these scripts anyway, so they keep the palette up front, unchanged.

This is the patch from [Trac #66642](https://core.trac.wordpress.org/ticket/66642), packaged as a plugin for WordPress 6.9 to 7.2. WordPress 7.3 loads the palette on demand itself, so on 7.3 and later the palette part of this plugin does nothing.

The same trick is applied to the media modal on Settings > General. That screen loads the whole media library stack, about 400 KB of scripts plus 57 KB of modal templates, for one button: the Site Icon picker. This plugin holds those assets back until the button is clicked, then loads them and opens the modal. The post editor, the media library and the customizer need the modal up front, so they are left alone. Unlike the palette part, the media deferral runs on every version, including 7.3 and later.

The same trick is applied a third time to the code editor in the Customizer. That screen loads the CodeMirror editor, about 600 KB of scripts, for the Additional CSS section, which starts collapsed. This plugin holds the editor back until the section is first opened, then loads it and initializes the editor. The Custom HTML widget's editor is handled the same way when its form opens. Like the media deferral, this runs on every version.

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

= Does the Additional CSS editor still work? =

Yes. Open the Additional CSS section in the Customizer and the code editor loads, with syntax highlighting and linting as before. The first open loads the editor's scripts first, so it takes a moment longer; after that it opens instantly. If you never open the section, the editor never loads.

= How much faster is it? =

Measured on WordPress 7.2-beta4 (see the article this plugin is based on): Settings > General went from 80 scripts to 39, the Dashboard after login from 6.7 MB downloaded to 1.4 MB, and DOMContentLoaded on the Dashboard from 282 ms to 155 ms (696 ms to 226 ms on a 4x slower CPU). Your numbers will vary, but the scripts that are gone are gone on every classic admin screen.

== Changelog ==

= 1.3.0 =
* Loads the Customizer's code editor on demand: CodeMirror and the code editor wrapper wait until the Additional CSS section is first opened, or a Custom HTML widget form opens. This runs on every version.

= 1.2.0 =
* Renamed from Lazy Command Palette to Lazy Admin. No functional changes.

= 1.1.0 =
* Loads the media modal on demand on Settings > General: the media library scripts and modal templates wait until the Site Icon button is clicked. This runs on every version, including 7.3 and later.
* The palette part is unchanged and still only runs on WordPress 6.9 to 7.2.

= 1.0.0 =
* Initial release. Loads the Command Palette on demand on WordPress 6.9 to 7.2.
