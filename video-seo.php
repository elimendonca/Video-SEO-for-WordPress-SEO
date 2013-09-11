<?php
/*
Plugin Name: Video SEO for WordPress SEO by Yoast
Version: 1.5.4.5
Plugin URI: http://yoast.com/wordpress/video-seo/
Description: This Video SEO module adds all needed meta data and XML Video sitemap capabalities to the metadata capabilities of WordPress SEO to fully optimize your site for video results in the search results.
Author: Joost de Valk
Author URI: http://yoast.com

Copyright 2012-2013 Joost de Valk (email: joost@yoast.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// retrieve our license key from the DB
$options = get_option( 'wpseo_video' );

if ( !is_array( $options ) ) {
	$options = get_option( 'wpseo_xml' );

	$options['dbversion'] = 1;

	update_option( 'wpseo_video', $options );
}

if ( isset( $options['yoast-video-seo-license'] ) && !empty( $options['yoast-video-seo-license'] ) ) {
	if ( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
		// load our custom updater
		include( dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php' );
	}

	$edd_updater = new EDD_SL_Plugin_Updater( 'http://yoast.com/', __FILE__, array(
			'version'   => '1.5.4.5', // current version number
			'license'   => trim( $options['yoast-video-seo-license'] ), // license key
			'item_name' => 'Video SEO for WordPress', // name of this plugin in the Easy Digital Downloads system
			'author'    => 'Joost de Valk' // author of this plugin
		)
	);
}

load_plugin_textdomain( 'yoast-video-seo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
register_activation_hook( __FILE__, array( 'wpseo_Video_Sitemap', 'activate_license' ) );
/**
 * All functionality for fetching video data and creating an XML video sitemap with it.
 *
 * @link       http://codex.wordpress.org/oEmbed oEmbed Codex Article
 * @link       http://oembed.com/ oEmbed Homepage
 *
 * @package    WordPress SEO
 * @subpackage WordPress SEO Video
 */

/**
 * wpseo_video_Video_Sitemap class.
 *
 * @package WordPress SEO Video
 * @since   0.1
 */
class wpseo_Video_Sitemap {

	/**
	 * The maximum number of entries per sitemap page
	 */
	private $max_entries = 5;

	/**
	 * Constructor for the wpseo_Video_Sitemap class.
	 *
	 * @since 0.1
	 */
	function __construct() {

		$options = get_option( 'wpseo_video' );

		$this->set_defaults();

		if ( !isset( $GLOBALS['content_width'] ) && isset( $options['content_width'] ) && is_numeric( $options['content_width'] ) && $options['content_width'] !== 0 )
			$GLOBALS['content_width'] = $options['content_width'];

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'init' ) );

		if ( is_admin() ) {
			add_action( 'update_option_wpseo_video', array( $this, 'activate_license' ) );

			add_action( 'admin_init', array( $this, 'options_init' ) );
			add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
			add_filter( 'wpseo_admin_pages', array( $this, 'style_admin' ) );

			if ( isset( $options['yoast-video-seo-license'] ) && isset( $options['yoast-video-seo-license-status'] )
				&& $options['yoast-video-seo-license-status'] == 'valid'
			) {
				add_action( 'save_post', array( $this, 'update_video_post_meta' ) );

				add_filter( 'wpseo_save_metaboxes', array( $this, 'save_meta_boxes' ), 10, 1 );

				add_action( 'wpseo_tab_header', array( $this, 'tab_header' ) );
				add_action( 'wpseo_tab_content', array( $this, 'tab_content' ) );

				add_filter( 'wpseo_snippet', array( $this, 'snippet_preview' ), 10, 3 );
				add_filter( 'wpseo_metadesc_length', array( $this, 'meta_length' ), 10, 1 );
				add_filter( 'wpseo_metadesc_length_reason', array( $this, 'meta_length_reason' ), 10, 1 );

				add_filter( 'wpseo_linkdex_results', array( $this, 'filter_linkdex_results' ), 10, 3 );
			}
		} else {
			if ( isset( $options['yoast-video-seo-license'] ) && isset( $options['yoast-video-seo-license-status'] )
				&& $options['yoast-video-seo-license-status'] == 'valid'
			) {
				// OpenGraph
				add_action( 'wpseo_opengraph', array( $this, 'opengraph' ) );
				add_filter( 'wpseo_opengraph_type', array( $this, 'opengraph_type' ), 10, 1 );
				add_filter( 'wpseo_opengraph_image', array( $this, 'opengraph_image' ), 5, 1 );

				// XML Sitemap Index addition
				add_filter( 'wpseo_sitemap_index', array( $this, 'add_to_index' ) );

				// Content filter for non-detected video's
				add_filter( 'the_content', array( $this, 'content_filter' ), 5, 1 );

				if ( isset( $options['fitvids'] ) && $options['fitvids'] ) {
					// Fitvids scripting
					add_action( 'wp_head', array( $this, 'fitvids' ) );
				}

				if ( !isset( $options['disable_rss'] ) || !$options['disable_rss'] ) {
					// MRSS
					add_action( 'rss2_ns', array( $this, 'mrss_namespace' ) );
					add_action( 'rss2_item', array( $this, 'mrss_item' ), 10, 1 );
					add_filter( 'mrss_media', array( $this, 'mrss_add_video' ) );
				}
			}
		}
	}

	/**
	 * Adds the fitvids JavaScript to the output if there's a video on the page that's supported by this script.
	 *
	 * @since 1.5.4
	 */
	function fitvids() {
		if ( !is_singular() )
			return;

		global $post;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return;

		// Check if the current post contains a YouTube, Vimeo, Blip.tv or Viddler video, if it does, add the fitvids code.
		if ( in_array( $video['type'], array( 'youtube', 'vimeo', 'blip.tv', 'viddler', 'wistia' ) ) )
			wp_enqueue_script( 'fitvids', plugin_dir_url( __FILE__ ) . 'js/jquery.fitvids.min.js', array( 'jquery' ) );

		add_action( 'wp_footer', array( $this, 'fitvids_footer' ) );
	}

	/**
	 * The fitvids instantiation code.
	 *
	 * @since 1.5.4
	 */
	function fitvids_footer() {
		global $post;

		// Try and use the post class to determine the container
		$classes = get_post_class( '', $post->ID );
		$class   = "post";
		if ( is_array( $classes ) )
			$class = $classes[0];
		?>
		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$(".<?php echo $class; ?>").fitVids({ customSelector: "iframe.wistia_embed"});
			});
		</script>
	<?php
	}

	/**
	 * Make sure the Video SEO plugin receives Yoast admin styling
	 *
	 * @param array $adminpages The array of pages that have Yoast admin styling
	 * @return array $adminpages
	 */
	function style_admin( $adminpages ) {
		array_push( $adminpages, 'wpseo_video' );
		return $adminpages;
	}

	/**
	 * Register the wpseo_video setting
	 */
	function options_init() {
		register_setting( 'yoast_wpseo_video_options', 'wpseo_video' );
	}

	/**
	 * Register the Video SEO submenu.
	 */
	function register_settings_page() {
		add_submenu_page( 'wpseo_dashboard', __( 'Video SEO', 'yoast-video-seo' ), __( 'Video SEO', 'yoast-video-seo' ), 'manage_options', 'wpseo_video', array( $this, 'admin_panel' ) );
	}

	/**
	 * See if there's a license to activate
	 *
	 * @since 1.0
	 */
	static function activate_license() {
		$options = get_option( 'wpseo_video' );

		if ( !( defined( 'WPSEO_VIDEO_LICENSE' ) && WPSEO_VIDEO_LICENSE ) ) {
			if ( !isset( $options['yoast-video-seo-license'] ) || empty( $options['yoast-video-seo-license'] ) ) {
				$options['yoast-video-seo-license'] = $options['yoast-video-seo-license-status'] = '';
				unset( $options['yoast-video-seo-license'] );
				unset( $options['yoast-video-seo-license-status'] );
				update_option( 'wpseo_video', $options );
				return;
			}
		}

		$license_key = self::get_license_key();
		if ( self::is_license_valid() ) {
			return;
		} else if ( $license_key ) {
			// data to send in our API request
			$api_params = array(
				'edd_action' => 'activate_license',
				'license'    => $license_key,
				'item_name'  => urlencode( 'Video SEO for WordPress' ) // the name of our product in EDD
			);

			// Call the custom API.
			$url      = add_query_arg( $api_params, 'http://yoast.com/' );
			$args     = array(
				'timeout' => 25,
				'rand'    => rand( 1000, 9999 )
			);
			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				return;
			}

			// decode the license data
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			// $license_data->license will be either "valid" or "invalid"
			$options['yoast-video-seo-license-status'] = $license_data->license;

			// Constant is defined and license is valid, update the license in options
			if ( $license_data->license == 'valid' && defined( 'WPSEO_VIDEO_LICENSE' ) && WPSEO_VIDEO_LICENSE )
				$options['yoast-video-seo-license'] = WPSEO_VIDEO_LICENSE;

			update_option( 'wpseo_video', $options );
		}
	}

	/**
	 * Adds the rewrite for the video XML sitemap
	 *
	 * @since 0.1
	 */
	function init() {
		// Get options to set the entries per page
		$options           = get_wpseo_options();
		$this->max_entries = ( isset( $options['entries-per-page'] ) && $options['entries-per-page'] != '' ) ? intval( $options['entries-per-page'] ) : 1000;

		// Add oEmbed providers
		$this->add_oembed();

		// Register the sitemap
		if ( isset( $GLOBALS['wpseo_sitemaps'] ) ) {
			$GLOBALS['wpseo_sitemaps']->register_sitemap( $this->video_sitemap_basename(), array( $this, 'build_video_sitemap' ) );
			$GLOBALS['wpseo_sitemaps']->register_xsl( 'video', array( $this, 'build_video_sitemap_xsl' ), 'video-sitemap\.xsl' );
		}
	}

	/**
	 * Register defaults for the video sitemap
	 *
	 * @since 0.2
	 */
	function set_defaults() {
		$options = get_option( 'wpseo_video' );

		if ( !isset( $options['videositemap_posttypes'] ) ) {
			$options['videositemap_posttypes'] = get_post_types( array( 'public' => true ) );
			update_option( 'wpseo_video', $options );
		}
	}

	/**
	 * Returns the basename of the video-sitemap, the first portion of the name of the sitemap "file".
	 *
	 * Defaults to video, but it's possible to override it by using the YOAST_VIDEO_SITEMAP_BASENAME constant.
	 *
	 * @since 1.5.3
	 *
	 * @return string $basename
	 */
	function video_sitemap_basename() {
		$basename = 'video';

		if ( post_type_exists( 'video' ) )
			$basename = 'yoast-video';

		if ( defined( 'YOAST_VIDEO_SITEMAP_BASENAME' ) )
			$basename = YOAST_VIDEO_SITEMAP_BASENAME;

		return $basename;
	}

	/**
	 * Return the Video Sitemap URL
	 *
	 * @since 1.2.1
	 *
	 * @return string The URL to the video Sitemap.
	 */
	function sitemap_url() {
		$base = $GLOBALS['wp_rewrite']->using_index_permalinks() ? 'index.php/' : '';

		return home_url( $base . $this->video_sitemap_basename() . '-sitemap.xml' );
	}

	/**
	 * Adds the video XML sitemap to the Index Sitemap.
	 *
	 * @since  0.1
	 *
	 * @param string $str String with the filtered additions to the index sitemap in it.
	 * @return string $str String with the Video XML sitemap additions to the index sitemap in it.
	 */
	function add_to_index( $str ) {
		$options = get_option( 'wpseo_video' );

		$base = $GLOBALS['wp_rewrite']->using_index_permalinks() ? 'index.php/' : '';

		if ( isset( $options['videositemap_posttypes'] ) ) {
			// Use fields => ids to limit the overhead of fetching entire post objects,
			// fetch only an array of ids instead to count
			$args = array(
				'post_type'      => $options['videositemap_posttypes'],
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_yoast_wpseo_video_meta',
				'meta_compare'   => '!=',
				'meta_value'     => 'none',
				'fields'         => 'ids'
			);
			// Copy these args to be used and modify later
			$date_args = $args;

			$video_ids = get_posts( $args );
			$count     = count( $video_ids );

			$n = ( $count > $this->max_entries ) ? (int) ceil( $count / $this->max_entries ) : 1;
			for ( $i = 0; $i < $n; $i++ ) {
				$count = ( $n > 1 ) ? $i + 1 : '';

				if ( empty( $count ) || $count == $n ) {
					$date_args['fields']         = 'all';
					$date_args['posts_per_page'] = 1;
					$date_args['offset']         = 0;
					$date_args['order']          = 'DESC';
					$date_args['orderby']        = 'modified';
				} else {
					$date_args['fields']         = 'all';
					$date_args['posts_per_page'] = 1;
					$date_args['offset']         = $this->max_entries * ( $i + 1 ) - 1;
					$date_args['order']          = 'ASC';
					$date_args['orderby']        = 'modified';
				}
				$posts = get_posts( $date_args );
				$date  = date( 'c', strtotime( $posts[0]->post_modified_gmt ) );

				$text = ( $count > 1 ) ? $count : '';
				$str .= '<sitemap>' . "\n";
				$str .= '<loc>' . home_url( $base . $this->video_sitemap_basename() . '-sitemap' . $text . '.xml' ) . '</loc>' . "\n";
				$str .= '<lastmod>' . $date . '</lastmod>' . "\n";
				$str .= '</sitemap>' . "\n";
			}

		}

		return $str;
	}

	/**
	 * Adds oembed endpoints for supported video platforms that are not supported by core.
	 *
	 * @since 1.3.5
	 */
	function add_oembed() {
		// Wistia
		wp_oembed_add_provider( '/https?:\/\/(.+)?(wistia\.com|wi\.st)\/(medias|embed)\/.*/', 'http://fast.wistia.com/oembed', true );

		// Animoto
		wp_oembed_add_provider( '/(http:\/\/animoto\.com\/play\/*)/', 'http://animoto.com/services/oembed?format=json', true );

		// Screenr
		wp_oembed_add_provider( 'http://www.screenr.com/*', 'http://www.screenr.com/api/oembed.{format}', false );

		// EVS
		$evs_location = get_option( 'evs_location' );
		wp_oembed_add_provider( $evs_location . '/*', $evs_location . '/oembed.php', false );
	}


	/**
	 * Add the MRSS namespace to the RSS feed.
	 *
	 * @since 0.1
	 */
	function mrss_namespace() {
		echo 'xmlns:media="http://search.yahoo.com/mrss/"';
	}

	/**
	 * Add the MRSS info to the feed
	 *
	 * Based upon the MRSS plugin developed by Andy Skelton
	 *
	 * @since     0.1
	 * @copyright Andy Skelton
	 */
	function mrss_item() {
		global $mrss_gallery_lookup;
		$media = array();

		// Honor the feed settings. Don't include any media that isn't in the feed.
		if ( get_option( 'rss_use_excerpt' ) || !strlen( get_the_content() ) ) {
			$content = the_excerpt_rss();
		} else {
			// If any galleries are processed, we need to capture the attachment IDs.
			add_filter( 'wp_get_attachment_link', array( $this, 'mrss_gallery_lookup' ), 10, 5 );
			$content = apply_filters( 'the_content', get_the_content() );
			remove_filter( 'wp_get_attachment_link', array( $this, 'mrss_gallery_lookup' ), 10, 5 );
			$lookup = $mrss_gallery_lookup;
			unset( $mrss_gallery_lookup );
		}

		// img tags
		$images = 0;
		if ( preg_match_all( '|<img ([^>]+)|', $content, $matches ) ) {
			foreach ( $matches[1] as $attrs ) {
				$item = $img = array();
				// Construct $img array from <img> attributes
				foreach ( wp_kses_hair( $attrs, array( 'http' ) ) as $attr )
					$img[$attr['name']] = $attr['value'];
				if ( !isset( $img['src'] ) )
					continue;
				$img['src'] = $this->mrss_url( $img['src'] );
				// Skip emoticons
				if ( isset( $img['class'] ) && false !== strpos( $img['class'], 'wp-smiley' ) )
					continue;
				$id = false;
				if ( isset( $lookup[$img['src']] ) ) {
					$id = $lookup[$img['src']];
				} elseif ( isset( $img['class'] ) && preg_match( '/wp-image-(\d+)/', $img['class'], $match ) ) {
					$id = $match[1];
				}
				if ( $id ) {
					// It's an attachment, so we will get the URLs, title, and description from functions
					$attachment =& get_post( $id );
					$src        = wp_get_attachment_image_src( $id, 'full' );
					if ( !empty( $src[0] ) )
						$img['src'] = $src[0];
					$thumbnail = wp_get_attachment_image_src( $id, 'thumbnail' );
					if ( !empty( $thumbnail[0] ) && $thumbnail[0] != $img['src'] )
						$img['thumbnail'] = $thumbnail[0];
					$title = get_the_title( $id );
					if ( !empty( $title ) )
						$img['title'] = trim( $title );
					if ( !empty( $attachment->post_excerpt ) )
						$img['description'] = trim( $attachment->post_excerpt );
				}
				// If this is the first image in the markup, make it the post thumbnail
				if ( ++$images == 1 ) {
					if ( isset( $img['thumbnail'] ) )
						$media[]['thumbnail']['attr']['url'] = $img['thumbnail'];
					else
						$media[]['thumbnail']['attr']['url'] = $img['src'];
				}

				$item['content']['attr']['url']    = $img['src'];
				$item['content']['attr']['medium'] = 'image';
				if ( !empty( $img['title'] ) ) {
					$item['content']['children']['title']['attr']['type'] = 'html';
					$item['content']['children']['title']['children'][]   = $img['title'];
				} elseif ( !empty( $img['alt'] ) ) {
					$item['content']['children']['title']['attr']['type'] = 'html';
					$item['content']['children']['title']['children'][]   = $img['alt'];
				}
				if ( !empty( $img['description'] ) ) {
					$item['content']['children']['description']['attr']['type'] = 'html';
					$item['content']['children']['description']['children'][]   = $img['description'];
				}
				if ( !empty( $img['thumbnail'] ) )
					$item['content']['children']['thumbnail']['attr']['url'] = $img['thumbnail'];
				$media[] = $item;
			}
		}

		$media = apply_filters( 'mrss_media', $media );
		$this->mrss_print( $media );
	}

	function mrss_url( $url ) {
		if ( preg_match( '!^https?://!', $url ) )
			return $url;
		if ( $url{0} == '/' )
			return rtrim( home_url(), '/' ) . $url;
		return home_url() . $url;
	}

	function mrss_gallery_lookup( $link, $id ) {
		global $mrss_gallery_lookup;
		preg_match( '/ src="(.*?)"/', $link, $matches );
		$mrss_gallery_lookup[$matches[1]] = $id;
		return $link;
	}

	function mrss_print( $media ) {
		if ( !empty( $media ) )
			foreach ( $media as $element )
				$this->mrss_print_element( $element );
		echo "\n";
	}

	function mrss_print_element( $element, $indent = 2 ) {
		echo "\n";
		foreach ( $element as $name => $data ) {
			echo str_repeat( "\t", $indent ) . "<media:$name";
			$cdata = false;
			if ( $name == 'title' || $name == 'description' )
				$cdata = true;
			if ( !empty( $data['attr'] ) ) {
				foreach ( $data['attr'] as $attr => $value )
					echo " $attr=\"" . esc_attr( ent2ncr( $value ) ) . "\"";
			}
			if ( !empty( $data['children'] ) ) {
				$nl = false;
				echo ">";
				foreach ( $data['children'] as $_name => $_data ) {
					if ( is_int( $_name ) ) {
						if ( $cdata )
							echo '<![CDATA[';
						echo ent2ncr( esc_html( $_data ) );
						if ( $cdata )
							echo ']]>';
					} else {
						$nl = true;
						$this->mrss_print_element( array( $_name => $_data ), $indent + 1 );
					}
				}
				if ( $nl )
					echo "\n" . str_repeat( "\t", $indent );
				echo "</media:$name>";
			} else {
				echo " />";
			}
		}
	}

	/**
	 * Add the video output to the MRSS feed.
	 *
	 * @since 0.1
	 */
	function mrss_add_video( $media ) {
		global $post;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return $media;

		$video_duration = wpseo_get_value( 'videositemap-duration', $post->ID );
		if ( !$video_duration && isset( $video['duration'] ) ) {
			$video_duration = $video['duration'];
		}

		$item['content']['attr']['url']                             = $video['player_loc'];
		$item['content']['attr']['duration']                        = $video_duration;
		$item['content']['children']['player']['attr']['url']       = $video['player_loc'];
		$item['content']['children']['title']['attr']['type']       = 'html';
		$item['content']['children']['title']['children'][]         = esc_html( $video['title'] );
		$item['content']['children']['description']['attr']['type'] = 'html';
		$item['content']['children']['description']['children'][]   = esc_html( $video['description'] );
		$item['content']['children']['thumbnail']['attr']['url']    = $video['thumbnail_loc'];
		$item['content']['children']['keywords']['children'][]      = implode( ',', $video['tag'] );
		array_unshift( $media, $item );

		return $media;
	}

	/**
	 * Downloads an externally hosted thumbnail image to the local server
	 *
	 * @since 0.1
	 *
	 * @param string $url          The remote URL of the image.
	 * @param string $vid          Array with the video data.
	 * @param string $ext          Extension to use for the image, optional.
	 *
	 * @return bool|string $img[0] The link to the now locally hosted image.
	 */
	function make_image_local( $url, $vid, $ext = '' ) {

		// Remove query parameters from the URL
		$url = strtok( $url, '?' );

		if ( isset( $vid['post_id'] ) ) {
			$att = get_posts( array(
				'numberposts' => 1,
				'post_type'   => 'attachment',
				'meta_key'    => 'wpseo_video_id',
				'meta_value'  => isset( $vid['id'] ) ? $vid['id'] : '',
				'post_parent' => $vid['post_id'],
				'fields'      => 'ids'
			) );

			if ( count( $att ) > 0 ) {
				$img = wp_get_attachment_image_src( $att[0], 'full' );

				if ( $img ) {
					if ( strpos( $img[0], 'http' ) !== 0 )
						return get_site_url( null, $img[0] );
					else
						return $img[0];
				}
			}

		}

		// Disable wp smush.it to speed up the process
		remove_filter( 'wp_generate_attachment_metadata', 'wp_smushit_resize_from_meta_data' );

		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return false;
		} else {
			preg_match( '/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $url, $matches );

			if ( isset( $matches[1] ) )
				$ext = $matches[1];
			$title      = sanitize_title( strtolower( $vid['title'] ) );
			$file_array = array(
				'name'     => sanitize_file_name( preg_replace( '/[^a-z0-9\s\-_]/i', '', $title ) ) . '.' . $ext,
				'tmp_name' => $tmp
			);

			if ( isset( $vid['post_id'] ) && !defined( 'WPSEO_VIDEO_NO_ATTACHMENTS' ) ) {

				$ret = media_handle_sideload( $file_array, $vid['post_id'], 'Video thumbnail for ' . $vid['type'] . ' video ' . $vid['title'] );

				if ( isset( $vid['id'] ) )
					update_post_meta( $ret, 'wpseo_video_id', $vid['id'] );

				$img = wp_get_attachment_image_src( $ret, 'full' );

				if ( $img ) {
					// Try and prevent relative paths to images
					if ( strpos( $img[0], 'http' ) !== 0 )
						$img = get_site_url( null, $img[0] );
					else
						$img = $img[0];

					return $img;
				}

			} else {
				$file = wp_handle_sideload( $file_array, array( 'test_form' => false ) );

				if ( !isset( $file['error'] ) ) {
					return $file['url'];
				}
			}
			return false;
		}
	}

	/**
	 * Checks whether there are oembed URLs in the post that should be included in the video sitemap.
	 *
	 * @since 0.1
	 *
	 * @param string $content the content of the post.
	 * @return array|boolean returns array $urls with type of video as array key and video URL as content, or false on negative
	 */
	function grab_embeddable_urls( $content ) {
		global $echo;

		$options      = get_option( 'wpseo_video' );
		$evs_location = get_option( 'evs_location' );

		// For compatibility with Youtube Lyte and Smart Youtube Pro
		$content = str_replace( array( 'httpv://', 'httpvh://', 'httpvp://', 'httpa://' ), 'http://', $content );

		// Catch both the single line embeds as well as the embeds using the [embed] shortcode.
		preg_match_all( '|\[embed([^\]]+)?\](https?://[^\s"]+)\[/embed\]|im', $content, $matches );
		preg_match_all( '/^\s*(<p>)?(https?:\/\/[^\s"]+)\s*$/im', $content, $matches2 );

		$matched_urls = array_merge( $matches[2], $matches2[2] );

		if ( preg_match_all( '|(<iframe.*</iframe>)|', $content, $iframes, PREG_SET_ORDER ) ) {
			foreach ( $iframes as $iframe ) {
				if ( preg_match( '/id=(\'|")vzvd-(\d+)\1/', $iframe[1], $iframesrc ) ) {
					if ( isset( $options['vzaar_domain'] ) && !empty( $options['vzaar_domain'] ) )
						$matched_urls[] = 'http://' . $options['vzaar_domain'] . '/' . $iframesrc[2] . '/video';
					else
						$matched_urls[] = 'http://view.vzaar.com/' . $iframesrc[2] . '/video';
				} else if ( preg_match( '/src=(\'|")(.*?)\1/', $iframe[1], $iframesrc ) )
					$matched_urls[] = $iframesrc[2];
			}
		}

		if ( preg_match_all( '|(<object.*</object>)|', $content, $objects, PREG_SET_ORDER ) ) {
			foreach ( $objects as $object ) {
				if ( preg_match( '/<param name=(\'|")src\1 value=(\'|")(.*?)\1/', $object[1], $srcmatch ) ) {
					$matched_urls[] = $srcmatch[3];
				} else if ( preg_match( '/<param name=(\'|")movie\1 value=(\'|")(.*?)\1/', $object[1], $moviematch ) ) {
					$matched_urls[] = $moviematch[3];
				}
			}
		}

		if ( preg_match( '/<a href=(\'|")(https?:\/\/(www\.)?(youtube|vimeo)\.com\/.*?)\1 rel=(\'|")wp-video-lightbox\1/', $content, $matches ) ) {
			$matched_urls[] = $matches[2];
		}

		if ( preg_match( '/<a class="youtubepop" href=(\'|")(https?:\/\/(www\.)?(youtube|vimeo)\.com\/.*?)\1>/', $content, $matches ) ) {
			$matched_urls[] = $matches[2];
		}

		if ( preg_match( '/<a href=(\'|")(.*?)\1 .*?data-titan-lightbox=(\'|")on\3.*?>/', $content, $matches ) ) {
			$matched_urls[] = $matches[2];
		}

		if ( isset( $options['vzaar_domain'] ) )
			$vzaar_info = $this->parse_url( $options['vzaar_domain'] );

		$evs_info = $this->parse_url( $evs_location );
		if ( count( $matched_urls ) > 0 ) {
			$urls = array();

			foreach ( $matched_urls as $match ) {
				if ( substr( $match, 0, 4 ) != 'http' )
					$match = 'http:' . $match;
				if ( $echo && WP_DEBUG )
					echo $match . '<br/>';

				$url_info = $this->parse_url( $match );
				if ( !isset( $url_info['domain'] ) )
					continue;
				if ( $echo && WP_DEBUG )
					echo $url_info['domain'] . '<br/>';
				switch ( $url_info['domain'] ) {
					case 'animoto.com':
						$urls['animoto'] = $match;
						break;
					case 'blip.tv':
						$urls['blip'] = $match;
						break;
					case 'brightcove.com':
						if ( preg_match( '#<param name="flashVars" value="playerID=(\d+)#', $content, $bcmatch ) ) {
							$urls['brightcove'] = $bcmatch[1];
						}

						if ( preg_match( '#<param name="flashVars" value="videoId=(\d+)#', $content, $bcmatch ) ) {
							$urls['brightcove'] = $bcmatch[1];
						}
						break;
					case 'dailymotion.com':
						$urls['dailymotion'] = $match;
						break;
					case 'flickr.com':
						$urls['flickr'] = $match;
						break;
					case 'muzu.tv':
						$urls['muzutv'] = $match;
						break;
					case 'screenr.com':
						$urls['screenr'] = $match;
						break;
					case 'viddler.com':
						$urls['viddler'] = $match;
						break;
					case 'vimeo.com':
						$urls['vimeo'] = $match;
						break;
					case 'vzaar.com':
						$urls['vzaar'] = $match;
						break;
					case $vzaar_info['domain']:
						$urls['vzaar'] = $match;
						break;
					case 'wistia.com':
					case 'wistia.net':
						$urls['wistia'] = $match;
						break;
					case 'wordpress.tv':
						$urls['wordpress.tv'] = $match;
						break;
					case 'youtu.be':
						$urls['youtube'] = $match;
						break;
					case 'youtube.com':
						$urls['youtube'] = $match;
						break;
					case 'youtube-nocookie.com':
						$urls['youtube'] = $match;
						break;
					case $evs_info['domain']:
						$urls['evs'] = $match;
				}
			}

			if ( count( $urls ) > 0 ) {
				return $urls;
			} else
				return false;
		} else {
			return false;
		}
	}

	/**
	 * Checks whether there are oembed URLs in the post that should be included in the video sitemap.
	 * Uses DOMDocument and XPath to parse the content for url instead of preg matches
	 *
	 * @since 1.5.4.4
	 *
	 * @param string $content the content of the post.
	 * @return array|boolean returns array $urls with type of video as array key and video URL as content, or false on negative
	 */
	function grab_embeddable_urls_xpath( $content ) {
		global $echo;

		$options = get_option( 'wpseo_video' );

		// For compatibility with Youtube Lyte and Smart Youtube Pro
		$content = str_replace( array( 'httpv://', 'httpvh://', 'httpvp://', 'httpa://' ), 'http://', $content );

		$dom = new DOMDocument();
		@$dom->loadHTML( $content );
		$xpath = new DOMXPath( $dom );

		$matched_urls = array();

		// for object embeds (i.e screencast.com)
		$objects = $xpath->query( '//object/param[@name="movie"] | //object/param[@name="src"]' );
		foreach ( $objects as $object ) {
			$value          = $object->getAttribute( 'value' );
			$matched_urls[] = $value;
		}

		// for iframe embeds (i.e. vidyard.com)
		$iframes = $xpath->query( '//iframe' );
		foreach ( $iframes as $iframe ) {
			$src            = $iframe->getAttribute( 'src' );
			$matched_urls[] = $src;
		}

		// additional check for vidyard embed with javascript and lightbox
		$script = $xpath->query( '//script[contains(@src,"play.vidyard.com")]' );
		foreach ( $script as $element ) {
			$src            = $element->getAttribute( 'src' );
			$matched_urls[] = $src;
		}

		// additional check for cincopa embed via javascript
		$script = $xpath->query( '//script/text()[contains(.,"cp_load_widget")]' );
		foreach ( $script as $element ) {
			// Remove CDATA
			$src            = preg_replace( '~//\s*?<!\[CDATA\[\s*|\s*//\s*\]\]>~', '', $element->wholeText );
			$src            = 'http://cincopa.com?' . $src;
			$matched_urls[] = $src;
		}

		// additional check for brightcove
		$script = $xpath->query( '//object/param[contains(@value,"brightcove.com")]/following-sibling::param[@name="flashVars"]' );
		foreach ( $script as $element ) {
			$src            = $element->getAttribute( 'value' );
			$src            = 'http://brightcove.com?' . $src;
			$matched_urls[] = $src;
		}

		// foreach ( $iframes as $iframe ) {
		// if ( preg_match( '/id=(\'|")vzvd-(\d+)\1/', $iframe[1], $iframesrc ) ) {
		// if ( isset( $options['vzaar_domain'] ) && !empty( $options['vzaar_domain'] ) )
		// $matched_urls[] = 'http://' . $options['vzaar_domain'] . '/' . $iframesrc[2] . '/video';
		// else
		// $matched_urls[] = 'http://view.vzaar.com/' . $iframesrc[2] . '/video';
		// } else if ( preg_match( '/src=(\'|")(.*?)\1/', $iframe[1], $iframesrc ) )
		// $matched_urls[] = $iframesrc[2];
		// }

		if ( count( $matched_urls ) > 0 ) {
			$urls = array();

			foreach ( $matched_urls as $match ) {
				if ( substr( $match, 0, 4 ) != 'http' )
					$match = 'http:' . $match;
				if ( $echo && WP_DEBUG )
					echo $match . '<br/>';

				$url_info = $this->parse_url( $match );
				if ( !isset( $url_info['domain'] ) )
					continue;
				if ( $echo && WP_DEBUG )
					echo $url_info['domain'] . '<br/>';
				switch ( $url_info['domain'] ) {
					case 'animoto.com':
						$urls['animoto'] = $match;
						break;
					case 'brightcove.com':
						$urls['brightcove'] = $match;
						break;
					case 'cincopa.com':
						$urls['cincopa'] = $match;
						break;
					case 'screenr.com':
						$urls['screenr'] = $match;
						break;
					// work around for screencast.com b/c there's no connection between url and the embed code
					case 'screencast.com':
						$urls['screencast']['url']   = $match;
						$urls['screencast']['embed'] = $content;
						break;
					case 'vidyard.com':
						$urls['vidyard'] = $match;
						break;

				}
			}

			if ( count( $urls ) > 0 ) {
				return $urls;
			} else
				return false;
		} else {
			return false;
		}

		return $urls;
	}


	/**
	 * Parse a URL and find the host name and more.
	 *
	 * @since 1.1
	 *
	 * @link  http://php.net/manual/en/function.parse-url.php#83875
	 *
	 * @param string $url The URL to parse
	 * @return array
	 */
	function parse_url( $url ) {
		$r = "^(?:(?P<scheme>\w+)://)?";
		$r .= "(?:(?P<login>\w+):(?P<pass>\w+)@)?";
		$r .= "(?P<host>(?:(?P<subdomain>[-\w\.]+)\.)?" . "(?P<domain>[-\w]+\.(?P<extension>\w+)))";
		$r .= "(?::(?P<port>\d+))?";
		$r .= "(?P<path>[\w/]*/(?P<file>\w+(?:\.\w+)?)?)?";
		$r .= "(?:\?(?P<arg>[\w=&]+))?";
		$r .= "(?:#(?P<anchor>\w+))?";
		$r = "!$r!"; // Delimiters

		preg_match( $r, $url, $out );

		return $out;
	}

	/**
	 * Wrapper for the WordPress internal wp_remote_get function, making sure a proper user-agent is sent along.
	 *
	 * @since 0.1
	 *
	 * @param string $url     The URL to retrieve.
	 * @param array  $headers Optional headers to send.
	 *
	 * @return array|boolean $body Returns the body of the post when successfull, false when unsuccessfull.
	 */
	function remote_get( $url, $headers = array() ) {
		$response = wp_remote_get( $url,
			array(
				'redirection' => 1,
				'httpversion' => '1.1',
				'user-agent'  => 'WordPress Video SEO plugin ' . WPSEO_VERSION . '; WordPress (' . home_url( '/' ) . ')',
				'timeout'     => 15,
				'headers'     => $headers
			)
		);

		if ( !is_wp_error( $response ) && $response['response']['code'] == 200 ) {
			return $response['body'];
		} else {
			return false;
		}
	}

	/**
	 * Use the "new" post data with the old video data, to prevent the need for an external video API call when the video hasn't changed.
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The "new" video array
	 * @param array  $oldvid The old video array
	 * @param string $thumb  Possibly the thumbnail, if set manually.
	 * @return array $vid With the new values from $vid and the old values from $oldvid combined.
	 */
	function use_old_video_data( $vid, $oldvid, $thumb ) {
		$oldvid['title']            = $vid['title'];
		$oldvid['description']      = $vid['description'];
		$oldvid['publication_date'] = $vid['publication_date'];
		if ( isset( $vid['category'] ) )
			$oldvid['category'] = $vid['category'];
		if ( isset( $vid['tag'] ) )
			$oldvid['tag'] = $vid['tag'];

		if ( $thumb != '' )
			$oldvid['thumbnail_loc'] = $thumb;

		return $oldvid;
	}

	/**
	 * Retrieve video details from Brightcove
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch" from Brightcove, if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function brightcove_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( ( isset( $oldvid['url'] ) && $oldvid['url'] == $vid['url'] ) || ( isset( $oldvid['id'] ) && isset( $vid['id'] ) && $oldvid['id'] == $vid['id'] ) ) {
			return $this->use_old_video_data( $vid, $oldvid, $thumb );
		}

		// grab Brightcove api key from wp_options
		$token = get_option( 'bc_api_key' );
		if ( empty( $token ) )
			return false;

		if ( !isset( $vid['id'] ) ) {
			$parse      = parse_url( $vid['url'] );
			$query_vars = array();

			parse_str( $parse['query'], $query_vars );

			if ( isset( $query_vars['vidID'] ) && $query_vars['vidID'] ) {
				$vid['id'] = $query_vars['ID'];

			} else if ( isset( $query_vars['playerID'] ) && $query_vars['playerID'] ) {
				$vid['player_id'] = $query_vars['playerID'];
			}

			// Player id is given which means this is a playlist so grab the first video from the playlist
			if ( isset( $vid['player_id'] ) && $vid['player_id'] ) {
				$request = 'http://api.brightcove.com/services/library?command=find_playlists_for_player_id&player_id=' . $vid['player_id'] . '&video_fields=id&token=' . $token;

				$response = $this->remote_get( $request );
				if ( $response == null || $response == 'null' )
					return false;

				$bc = json_decode( $response );
				if ( WP_DEBUG )
					echo '<pre>' . print_r( $bc, 1 ) . '</pre>';

				if ( isset( $bc->error ) ) {
					return false;
				}

				if ( isset( $bc->items[0]->videoIds[0] ) && $bc->items[0]->videoIds[0] ) {
					$vid['id'] = $bc->items[0]->videoIds[0];
				}
			}
		}

		if ( isset( $vid['id'] ) ) {
			$request = 'http://api.brightcove.com/services/library?command=find_video_by_id&video_id=' . $vid['id'] . '&video_fields=name,playsTotal,videoStillURL,length,FLVURL,videoFullLength&media_delivery=http&token=' . $token;

			$response = $this->remote_get( $request );
			if ( $response == null || $response == 'null' )
				return false;

			$bc = json_decode( $response );
			if ( WP_DEBUG )
				echo '<pre>' . print_r( $bc, 1 ) . '</pre>';

			if ( isset( $bc->error ) ) {
				return false;
			}

			$vid['type']          = 'brightcove';
			$vid['duration']      = (int) ( $bc->length / 1000 );
			$vid['view_count']    = (int) $bc->playsTotal;
			$vid['thumbnail_loc'] = $this->make_image_local( $bc->videoStillURL, $vid );
			$vid['content_loc']   = $bc->FLVURL;
			$vid['width']         = $bc->videoFullLength->frameWidth;
			$vid['height']        = $bc->videoFullLength->frameHeight;

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else
				$vid['thumbnail_loc'] = $this->make_image_local( $bc->videoStillURL, $vid );

		}

		return $vid;
	}

	/**
	 * Retrieve video details from Viddler
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch" from Viddler, if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function viddler_details( $vid, $oldvid = array(), $thumb = '' ) {

		if ( ( isset( $oldvid['url'] ) && isset( $vid['url'] ) && $oldvid['url'] == $vid['url'] ) || ( isset( $oldvid['id'] ) && isset( $vid['id'] ) && $oldvid['id'] == $vid['id'] ) ) {
			return $this->use_old_video_data( $vid, $oldvid, $thumb );
		}

		if ( !isset( $vid['id'] ) && isset( $vid['url'] ) ) {
			if ( preg_match( '#https?://(www.)?viddler\.com/embed/([^/]+).*#', $vid['url'], $match ) )
				$vid['id'] = $match[2];
		}

		if ( isset( $vid['id'] ) )
			$response = $this->remote_get( 'http://api.viddler.com/api/v2/viddler.videos.getDetails.php?key=0118093f713643444556524f452f&video_id=' . $vid['id'] );
		else
			$response = $this->remote_get( 'http://api.viddler.com/api/v2/viddler.videos.getDetails.php?key=0118093f713643444556524f452f&url=' . $vid['url'] );

		if ( $response ) {
			$video = unserialize( $response );

			$vid['id']         = $video['video']['id'];
			$vid['duration']   = $video['video']['length'];
			$vid['view_count'] = (int) $video['video']['view_count'];
			$vid['player_loc'] = 'http://www.viddler.com/player/' . $video['video']['id'] . '/';
			$vid['type']       = 'viddler';

			if ( isset( $video['video']['files'] ) ) {
				foreach ( $video['video']['files'] as $file ) {
					if ( $file['ext'] == 'mp4' )
						$vid['content_loc'] = $file['url'];
				}
			}

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else
				$vid['thumbnail_loc'] = $this->make_image_local( $video['video']['thumbnail_url'], $vid );

			return $vid;
		}

		return false;
	}

	/**
	 * Retrieve video details from Flickr
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function flickr_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( preg_match( '|/(\d+)/?$|', $vid['url'], $matches ) ) {
			$vid['id'] = $matches[1];

			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = $this->remote_get( "http://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key=2d2985adb59d21e6933368e41e5ca3b0&photo_id=" . $vid['id'] . "&format=json&nojsoncallback=1" );

			if ( $response ) {
				$flickr = json_decode( $response );

				if ( $flickr->photo->media != 'video' )
					return false;

				$vid['duration']   = $flickr->photo->video->duration;
				$vid['view_count'] = (int) $flickr->photo->views;
				$vid['type']       = 'flickr';
				$vid['player_loc'] = 'http://www.flickr.com/apps/video/stewart.swf?v=109786&intl_lang=en_us&photo_secret=' . $flickr->photo->secret . '&photo_id=' . $vid['id'];

				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else
					$vid['thumbnail_loc'] = $this->make_image_local( 'http://farm' . $flickr->photo->farm . '.staticflickr.com/' . $flickr->photo->server . '/' . $matches[1] . '_' . $flickr->photo->secret . '.jpg', $vid );
				return $vid;
			}
		}
		return false;
	}

	/**
	 * Retrieve video details from Dailymotion
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function dailymotion_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '|https?://(www\.)?dailymotion.com/video/([^_]+)_.*|', $vid['url'], $matches ) )
				$vid['id'] = $matches[2];
			else
				return false;
		}

		if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
			return $this->use_old_video_data( $vid, $oldvid, $thumb );

		$response = $this->remote_get( 'https://api.dailymotion.com/video/' . $vid['id'] . '?fields=duration,embed_url,thumbnail_large_url,views_total' );

		if ( $response ) {
			$video = json_decode( $response );

			$vid['view_count'] = (int) $video->views_total;
			$vid['duration']   = $video->duration;
			$vid['player_loc'] = $video->embed_url;
			$vid['type']       = 'dailymotion';

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else
				$vid['thumbnail_loc'] = $this->make_image_local( $video->thumbnail_large_url, $vid );

			return $vid;
		}

		return false;
	}

	/**
	 * Retrieve video details from Blip.tv
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function blip_details( $vid, $oldvid = array(), $thumb = '' ) {

		if ( !isset( $vid['id'] ) || empty( $vid['id'] ) ) {
			if ( preg_match( '/.*-(\d+)$/', $vid['url'], $matches ) )
				$vid['id'] = $matches[1];

			// This isn't active yet as the ID here doesn't translate directly into a Blip ID...
			// if ( preg_match( '|http://blip\.tv/play/([^\.]+)\.html.*|', $vid['url'], $matches ) )
			//	$vid['id'] = $matches[1];
		}

		if ( isset( $vid['id'] ) ) {

			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = $this->remote_get( 'http://blip.tv/rss/view/' . $vid['id'] );

			if ( $response ) {
				preg_match( "|<blip:runtime>(\d+)</blip:runtime>|", $response, $match );
				$vid['duration'] = $match[1];

				preg_match( '|<media:player url="([^"]+)">|', $response, $match );
				$vid['player_loc'] = $match[1];

				preg_match( '|<enclosure length="[\d]+" type="[^"]+" url="([^"]+)"/>|', $response, $match );
				$vid['content_loc'] = $match[1];

				$vid['type'] = 'blip.tv';

				if ( $thumb != '' ) {
					$vid['thumbnail_loc'] = $thumb;
				} else {
					preg_match( '|<media:thumbnail url="([^"]+)"/>|', $response, $match );
					$vid['thumbnail_loc'] = $this->make_image_local( $match[1], $vid );
				}
				return $vid;
			}
		}
		return false;
	}

	/**
	 * Retrieve video details from Animoto
	 *
	 * @since 1.4.3
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function animoto_details( $vid, $oldvid = array(), $thumb = '' ) {

		if ( isset( $vid['url'] ) ) {
			if ( preg_match( '/http:\/\/static\.animoto\.com\/swf\/.*?&f=([^&]+).*/', $vid['url'], $match ) )
				$vid['url'] = 'http://animoto.com/play/' . $match[1];

			if ( isset( $oldvid['url'] ) && $vid['url'] == $oldvid['url'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = $this->remote_get( 'http://animoto.com/services/oembed?format=json&url=' . urlencode( $vid['url'] ) );
			if ( !is_wp_error( $response ) && $response ) {
				$video = json_decode( $response );

				$vid['player_loc'] = $video->video_url;

				// Animoto doesn't provide duration in the oembed API, unfortunately.
				// $vid['duration']   = $video->duration;

				$vid['type'] = 'animoto';

				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else
					$vid['thumbnail_loc'] = $this->make_image_local( $video->thumbnail_url, $vid );

				return $vid;
			}
		}

		return false;
	}

	/**
	 * Retrieve video details from Vimeo
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function vimeo_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '#https?://(player\.|www\.)?vimeo\.com/(video/)?(\d+)#', $vid['url'], $matches ) )
				$vid['id'] = $matches[3];

			if ( preg_match( '#https?://(www\.)?vimeo\.com/moogaloop\.swf\?clip_id=([^&]+)#', $vid['url'], $matches ) )
				$vid['id'] = $matches[2];
		}

		if ( isset( $vid['id'] ) )
			$vid['url'] = 'http://vimeo.com/' . $vid['id'];

		if ( isset( $oldvid['url'] ) && $vid['url'] == $oldvid['url'] )
			return $this->use_old_video_data( $vid, $oldvid, $thumb );

		$response = $this->remote_get( 'http://vimeo.com/api/oembed.json?url=' . $vid['url'] );
		if ( $response ) {
			$video = json_decode( $response );

			$vid['id']         = $video->video_id;
			$vid['player_loc'] = 'https://www.vimeo.com/moogaloop.swf?clip_id=' . $vid['id'];
			$vid['duration']   = $video->duration;
			$vid['width']      = $video->width;
			$vid['height']     = $video->height;
			$vid['type']       = 'vimeo';

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else
				$vid['thumbnail_loc'] = $this->make_image_local( $video->thumbnail_url, $vid );

			return $vid;
		}

		return false;
	}

	/**
	 * Retrieve video details from Vzaar
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function vzaar_details( $vid, $oldvid = array(), $thumb = '' ) {
		$options = get_option( 'wpseo_video' );

		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '/\/(\d+)\/(player|flashplayer|video|download)$/', $vid['url'], $match ) )
				$vid['id'] = $match[1];
		}


		if ( isset( $vid['id'] ) ) {

			$vid['type'] = 'vzaar';

			if ( is_array( $oldvid ) && isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$base_url = 'http://vzaar.com/videos/';
			if ( isset( $options['vzaar_domain'] ) )
				$base_url = 'http://' . $options['vzaar_domain'] . '/';

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else
				$vid['thumbnail_loc'] = $this->make_image_local( $base_url . $vid['id'] . '/image', $vid, 'jpg' );

			$vid['player_loc'] = 'https://view.vzaar.com/' . $vid['id'] . '/flasplayer';

			$response = $this->remote_get( $base_url . $vid['id'] . '.json', array( 'referer' => get_site_url() ) );

			// We don't strictly need a response, funnily enough, though we lack the duration when we don't get it.
			if ( $response ) {
				$video = json_decode( $response );

				if ( isset( $vid['duration'] ) )
					$vid['duration'] = round( $video->duration );
				if ( isset( $vid['width'] ) )
					$vid['width'] = $video->width;
				if ( isset( $vid['height'] ) )
					$vid['height'] = $video->height;
			}

			return $vid;
		}

		return false;
	}


	/**
	 * Retrieve video details from Vidyard
	 *
	 * @since 1.3.4.4
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function vidyard_details( $vid, $oldvid = array(), $thumb = '' ) {
		$options = get_option( 'wpseo_video' );

		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '~vidyard\.com/(.*?)(\.js|\.html|\?|$)~', $vid['url'], $match ) )
				$vid['id'] = $match[1];
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$url      = 'http://play.vidyard.com/' . $vid['id'];
			$response = $this->remote_get( $url );

			if ( $response ) {
				// must use preg match because the data is in inline javascript
				preg_match( '/vidyard_chapter_data = (\[.*?\]);/s', $response, $match );
				// replace single quotes with double quotes so it can be json decoded
				$json = str_replace( '\'', '"', trim( $match[1] ) );
				$json = json_decode( $json, true );
				$json = reset( $json );

				$vidyard_video_id   = $json['video_id'];
				$vid['player_loc']  = $url;
				$vid['content_loc'] = $json['sd_unsecure_url'];
				$vid['duration']    = $json['seconds'];
				$vid['type']        = 'vidyard';

				// preg match for thumbnail
				preg_match( '/vidyard_thumbnail_data = ({.*?});/s', $response, $match1 );
				$thumbnail_data = str_replace( '\'', '"', trim( $match1[1] ) );
				$thumbnail_data = json_decode( $thumbnail_data, true );
				$thumbnail_data = reset( $thumbnail_data );
				$thumbnail      = $thumbnail_data['url'];

				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else
					$vid['thumbnail_loc'] = $this->make_image_local( $thumbnail, $vid );

				return $vid;
			}
		}

		return false;
	}


	/**
	 * Retrieve video details from Vippy
	 *
	 * @since 1.3.4
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function vippy_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( isset( $oldvid['id'] ) && isset( $vid['id'] ) && $oldvid['id'] == $vid['id'] ) {
			return $this->use_old_video_data( $vid, $oldvid, $thumb );
		}
		$vippy_id = $vid['id'];
		// Requires the Vippy plugin http://wordpress.org/extend/plugins/vippy/
		if ( !class_exists( 'Vippy' ) )
			return false;

		//Retrieve the vippy video
		$vippy       = new Vippy;
		$vippy_video = $vippy->get_video( array( 'videoId' => $vippy_id, 'statistics' => 1 ) );
		if ( isset( $vippy_video->error ) ) return false;

		//Fill the details
		$vippy_video = isset( $vippy_video->vippy[0] ) ? $vippy_video->vippy[0] : false;
		if ( !$vippy_video ) return false;
		$vid['type']        = 'vippy';
		$vid['content_loc'] = isset( $vippy_video->open_graph_url ) ? $vippy_video->highQuality : ''; //MP4
		if ( $thumb != '' )
			$vid['thumbnail_loc'] = $thumb;
		else
			$vid['thumbnail_loc'] = isset( $vippy_video->thumbnail ) ? $this->make_image_local( $vippy_video->thumbnail, $vid ) : '';
		$vid['duration']   = isset( $vippy_video->duration ) ? round( $vippy_video->duration ) : 0; //convert 30.09 to 30
		$vid['view_count'] = (int) $vippy_video->views;
		return $vid;
	}

	/**
	 * Retrieve video details from Wistia
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function wistia_details( $vid, $oldvid = array(), $thumb = '' ) {

		if ( isset( $oldvid['url'] ) && isset( $vid['url'] ) && $vid['url'] == $oldvid['url'] )
			return $this->use_old_video_data( $vid, $oldvid, $thumb );

		if ( isset( $vid['id'] ) )
			$vid['url'] = 'http://home.wistia.com/medias/' . $vid['id'];

		if ( isset( $vid['url'] ) ) {

			$url = urlencode( $vid['url'] );

			$response = $this->remote_get( 'http://fast.wistia.com/oembed?url=' . $url );

			if ( $response ) {
				$video = json_decode( $response );

				if ( preg_match( '/<iframe src=(\'|")(.*?)\1/', $video->html, $match ) ) {

					$framesrc = $this->remote_get( $match[2] );

					if ( preg_match( '/<a href=(\'|")(.*?)\1 id=(\'|")wistia_fallback\3/', $framesrc, $framematch ) ) {

						$vid['content_loc'] = $framematch[2];
						$vid['duration']    = round( $video->duration );
						$vid['type']        = 'wistia';
						$vid['width']       = $video->width;
						$vid['height']      = $video->height;

						if ( $thumb != '' )
							$vid['thumbnail_loc'] = $thumb;
						else {
							$vid['thumbnail_loc'] = $this->make_image_local( $video->thumbnail_url, $vid );
						}

						if ( preg_match( '/"type":"flv","url":"(.*?)"/', $framesrc, $tmpmatch ) )
							$flv = $tmpmatch[1];

						if ( preg_match( '/"type":"still","url":"(.*?)"/', $framesrc, $tmpmatch ) )
							$still = $tmpmatch[1];

						if ( preg_match( '/"accountKey":"(.*?)"/', $framesrc, $tmpmatch ) )
							$account_key = $tmpmatch[1];

						if ( preg_match( '/"mediaKey":"(.*?)"/', $framesrc, $tmpmatch ) )
							$media_key = $tmpmatch[1];

						if ( isset( $flv, $still, $account_key, $media_key ) )
							$vid['player_loc'] = 'https://wistia.sslcs.cdngc.net/flash/embed_player_v2.0.swf?videoUrl=' . $flv . '&stillUrl=' . $still . '&controlsVisibleOnLoad=false&unbufferedSeek=true&autoLoad=false&autoPlay=true&endVideoBehavior=default&embedServiceURL=http://distillery.wistia.com/x&accountKey=' . $account_key . '&mediaID=' . $media_key . '&mediaDuration=' . $video->duration . '&fullscreenDisabled=false';

						return $vid;
					}

				}
			}
		}

		return false;
	}

	/**
	 * Retrieve video details from YouTube
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function youtube_details( $vid, $oldvid = array(), $thumb = '' ) {

		if ( !isset( $vid['id'] ) ) {
			$id_match = '[0-9a-zA-Z\-_]+';
			if ( preg_match( '|https?://(www\.)?youtube\.com/(watch)?\?.*v=(' . $id_match . ')|', $vid['url'], $matches ) )
				$vid['id'] = $matches[3];
			else if ( preg_match( '|https?://(www\.)?youtube(-nocookie)?\.com/embed/(' . $id_match . ')|', $vid['url'], $matches ) )
				$vid['id'] = $matches[3];
			else if ( preg_match( '|https?://(www\.)?youtube\.com/v/(' . $id_match . ')|', $vid['url'], $matches ) )
				$vid['id'] = $matches[2];
			else if ( preg_match( '|http://youtu\.be/(' . $id_match . ')|', $vid['url'], $matches ) )
				$vid['id'] = $matches[1];
			else if ( !preg_match( '|^http|', $vid['url'], $matches ) )
				$vid['id'] = $vid['url'];
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$vid['player_loc'] = htmlentities( 'https://www.youtube-nocookie.com/v/' . $vid['id'] );
			$vid['type']       = 'youtube';

			$response = $this->remote_get( 'http://gdata.youtube.com/feeds/api/videos/' . $vid['id'] );

			if ( $response ) {
				// Thumbnail
				if ( $thumb != '' ) {
					$vid['thumbnail_loc'] = $thumb;
				} else {
					preg_match( "|<media:thumbnail url='([^']+)'|", $response, $match );
					$vid['thumbnail_loc'] = $this->make_image_local( $match[1], $vid );
				}

				// View count
				preg_match( "|<yt:statistics favoriteCount='([\d]+)' viewCount='([\d]+)'/>|", $response, $match );
				$vid['view_count'] = (int) $match[2];

				$vid['width']  = 640;
				$vid['height'] = 390;

				// Duration
				preg_match( "|<yt:duration seconds='([0-9]+)'/>|", $response, $match );
				$vid['duration'] = $match[1];
				return $vid;
			}
		}

		return false;
	}

	/**
	 * Retrieve video details from VideoPress
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function videopress_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
			return $this->use_old_video_data( $vid, $oldvid, $thumb );

		$domain         = parse_url( home_url(), PHP_URL_HOST );
		$request_params = array( 'guid' => $vid['id'], 'domain' => $domain );

		$url = 'https://v.wordpress.com/data/wordpress.json?' . http_build_query( $request_params, null, '&' );

		$response = $this->remote_get( $url );

		if ( $response ) {
			$video = json_decode( $response );

			$vid['duration']   = $video->duration;
			$vid['player_loc'] = 'https://v0.wordpress.com/player.swf?v=1.03&amp;guid=' . $vid['id'] . '&amp;isDynamicSeeking=true';

			$vid['type'] = 'videopress';

			if ( isset( $video->mp4 ) )
				$vid['content_loc'] = $video->mp4->url;

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else
				$vid['thumbnail_loc'] = $this->make_image_local( $video->posterframe, $vid );

			return $vid;
		}

		return false;
	}

	/**
	 * Retrieve video details from WordPress.tv (well grab the ID and then use the VideoPress API)
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function wordpresstv_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( isset( $oldvid['url'] ) && $vid['url'] == $oldvid['url'] )
			return $this->use_old_video_data( $vid, $oldvid, $thumb );

		$response = $this->remote_get( 'http://wordpress.tv/oembed/?url=' . $vid['url'] );
		if ( $response ) {
			$wptv = json_decode( $response );

			if ( preg_match( '|v\.wordpress\.com/([^"]+)|', $wptv->html, $match ) ) {
				$vid['id'] = $match[1];
				return $this->videopress_details( $vid, $oldvid, $thumb );
			} else {
				return false;
			}

		}
		return false;
	}

	/**
	 * Retrieve video details from Metacafe
	 *
	 * @since 0.1
	 *
	 * @link  http://help.metacafe.com/?page_id=238 Metacafe API docs.
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function metacafe_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '|/watch/(\d+)/|', $vid['url'], $matches ) )
				$vid['id'] = $matches[1];
		}

		if ( isset( $vid['id'] ) ) {

			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$vid['type'] = 'metacafe';

			$response = $this->remote_get( 'http://www.metacafe.com/api/item/' . $vid['id'] . '/' );

			preg_match( '/duration="(\d+)"/', $response, $match );
			$vid['duration'] = $match[1];

			$vid['player_loc'] = 'http://www.metacafe.com/fplayer/' . $vid['id'] . '/.swf';

			preg_match( '/<media:content url="([^"]+)"/', $response, $match );
			$vid['content_loc'] = $match[1];

			if ( $thumb != '' ) {
				$vid['thumbnail_loc'] = $thumb;
			} else {
				preg_match( '/<media:thumbnail url="([^"]+)"/', $response, $match );
				$vid['thumbnail_loc'] = $this->make_image_local( $match[1], $vid );
			}

			return $vid;
		}

		return false;
	}

	/**
	 * Retrieve video details for Veoh Video's
	 *
	 * @since 0.1
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function veoh_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '|veoh\.com/videos/([^/]+)$|', $vid['url'], $matches ) )
				$vid['id'] = $matches[1];
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$vid['type'] = 'veoh';

			$vid['player_loc'] = 'http://www.veoh.com/veohplayer.swf?permalinkId=' . $vid['id'];

			if ( $thumb != '' ) {
				$vid['thumbnail_loc'] = $thumb;
			} else {
				$vid['thumbnail_loc'] = $this->make_image_local( 'http://ll-images.veoh.com/media/w300/thumb-' . $vid['id'] . '-1.jpg', $vid );
			}

			return $vid;
		}

		return false;
	}

	/**
	 * Retrieve video details for Screencast.com
	 *
	 * @since 1.5.4.4
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 *
	 * TODO: no api or connection from getting video details from the url so we extract the details from the embed code itself
	 */
	function screencast_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '|screencast\.com/(.*)$|', $vid['url']['url'], $matches ) )
				$vid['id'] = $matches[1];
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = isset( $vid['url']['embed'] ) ? $vid['url']['embed'] : '';
			// $response = $this->remote_get( 'http://screencast.com/t/eUvutAvEibx9' );

			if ( $response ) {
				$dom = new DOMDocument();
				@$dom->loadHTML( $response );
				$xpath = new DOMXPath( $dom );

				$item = $xpath->query( '//object/param[@name="flashVars"]' );
				$item = $item->item( 0 )->getAttribute( 'value' );
				parse_str( $item, $video );

				$vid['type']       = 'screencast';
				$vid['player_loc'] = $video['content'];
				$vid['width']      = $video['containerwidth'];
				$vid['height']     = $video['containerheight'];

				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else
					$vid['thumbnail_loc'] = $this->make_image_local( $video['thumb'], $vid );

				return $vid;
			}
		}

		return false;
	}

	/**
	 * Retrieve video details for Screenr
	 *
	 * @since ?
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function screenr_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			if ( preg_match( '|screenr\.com/([^/]+)$|', $vid['url'], $matches ) )
				$vid['id'] = $matches[1];
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = $this->remote_get( 'http://www.screenr.com/api/oembed.json?url=http://screenr.com/' . $vid['id'] );

			if ( $response ) {
				$video = json_decode( $response );

				$vid['type']       = 'screenr';
				$vid['player_loc'] = 'http://www.screenr.com/embed/' . $vid['id'];
				$vid['width']      = $video->width;
				$vid['height']     = $video->height;

				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else
					$vid['thumbnail_loc'] = $this->make_image_local( $video->thumbnail_url, $vid );

				return $vid;
			}

		}

		return false;
	}

	/**
	 * Retrieve video details for Muzu.tv
	 *
	 * @since ?
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function muzutv_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			// TODO: preg_match does not grab the id properly
			// example http://www.muzu.tv/sean-paul-beenie-man/greatest-gallis-music-video/1847016/
			// if ( preg_match( '|muzu\.tv/.*([^/]+)$|', $vid['url'], $matches ) )
			// $vid['id'] = $matches[1];
			$parse = parse_url( $vid['url'] );
			if ( preg_match( '|muzu\.tv$|', $parse['host'], $matches ) ) {
				$pieces    = array_filter( explode( '/', $parse['path'] ), 'strlen' );
				$vid['id'] = end( $pieces );
			}
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = $this->remote_get( 'http://www.muzu.tv/api/video/details/' . $vid['id'] . '?muzuid=b00q0xGOTl' );

			if ( $response ) {
				$xml   = new SimpleXMLElement( $response );
				$media = $xml->channel->item->children( 'http://search.yahoo.com/mrss/' );

				$vid['type']       = 'muzutv';
				$vid['player_loc'] = 'https://player.muzu.tv/player/getPlayer/i/293053/vidId=' . $vid['id'] . '&autostart=y&dswf=y';
				$vid['duration']   = (int) $media->content->attributes()->duration;
				$vid['width']      = (string) $media->content->attributes()->width;
				$vid['height']     = (string) $media->content->attributes()->height;

				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else {
					$thumbnail            = (string) $xml->channel->image->url;
					$vid['thumbnail_loc'] = $this->make_image_local( $thumbnail, $vid );
				}

				return $vid;
			}
		}

		return false;
	}

	/**
	 * Retrieve video details for cincopa
	 *
	 * @since ?
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function cincopa_details( $vid, $oldvid = array(), $thumb = '' ) {
		if ( !isset( $vid['id'] ) ) {
			$parse = parse_url( $vid['url'] );
			if ( preg_match( "~cp_load_widget\('(.*?)',.*?\);~", $parse['query'], $matches ) ) {
				$vid['id'] = $matches[1];
			}
		}

		if ( isset( $vid['id'] ) ) {
			if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
				return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$response = $this->remote_get( 'http://www.cincopa.com/media-platform/runtime/rss200.aspx?fid=' . $vid['id'] );

			if ( $response ) {
				$xml   = new SimpleXMLElement( $response );
				$media = $xml->channel->item->children( 'http://search.yahoo.com/mrss/' );

				$vid['type']       = 'cincopa';
				$vid['player_loc'] = (string) $media->content->attributes()->url;
				// $vid['duration']   = (int) $media->content->attributes()->duration;
				// $vid['width']      = (string) $media->content->attributes()->width;
				// $vid['height']     = (string) $media->content->attributes()->height;

				// TODO: thumbnails are not working cuurent b/c $this->make_image_local() strips query parameters
				// and this service needs query params to generate thumbnails look for a more direct approach
				if ( $thumb != '' )
					$vid['thumbnail_loc'] = $thumb;
				else {
					$thumbnail            = (string) $media->thumbnail->attributes()->url;
					$vid['thumbnail_loc'] = $this->make_image_local( $thumbnail, $vid );
				}

				return $vid;
			}
		}

		return false;
	}

	/**
	 * Retrieve video details for Easy Video Suite (EVS)
	 *
	 * @since ?
	 *
	 * @param array  $vid    The video array with all the data.
	 * @param array  $oldvid The video array with all the data of the previous "fetch", if available.
	 * @param string $thumb  The URL to the manually set thumbnail, if available.
	 * @return array|boolean $vid Returns a filled video array when successfull, false when unsuccessful.
	 */
	function evs_details( $vid, $oldvid = array(), $thumb = '' ) {
		$evs_location = get_option( 'evs_location' );

		if ( !$evs_location )
			return false;

		if ( !isset( $vid['id'] ) ) {
			$vid['id'] = $vid['url'];
		}

		if ( isset( $vid['id'] ) ) {
			// if ( isset( $oldvid['id'] ) && $vid['id'] == $oldvid['id'] )
			// return $this->use_old_video_data( $vid, $oldvid, $thumb );

			$api = $evs_location . '/api.php';

			// TODO: other evs metadata
			// player_loc, content_loc, duration, width, height
			$vid['content_loc'] = $vid['url'];
			$vid['player_loc']  = $vid['url'];

			// evs thumbnail info
			$video_thumb_info = wp_remote_post( $api, array(
				'method'      => 'POST',
				'timeout'     => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(),
				'cookies'     => array(),
				'body'        => array(
					'page_url' => $vid['id'],
					'method'   => 'public-file-images'
				)
			) );

			$video_thumb_info = json_decode( $video_thumb_info['body'] );

			if ( $thumb != '' )
				$vid['thumbnail_loc'] = $thumb;
			else {
				if ( is_object( $video_thumb_info ) && $video_thumb_info->success == true ) {
					$vid['thumbnail_loc'] = $this->make_image_local( $video_thumb_info->thumbnail, $vid );
				}
			}

			return $vid;
		}

		return false;
	}


	/**
	 * Parse the content of a post or term description.
	 *
	 * @since 1.3
	 *
	 * @param string $content The content to parse for videos.
	 * @param array  $vid     The video array to update.
	 * @param array  $oldvid  The former video array.
	 * @return array $vid
	 */
	function index_content( $content, $vid, $oldvid = array() ) {
		global $shortcode_tags;

		$content = apply_filters( 'wpseo_video_index_content', $content, $vid );

		if ( preg_match( '/(<video.*<\/video>)/s', $content, $html5vid ) ) {

			if ( preg_match( '/src=(\'|")(.*?)\.(mpg|mpeg|mp4|m4v|mov|wmv|asf|avi|ra|ram|rm|flv|swf)\1/', $html5vid[1], $content_loc ) ) {
				$vid['content_loc'] = $content_loc[2] . '.' . $content_loc[3];

				if ( preg_match( '/poster=(\'|")(.*?)\1/', $html5vid[1], $thumbnail_loc ) )
					$vid['thumbnail_loc'] = $thumbnail_loc[2];

				$vid['type'] = 'html5vid';
			}
		}

		if ( preg_match( '/<div id=(\'|")wistia_(.*?)\1 class=(\'|")wistia_embed\s?.*\3/', $content, $matches ) ) {
			$vid['id'] = $matches[2];
			$vid       = $this->wistia_details( $vid, $oldvid );
		}

		if ( preg_match( '/<a(.*?)href="https?:\/\/fast.wistia.(com|net)\/embed\/iframe\/(.*?)\?/', $content, $matches ) ) {
			$vid['id'] = $matches[3];
			$vid       = $this->wistia_details( $vid, $oldvid );
		}

		if ( isset( $vid['content_loc'] ) || isset( $vid['player_loc'] ) ) {
			$vid = apply_filters( 'wpseo_video_' . $vid['type'] . '_details', $vid );
			return $vid;
		}

		$shortcode_tags = array(
			'bliptv'                 => '',
			'blip.tv'                => '',
			'cincopa'                => '',
			'dailymotion'            => '',
			'embedplusvideo'         => '',
			'ez_video'               => '',
			'ez_youtube'             => '',
			'ez_vimeo'               => '',
			'flickrvideo'            => '',
			'flowplayer'             => '',
			'flv'                    => '',
			'fvplayer'               => '',
			'jwplayer'               => '',
			'metacafe'               => '',
			'pb_vidembed'            => '',
			'sublimevideo'           => '',
			'sublimevideo-lightbox'  => '',
			'tube'                   => '',
			'tubepress'              => '',
			'veoh'                   => '',
			'viddler'                => '',
			'video'                  => '',
			'videojs'                => '',
			'video_lightbox_vimeo5'  => '',
			'video_lightbox_youtube' => '',
			'vimeo'                  => '',
			'vippy'                  => '',
			'vzaarmedia'             => '',
			'weaver_vimeo'           => '',
			'weaver_youtube'         => '',
			'wpvideo'                => '',
			'wposflv'                => '',
			'youtube'                => '',
			'youtubewd'              => '',
			'youtube_sc'             => '',
			'youtube-embed'          => '',
			'youtube-white-label'    => '',
		);
		if ( preg_match( '/' . get_shortcode_regex() . '/', $content, $matches ) ) {
			$thumb = '';
			preg_match( '/image=(\'|")?(.*?)\1?/', $matches[3], $match );
			if ( isset( $match[2] ) && !empty( $match[2] ) )
				$thumb = $match[2];

			// support for Advanced Responsive Video Embedder plugin shortcode format
			// [provider id="id"]
			$embedder = shortcode_parse_atts( $matches[3] );

			switch ( $matches[2] ) {
				case 'bliptv':
					$vid['id'] = trim( $matches[3] );
					$vid       = $this->blip_details( $vid, $oldvid, $thumb );

					// TODO: embedder uses an embed id but to fetch the details
					// requires post_id or url. Need to find the link between them
					// [bliptv id="hdljgdbVBwI"]
					// http://blip.tv/rss/view/3516963
					// http://blip.tv/day9tv/day-9-daily-101-kawaii-rice-tvp-style-3516963
					// if( isset( $embedder['id'] ) && $embedder['id'] ) {
					// $vid['id'] = trim( $embedder['id'] );
					// $vid       = $this->blip_details( $vid, $oldvid, $thumb );
					// }

					break;
				case 'blip.tv':
					if ( preg_match( '|posts_id=(\d+)|', $matches[3], $match ) ) {
						$vid['id'] = $match[1];
						$vid       = $this->blip_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'cincopa':
					if ( $embedder ) {
						$vid['id'] = $embedder[0];
						$vid       = $this->cincopa_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'dailymotion':
					// TODO: SSL certificate problem, verify that the CA cert is OK. Details:
					// error:14090086:SSL routines:SSL3_GET_SERVER_CERTIFICATE:certificate verify failed

					if ( isset( $embedder['id'] ) && $embedder['id'] ) {
						$vid['id'] = $embedder['id'];
					} else if ( !empty( $matches[5] ) ) {
						$vid['url'] = $matches[5];
					} else if ( !empty( $matches[3] ) ) {
						$url_or_id = trim( $matches[3] );
						if ( strpos( $url_or_id, 'http' ) === 0 )
							$vid['url'] = $url_or_id;
						else
							$vid['id'] = $url_or_id;
					}
					$vid = $this->dailymotion_details( $vid, $oldvid, $thumb );
					break;
				case 'embedplusvideo':
					if ( preg_match( '/standard=(\'|")(.*?)\1/', $matches[3], $match ) ) {
						$vid['url'] = $match[2];
						$vid        = $this->youtube_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'ez_video':
					if ( $embedder['player'] == 'flowplayer' )
						$vid['type'] = 'flowplayer';
					else if ( $embedder['player'] == 'jwplayer' )
						$vid['type'] = 'jwplayer';

					$vid['url']           = $embedder['url'];
					$vid['player_loc']    = $embedder['url'];
					$vid['thumbnail_loc'] = isset( $embedder['splash'] ) ? $embedder['splash'] : '';
					$vid['width']         = $embedder['width'];
					$vid['height']        = $embedder['height'];
					break;
				case 'ez_youtube':
					$vid['url'] = $embedder['url'];
					$vid        = $this->youtube_details( $vid, $oldvid, $thumb );
					break;
				case 'ez_vimeo':
					$vid['url'] = $embedder['url'];
					$vid        = $this->vimeo_details( $vid, $oldvid, $thumb );
					break;
				case 'flickrvideo':
					$vid['url'] = $matches[5];
					$vid        = $this->flickr_details( $vid, $oldvid, $thumb );
					break;
				case 'flowplayer':
				case 'fvplayer':
					if ( preg_match( '/src=(\'|")?((.*?)\.(mpg|mpeg|mp4|m4v|mov|wmv|asf|avi|ra|ram|rm|flv|swf))\1?/', $matches[0], $match ) ) {
						$vid['content_loc'] = $match[2];

						if ( preg_match( '/splash=(\'|")?((.*?)\.(jpg|png|gif))\1?/', $matches[0], $match ) ) {
							$vid['thumbnail_loc'] = $match[2];
						}
						$vid['type'] = 'jwplayer';
					}
					break;
				case 'flv':
					$vid['content_loc']   = $matches[5];
					$vid['player_loc']    = plugins_url( '/vipers-video-quicktags/resources/jw-flv-player/player.swf?file=' . urlencode( $matches[5] ) );
					$vid['thumbnail_loc'] = $thumb;
					$vid['id']            = md5( $matches[5] );
					$vid['type']          = 'flv';
					break;
				case 'jwplayer':
					$vid['type'] = 'jwplayer';
					if ( preg_match( '/mediaid=(\'|")?(\d+)\1?/', $matches[0], $match ) ) {
						$vid['content_loc']   = WP_CONTENT_URL . '/uploads/' . get_attached_file( $match[2] );
						$vid['duration']      = get_post_meta( $match[2], 'jwplayermodule_duration', true );
						$vid['thumbnail_loc'] = get_post_meta( $match[2], 'jwplayermodule_thumbnail_url', true );
					} else {
						if ( preg_match( '/html5_file=(\'|")?(.*?)\1?/', $matches[0], $match ) ) {
							$vid['content_loc'] = $match[2];
						} else if ( preg_match( '/file=(\'|")?(.*?)\1?/', $matches[0], $match ) ) {
							$vid['content_loc'] = $match[2];
						}
						if ( isset( $vid['content_loc'] ) ) {
							preg_match( '/image=(\'|")?(.*?)\1?/', $matches[0], $match );
							$vid['thumbnail_loc'] = $match[2];
						}
					}
					break;
				case 'metacafe':
					if ( isset( $embedder['id'] ) && $embedder['id'] ) {
						$vid['id'] = $embedder['id'];
					} else if ( !empty( $matches[5] ) ) {
						$vid['url'] = $matches[5];
					} else if ( !empty( $matches[3] ) ) {
						$vid['id'] = trim( $matches[3] );
					}
					$vid = $this->metacafe_details( $vid, $oldvid, $thumb );
					break;
				case 'pb_vidembed':
					if ( preg_match( '/url=(\'|")(.*?)\1/', $matches[0], $match ) ) {
						$vid['url'] = $match[2];
						if ( strpos( $vid['url'], 'youtube' ) ) {
							$vid = $this->youtube_details( $vid, $oldvid, $thumb );
						} else if ( strpos( $vid['url'], 'vimeo' ) ) {
							$vid = $this->vimeo_details( $vid, $oldvid, $thumb );
						} else {
							$vid['content_loc'] = $vid['url'];
							$vid['type']        = 'pb_embed';
						}
					}
					break;
				case 'sublimevideo':
				case 'sublimevideo-lightbox':
					if ( preg_match( '/src1=(\'|")(\(hd\))?(.*?)\1/', $matches[0], $match ) ) {
						$vid['content_loc'] = $match[3];
						if ( preg_match( '/poster=(\'|")(.*?)\1/', $matches[0], $match ) )
							$vid['thumbnail_loc'] = $match[2];

						$vid['type'] = 'Sublime';
					}
					break;
				case 'tube':
					$vid['url'] = $matches[5];
					$vid        = $this->youtube_details( $vid, $oldvid, $thumb );
					break;
				case 'tubepress':
					if ( preg_match( '/.*video=(\'|")([0-9a-zA-Z\-_]+)\1.*/', $matches[0], $match ) ) {
						$vid['id'] = $match[2];
						$vid       = $this->youtube_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'veoh':
					if ( isset( $embedder['id'] ) && $embedder['id'] ) {
						$vid['id'] = $embedder['id'];
					} else if ( !empty( $matches[5] ) ) {
						$vid['url'] = $matches[5];
					} else if ( !empty( $matches[3] ) ) {
						$vid['id'] = trim( $matches[3] );
					}
					$vid = $this->veoh_details( $vid, $oldvid, $thumb );
					break;
				case 'viddler':
					if ( isset( $embedder['id'] ) && $embedder['id'] ) {
						$vid['id'] = $embedder['id'];
						$vid       = $this->viddler_details( $vid, $oldvid, $thumb );
					} else if ( preg_match( '/.*id=([^&]+).*/', $matches[0], $match ) ) {
						$vid['id'] = $match[1];
						$vid       = $this->viddler_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'video':
				case 'videojs':
					if ( preg_match( '/(mp4|name|ogg|webm|src)=(\'|")(.*?)\2/', $matches[3], $match ) ) {

						$src         = $match[3];
						$vid['type'] = 'mediaelement-js';

						// If the src has an extension, use it as content_loc, otherwise, see if we can find the file
						if ( substr( $src, 0, 1 ) == '/' ) {
							$info               = parse_url( get_site_url() );
							$vid['content_loc'] = $info['scheme'] . '://' . $info['host'] . $src;
						} else {
							$vid['content_loc'] = $src;
						}

						// If a poster image was specified, use that, otherwise, try and find a suitable .jpg
						if ( preg_match( '/poster=(\'|")(.*?)\1/', $matches[3], $match ) ) {
							if ( substr( $match[2], 0, 1 ) == '/' ) {
								$info                 = parse_url( get_site_url() );
								$vid['thumbnail_loc'] = $info['scheme'] . '://' . $info['host'] . $match[2];
							} else {
								$vid['thumbnail_loc'] = $match[2];
							}
						} else {
							$img_file = preg_replace( '/\.(mpg|mpeg|mp4|m4v|mov|wmv|asf|avi|ra|ram|rm|flv|swf)/', '.jpg', $vid['content_loc'] );
							if ( file_exists( $img_file ) )
								$vid['thumbnail_loc'] = $img_file;
						}

					}
					break;
				case 'video_lightbox_vimeo5':
					if ( preg_match( '/video_id=(\'|")?(\d+)\1?/', $matches[0], $match ) ) {
						$vid['id'] = $match[2];
						$vid       = $this->vimeo_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'video_lightbox_youtube':
					if ( preg_match( '/video_id=(\'|")?([0-9a-zA-Z\-_]+)\1?/', $matches[0], $match ) ) {
						$vid['id'] = $match[2];
						$vid       = $this->youtube_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'vimeo':
					if ( isset( $embedder['id'] ) && $embedder['id'] ) {
						$vid['id'] = $embedder['id'];
					} else if ( !empty( $matches[5] ) ) {
						$vid['url'] = $matches[5];
					} else if ( !empty( $matches[3] ) ) {
						$vid['id'] = trim( $matches[3] );
					} else if ( preg_match( '/id=(\'|")?([0-9]+)\1?/', $matches[0], $match ) ) {
						$vid['id'] = trim( $match[2] );
					}
					$vid = $this->vimeo_details( $vid, $oldvid, $thumb );
					break;
				case 'vippy':
					$atts      = shortcode_parse_atts( $matches[3] );
					$vid['id'] = isset( $atts['id'] ) ? $atts['id'] : 0;
					$vid       = $this->vippy_details( $vid, $oldvid, $thumb );
					break;
				case 'vzaarmedia':
					$atts      = shortcode_parse_atts( $matches[3] );
					$vid['id'] = isset( $atts['vid'] ) ? $atts['vid'] : 0;
					if ( $vid['id'] != 0 )
						$vid = $this->vzaar_details( $vid, $oldvid, $thumb );
					break;
				case 'wpvideo':
				case 'videopress':
					if ( preg_match( '/^([^\s]+)/', trim( $matches[3] ), $match ) ) {
						$vid['id'] = $match[1];
						$vid       = $this->videopress_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'weaver_vimeo':
					// [weaver_vimeo vimeo-url id=videoid sd=0 percent=100 ratio=0.5625 center=1 color=#hex autoplay=0 loop=0 portrait=1 title=1 byline=1]
					if ( preg_match( '/\[weaver_vimeo (https?:\/\/(www\.)?vimeo\.com\/\d+)/', $matches[0], $match ) ) {
						$vid['url'] = $match[1];
					} else if ( preg_match( '/id=(\d+)/', $matches[3], $match ) ) {
						$vid['id'] = $match[1];
					} else {
						break;
					}

					$vid = $this->vimeo_details( $vid, $oldvid, $thumb );
					break;
				case 'weaver_youtube':
					if ( preg_match( '/\[weaver_youtube (http[^\s]+)/', $matches[0], $match ) ) {
						$vid['url'] = $match[1];
					} else if ( preg_match( '/id=([^\s]+)/', $matches[3], $match ) ) {
						$vid['id'] = $match[1];
					} else {
						break;
					}
					$vid = $this->youtube_details( $vid, $oldvid, $thumb );
					break;
				case 'wposflv':
					if ( class_exists( 'WPOSFLV' ) ) {
						$vid['type'] = 'wposflv';

						// If the src has an extension, use it as content_loc, otherwise, see if we can find the file
						$src = $embedder['src'];
						if ( substr( $src, strlen( $src ) - 4, 1 ) == '.' ) {
							$vid['content_loc'] = $src;
						} else {
							if ( substr( $src, 0, 4 ) != 'http' )
								$filename = WP_CONTENT_DIR . substr( $src, strlen( WP_CONTENT_DIR ) - strrpos( WP_CONTENT_DIR, '/' ) );
							else
								$filename = WP_CONTENT_DIR . substr( $src, strlen( WP_CONTENT_URL ) );

							if ( file_exists( $filename . '.mp4' ) ) {
								$vid['content_loc'] = $src . '.mp4';
							} elseif ( file_exists( $filename . '.m4v' ) ) {
								$vid['content_loc'] = $src . '.m4v';
							}

						}

						if ( isset( $embedder['width'] ) && $embedder['width'] )
							$vid['width'] = $embedder['width'];

						if ( isset( $embedder['height'] ) && $embedder['height'] )
							$vid['height'] = $embedder['height'];


						if ( $thumb != '' )
							$vid['thumbnail_loc'] = $thumb;
						else {
							// If a thumbnail image was specified in the shortcode, use that, otherwise, try and find a suitable .jpg
							$thumbnail = $embedder['previewimage'];
							if ( $thumbnail ) {
								$vid['thumbnail_loc'] = $thumbnail;
							} else {
								$img_file = preg_replace( '/\.(mpg|mpeg|mp4|m4v|mov|wmv|asf|avi|ra|ram|rm|flv|swf)/', '.jpg', $vid['content_loc'] );
								if ( file_exists( $img_file ) )
									$vid['thumbnail_loc'] = $img_file;
							}
						}
					}
					break;
				case 'youtube':
				case 'youtubewd':
				case 'youtube-embed':
				case 'youtube-white-label':
					if ( isset( $embedder['id'] ) && $embedder['id'] ) {
						$vid['id'] = $embedder['id'];
						$vid       = $this->youtube_details( $vid, $oldvid, $thumb );
					} else if ( !empty( $matches[5] ) ) {
						if ( preg_match( '/^([0-9a-zA-Z\-_]+)$/', $matches[5] ) )
							$vid['id'] = $matches[5];
						else
							$vid['url'] = $matches[5];
						$vid = $this->youtube_details( $vid, $oldvid, $thumb );
					} else if ( preg_match( '/id=(\'|")?([0-9a-zA-Z\-_]+)\1?/', $matches[0], $match ) ) {
						$vid['id'] = trim( $match[2] );
						$vid       = $this->youtube_details( $vid, $oldvid, $thumb );
					} else if ( preg_match( '/v=([0-9a-zA-Z\-_]+)/', $matches[0], $match ) ) {
						$vid['id'] = $match[1];
						$vid       = $this->youtube_details( $vid, $oldvid, $thumb );
					}
					break;
				case 'youtube_sc':
					if ( preg_match( '/(url|v|video)=(\'|")(.*?)\2/', $matches[3], $match ) ) {
						$vid['url'] = $match[3];
						$vid        = $this->youtube_details( $vid, $oldvid, $thumb );
					}
					break;
				default:
					if ( WP_DEBUG ) {
						echo '<pre>' . print_r( $matches, 1 ) . '</pre>';
						echo '<pre>' . print_r( $vid, 1 ) . '</pre>';
					}
					$vid = false;
					break;
			}
			if ( isset( $vid['content_loc'] ) || isset( $vid['player_loc'] ) ) {
				$vid = apply_filters( 'wpseo_video_' . $vid['type'] . '_details', $vid );
				return $vid;
			}
		}

		if ( !isset( $vid['id'] ) && $oembed = $this->grab_embeddable_urls_xpath( $content ) ) {
			foreach ( $oembed as $type => $url ) {
				$vid['url'] = $url;
				switch ( $type ) {
					case 'animoto':
						$vid = $this->animoto_details( $vid, $oldvid );
						break;
					case 'brightcove':
						$vid = $this->brightcove_details( $vid, $oldvid );
						break;
					case 'cincopa':
						$vid = $this->cincopa_details( $vid, $oldvid );
						break;
					case 'screencast':
						$vid = $this->screencast_details( $vid, $oldvid );
						break;
					case 'vidyard':
						$vid = $this->vidyard_details( $vid, $oldvid );
						break;
				}

				if ( isset( $vid['content_loc'] ) || isset( $vid['player_loc'] ) ) {
					$vid = apply_filters( 'wpseo_video_' . $vid['type'] . '_details', $vid );
					return $vid;
				}
			}
		}

		if ( !isset( $vid['id'] ) && $oembed = $this->grab_embeddable_urls( $content ) ) {
			foreach ( $oembed as $type => $url ) {
				$vid['url'] = $url;
				switch ( $type ) {
					case 'animoto':
						$vid = $this->animoto_details( $vid, $oldvid );
						break;
					case 'blip':
						$vid = $this->blip_details( $vid, $oldvid );
						break;
					case 'brightcove':
						$vid = $this->brightcove_details( $vid, $oldvid );
						break;
					case 'dailymotion':
						$vid = $this->dailymotion_details( $vid, $oldvid );
						break;
					case 'evs':
						$vid = $this->evs_details( $vid, $oldvid );
						break;
					case 'flickr':
						$vid = $this->flickr_details( $vid, $oldvid );
						break;
					case 'muzutv':
						$vid = $this->muzutv_details( $vid, $oldvid );
						break;
					case 'screenr':
						$vid = $this->screenr_details( $vid, $oldvid );
						break;
					case 'viddler':
						$vid = $this->viddler_details( $vid, $oldvid );
						break;
					case 'vimeo':
						$vid = $this->vimeo_details( $vid, $oldvid );
						break;
					case 'vzaar':
						$vid = $this->vzaar_details( $vid, $oldvid );
						break;
					case 'wistia':
						$vid = $this->wistia_details( $vid, $oldvid );
						break;
					case 'wordpress.tv':
						$vid = $this->wordpresstv_details( $vid, $oldvid );
						break;
					case 'youtube':
						$vid = $this->youtube_details( $vid, $oldvid );
						break;
				}

				if ( isset( $vid['content_loc'] ) || isset( $vid['player_loc'] ) ) {
					$vid = apply_filters( 'wpseo_video_' . $vid['type'] . '_details', $vid );
					return $vid;
				}
			}
		}

		// support for wordpress-automatic-youtube-video-post plugin
		if ( is_plugin_active( 'automatic-youtube-video-posts/tern_wp_youtube.php' ) ) {
			$youtube_id = get_post_meta( $vid['post_id'], '_tern_wp_youtube_video', true );

			if ( $youtube_id ) {
				$vid['id'] = $youtube_id;
				$vid       = $this->youtube_details( $vid, $oldvid );
			}

			if ( isset( $vid['content_loc'] ) || isset( $vid['player_loc'] ) ) {
				$vid = apply_filters( 'wpseo_video_' . $vid['type'] . '_details', $vid );
				return $vid;
			}
		}

		return 'none';
	}

	/**
	 * Check and, if applicable, update video details for a term description
	 *
	 * @since 1.3
	 *
	 * @param object  $term The term to check the description and possibly update the video details for.
	 * @param boolean $echo Whether or not to echo the performed actions.
	 *
	 * @return mixed $vid The video array that was just stored, or "none" if nothing was stored.
	 */
	function update_video_term_meta( $term, $echo = false ) {
		$options = array_merge( get_wpseo_options(), get_option( 'wpseo_video' ) );

		if ( !isset( $options['videositemap_taxonomies'] ) )
			return false;

		if ( !in_array( $term->taxonomy, $options['videositemap_taxonomies'] ) )
			return false;

		$tax_meta = get_option( 'wpseo_taxonomy_meta' );
		$oldvid   = array();
		if ( !isset( $_POST['force'] ) ) {
			if ( isset( $tax_meta[$term->taxonomy] )
				&& isset( $tax_meta[$term->taxonomy]['_video'] )
				&& isset( $tax_meta[$term->taxonomy]['_video'][$term->term_id] )
			)
				$oldvid = $tax_meta[$term->taxonomy]['_video'][$term->term_id];
		}

		$vid = array();

		$title = wpseo_get_term_meta( $term->term_id, $term->taxonomy, 'wpseo_title' );
		if ( empty( $title ) && isset( $options['title-' . $term->taxonomy] ) )
			$title = wpseo_replace_vars( $options['title-' . $term->taxonomy], (array) $term );
		if ( empty( $title ) )
			$title = $term->name;
		$vid['title'] = htmlspecialchars( $title );

		$vid['description'] = wpseo_get_term_meta( $term->term_id, $term->taxonomy, 'wpseo_metadesc' );
		if ( !$vid['description'] ) {
			$vid['description'] = htmlspecialchars( substr( preg_replace( '/\s+/', ' ', strip_tags( $this->strip_shortcodes( get_term_field( 'description', $term->term_id, $term->taxonomy ) ) ) ), 0, 300 ) );
		}

		$vid['publication_date'] = date( "Y-m-d\TH:i:s+00:00" );

		// concatenate genesis intro text and term description to index the videos for both
		$genesis_term_meta = get_option( 'genesis-term-meta' );

		$content = '';
		if ( isset( $genesis_term_meta[$term->term_id]['intro_text'] ) && $genesis_term_meta[$term->term_id]['intro_text'] )
			$content .= $genesis_term_meta[$term->term_id]['intro_text'];

		$content .= "\n" . $term->description;
		$content = stripslashes( $content );

		$vid = $this->index_content( $content, $vid, $oldvid );

		if ( $vid != 'none' ) {
			$tax_meta[$term->taxonomy]['_video'][$term->term_id] = $vid;
			update_option( 'wpseo_taxonomy_meta', $tax_meta );

			if ( $echo ) {
				$link = get_term_link( $term );
				if ( !is_wp_error( $link ) ) {
					echo 'Updated <a href="' . $link . '">' . $vid['title'] . '</a> - ' . $vid['type'] . '<br/>';
				}
			}
		}

		return $vid;
	}

	/**
	 * Returns the custom fields to check for posts.
	 *
	 * @param int $post_id The ID of the post to grab the custom fields for.
	 *
	 * @since 1.3.4
	 *
	 * @return array $custom_fields Array of custom field values.
	 */
	function get_custom_fields( $post_id ) {
		$custom_fields = array(
			'videoembed', // Press75 Simple Video Embedder
			'_videoembed_manual', // Press75 Simple Video Embedder
			'_videoembed', // Press75 Simple Video Embedder
			'_premise_settings', // Premise
		);
		$options       = get_option( 'wpseo_video' );
		if ( isset( $options['custom_fields'] ) ) {
			$setting       = (array) explode( ',', str_replace( ' ', '', $options['custom_fields'] ) );
			$custom_fields = array_merge( $custom_fields, $setting );
		}

		$values = array();
		foreach ( $custom_fields as $cf ) {
			$meta_val = get_post_meta( $post_id, $cf, true );
			if ( is_array( $meta_val ) ) {
				foreach ( $meta_val as $val ) {
					$values[] = $val;
				}
			} else {
				$values[] = $meta_val;
			}
		}

		return $values;
	}

	/**
	 * Check and, if applicable, update video details for a post
	 *
	 * @since 0.1
	 *
	 * @param object  $post The post to check and possibly update the video details for.
	 * @param boolean $echo Whether or not to echo the performed actions.
	 *
	 * @return mixed $vid The video array that was just stored, or "none" if nothing was stored.
	 */
	function update_video_post_meta( $post, $echo = false ) {
		global $wp_query;

		if ( is_numeric( $post ) )
			$post = get_post( $post );

		if ( !isset( $post->ID ) )
			return false;

		$options = array_merge( get_wpseo_options(), get_option( 'wpseo_video' ) );

		if ( !isset( $options['videositemap_posttypes'] ) )
			return false;

		if ( !in_array( $post->post_type, $options['videositemap_posttypes'] ) )
			return false;

		$_GLOBALS['post'] = $post;

		$oldvid = array();
		if ( !isset( $_POST['force'] ) )
			$oldvid = wpseo_get_value( 'video_meta', $post->ID );

		$title = wpseo_get_value( 'title', $post->ID );
		if ( ( !$title || empty( $title ) ) && isset( $options['title-' . $post->post_type] ) )
			$title = wpseo_replace_vars( $options['title-' . $post->post_type], (array) $post );
		else if ( ( !$title || empty( $title ) ) && !isset( $options['title-' . $post->post_type] ) )
			$title = wpseo_replace_vars( "%%title%% - %%sitename%%", (array) $post );

		if ( empty( $title ) )
			$title = $post->post_title;

		$vid = array();

		if ( $post->post_type == 'post' ) {
			$wp_query->is_single = true;
			$wp_query->is_page   = false;
		} else {
			$wp_query->is_single = false;
			$wp_query->is_page   = true;
		}

		$vid['post_id'] = $post->ID;

		$vid['title']            = htmlspecialchars( $title );
		$vid['publication_date'] = mysql2date( "Y-m-d\TH:i:s+00:00", $post->post_date_gmt );

		$vid['description'] = wpseo_get_value( 'metadesc', $post->ID );
		if ( empty( $vid['description'] ) || !$vid['description'] ) {
			$vid['description'] = htmlspecialchars( substr( preg_replace( '/\s+/', ' ', strip_tags( $this->strip_shortcodes( $post->post_content ) ) ), 0, 300 ) );
		}

		$content = $post->post_content;
		$index   = true;
		if ( $custom_fields = $this->get_custom_fields( $post->ID ) ) {
			foreach ( $custom_fields as $cf_val ) {
				if ( is_array( $cf_val ) )
					$cf_val = $cf_val[0];

				// Silly, silly themes _encode_ the value of the post meta field. Yeah it's ridiculous. But this fixes it.
				if ( strpos( $cf_val, '&lt;' ) !== false )
					$cf_val = html_entity_decode( $cf_val );

				if ( preg_match( '/\.(mpg|mpeg|mp4|m4v|mov|wmv|asf|avi|ra|ram|rm|flv|swf)$/', $cf_val ) ) {
					$vid['content_loc'] = $cf_val;
					$vid['type']        = 'custom_field';

					$index = false;
				} else {
					$content .= "\n" . $cf_val . "\n";
				}
			}
		}

		if ( $index )
			$vid = $this->index_content( $content, $vid, $oldvid );

		if ( 'none' != $vid ) {
			if ( !isset( $vid['thumbnail_loc'] ) || empty( $vid['thumbnail_loc'] ) ) {
				$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'single-post-thumbnail' );
				if ( strpos( $img[0], 'http' ) !== 0 )
					$vid['thumbnail_loc'] = get_site_url( null, $img[0] );
				else
					$vid['thumbnail_loc'] = $img[0];
			}

			// Grab the meta data from the post
			if ( isset( $_POST['yoast_wpseo_videositemap-category'] ) && !empty( $_POST['yoast_wpseo_videositemap-category'] ) ) {
				$vid['category'] = $_POST['yoast_wpseo_videositemap-category'];
			} else {
				$cats = wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
				if ( isset( $cats[0] ) )
					$vid['category'] = $cats[0];
				unset( $cats );
			}

			$tags = wp_get_object_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );

			if ( isset( $_POST['yoast_wpseo_videositemap-tags'] ) && !empty( $_POST['yoast_wpseo_videositemap-tags'] ) ) {
				$extra_tags = explode( ',', $_POST['yoast_wpseo_videositemap-tags'] );
				$tags       = array_merge( $extra_tags, $tags );
			}

			$tag = array();
			if ( is_array( $tags ) ) {
				foreach ( $tags as $t ) {
					$tag[] = $t;
				}
			} else {
				if ( isset( $cats[0] ) )
					$tag[] = $cats[0]->name;
			}

			$focuskw = wpseo_get_value( 'focuskw', $post->ID );
			if ( !empty( $focuskw ) )
				$tag[] = $focuskw;
			$vid['tag'] = $tag;

			if ( $echo )
				echo 'Updated <a href="' . home_url( '?p=' . $post->ID ) . '">' . $post->post_title . '</a> - ' . $vid['type'] . '<br/>';
		}

		wpseo_set_value( 'video_meta', $vid, $post->ID );

//		echo '<pre>'.print_r( $_POST,1 ).'</pre>';
		return $vid;
	}

	/**
	 * Remove both used and unused shortcodes from content.
	 *
	 * @since 1.3.3
	 *
	 * @param string $content Content to remove shortcodes from.
	 * @return string
	 */
	function strip_shortcodes( $content ) {
		$content = preg_replace( '|\[(.+?)\](.+?\[/\\1\])?|s', '', $content );

		return $content;
	}

	/**
	 * Check whether the current visitor is really Google or Bing's bot by doing a reverse DNS lookup
	 *
	 * @since 1.2.2
	 *
	 * @return boolean
	 */
	function is_valid_bot() {
		if ( preg_match( "/(Google|bing)bot/", $_SERVER['HTTP_USER_AGENT'], $match ) ) {
			$hostname = gethostbyaddr( $_SERVER['REMOTE_ADDR'] );

			if (
				( $match[1] == 'Google' && preg_match( "/googlebot\.com$/", $hostname ) && gethostbyname( $hostname ) == $_SERVER['REMOTE_ADDR'] ) ||
				( $match[1] == 'bing' && preg_match( "/search\.msn\.com$/", $hostname ) && gethostbyname( $hostname ) == $_SERVER['REMOTE_ADDR'] )
			) {
				return true;
			}
		}

		return false;
	}

	function build_video_sitemap_xsl() {
		require plugin_dir_path( __FILE__ ) . 'xml-video-sitemap.php';
	}

	/**
	 * The main function of this class: it generates the XML sitemap's contents.
	 *
	 * @since 0.1
	 */
	function build_video_sitemap() {
		$options = get_option( 'wpseo_video' );

		// Restrict access to the video sitemap to admins and valid bots
		if ( isset( $options['cloak_sitemap'] ) && $options['cloak_sitemap'] && !current_user_can( 'manage_options' ) && !$this->is_valid_bot() ) {
			wp_die( "We're sorry, access to our video sitemap is restricted to site admins and valid Google & Bing bots." );
		}

		$output = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		$printed_post_ids = array();

		$steps  = 5;
		$n      = (int) get_query_var( 'sitemap_n' );
		$offset = ( $n > 1 ) ? ( $n - 1 ) * $this->max_entries : 0;
		$total  = $offset + $this->max_entries;

		if ( isset( $options['videositemap_posttypes'] ) ) {
			// Set the initial args array to get videos in chunks
			$args = array(
				'post_type'      => $options['videositemap_posttypes'],
				'post_status'    => 'publish',
				'posts_per_page' => $steps,
				'offset'         => $offset,
				'meta_key'       => '_yoast_wpseo_video_meta',
				'meta_compare'   => '!=',
				'meta_value'     => 'none',
				'order'          => 'ASC',
				'orderby'        => 'post_modified'
			);

			// TODO: add support to tax video to honor pages
			/*
				add a bool to the while loop to see if tax as been processed
				if $items is empty the posts are done so move on to tax

				do some math between $printed_post_ids and $this-max_entries to figure out how many from tax to add to this pagination
			*/

			// Add entries to the sitemap until the total is hit (rounded up by nearest $steps)
			while ( ( $total > $offset ) && ( $items = get_posts( $args ) ) ) {

				if ( !empty( $items ) ) {
					foreach ( $items as $item ) {
						if ( in_array( $item->ID, $printed_post_ids ) )
							continue;
						else
							$printed_post_ids[] = $item->ID;
						if ( false != wpseo_get_value( 'meta-robots', $item->ID ) && strpos( wpseo_get_value( 'meta-robots', $item->ID ), 'noindex' ) !== false )
							continue;

						$disable = wpseo_get_value( 'videositemap-disable', $item->ID );
						if ( $disable == 'on' )
							continue;

						$video = wpseo_get_value( 'video_meta', $item->ID );

						// Allow for the video's thumbnail to be overridden by the meta box input
						$videoimg = wpseo_get_value( 'videositemap-thumbnail', $item->ID );
						if ( $videoimg && trim( $videoimg ) != '' )
							$video['thumbnail_loc'] = $videoimg;

						// When we don't have a thumbnail and either a player_loc or a content_loc, skip this video.
						if (
							!isset( $video['thumbnail_loc'] )
							|| ( !isset( $video['player_loc'] ) && !isset( $video['content_loc'] ) )
						) {
							continue;
						}

						$video_duration = wpseo_get_value( 'videositemap-duration', $item->ID );
						if ( $video_duration && $video_duration > 0 ) {
							$video['duration'] = $video_duration;
						}

						$video['permalink'] = get_permalink( $item );

						$rating = apply_filters( 'wpseo_video_rating', wpseo_get_value( 'videositemap-rating', $item->ID ) );
						if ( $rating && is_numeric( $rating ) && $rating >= 0 && $rating <= 5 )
							$video['rating'] = number_format( $rating, 1 );

						$not_family_friendly = apply_filters( 'wpseo_video_family_friendly', wpseo_get_value( 'videositemap-not-family-friendly', $item->ID ), $item->ID );
						if ( $not_family_friendly && $not_family_friendly == 'on' )
							$video['family_friendly'] = "no";
						else
							$video['family_friendly'] = "yes";

						$video['author'] = $item->post_author;

						$output .= $this->print_sitemap_line( $video );
					}
				}

				// Update these args for the next iteration
				$offset = $offset + $steps;
				$args['offset'] += $steps;
			}
		}

		$tax_meta = get_option( 'wpseo_taxonomy_meta' );
		$terms    = array();
		if ( isset( $options['videositemap_taxonomies'] ) )
			$terms = get_terms( $options['videositemap_taxonomies'] );

		foreach ( $terms as $term ) {
			if ( !is_object( $term ) || !isset( $tax_meta ) || !isset( $tax_meta[$term->taxonomy] ) || !isset( $tax_meta[$term->taxonomy]['_video'] ) || !isset( $tax_meta[$term->taxonomy]['_video'][$term->term_id] ) )
				continue;
			$video = $tax_meta[$term->taxonomy]['_video'][$term->term_id];
			if ( is_array( $video ) ) {
				$video['permalink'] = get_term_link( $term, $term->taxonomy );
				$video['category']  = $term->name;
				$output .= $this->print_sitemap_line( $video );
			}
		}

		$output .= '</urlset>';
		$GLOBALS['wpseo_sitemaps']->set_sitemap( $output );
		$GLOBALS['wpseo_sitemaps']->set_stylesheet(
			"\n" . '<?xml-stylesheet type="text/xsl" href="' . home_url( 'video-sitemap.xsl' ) . '"?>'
		);
	}

	/**
	 * Print a full <url> line in the sitemap.
	 *
	 * @since 1.3
	 *
	 * @param $video array The video object to print out
	 * @return string The output generated
	 */
	function print_sitemap_line( $video ) {
		if ( !is_array( $video ) )
			return '';

		$output = "\t<url>\n";
		$output .= "\t\t<loc>" . htmlspecialchars( $video['permalink'] ) . '</loc>' . "\n";
		$output .= "\t\t<video:video>\n";

		foreach ( $video as $key => $val ) {
			if ( in_array( $key, array( 'id', 'url', 'type', 'permalink', 'post_id', 'hd' ) ) )
				continue;

			if ( empty( $video['publication_date'] ) ) {
				$post                      = get_post( $video['post_id'] );
				if ( $post->post_date_gmt != '0000-00-00 00:00:00' )
					$video['publication_date'] = mysql2date( "Y-m-d\TH:i:s+00:00", $post->post_date_gmt );
				else if ( $post->post_date != '0000-00-00 00:00:00' )
					$video['publication_date'] = date( "Y-m-d\TH:i:s+00:00", get_gmt_from_date( $post->post_date ) );
				else
					return '<!-- Post with ID '.$video['post_id']. 'skipped, because there\'s no valid date in the DB for it. -->'; // If we have no valid date for the post, skip the video and don't print it in the XML Video Sitemap.
			}

			if ( $key == 'author' ) {
				$output .= "\t\t\t<video:uploader info='" . get_author_posts_url( $val ) . "'><![CDATA[" . get_the_author_meta( 'display_name', $val ) . "]]></video:uploader>\n";
				continue;
			}

			$xtra = '';
			if ( $key == 'player_loc' )
				$xtra = ' allow_embed="yes"';

			if ( $key == 'description' && empty( $val ) )
				$val = $video['title'];

			if ( !is_array( $val ) ) {
				$val = $this->clean_string( $val );
				if ( in_array( $key, array( 'description', 'category', 'tag', 'title' ) ) )
					$val = '<![CDATA[' . $val . ']]>';
				$output .= "\t\t\t<video:" . $key . $xtra . ">" . $val . "</video:" . $key . ">\n";
			} else {
				$i = 1;
				foreach ( $val as $v ) {
					// Only 32 tags are allowed
					if ( $key == 'tag' && $i == 33 )
						break;
					$v = $this->clean_string( $v );
					if ( in_array( $key, array( 'description', 'category', 'tag', 'title' ) ) )
						$v = '<![CDATA[' . $v . ']]>';
					$output .= "\t\t\t<video:" . $key . $xtra . ">" . $v . "</video:" . $key . ">\n";
					$i++;
				}
			}
		}

		// Allow custom implementations with extra tags here
		$output .= apply_filters( 'wpseo_video_item', '', isset( $video['post_id'] ) ? $video['post_id'] : 0 );

		$output .= "\t\t</video:video>\n";

		$output .= "\t</url>\n";

		return $output;
	}

	/**
	 * Cleans a string for XML display purposes.
	 *
	 * @since 1.2.1
	 *
	 * @link  http://php.net/manual/en/function.html-entity-decode.php#98697 Modified for WP from here.
	 *
	 * @param string $in     The string to clean.
	 * @param int    $offset Offset of the string to start the cleaning at.
	 *
	 * @return string Cleaned string.
	 */
	function clean_string( $in, $offset = null ) {
		$out = trim( $in );
		$out = $this->strip_shortcodes( $out );
		$out = html_entity_decode( $out, ENT_QUOTES, "ISO-8859-15" );
		$out = html_entity_decode( $out, ENT_QUOTES, get_bloginfo( 'charset' ) );
		if ( !empty( $out ) ) {
			$entity_start = strpos( $out, '&', $offset );
			if ( $entity_start === false ) {
				// ideal
				return _wp_specialchars( $out );
			} else {
				$entity_end = strpos( $out, ';', $entity_start );
				if ( $entity_end === false ) {
					return _wp_specialchars( $out );
				} // zu lang um eine entity zu sein
				else if ( $entity_end > $entity_start + 7 ) {
					// und weiter gehts
					$out = $this->clean_string( $out, $entity_start + 1 );
				} // gottcha!
				else {
					$clean = substr( $out, 0, $entity_start );
					$subst = substr( $out, $entity_start + 1, 1 );
					// &scaron; => "s" / &#353; => "_"
					$clean .= ( $subst != "#" ) ? $subst : "_";
					$clean .= substr( $out, $entity_end + 1 );
					// und weiter gehts
					$out = $this->clean_string( $clean, $entity_start + 1 );
				}
			}
		}
		return _wp_specialchars( $out );
	}

	/**
	 * Adds the header for the Video tab in the WordPress SEO meta box on edit post pages.
	 *
	 * @since 0.1
	 */
	function tab_header() {
		global $post;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return;

		$options = get_option( 'wpseo_video' );

		if ( isset( $options['videositemap_posttypes'] ) ) {
			foreach ( $options['videositemap_posttypes'] as $post_type ) {
				if ( $post->post_type == $post_type )
					echo '<li class="video"><a <a class="wpseo_tablink" href="#wpseo_video">' . __( 'Video', 'yoast-video-seo' ) . '</a></li>';
			}
		}
	}

	/**
	 * Outputs the content for the Video tab in the WordPress SEO meta box on edit post pages.
	 *
	 * @since 0.1
	 */
	function tab_content() {
		global $post;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return;
		$options = get_option( 'wpseo_video' );

		if ( !isset( $options['videositemap_posttypes'] ) || !in_array( $post->post_type, $options['videositemap_posttypes'] ) )
			return;

		$content = '';

		foreach ( $this->get_meta_boxes() as $meta_box ) {
			$content .= $this->do_meta_box( $meta_box );
		}
		$this->do_tab( 'video', __( 'Video', 'yoast-video-seo' ), $content );
	}

	/**
	 * Output a tab in the WP SEO Metabox
	 *
	 * @since 0.2
	 *
	 * @param string $id      CSS ID of the tab.
	 * @param string $heading Heading for the tab.
	 * @param string $content Content of the tab.
	 */
	function do_tab( $id, $heading, $content ) {
		?>
		<div class="wpseotab <?php echo $id ?>">
			<h4 class="wpseo-heading"><?php echo $heading ?></h4>
			<table class="form-table">
				<?php echo $content ?>
			</table>
		</div>
	<?php
	}

	/**
	 * Adds a line in the meta box
	 *
	 * @since 0.2
	 *
	 * @param array $meta_box Contains the vars based on which output is generated.
	 * @return string
	 */
	function do_meta_box( $meta_box ) {
		$content = '';

		if ( !isset( $meta_box['name'] ) ) {
			$meta_box['name'] = '';
		} else {
			$meta_box_value = wpseo_get_value( $meta_box['name'] );
		}

		$class = '';
		if ( !empty( $meta_box['class'] ) )
			$class = ' ' . $meta_box['class'];

		$placeholder = '';
		if ( isset( $meta_box['placeholder'] ) && !empty( $meta_box['placeholder'] ) )
			$placeholder = $meta_box['placeholder'];

		if ( ( !isset( $meta_box_value ) || empty( $meta_box_value ) ) && isset( $meta_box['std'] ) )
			$meta_box_value = $meta_box['std'];

		$content .= '<tr>';
		$content .= '<th scope="row"><label for="yoast_wpseo_' . $meta_box['name'] . '">' . $meta_box['title'] . ':</label></th>';
		$content .= '<td>';

		switch ( $meta_box['type'] ) {
			case "text":
				$ac = '';
				if ( isset( $meta_box['autocomplete'] ) && $meta_box['autocomplete'] == 'off' )
					$ac = 'autocomplete="off" ';
				$content .= '<input type="text" placeholder="' . $placeholder . '" id="yoast_wpseo_' . $meta_box['name'] . '" ' . $ac . 'name="yoast_wpseo_' . $meta_box['name'] . '" value="' . esc_attr( $meta_box_value ) . '" class="large-text"/><br/>';
				break;
			case "number":
				$content .= '<input type="number" id="yoast_wpseo_' . $meta_box['name'] . '" name="yoast_wpseo_' . $meta_box['name'] . '" value="' . esc_attr( $meta_box_value ) . '" class="small-text"/><br/>';
				break;
			case "textarea":
				$content .= '<textarea class="large-text" rows="3" id="yoast_wpseo_' . $meta_box['name'] . '" name="yoast_wpseo_' . $meta_box['name'] . '">' . esc_html( $meta_box_value ) . '</textarea>';
				break;
			case "select":
				$content .= '<select name="yoast_wpseo_' . $meta_box['name'] . '" id="yoast_wpseo_' . $meta_box['name'] . '" class="yoast' . $class . '">';
				foreach ( $meta_box['options'] as $val => $option ) {
					$selected = '';
					if ( $meta_box_value == $val )
						$selected = 'selected="selected"';
					$content .= '<option ' . $selected . ' value="' . esc_attr( $val ) . '">' . $option . '</option>';
				}
				$content .= '</select>';
				break;
			case "multiselect":
				$selectedarr         = explode( ',', $meta_box_value );
				$meta_box['options'] = array( 'none' => 'None' ) + $meta_box['options'];
				$content .= '<select multiple="multiple" size="' . count( $meta_box['options'] ) . '" style="height: ' . ( count( $meta_box['options'] ) * 16 ) . 'px;" name="yoast_wpseo_' . $meta_box['name'] . '[]" id="yoast_wpseo_' . $meta_box['name'] . '" class="yoast' . $class . '">';
				foreach ( $meta_box['options'] as $val => $option ) {
					$selected = '';
					if ( in_array( $val, $selectedarr ) )
						$selected = 'selected="selected"';
					$content .= '<option ' . $selected . ' value="' . esc_attr( $val ) . '">' . $option . '</option>';
				}
				$content .= '</select>';
				break;
			case "checkbox":
				$checked = '';
				if ( $meta_box_value != 'off' )
					$checked = 'checked="checked"';
				$expl = ( isset( $meta_box['expl'] ) ) ? esc_html( $meta_box['expl'] ) : '';
				$content .= '<input type="checkbox" id="yoast_wpseo_' . $meta_box['name'] . '" name="yoast_wpseo_' . $meta_box['name'] . '" ' . $checked . ' class="yoast' . $class . '"/> ' . $expl . '<br />';
				break;
			case "radio":
				if ( $meta_box_value == '' )
					$meta_box_value = $meta_box['std'];
				foreach ( $meta_box['options'] as $val => $option ) {
					$selected = '';
					if ( $meta_box_value == $val )
						$selected = 'checked="checked"';
					$content .= '<input type="radio" ' . $selected . ' id="yoast_wpseo_' . $meta_box['name'] . '_' . $val . '" name="yoast_wpseo_' . $meta_box['name'] . '" value="' . esc_attr( $val ) . '"/> <label for="yoast_wpseo_' . $meta_box['name'] . '_' . $val . '">' . $option . '</label> ';
				}
				break;
			case "divtext":
				$content .= '<p>' . $meta_box['description'] . '</p>';
		}

		if ( isset( $meta_box['description'] ) && !empty( $meta_box['description'] ) )
			$content .= '<p>' . $meta_box['description'] . '</p>';

		$content .= '</td>';
		$content .= '</tr>';

		return $content;
	}

	/**
	 * Defines the meta box inputs
	 *
	 * @since 0.1
	 *
	 * @return array $mbs meta box inputs
	 */
	function get_meta_boxes() {
		global $post;

		if ( !isset( $post->ID ) )
			return array();

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return array();

		$mbs = array(
			array(
				"name"  => "videositemap-disable",
				"type"  => "checkbox",
				"std"   => 'off',
				"title" => __( "Disable video", "yoast-video-seo" ),
				"expl"  => sprintf( __( "Disable video for this %s", "yoast-video-seo" ), $post->post_type ),
			),
			array(
				"name"        => "videositemap-thumbnail",
				"type"        => "text",
				"std"         => "",
				"placeholder" => __( "URL to thumbnail image (remember it'll be displayed as 16:9)", "yoast-video-seo" ),
				"title"       => __( "Video Thumbnail", "yoast-video-seo" ),
				"description" => ( false != wpseo_get_value( 'videositemap-thumbnail', $post->ID ) ) ? '' : sprintf( __( 'Now set to %1$sthis image%2$s based on the embed code.', 'yoast-video-seo' ), '<a target="_blank" href="' . $video['thumbnail_loc'] . '">', '</a>' ),
			),
			array(
				"name"        => "videositemap-duration",
				"type"        => "number",
				"std"         => isset( $video['duration'] ) ? $video['duration'] : 0,
				"title"       => __( "Video Duration", "yoast-video-seo" ),
				"description" => __( 'Overwrite the video duration, or enter one if it\'s empty.', 'yoast-video-seo' ),
			),
			array(
				"name"        => "videositemap-tags",
				"type"        => "text",
				"std"         => "",
				"title"       => __( "Tags", "yoast-video-seo" ),
				"description" => __( 'Add extra tags for this video', 'yoast-video-seo' ),
			),
			array(
				"name"        => "videositemap-category",
				"type"        => "text",
				"std"         => "",
				"title"       => __( "Category", "yoast-video-seo" ),
				"description" => __( 'Override video category for this video', 'yoast-video-seo' ),
			),
			array(
				"name"        => "videositemap-rating",
				"type"        => "number",
				"std"         => "",
				"title"       => __( "Rating", "yoast-video-seo" ),
				"description" => __( "Set a rating between 0 and 5.", "yoast-video-seo" ),
			),
			array(
				"name"  => "videositemap-not-family-friendly",
				"type"  => "checkbox",
				"std"   => "off",
				"title" => __( "Not Family-friendly", "yoast-video-seo" ),
				"expl"  => __( "If this video should only be available for safe search users, check this box.", "yoast-video-seo" ),
			),
		);
		return $mbs;
	}

	/**
	 * Save the values from the meta box inputs
	 *
	 * @since 0.1
	 *
	 * @param array $mbs meta boxes to merge the inputs with.
	 * @return array $mbs meta box inputs
	 */
	function save_meta_boxes( $mbs ) {
		$mbs = array_merge( $mbs, $this->get_meta_boxes() );
		return $mbs;
	}

	/**
	 * Replace the default snippet with a video snippet by hooking this function into the wpseo_snippet filter.
	 *
	 * @since 0.1
	 *
	 * @param string $content The original snippet content.
	 * @param object $post    The post object of the post for which the snippet was generated.
	 * @param array  $vars    An array of variables for use within the snippet, containing title, description, date and slug
	 * @return string $content The new video snippet if video metadata was found for the post.
	 */
	function snippet_preview( $content, $post, $vars ) {
		$options = get_option( 'wpseo_video' );

		if ( !isset( $options['videositemap_posttypes'] ) || !in_array( $post->post_type, $options['videositemap_posttypes'] ) )
			return $content;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
		if ( $disable == 'on' )
			return $content;

		if ( !$video || $video == 'none' )
			return $content;

		extract( $vars );

		$videoimg = wpseo_get_value( 'videositemap-thumbnail', $post->ID );
		if ( !$videoimg && isset( $video['thumbnail_loc'] ) )
			$videoimg = $video['thumbnail_loc'];

		if ( is_ssl() )
			$videoimg = str_replace( 'http://', 'https://', $videoimg );

		$video_duration = wpseo_get_value( 'videositemap-duration', $post->ID );
		if ( !$video_duration && isset( $video['duration'] ) ) {
			$video_duration = $video['duration'];
		}

		$duration = $duration_snip = '';
		if ( $video_duration ) {
			$mins = floor( $video_duration / 60 );
			$secs = $video_duration - ( $mins * 60 );
			if ( $secs == 0 )
				$secs = '00';
			else if ( $secs < 10 )
				$secs = '0' . $secs;
			$duration = $mins . ':' . $secs;

			if ( $video_duration > 60 )
				$duration_snip = number_format( $video_duration / 60 ) . ' min';
			else
				$duration_snip = $video_duration . ' sec';
		}
		$url = trailingslashit( home_url() ) . $vars['slug'];
		$url = str_replace( 'http://', '', $url );

		$content = '<div id="wpseosnippet">
			<table class="video" cellpadding="0" cellspacing="0">
				<tr>
					<td colspan="2">
						<h4 style="margin:0;font-weight:normal;"><a class="title" target="_blank" href="' . get_permalink( $post->ID ) . '">' . $vars['title'] . '</a></h4>
					</td>
				</tr>
				<tr>
					<td style="padding-right:8px;padding-top:4px; vertical-align:top;" width="1%">
						<div style="position:relative;width:120px;height:65px;overflow:hidden">
							<a href="#" style="text-decoration:none">
								<div style="position:relative;top:-12px">
									<img align="middle" style="display:inline-block;height:90px;margin:0px 0px 0px 0px;width:120px" width="120" height="90" src="' . $videoimg . '"/>
								</div>
								<span style="position:absolute;bottom:0;right:0;text-align:right;font-size:11px;color:#000;background-color:#000;padding:1px 3px;text-decoration:none;font-weight:bold;filter:alpha(opacity=70);-moz-opacity:0.7;-khtml-opacity:0.7;opacity:0.7">&#x25B6;&nbsp;' . $duration . '</span>
								<span style="position:absolute;bottom:0;right:0;text-align:right;font-size:11px;color:#fff;padding:1px 3px;text-decoration:none;font-weight:bold">&#x25B6;&nbsp;' . $duration . '</span>
							</a>
						</div>
					</td>
					<td style="padding-top: 1px; vertical-align: text-top;">
						<div>
							<cite class="url">' . $url . '</cite>
							<p style="color:#666;font-size:13px;line-height:16px;">' . date( 'j M Y', strtotime( $post->post_date ) ) . ' - ' . $duration_snip . '</p>
							<p style="color:#222;font-size:13px;line-height:16px;" class="desc"><span class="content">' . $vars['desc'] . '</span></p>
						</div>
					</td>
				</tr>
			</table>
			<div style="margin-top:7px">';

		return $content;
	}

	/**
	 * Roughly calculate the length of an FLV video.
	 *
	 * @since 1.3.1
	 *
	 * @param string $file The path to the video file to calculate the length for
	 *
	 * @return integer Duration of the video
	 */
	function get_flv_duration( $file ) {
		if ( $flv = fopen( $file, 'rb' ) ) {
			fseek( $flv, -4, SEEK_END );
			$arr             = unpack( 'N', fread( $flv, 4 ) );
			$last_tag_offset = $arr[1];
			fseek( $flv, -( $last_tag_offset + 4 ), SEEK_END );
			fseek( $flv, 4, SEEK_CUR );
			$t0                    = fread( $flv, 3 );
			$t1                    = fread( $flv, 1 );
			$arr                   = unpack( 'N', $t1 . $t0 );
			$milliseconds_duration = $arr[1];
			return $milliseconds_duration;
		} else {
			return 0;
		}
	}

	/**
	 * Restricts the length of the meta description in the snippet preview and throws appropriate warnings.
	 *
	 * @since 0.1
	 *
	 * @param int $length The snippet length as defined by default.
	 * @return int $length The max snippet length for a video snippet.
	 */
	function meta_length( $length ) {
		global $post;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' ) {
			return $length;
		} else {
			$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
			if ( $disable == 'on' )
				return $length;
			return 115;
		}
	}

	/**
	 * Explains the length restriction of the meta description
	 *
	 * @since 0.1
	 *
	 * @param string $reason Input string.
	 * @return string $reason  The reason why the meta description is limited.
	 */
	function meta_length_reason( $reason ) {
		global $post;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return $reason;
		else
			return __( ' (because it\'s a video snippet)', 'yoast-video-seo' );
	}

	/**
	 * Outputs the admin panel for the Video Sitemaps on the XML Sitemaps page with the WP SEO admin
	 *
	 * @since 0.1
	 */
	function admin_panel() {
		$options = get_option( 'wpseo_video' );
		$xmlopt  = get_option( 'wpseo_xml' );

		if ( isset( $_GET['debug'] ) )
			echo '<pre>' . print_r( $options, 1 ) . '</pre>';

		if ( isset( $_GET['deactivate'] ) && 'true' == $_GET['deactivate'] ) {

			if ( wp_verify_nonce( $_GET['nonce'], 'yoast_video_seo_deactivate_license' ) === false )
				return;

			// data to send in our API request
			$api_params = array(
				'edd_action' => 'deactivate_license',
				'license'    => $options['yoast-video-seo-license'],
				'item_name'  => urlencode( 'Video SEO for WordPress' )
			);

			// Send the remote request
			$url = add_query_arg( $api_params, 'http://yoast.com/' );

			$response = wp_remote_get( $url, array( 'timeout' => 25, 'sslverify' => false ) );

			if ( !is_wp_error( $response ) ) {
				$response = json_decode( $response['body'] );

				if ( 'deactivated' == $response->license || 'failed' == $response->license ) {
					unset( $options['yoast-video-seo-license'] );
					$options['yoast-video-seo-license-status'] = 'invalid';
					update_option( 'wpseo_video', $options );
				}
			}

			echo '<script type="text/javascript">document.location = "' . admin_url( 'admin.php?page=wpseo_video' ) . '"</script>';
		}
		?>
		<div class="wrap">

		<a href="http://yoast.com/wordpress/video-seo/">
			<div id="yoast-icon"
				 style="background: url('<?php echo WPSEO_URL; ?>images/wordpress-SEO-32x32.png') no-repeat;"
				 class="icon32">
				<br/>
			</div>
		</a>

		<h2 id="wpseo-title"><?php _e( "Yoast WordPress SEO: ", 'yoast-video-seo' );
			echo __( 'Video SEO Settings', 'yoast-video-seo' ); ?></h2>

		<form action="<?php echo admin_url( 'options.php' ); ?>" method="post" id="wpseo-conf">

			<?php

			settings_fields( 'yoast_wpseo_video_options' );

			$license_active = $this->is_license_valid();

			if ( !( defined( 'WPSEO_VIDEO_LICENSE' ) && WPSEO_VIDEO_LICENSE ) ) {
				echo '<h2>' . __( 'License', 'yoast-video-seo' ) . '</h2>';
				echo '<label class="textinput" for="license">' . __( 'License Key', 'yoast-video-seo' ) . ':</label> '
					. '<input id="license" class="textinput" type="text" name="wpseo_video[yoast-video-seo-license]" value="'
					. ( isset( $options['yoast-video-seo-license'] ) ? $options['yoast-video-seo-license'] : '' ) . '"/><br/>';
				echo '<p class="clear description">' . __( 'License Status', 'yoast-video-seo' ) . ': ' . ( ( $license_active ) ? '<span style="color:#090; font-weight:bold">' . __( 'active', 'yoast-video-seo' ) . '</span>' : '<span style="color:#f00; font-weight:bold">' . __( 'inactive', 'yoast-video-seo' ) . '</span>' ) . '</p>';
				echo '<input type="hidden" name="wpseo_video[yoast-video-seo-license-status]" value="' . ( ( $license_active ) ? 'valid' : 'invalid' ) . '"/>';
			}

			if ( $license_active ) {
				if ( !( defined( 'WPSEO_VIDEO_LICENSE' ) && WPSEO_VIDEO_LICENSE ) ) {
					echo '<p><a href="' . admin_url( 'admin.php?page=wpseo_video&deactivate=true&nonce=' . wp_create_nonce( 'yoast_video_seo_deactivate_license' ) ) . '" class="button">' . __( 'Deactivate License', 'yoast-video-seo' ) . '</a></p>';
					echo '<p class="clear description">' . __( 'If you want to stop this site from counting towards your license limit, should you have one, simply press deactivate license above.', 'yoast-video-seo' ) . '</p>';
				}

				if ( !isset( $xmlopt['enablexmlsitemap'] ) ) {
					echo '<p>' . __( 'Please enable the XML sitemap under the SEO -> XML Sitemaps settings', 'yoast-video-seo' ) . '</p>';
				} else {
					if ( !isset( $options['cloak_sitemap'] ) )
						$options['cloak_sitemap'] = false;

					if ( !isset( $options['custom_fields'] ) )
						$options['custom_fields'] = '';

					if ( !isset( $options['disable_rss'] ) )
						$options['disable_rss'] = false;

					// Use fields => ids to limit the overhead of fetching entire post objects,
					// fetch only an array of ids instead to count
					$args          = array(
						'post_type'      => $options['videositemap_posttypes'],
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						// 'offset'         => 0,
						'meta_key'       => '_yoast_wpseo_video_meta',
						'meta_compare'   => '!=',
						'meta_value'     => 'none',
						'fields'         => 'ids'
					);
					$video_ids     = get_posts( $args );
					$count         = count( $video_ids );
					$n             = ( $count > $this->max_entries ) ? (int) ceil( $count / $this->max_entries ) : '';
					$video_lastest = str_replace( 'sitemap.xml', "sitemap$n.xml", $this->sitemap_url() );

					echo '<h2>' . __( 'General Settings', 'yoast-video-seo' ) . '</h2>';
					echo '<p>' . __( 'Please find your video sitemap here:', 'yoast-video-seo' ) . ' <a class="button" target="_blank" href="' . $video_lastest . '">' . __( 'Video Sitemap', 'yoast-video-seo' ) . '</a></p>';

					echo '<p><input class="checkbox double" id="cloak_sitemap" type="checkbox" name="wpseo_video[cloak_sitemap]" ' . checked( $options['cloak_sitemap'], 'on', false ) . '> ';
					echo '<label for="cloak_sitemap">' . __( 'Hide the sitemap from normal visitors?', 'yoast-video-seo' ) . '</label></p>';

					echo '<br class="clear"/>';

					echo '<p><input class="checkbox double" id="disable_rss" type="checkbox" name="wpseo_video[disable_rss]" ' . checked( $options['disable_rss'], 'on', false ) . '> ';
					echo '<label for="disable_rss">' . __( 'Disable Media RSS Enhancement', 'yoast-video-seo' ) . '</label></p>';

					echo '<br class="clear"/>';
					echo '<p><label class="textinput" for="wpseo_video_custom_fields">' . __( 'Custom fields:', 'yoast-video-seo' ) . '</label>';
					echo '<input type="text" class="textinput" size="100" id="wpseo_video_custom_fields" name="wpseo_video[custom_fields]" value="' . $options['custom_fields'] . '"></p>';
					echo '<p class="clear description">' . __( 'Custom fields the plugin should check for video content (comma separated)', 'yoast-video-seo' ) . '</p>';

					if ( !isset( $options['content_width'] ) )
						$options['content_width'] = '';

					echo '<br class="clear"/>';
					echo '<h2>' . __( 'Embed Settings', 'yoast-video-seo' ) . '</h2>';

					if ( !isset( $options['fitvids'] ) )
						$options['fitvids'] = false;

					echo '<p><input class="checkbox double" id="fitvids" type="checkbox" name="wpseo_video[fitvids]" ' . checked( $options['fitvids'], 'on', false ) . '> ';
					echo '<label for="fitvids">' . sprintf( __( 'Try to make videos responsive using %sFitVids.js%s?', 'yoast-video-seo' ), '<a href="http://fitvidsjs.com/">', '</a>' ) . '</label></p>';

					echo '<br class="clear"/>';

					echo '<p><label class="textinput" for="wpseo_video_content_width">' . __( 'Content width:', 'yoast-video-seo' ) . '</label> ';
					echo '<input type="text" class="textinput" size="10" id="wpseo_video_content_width" name="wpseo_video[content_width]" value="' . $options['content_width'] . '"></p>';
					echo '<p class="clear description">' . __( 'This defaults to your themes content width, but if it\'s empty, setting a value here will make sure videos are embedded in the right width.', 'yoast-video-seo' ) . '</p>';

					if ( !isset( $options['vzaar_domain'] ) )
						$options['vzaar_domain'] = '';

					echo '<p><label class="textinput" for="wpseo_video_vzaar_domain">' . __( 'Vzaar domain:', 'yoast-video-seo' ) . '</label> ';
					echo '<input type="text" class="textinput" size="10" id="wpseo_video_vzaar_domain" name="wpseo_video[vzaar_domain]" value="' . $options['vzaar_domain'] . '"></p>';
					echo '<p class="clear description">' . __( 'If you use Vzaar, set this to the domainname you use for your Vzaar videos, no http: or slashes needed.', 'yoast-video-seo' ) . '</p>';

					echo '<h2>' . __( 'Post Types to include in XML Video Sitemap', 'yoast-video-seo' ) . '</h2>';
					echo '<p>' . __( 'Determine which post types on your site might contain video.', 'yoast-video-seo' ) . '</p>';

					foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $posttype ) {
						$sel = '';
						if ( isset( $options['videositemap_posttypes'] )
							&& is_array( $options['videositemap_posttypes'] )
							&& in_array( $posttype->name, $options['videositemap_posttypes'] )
						)
							$sel = 'checked="checked" ';
						echo '<input class="checkbox double" id="include' . $posttype->name . '" type="checkbox" '
							. 'name="wpseo_video[videositemap_posttypes][' . $posttype->name . ']" ' . $sel . 'value="' . $posttype->name . '"/> '
							. '<label for="include' . $posttype->name . '">' . $posttype->labels->name . '</label><br class="clear">';
					}

					echo '<h2>' . __( 'Taxonomies to include in XML Video Sitemap', 'yoast-video-seo' ) . '</h2>';
					echo '<p>' . __( 'You can also include your taxonomy archives, for instance, if you have videos on a category page.', 'yoast-video-seo' ) . '</p>';

					foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
						$sel = '';
						if ( isset( $options['videositemap_taxonomies'] )
							&& is_array( $options['videositemap_taxonomies'] )
							&& in_array( $tax->name, $options['videositemap_taxonomies'] )
						)
							$sel = 'checked="checked" ';
						echo '<input class="checkbox double" id="include' . $tax->name . '" type="checkbox" '
							. 'name="wpseo_video[videositemap_taxonomies][' . $tax->name . ']" ' . $sel . 'value="' . $tax->name . '"/> '
							. '<label for="include' . $tax->name . '">' . $tax->labels->name . '</label><br class="clear">';
					}

					echo '<br class="clear"/>';
				}

			} else {

				echo '<p>' . __( 'Insert the license key you got when you bought the plugin, then click save.', 'yoast-video-seo' ) . '</p>';
			}

			?>
			<div class="submit">
				<input type="submit" class="button-primary" name="submit"
					   value="<?php _e( "Save Settings", 'yoast-video-seo' ); ?>"/>
			</div>
		</form>

		<?php
		if ( $license_active ) {
			if ( isset( $_POST['reindex'] ) ) {
				$this->reindex();
			}
			?>

			<h2><?php _e( 'Indexation of Video\'s in your content', 'yoast-video-seo' ); ?></h2>

			<p style="max-width: 600px;"><?php _e( 'This process goes through all the post types specified by you, as well as the terms of each taxonomy, to check for videos in the content. If the plugin finds a video, it updates the meta data for that piece of content, so it can add that meta data and content to the XML Video Sitemap.', 'yoast-video-seo' ); ?></p>

			<p style="max-width: 600px;"><?php _e( 'By default the plugin only checks content that hasn\'t been checked yet. However, if you check \'Force Re-Index\', it will re-check all content. This is particularly interesting if you want to check for a video embed code that wasn\'t supported before, of if you want to update thumbnail images en masse.', 'yoast-video-seo' ); ?></p>

			<form method="post" action="">
				<input class="checkbox double" type="checkbox" name="force" id="force"> <label
					for="force"><?php _e( "Force reindex of already indexed video's.", 'yoast-video-seo' ); ?></label><br/>
				<br/>
				<input type="submit" class="button" name="reindex"
					   value="<?php _e( 'Re-Index Videos', 'yoast-video-seo' ); ?>"/>
			</form>
			</div>
		<?php
		}
	}

	/**
	 * Based on the video type being used, this content filtering function will automatically optimize the embed codes
	 * to allow for proper recognition by search engines.
	 *
	 * This function also, since version 1.2, adds the schema.org videoObject output.
	 *
	 * @link  http://schema.org/VideoObject
	 * @link  https://developers.google.com/webmasters/videosearch/schema
	 *
	 * @since 0.1
	 *
	 * @param string $content The content of the post.
	 * @return string $content The content of the post as modified by the function, if applicable.
	 */
	function content_filter( $content ) {
		global $post, $content_width;

		if ( is_feed() || is_home() || is_archive() || is_tax() || is_tag() || is_category() )
			return $content;

		if ( !is_object( $post ) )
			return $content;

		$video = wpseo_get_value( 'video_meta', $post->ID );

		if ( !$video || $video == 'none' )
			return $content;

		$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
		if ( $disable == 'on' )
			return $content;

		if ( !is_numeric( $content_width ) )
			$content_width = 400;

		switch ( $video['type'] ) {
			case 'vimeo':
				$content = str_replace( '<iframe src="http://player.vimeo.com', '<noframes><embed src="http://vimeo.com/moogaloop.swf?clip_id=' . $video['id'] . '" type="application/x-shockwave-flash" width="400" height="300"></embed></noframes><iframe src="http://player.vimeo.com', $content );
				break;
			case 'dailymotion':
				// If dailymotion is embedded using the Viper shortcode, we have to add a noscript version too
				if ( strpos( $content, '<iframe src="http://www.dailymotion' ) === false ) {
					$content = str_replace( '[/dailymotion]', '[/dailymotion]<noscript><iframe src="http://www.dailymotion.com/embed/video/' . $video['id'] . '" width="' . $content_width . '" height="' . floor( $content_width / 1.33 ) . '" frameborder="0"></iframe></noscript>', $content );
				}
				break;
		}

		$desc = trim( wpseo_get_value( 'metadesc', $post->ID ) );
		if ( !$desc || empty( $desc ) ) {
			$desc = trim( substr( $this->strip_shortcodes( $this->strip_tags( $post->post_content ) ), 0, 300 ) );
		}

		if ( empty( $desc ) )
			$desc = $this->strip_tags( get_the_title() );


		$content .= "\n\n";
		$content .= '<span itemprop="video" itemscope itemtype="http://schema.org/VideoObject">';
		$content .= '<meta itemprop="name" content="' . esc_attr( $this->strip_tags( get_the_title() ) ) . '">';
		$content .= '<meta itemprop="thumbnailURL" content="' . esc_attr( $video['thumbnail_loc'] ) . '">';
		$content .= '<meta itemprop="description" content="' . esc_attr( $desc ) . '">';
		$content .= '<meta itemprop="uploadDate" content="' . date( 'c', strtotime( $post->post_date ) ) . '">';
		if ( isset( $video['player_loc'] ) )
			$content .= '<meta itemprop="embedURL" content="' . $video['player_loc'] . '">';
		if ( isset( $video['content_loc'] ) )
			$content .= '<meta itemprop="contentURL" content="' . $video['content_loc'] . '">';

		$video_duration = wpseo_get_value( 'videositemap-duration', $post->ID );
		if ( !$video_duration && isset( $video['duration'] ) ) {
			$video_duration = $video['duration'];
		}

		if ( $video_duration )
			$content .= '<meta itemprop="duration" content="' . $this->iso_8601_duration( $video_duration ) . '">';
		$content .= '</span>';

		return $content;
	}

	/**
	 * A better strip tags that leaves spaces intact (and rips out more code)
	 *
	 * @since 1.3.4
	 *
	 * @link  http://www.php.net/manual/en/function.strip-tags.php#110280
	 *
	 * @param string $string string to strip tags from
	 *
	 * @return string
	 */
	function strip_tags( $string ) {

		// ----- remove HTML TAGs -----
		$string = preg_replace( '/<[^>]*>/', ' ', $string );

		// ----- remove control characters -----
		$string = str_replace( "\r", '', $string ); // --- replace with empty space
		$string = str_replace( "\n", ' ', $string ); // --- replace with space
		$string = str_replace( "\t", ' ', $string ); // --- replace with space

		// ----- remove multiple spaces -----
		$string = trim( preg_replace( '/ {2,}/', ' ', $string ) );

		return $string;

	}

	/**
	 * Convert the duration in seconds to an ISO 8601 compatible output. Assumes the length is not over 24 hours.
	 *
	 * @link http://en.wikipedia.org/wiki/ISO_8601
	 *
	 * @param int $duration The duration in seconds.
	 *
	 * @return string $out ISO 8601 compatible output.
	 */
	function iso_8601_duration( $duration ) {
		$out = 'PT';
		if ( $duration > 3600 ) {
			$hours = floor( $duration / 3600 );
			$out .= $hours . 'H';
			$duration = $duration - ( $hours * 3600 );
		}
		if ( $duration > 60 ) {
			$minutes = floor( $duration / 60 );
			$out .= $minutes . 'M';
			$duration = $duration - ( $minutes * 60 );
		}
		if ( $duration > 0 ) {
			$out .= $duration . 'S';
		}
		return $out;
	}

	/**
	 * Filter the OpenGraph type for the post and sets it to 'video'
	 *
	 * @since 0.1
	 *
	 * @param string $type The type, normally "article"
	 * @return string $type Value 'video'
	 */
	function opengraph_type( $type ) {
		return $this->type_filter( $type, 'video.movie' );
	}

	/**
	 * Switch the Twitter card type to player if needed.
	 *
	 * @param string $type
	 * @return string
	 */
	function card_type( $type ) {
		return $this->type_filter( $type, 'player' );
	}

	/**
	 * Helper function for Twitter and OpenGraph card types
	 *
	 * @param string $type
	 * @param string $video_output
	 * @return string
	 */
	function type_filter( $type, $video_output ) {
		if ( is_singular() ) {
			global $post;

			$video = wpseo_get_value( 'video_meta', $post->ID );

			if ( !$video || $video == 'none' )
				return $type;
			else {
				$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
				if ( $disable == 'on' ) {
					return $type;
				} else {
					return $video_output;
				}
			}

		} else if ( is_tax() || is_category() || is_tag() ) {
			$options = get_option( 'wpseo_video' );

			$term = get_queried_object();

			if ( isset( $options['videositemap_taxonomies'] )
				&& is_array( $options['videositemap_taxonomies'] )
				&& in_array( $term->taxonomy, $options['videositemap_taxonomies'] )
			) {
				$tax_meta = get_option( 'wpseo_taxonomy_meta' );
				if ( isset( $tax_meta[$term->taxonomy]['_video'][$term->term_id] ) ) {
					return $video_output;
				}
			}

		}

		return $type;
	}

	/**
	 * Filter the OpenGraph image for the post and sets it to the video thumbnail
	 *
	 * @since 0.1
	 *
	 * @param string $image URL to the image
	 * @return string $image URL to the video thumbnail image
	 */
	function opengraph_image( $image ) {
		if ( !empty( $image ) )
			return $image;

		if ( is_singular() ) {
			global $post;

			$video = wpseo_get_value( 'video_meta', $post->ID );

			if ( !$video || $video == 'none' )
				return $image;

			$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
			if ( $disable == 'on' )
				return $image;

			return $video['thumbnail_loc'];
		} else if ( is_tax() || is_category() || is_tag() ) {
			$options = get_option( 'wpseo_video' );

			$term = get_queried_object();

			if ( isset( $options['videositemap_taxonomies'] )
				&& is_array( $options['videositemap_taxonomies'] )
				&& in_array( $term->taxonomy, $options['videositemap_taxonomies'] )
			) {
				$tax_meta = get_option( 'wpseo_taxonomy_meta' );
				if ( isset( $tax_meta[$term->taxonomy]['_video'][$term->term_id] ) ) {
					$video = $tax_meta[$term->taxonomy]['_video'][$term->term_id];
					return $video['thumbnail_loc'];
				}
			}
		}
		return $image;
	}

	/**
	 * Add OpenGraph video info if present
	 *
	 * @since 0.1
	 */
	function opengraph() {
		if ( is_singular() ) {
			global $post;

			$video = wpseo_get_value( 'video_meta', $post->ID );

			if ( !$video || $video == 'none' )
				return false;

			$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
			if ( $disable == 'on' )
				return false;
		} else if ( is_tax() || is_category() || is_tag() ) {
			$options = get_option( 'wpseo_video' );

			$term = get_queried_object();

			if ( isset( $options['videositemap_taxonomies'] )
				&& is_array( $options['videositemap_taxonomies'] )
				&& in_array( $term->taxonomy, $options['videositemap_taxonomies'] )
			) {
				$tax_meta = get_option( 'wpseo_taxonomy_meta' );
				if ( isset( $tax_meta[$term->taxonomy]['_video'][$term->term_id] ) ) {
					$video = $tax_meta[$term->taxonomy]['_video'][$term->term_id];
				} else {
					return false;
				}
			}
		} else {
			return false;
		}

		if ( !isset( $video['player_loc'] ) )
			return false;

		echo '<meta property="og:video" content="' . $video['player_loc'] . '" />' . "\n";
		echo '<meta property="og:video:type" content="application/x-shockwave-flash" />' . "\n";
		if ( isset( $video['width'] ) && isset( $video['height'] ) ) {
			echo '<meta property="og:video:width" content="' . $video['width'] . '" />' . "\n";
			echo '<meta property="og:video:height" content="' . $video['height'] . '" />' . "\n";
		}
		echo '<meta property="og:image" content="' . $video['thumbnail_loc'] . '" />' . "\n";
	}

	/**
	 * Make the get_terms query only return terms with a non-empty description.
	 *
	 * @since 1.3
	 *
	 * @param $pieces array The separate pieces of the terms query to filter.
	 * @return mixed
	 */
	function filter_terms_clauses( $pieces ) {
		$pieces['where'] .= " AND tt.description != ''";
		return $pieces;
	}

	/**
	 * Reindex the video info from posts
	 *
	 * @since 0.1
	 */
	function reindex() {
		require_once ABSPATH . '/wp-admin/includes/media.php';

		echo "<strong>" . __( "Reindex starts....", "yoast-video-seo" ) . "</strong><br/>";

		$options = get_option( 'wpseo_video' );

		if ( isset( $options['videositemap_posttypes'] ) ) {
			$args = array(
				'post_type'   => $options['videositemap_posttypes'],
				'post_status' => 'publish',
				'numberposts' => 100,
				'offset'      => 0,
			);

			global $wp_version;
			if ( !isset( $_POST['force'] ) ) {
				if ( version_compare( $wp_version, '3.5', ">=" ) ) {
					$args['meta_query'] = array(
						'key'     => '_yoast_wpseo_video_meta',
						'compare' => 'NOT EXISTS'
					);
				}
			}

			$post_count_total = 0;
			foreach ( $options['videositemap_posttypes'] as $post_type ) {
				$post_count_total += wp_count_posts( $post_type )->publish;
			}

			while ( $post_count_total > $args['offset'] ) {
				$results = get_posts( $args );

				echo "<br/><strong>" . sprintf( __( "Found %d pieces of content to search through", "yoast-video-seo" ), count( $results ) ) . "</strong><br/><br/>";

				foreach ( $results as $post ) {
					$this->update_video_post_meta( $post, true );
					flush();
				}
				$args['offset'] += 99;
			}
		}

		// Get all the non-empty terms.
		add_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ) );
		$terms = array();
		foreach ( $options['videositemap_taxonomies'] as $key => $val ) {
			$new_terms = get_terms( $val );
			if ( is_array( $new_terms ) )
				$terms = array_merge( $terms, $new_terms );
		}
		remove_filter( 'terms_clauses', array( $this, 'filter_terms_clauses' ) );

		if ( count( $terms ) >= 1 ) {
			echo "<br/><strong>" . sprintf( __( "Found %d terms to search through", "yoast-video-seo" ), count( $terms ) ) . "</strong><br/><br/>";

			foreach ( $terms as $term ) {
				$this->update_video_term_meta( $term, true );
				flush();
			}
		}

		// Ping the search engines with our updated XML sitemap, we ping with the index sitemap because
		// we don't know which video sitemap, or sitemaps, have been updated / added.
		wpseo_ping_search_engines();

		echo "<br/><strong>" . __( 'Reindex completed.', 'yoast-video-seo' ) . "</strong>";
	}

	/**
	 * Filter the Page Analysis results to make sure we're giving the correct hints.
	 *
	 * @since 1.4
	 *
	 * @param array  $results The results array to filter and update.
	 * @param array  $job     The current jobs variables.
	 * @param object $post    The post object for the current page.
	 *
	 * @return array $results
	 */
	function filter_linkdex_results( $results, $job, $post ) {
		$video = wpseo_get_value( 'video_meta', $post->ID );
		if ( !$video || $video == 'none' )
			return $results;

		$disable = wpseo_get_value( 'videositemap-disable', $post->ID );
		if ( $disable == 'on' )
			return $results;

		if ( stripos( $job['title'], __( 'video', 'yoast-video-seo' ) ) === false ) {
			$results['title_video'] = array(
				'val' => 6,
				'msg' => __( 'You should consider adding the word "video" in your title, to optimize your ability to be found by people searching for video.', 'yoast-video-seo' )
			);
		} else {
			$results['title_video'] = array(
				'val' => 9,
				'msg' => __( 'You\'re using the word "video" in your title, this optimizes your ability to be found by people searching for video.', 'yoast-video-seo' )
			);
		}

		if ( $results['body_length']['raw'] > 150 && $results['body_length']['raw'] < 400 ) {
			$results['body_length'] = array(
				'val' => 9,
				'msg' => __( 'Your body copy is optimal length for your video to be recognized by Search Engines.', 'yoast-video-seo' )
			);
		} else if ( $results['body_length']['raw'] < 150 ) {
			$results['body_length'] = array(
				'val' => 6,
				'msg' => __( 'Your body copy is too short for Search Engines to understand the topic of your video, add some more content describing the contents of the video.', 'yoast-video-seo' )
			);
		} else {
			$results['body_length'] = array(
				'val' => 6,
				'msg' => sprintf( __( 'Your body copy is quite long, make sure that the video is the most important asset on the page, read %1$sthis post%2$s for more info.', 'yoast-video-seo' ), '<a href="http://yoast.com/video-not-showing-search-results/">', '</a>' )
			);
		}

		return $results;
	}


	/**
	 * Get the license key with priority to the defined constant
	 *
	 * @since 1.6
	 *
	 * @return string $license_key, or null if not found
	 */
	function get_license_key() {
		$options = get_option( 'wpseo_video' );

		if ( defined( 'WPSEO_VIDEO_LICENSE' ) && WPSEO_VIDEO_LICENSE )
			$license_key = WPSEO_VIDEO_LICENSE;
		else
			$license_key = isset( $options['yoast-video-seo-license'] ) ? $options['yoast-video-seo-license'] : '';

		return $license_key ? $license_key : null;
	}

	/**
	 *  Check wheather the license key is valid
	 *
	 * @since 1.6
	 *
	 * @return boolean true if valid, otherwise false
	 */
	function is_license_valid() {
		$options = get_option( 'wpseo_video' );

		if ( isset( $options['yoast-video-seo-license-status'] ) && 'valid' == $options['yoast-video-seo-license-status'] )
			return true;

		return false;
	}

}

/**
 * Throw an error if WordPress SEO is not installed.
 *
 * @since 0.2
 */
function yoast_wpseo_missing_error() {
	echo '<div class="error"><p>' . sprintf( __( 'Please %sinstall &amp; activate WordPress SEO by Yoast%s and then enable its XML sitemap functionality to allow the Video SEO module to work.' ), '<a href="' . admin_url( 'plugin-install.php?tab=search&type=term&s=wordpress+seo&plugin-search-input=Search+Plugins' ) . '">', '</a>' ) . '</p></div>';
}

/**
 * Throw an error if WordPress SEO is not installed.
 *
 * @since 1.5.4
 */
function yoast_wordpress_upgrade_error() {
	echo '<div class="error"><p>' . __( 'Please upgrade WordPress to the latest version to allow WordPress and the Video SEO module to work properly.', 'yoast-video-seo' ) . '</p></div>';
}

/**
 * Throw an error if WordPress SEO is not installed.
 *
 * @since 1.5.4
 */
function yoast_wpseo_upgrade_error() {
	echo '<div class="error"><p>' . __( 'Please upgrade the WordPress SEO plugin to the latest version to allow the Video SEO module to work.', 'yoast-video-seo' ) . '</p></div>';
}

/**
 * Initialize the Video SEO module on plugins loaded, so WP SEO should have set its constants and loaded its main classes.
 *
 * @since 0.2
 */
function yoast_wpseo_video_seo_init() {
	global $wp_version;

	if ( !version_compare( $wp_version, '3.4', '>=' ) ) {
		add_action( 'all_admin_notices', 'yoast_wordpress_upgrade_error' );
	} else if ( defined( 'WPSEO_VERSION' ) ) {
		if ( version_compare( WPSEO_VERSION, '1.4', '>=' ) ) {
			$wpseo_video_xml = new wpseo_Video_Sitemap();
		} else {
			add_action( 'all_admin_notices', 'yoast_wpseo_upgrade_error' );
		}

	} else {
		add_action( 'all_admin_notices', 'yoast_wpseo_missing_error' );
	}
}

add_action( 'plugins_loaded', 'yoast_wpseo_video_seo_init', 20 );