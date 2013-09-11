Video SEO
=========
Requires at least: 3.2
Tested up to: 3.5
Stable tag: 1.5.4.5

Video SEO adds Video SEO capabilities to WordPress SEO.

Description
-----------

This plugin adds Video XML Sitemaps as well as the necessary OpenGraph markup, Schema.org videoObject markup and mediaRSS for your videos.

Installation
------------

1. Go to Plugins -> Add New.
2. Click "Upload" right underneath "Install Plugins".
3. Upload the zip file that this readme was contained in.
4. Activate the plugin.
5. Go to SEO -> XML Sitemaps and enter your license key.
6. Save settings, your license key will be validated. If all is well, you should now see the XML Video Sitemap settings.
7. Make sure to hit the "Re-index video's" button if you have video's in old posts.

Frequently Asked Questions
--------------------------

You can find the FAQ [online here](http://yoast.com/video-seo-plugin-faq/).

Changelog
=========

1.5.4.5
-------
* To make best use of the new features in this update, please reindex your videos.

* Bugfixes
    * Several i18n namespace fixes.
    * Make video's in taxonomy descriptions pick up properly again.
    * Fix for Wistia popover embeds and Wistia https URLs.
    * Prevent output of hd attribute for video's in XML Video sitemap.
    * Make sure opengraph image is always set to "full" size.
    * Add width and height for Youtube vids.
    * Prevent notice in sitemap when video from taxonomy term is displayed.
    * Prevent wrong or empty dates in XML video sitemap.
* Enhancements
    * Add option to manually add tags per video.
    * Add option to override video category (normally defaults to first post category).
    * Order videos in XML video sitemap by date modified, ascending.
    * Add "proper" Facebook video integration.
    * Added support for [Advanced Responsive Video Embedder](http://wordpress.org/plugins/advanced-responsive-video-embedder/).
    * Added support for muzu.tv.
    * Allow for custom fields that hold arrays to be detected too.
    * Add support for custom Vimeo URLs. (eg http://vimeo.com/yoast/video-seo)
    * Make sure the video thumbnail is always put out as an og:image too.
	* Added support for Instabuilder video shortcodes
	* Added support for Vidyard
	* Set license key with a constant
	* Added support for Cincopa
	* Added support for Brightcove
	* Added support for videos in the 'Archive Intro Text' (Genesis) in the video sitemap
	* Added support for WP OS FLV plugin
	* Added support for [Wordpress Automatic Youtube Video Post] (http://wordpress.org/plugins/automatic-youtube-video-posts/)

1.5.4.4
-------
* Bugfixes
    * Spaces in custom fields settings are now properly trimmed.
    * Fix for Vzaar URLs.
    * Wistia embed with extra classes now properly detected.
* Enhancements
    * Video sitemap now adheres to same pagination as post sitemap.
    * Video XML Sitemap date now properly retrieved from last modified post with movie.

1.5.4.3
-------
* Enhancements
    * Add support for `fvplayer` shortcode.
    * Add option to manually change or enter duration.

1.5.4.2
-------

* Bugfixes:
    * Properly allow normale meta description length when video has been disabled for post.
* Enhancements:
    * Added option to disable RSS enhancements, to prevent clashes with podcasting plugins.

1.5.4.1
-------

* Move loading of the plugin to prio 20, in line with upgrades of the core WordPress SEO plugin.

1.5.4
-----

* Enhancements:
    * Added support for [fitvids.js](http://fitvidsjs.com/), enable it in the Video SEO settings to make your Youtube / Vimeo / Blip.tv / Viddler / Wistia videos responsive, meaning they'll become fluid. This might not work with all embed codes, let us know when it doesn't work for a particular one.
    * Removed the ping functionality as that's fixed within the core plugin.
    * Added code that forces you to update WordPress to 3.4 or higher and the WordPress SEO plugin to 1.4 or higher to use the plugin.
* Bugfixes:
    * Fixed a bug that would prevent the time last modified of the video sitemap to update.

1.5.3
-----

* Enhancements:
    * Improved defaults: now enables all public post-types by default on install.
    * Option to change the basename of the video sitemap, from video-sitemap.xml to whatever-sitemap.xml by setting the `YOAST_VIDEO_SITEMAP_BASENAME` constant.
    * If post meta values are encoded, the plugin now decodes them.
* Bugfixes:
    * No longer override opengraph image when one has already been set.
    * Add extra newlines before video schema to allow oEmbed to work.
    * No longer depends on response from Vzaar servers to create sitemap, properly uses the referer to authenticate requests and adds option in settings to add your Vzaar CNAME.
    * When there's a post-type with the slug `video`, the plugin now automatically changes the basename to `yoast-video`.
    * No longer print empty `<p>` for empty description in meta box.
    * Improve logic whereby "this image" link is shown correctly and only when the video thumb is not overridden.

1.5.2
-----

* Enhacements:
    * Added support for Vzaar video's, embedded with either iframe, object embed or shortcode through 1 of 2 plugins.
    * Added TubePress support.
* Bugfixes
    * Wistia.net support added (not just .com).
    * Fixed bug in parsing youtube_sc shortcodes.

1.5.1
-----

* Bugfixes:
    * Improved activation.
* Enhancements:
    * Add support for titan lightbox.
    * Prevented some notices.

1.5
---

* Bugfixes:
    * Make `mrss_gallery_lookup` public to prevent notices.
    * Fix some forms of object detection for youtube and others.
    * Fix detection of [video] shortcodes.
* Enhancements:
    * Allow deactivation of license key so it can be used on another domain.
    * Add link to detected thumbnail on video tab.
    * Changed text-domain from `wordpress-seo` to `yoast-video-seo`.
    * Made sure all the strings are translateable.
    * Touch up admin sections styling.
* i18n:
    * You can now translate the plugin to your native language should you need a translation, check [translate.yoast.com](http://translate.yoast.com/projects/yoast-video-seo) for details.
    * Changed text-domain from `wordpress-seo` to `yoast-video-seo`.
    * Added .pot file to repository.
    * Added Dutch translation.

1.4.4
-----

* Bugfixes:
    * Prevent issues with content_width global.
    * Prevent trying to activate an already activated license.
    * Prevent a notice for custom fields.
    * A fix for wistia popover embeds.
* New features:
    * Add PluginBuddy VidEmbed support.

1.4.3
-----

* Bugfixes:
    * Now matches multiple iframes / objects on a page.
    * Fix several bugs where embeds without quotes around the URL wouldn't be recognized.
* New features:
    * Added an option to set the content width for your theme if your theme doesn't set it.
    * Added support for Sublime video and its official WordPress plugin.
    * Added SEO & oEmbed support for Animoto.
    * Added ping for Bing with the video sitemap.
    * Added a _bunch_ of supported plugins & shortcodes for YouTube embeds.

1.4.2
-----

* Bugfixes / Enhancements:
    * Try to prevent timeout on license validation.
    * Clean up of a lot of regexes in the plugin.
    * Prevent relative image URL paths and images set as just 'h'.
    * Prevent double output of posts.
    * Fixed small bug that would prevent youtube URLs with the video ID in a weird place in the URL from working.
    * Improve Wistia embed support.
    * Lengthen timeout for video info requests.
* New features:
    * Added support for html5 video elements (d'0h!).
    * Add support for [vimeo id= and [youtube id= embed codes
    * Added support for self-hosted videos with just a file URL in custom field. In these cases the featured image is used as thumbnail.
    * Added generic fallback to post thumbnail image if there is no video thumbnail.

1.4
---

* Bugfixes / Enhancements:
    * Fix Vimeo embed detection.
    * Switch Vimeo to oEmbed API.
    * When available, use html5_file for jwplayer embeds.
* New features:
    * Added video content optimization tips in the page analysis tab of WordPress SEO.
    * Added support for WP Video Lightbox plugin.
    * Added initial support for [Flowplayer plugin](http://wordpress.org/extend/plugins/fv-wordpress-flowplayer/).
    * Added support for Wistia video hosting platform.
    * Added support for Vippy video hosting platform (thanks to Ronald Huereca).
    * Added support for shortcodes from Weaver theme.

1.3.4
-----

* Bugfixes:
    * Fixed Viddler check.
    * Fix strip tags for videoObject output.
    * Don't filter content when in a feed.
    * Improve parsing of VideoPress embed ID's.
* Enhancements:
    * Added support for checking custom fields for video's.
    * Added support for Press75's Simple Video Embedder (and thus for all their themes).

1.3.3
-----

* Bugfixes:
    * Properly catch thumbnail images when the path is relative instead of absolute.
    * Strip shortcodes for plugins that don't register them properly as well.
    * Prevent empty titles.
    * Wrap XML sitemap and MediaRSS textual content in CDATA tags, this solves about 900.000 issues with encoding.
    * Fixed [Veoh](http://www.veoh.com/) support.
* Enhancements:
    * When a post is in more than one category, the excess categories are now used as tags.
    * Don't print sitemap lines for video's that have no thumbnail and either a content location or a player location.
    * If the description and excerpt are empty, use the title for the description, as an empty description is invalid.
    * Changed the name of the family friendly variable, so it can't go "wrong" with old data.
    * Added support for the `video:uploader` tag. This automatically links to the post authors posts page.
    * Make terms use their own name as category in XML sitemap.
    * Added support for jwplayer shortcode embeds with file and image attributes instead of mediaid.
    * Added support for the [WordPress Video Plugin](http://wordpress.org/extend/plugins/wordpress-video-plugin/).
    * Added support for the [MediaElements.js](http://wordpress.org/extend/plugins/media-element-html5-video-and-audio-player/) plugin.
    * Added support for the [WP YouTube Player](http://wordpress.org/extend/plugins/wp-youtube-player/) plugin.
    * Added support for the [Advanced YouTube Embed Plugin by Embed Plus](http://wordpress.org/extend/plugins/embedplus-for-wordpress/) plugin.
    * Added support for the [VideoJS - HTML5 Video Player for WordPress](http://wordpress.org/extend/plugins/videojs-html5-video-player-for-wordpress/) plugin.
    * Added support for the [YouTube Shortcode](http://wordpress.org/extend/plugins/youtube-shortcode/) plugin.

1.3.2
-----

* Bugfixes:
    * Fix XSLT URL issue, for real this time. Sometimes you have to ignore WordPress internals because they are just
      plain wrong. This is such a time. The path to the XSL file should now always be correct. Note the word "should"
      though.
    * Improve matching of Youtube ID's, apparently those can contain underscores too.
    * Improve reindexation process by running through consecutive loops of 100 posts, to avoid memory issues.
    * Fixed very annoying bug where video's would be mark as non-family-frienldy by default.
    * Force view count to be an integer.
* Enhancements:
    * Switched around the logic for family friendliness. It now assumes all video's are family friendly by default and
      you have to check the box to make it NON family friendly.

1.3.1
-----

* Bugfixes:
    * Prevent relative paths to images
    * Prevent post_id from showing up in XML Video Sitemap
    * Fix wrong URL to XSLT
* Enhancements:
    * Added support for [JW Player Plugin](http://wordpress.org/extend/plugins/jw-player-plugin-for-wordpress/) embeds  (only embeds with `mediaid=<number>` will work for now).

1.3
---

* Bugfixes:
    * Even more YouTube embed fixes, also fixes empty Youtube ID issue.
    * Properly grab thumbnail from YouTube instead of "assuming" a URL.
    * Improve code that grabs duration from YouTube API.
* Enhancements:
    * Add support for searching through category / tag / term descriptions for video content.
    * Get viewcount from YouTube API.
    * Add option to hide sitemap from everyone except admins and Googlebot.
    * Add option to disable the video integration on a single post and page by adding a checkbox on the Video tab.
    * Changed the way reindex gets called, so the admin keeps working immediately after a reindex without a refresh.
    * Added option to force reindexation of old posts that have already been indexed as having video (normally
      they're just refreshed but no external calls are being done).

1.2.2
-----

* Bugfixes:
    * Properly work with [youtube]video-id[/youtube] type embed shortcodes.
* Enhancements:
    * Option to only show the XML video sitemap to admins and to googlebot, not to any other visitors. This prevents
      other visitors from downloading your video files.

1.2.1
-----

* Bugfixes:
    * Properly works with index.php URLs.
    * Sends right URL for video sitemap on Google ping at all times.
    * Correctly clean up video descriptions & tags for display in the XML sitemap.
* Enhancements:
    * Added support for Smart Youtube Pro.
    * Added support for Viddler iframe embeds.
    * Added support for youtu.be oEmbeds.
    * Preliminary Brightcove support.

1.2
---

* The Video tab in the meta box now works, so you can change the preview image.
* The plugin now adds full support for the videoObject schema.
* Several fixes to video recognition, especially for youtube iframe embeds, be sure to click re-index on the Video SEO page if you have those.

1.1
---

* This version should work better on activation.
* The plugin settings are now moved into its own SEO -> Video SEO admin page and out of the XML Sitemaps page.
* The plugin now recognizes youtube and vimeo embeds with an object tag or an iframe, to use this just click reindex video's.
* Improved the snippet preview date display.
* Fixed a few notices.

1.0
---

* Initial version

0.2
---

* First private beta release
