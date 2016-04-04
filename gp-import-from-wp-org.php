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
			$stable_link = gp_link_get( gp_url( '/gp-wp-import/' . $translation_set->id . '/stable' ), __( '[Stable]' ) );
			$dev_link = gp_link_get( gp_url( '/gp-wp-import/' . $translation_set->id . '/dev'), __( '[Development]' ) );
			$footer_links[] = sprintf( __( 'Import from wordpress.org: %s %s' ), $stable_link, $dev_link );
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

		$wp_url = sprintf( 'https://translate.wordpress.org/projects/wp-plugins/%s/%s/%s/default/export-translations', $project->slug, $source_type, $translation_set->locale );

		$data = file_get_contents( $wp_url );

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
