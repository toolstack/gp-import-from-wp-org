<?php
/*
Plugin Name: GP Import Translations from wordress.org
Plugin URI: http://glot-o-matic.com/gp-import-from-wp-org
Description: Automatically extract source strings from a remote repo.
Version: 0.5
Author: Greg Ross
Author URI: http://toolstack.com
Tags: glotpress, glotpress plugin, translate
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class GP_Import_From_WP_Org {
	public $id = 'gp-import-from-wp-org';

	private	$source_types = array( 'stable', 'dev' );

	public function __construct() {
		add_filter( 'gp_translations_footer_links', array( $this, 'gp_translations_footer_links' ), 10, 4 );

		// We can't use the filter in the defaults route code because plugins don't load until after
		// it has already run, so instead add the routes directly to the global GP_Router object.
		GP::$router->add( "/gp-wp-import/(.+?)/(.+?)", array( $this, 'gp_wp_import' ), 'get' );
		GP::$router->add( "/gp-wp-import/(.+?)/(.+?)", array( $this, 'gp_wp_import' ), 'post' );
	}

	public function gp_translations_footer_links( $footer_links, $project, $locale, $translation_set ) {
		if ( GP::$permission->current_user_can( 'approve', 'translation-set', $translation_set->id ) ) {
			$plugin_stable_link = gp_link_get( gp_url( '/gp-wp-import/' . $translation_set->id . '/stable' ), __( '[Stable]' ) );
			$plugin_dev_link = gp_link_get( gp_url( '/gp-wp-import/' . $translation_set->id . '/dev'), __( '[Development]' ) );
			$theme_link = gp_link_get( gp_url( '/gp-wp-import/' . $translation_set->id . '/stable'), __( '[Stable]' ) );

			// Get the project settings.
			$gp_auto_extract_project_settings = (array) get_option( 'gp_auto_extract', array() );
			//print_r ( $gp_auto_extract_project_settings[ $project->id ]); // Project options
			//print_r ( $gp_auto_extract_project_settings[ $project->id ]['type']); // Project Option Key 'type' value ( wordpress | wordpress_theme )
			$gp_auto_extract_project_settings_type = $gp_auto_extract_project_settings[ $project->id ]['type'];
			
			if ( $gp_auto_extract_project_settings_type == 'wordpress' ) {
				$project_type = __( 'Plugin' );
				$project_sources = sprintf( __( '%s %s' ), $plugin_stable_link, $plugin_dev_link );
			} elseif ( $gp_auto_extract_project_settings_type == 'wordpress_theme' ) {
				$project_type = __( 'Theme' );
				$project_sources = sprintf( __( '%s' ), $theme_link );
			}

			$footer_links[] = sprintf( __( 'Import %s translation from WordPress.org: %s' ), '<strong>' . $project_type . '</strong>', $project_sources );
		}

		return $footer_links;
	}

	public function before_request() {
	}

	public function gp_wp_import( $translation_set_id, $source_type = 'stable' ) {
		// Get a route object to use for redirection.
		$route = new GP_Route;

		if ( ! in_array( $source_type, $this->source_types ) ) {
			$route->redirect_with_error( __( 'Unknown WordPress source type!' ) );
		}

		$translation_set = GP::$translation_set->find_one( array( 'id' => $translation_set_id ) );
		$project = GP::$project->find_one( array( 'id' => $translation_set->project_id ) );

		// Get the project settings.
		$gp_auto_extract_project_settings = (array) get_option( 'gp_auto_extract', array() );
		//print_r ( $gp_auto_extract_project_settings[ $project->id ]); // Project options
		//print_r ( $gp_auto_extract_project_settings[ $project->id ]['type']); // Project Option Key 'type' value ( wordpress | wordpress_theme )
		$gp_auto_extract_project_settings_type = $gp_auto_extract_project_settings[ $project->id ]['type'];

		if ( $gp_auto_extract_project_settings_type == 'wordpress' ) {
			$wp_url = sprintf( 'https://translate.wordpress.org/projects/wp-plugins/%s/%s/%s/default/export-translations', $project->slug, $source_type, $translation_set->locale ); // Plugin URL
		} elseif ( $gp_auto_extract_project_settings_type == 'wordpress_theme' ) {
			$wp_url = sprintf( 'https://translate.wordpress.org/projects/wp-themes/%s/%s/default/export-translations', $project->slug, $translation_set->locale ); //Theme URL
		}

		$data = $this->get_web_page_contents( $wp_url );

		if ( false !== $data ) {
			$temp_file = tempnam( sys_get_temp_dir(), 'GPI' );

			if ( false !== file_put_contents( $temp_file, $data ) ) {
				echo $temp_file . "<br>";

				$format = gp_get_import_file_format( 'po', '' );

				$translations = $format->read_translations_from_file( $temp_file, $project );

				if ( !$translations ) {
					unlink( $temp_file );

					$route->redirect_with_error( __( 'Couldn&#8217;t load translations from file!' ) );

					return;
				}

				$translations_added = $translation_set->import( $translations );
				gp_notice_set( sprintf( __( '%s translations were added' ), $translations_added ) );

				unlink( $temp_file );
			}
		} else {
			$route->redirect_with_error( sprintf( __( 'Couldn&#8217;t download the translations from %s!' ), $wp_url ) );

			return;
		}

		// redirect back to the translation set page.
		$route->redirect( gp_url_project( $project, gp_url_join( $translation_set->locale, $translation_set->slug ) ) );
	}

	private function get_web_page_contents( $url ) {
		/*
		 * There's a few ways we can get a web page's contents:
		 *
		 * 1: file_get_contents() if stream wrappers are enabled
		 * 2: CURL
		 * 3: fsockopen
		 *
		 */

		if( function_exists( 'file_get_contents' ) && ini_get('allow_url_fopen') ) {
			return file_get_contents( $url );
		} else if( function_exists( 'curl_init' ) ) {
			$crl = curl_init();
			echo curl_error( $crl );

			curl_setopt( $crl, CURLOPT_URL, $url );
			echo curl_error( $crl );
			curl_setopt( $crl, CURLOPT_RETURNTRANSFER, 1 );
			echo curl_error( $crl );
			curl_setopt( $crl, CURLOPT_CONNECTTIMEOUT, ini_get("default_socket_timeout") );
			echo curl_error( $crl );
			curl_setopt( $crl, CURLOPT_SSL_VERIFYPEER, false );
			echo curl_error( $crl );
			
			$ret = curl_exec( $crl );
			echo curl_error( $crl );

			curl_close( $crl );

			return $ret;
		} else if( function_exists( 'fsockopen') ) {
			$parsed_url = parse_url( $url );

			// Check which protocol we're using and try and open the connection
			if( 'http' == $parsed_url['scheme'] ) {
				// For standard http, just use the host name and port 80.
				$fp = fsockopen( $parsed_url['host'], 80 );
			} else if( 'https' == $parsed_url['scheme'] ) {
				// For https, first try using tls to make the connection on port 443.
				$fp = fsockopen( 'tls://' . $parsed_url['host'], 443 );

				// If tls failed, try ssl on port 443.  If this fails as well, we'll let the error trapping below return FALSE.
				if( FALSE === $fp ) {
					$fp = fsockopen( 'ssl://' . $parsed_url['host'], 443 );
				}

			} else {
				return FALSE;
			}

			if( $fp ) {
				$site_url = get_site_url();

				$parsed = parse_url( $site_url );

				$out  = "GET {$url} HTTP/1.0\r\n";
				$out .= "Host: {$parsed['host']}\r\n";
				$out .= "Connection: close\r\n";
				$out .= "\r\n";

				fwrite( $fp, $out );

				$data = FALSE;

				while( !feof( $fp ) ) {
					$data .= fgets( $fp, 1024 );
				}

				fclose( $fp );

				$lines = explode( "\r\n", $data );
				$num_lines = count( $lines );
				
				for( $i = 0; $i < $num_lines; $i++ ) {
					if( '' == $lines[$i] ) {
						unset( $lines[$i] );
						break;
					} else {
						unset( $lines[$i] );
					}
				}
				
				$data = implode( "\r\n", $lines );
				
				return $data;
			}
		}

		return FALSE;
	}

	public function after_request() {
	}
}

// Add an action to WordPress's init hook to setup the plugin.  Don't just setup the plugin here as the GlotPress plugin may not have loaded yet.
add_action( 'gp_init', 'gp_import_from_wp_org_init' );

// This function creates the plugin.
function gp_import_from_wp_org_init() {
	GLOBAL $gp_import_from_wp_org;

	$gp_import_from_wp_org = new GP_Import_From_WP_Org;
}
