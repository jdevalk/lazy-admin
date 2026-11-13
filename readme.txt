=== Lazy Admin ===
Contributors: joostdevalk
Tags: admin, performance, command palette, javascript
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Loads the Command Palette, the media modal, the Customizer's code editor, the plugin update and install scripts, and the media library's audio and video player only when you open them, and trims the admin CSS bundle to the stylesheets each screen uses, so classic admin screens skip the ~40 block editor scripts the palette needs, Settings > General skips the media library scripts the Site Icon picker needs, the Customizer skips the CodeMirror editor until the Additional CSS section opens, six screens skip the update and install scripts until an update, install or details button is used, the media library skips the audio and video player until an audio or video attachment opens, the Customizer skips the media modal scripts until a media control is used, the Dashboard and Comments screen skip the reply editor until a Reply button is used, the Posts and Pages lists skip Quick Edit until a Quick Edit link is used, Writing Settings no longer loads the user profile scripts it never uses, and every admin screen loads a smaller shared stylesheet instead of one stylesheet per screen it never shows.

== Description ==

Since WordPress 6.9, every classic screen in wp-admin loads about 40 block editor scripts before you can click anything: React, the component library, the block editor, the data layer and more. They are there for one feature, the Command Palette, the search box that opens with Ctrl/Cmd+K. The palette is useful. But it only needs those scripts once someone opens it, and on most admin page views nobody does.

This plugin loads the palette on demand instead. Admin screens get a 1.4 KB loader instead of the whole palette. The palette's scripts and styles wait in an inert template at the end of the page. On the first Ctrl/Cmd+K or a click on the toolbar button, the loader adds them and opens the palette. The first open takes 100 to 250 ms. After that it opens instantly, as before.

The post editor and the site editor load these scripts anyway, so they keep the palette up front, unchanged.

This is the patch from [Trac #66642](https://core.trac.wordpress.org/ticket/66642), packaged as a plugin for WordPress 6.9 to 7.2. WordPress 7.3 loads the palette on demand itself, so on 7.3 and later the palette part of this plugin does nothing.

The same trick is applied to the media modal on Settings > General. That screen loads the whole media library stack, about 400 KB of scripts plus 57 KB of modal templates, for one button: the Site Icon picker. This plugin holds those assets back until the button is clicked, then loads them and opens the modal. The post editor, the media library and the customizer need the modal up front, so they are left alone. Unlike the palette part, the media deferral runs on every version, including 7.3 and later.

The same trick is applied a third time to the code editor in the Customizer. That screen loads the CodeMirror editor, about 600 KB of scripts, for the Additional CSS section, which starts collapsed. This plugin holds the editor back until the section is first opened, then loads it and initializes the editor. The Custom HTML widget's editor is handled the same way when its form opens. Like the media deferral, this runs on every version.

The same trick is applied a fourth time to the update, install and details scripts on the Dashboard, Themes, Plugins, Add Plugins, Update Core and Import screens. Those six screens load about 66 KB of scripts for the plugin update, install and details modal, the thickbox modal and the media upload helper, and they are only needed when an update, install, details or auto-update button is used. This plugin holds them back until one of those buttons is used, then loads them and runs the action. Like the media and code editor deferrals, this runs on every version.

The same trick is applied a fifth time to the audio and video player in the media library. That screen loads the MediaElement player, about 170 KB of scripts and styles, for the audio and video previews in the attachment details, and most attachments are images. This plugin holds the player back until an audio or video attachment is opened, then loads it and builds the player. The post editor and the Customizer need the player up front, so they are left alone. Like the other deferrals, this runs on every version.

The same trick is applied a sixth time to the media modal in the Customizer. That screen loads the whole media modal stack, about 400 KB of scripts, for the Site Icon, logo, header image, background image and media widget controls, and it is only needed when one of those controls opens the modal. This plugin holds the stack back until a media control's button is first used, then loads it and opens the modal. The attachment models stay up front, so a control with a value already set still fetches its attachment data when the control is created. Like the other deferrals, this runs on every version.

The same trick is applied a seventh time to the comment Reply editor on the Dashboard and the Comments screen. Those screens load quicktags and the link dialog, about 50 KB, for a reply form that only opens on click. This plugin holds them back until a Reply button is first used, then loads them, wires the editor toolbar, and opens the reply form. Double-clicking a comment to reply works the same way. Like the other deferrals, this runs on every version.

The same trick is applied an eighth time to Quick Edit on the Posts and Pages lists. Those screens load the inline editor and its tag-suggestion scripts, about 20 KB, for a Quick Edit link that is only used on click, and for Bulk Edit. This plugin holds them back until a Quick Edit link is first clicked, or Bulk Edit is applied with the Edit bulk action, then loads them and opens the editor. Like the other deferrals, this runs on every version.

And a ninth, smaller fix: Writing Settings loads the user profile scripts, about 9 KB, for no reason. That screen has no profile or password field anywhere, so the plugin simply stops loading them there. On the profile page the same scripts are legitimately needed and are left alone.

And a tenth: the admin CSS bundle. WordPress concatenates every admin stylesheet into one load-styles.php request, and the list comes from the wp-admin umbrella handle, which pulls in one stylesheet per screen on every admin page. Most screens load stylesheets for screens they never show. This plugin removes the per-screen stylesheets from the umbrella and re-enqueues each one only on the screen that needs it, so the shared bundle shrinks from about 426 KB to about 251 KB. The shared URL stays shared: the screens that share it, the Posts and Pages lists, Comments, Users, Plugins, Tools, Export, Import and the settings screens, all strip the same set, so they keep one cache entry. The list-tables stylesheet stays in the bundle everywhere, since it is what holds that shared URL together; it goes only on the post editor and the Customizer, which have their own URL and do not use it. A plugin that enqueues a style depending on a trimmed stylesheet gets it back through the dependency walk at print time, so plugin UIs that genuinely need one of these stylesheets keep working.

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

= Do plugin updates, installs and the plugin details modal still work? =

Yes. Update, install, activate and auto-update buttons work as before; the first one loads the update scripts first, so it takes a moment longer. The plugin details modal opens as before, and the auto-update toggle works with the keyboard too. If you never touch those buttons, the scripts never load.

= Does the audio and video player in the media library still work? =

Yes. Open an audio or video attachment and the player loads, with playback as before. The first open loads the player's scripts first, so it takes a moment longer; after that it plays instantly. Image attachments never load the player.

= Do the Customizer's media controls still work? =

Yes. The Site Icon, logo, header image, background image and media widget controls open the media modal as before. The first use loads the modal's scripts first, so it takes a moment longer; after that it opens instantly. A control with an image already set still shows its thumbnail on load.

= Does replying to a comment still work? =

Yes. Click Reply on the Dashboard or the Comments screen and the reply form opens with its formatting toolbar, as before. The first Reply loads the editor's scripts first, so it takes a moment longer; after that it opens instantly. Double-clicking a comment to reply works the same way.

= Does Quick Edit still work? =

Yes. Click Quick Edit on the Posts or Pages list and the inline editor opens as before. The first click loads the editor's scripts first, so it takes a moment longer; after that it opens instantly. Bulk Edit with the Edit action works the same way.

= Does the admin still look the same? =

Yes. The CSS trim only removes stylesheets for screens you are not on: the Dashboard keeps its own stylesheet, the post editor keeps the editor stylesheet, the media library keeps the media stylesheet, and so on. Nothing visible changes. A plugin that needs one of the trimmed stylesheets gets it back automatically.

= How much faster is it? =

Measured on WordPress 7.2-beta4 (see the article this plugin is based on): Settings > General went from 80 scripts to 39, the Dashboard after login from 6.7 MB downloaded to 1.4 MB, and DOMContentLoaded on the Dashboard from 282 ms to 155 ms (696 ms to 226 ms on a 4x slower CPU). Your numbers will vary, but the scripts that are gone are gone on every classic admin screen.

Measured on this plugin (WordPress 7.1.4, stylesheet bundle per screen, minified KB): Dashboard 421 to 273 (35% smaller), Posts list 416 to 245 (41%), Settings > General 416 to 267 (36%), Media library 451 to 316 (30%), Post editor 1183 to 1028 (13%), Customizer 470 to 327 (30%). Gzipped, each of those screens saves 24 to 31 KB on the wire. The Posts list number is the shared bundle, so the Posts and Pages lists, Comments, Users, Plugins, Tools, Export, Import and settings screens all share it.

== Changelog ==

= 0.5.0 =
* Renumbered from 1.6.0: the plugin is still in 0.x development, so 1.x overstated its maturity. Same code as 1.6.0.
* Trims the admin CSS bundle: the per-screen stylesheets (dashboard, edit, media, themes, about, nav-menus, widgets, revisions) are removed from the shared load-styles.php bundle and re-enqueued only on the screens that need them. The shared bundle shrinks from about 426 KB to about 251 KB, and the screens that share it keep one cache entry. list-tables stays in the bundle, since it is what holds the shared URL together; it goes only on the post editor and the Customizer, which have their own URL and do not use it. This runs on every version.

= 1.5.0 =
* Loads the Customizer's media modal stack on demand: the media views, uploader, image editor and audio and video player wait until a media control's button is first used. The attachment models stay up front so a control with a value already set still loads its thumbnail. This runs on every version.
* Loads the comment Reply editor on demand on the Dashboard and the Comments screen: quicktags and the link dialog wait until a Reply button is first used. Double-clicking a comment to reply works the same way. This runs on every version.
* Loads Quick Edit on demand on the Posts and Pages lists: the inline editor and its tag-suggestion scripts wait until a Quick Edit link is first clicked, or Bulk Edit is applied with the Edit bulk action. This runs on every version.
* Writing Settings no longer loads the user profile scripts: that screen has no profile or password field, so user-profile and password-strength-meter are simply not enqueued there. The profile page is unchanged.

= 1.4.0 =
* Loads the update, install and details scripts on demand on the Dashboard, Themes, Plugins, Add Plugins, Update Core and Import screens: the updates, thickbox, plugin-install and media-upload scripts wait until an update, install, details or auto-update button is used. This runs on every version.
* Loads the MediaElement audio and video player on demand in the media library: the player scripts and styles wait until an audio or video attachment is opened. Image attachments never load the player. This runs on every version.

= 1.3.0 =
* Loads the Customizer's code editor on demand: CodeMirror and the code editor wrapper wait until the Additional CSS section is first opened, or a Custom HTML widget form opens. This runs on every version.

= 1.2.0 =
* Renamed from Lazy Command Palette to Lazy Admin. No functional changes.

= 1.1.0 =
* Loads the media modal on demand on Settings > General: the media library scripts and modal templates wait until the Site Icon button is clicked. This runs on every version, including 7.3 and later.
* The palette part is unchanged and still only runs on WordPress 6.9 to 7.2.

= 1.0.0 =
* Initial release. Loads the Command Palette on demand on WordPress 6.9 to 7.2.
