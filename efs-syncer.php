<?php
/**
 * Plugin Name: EFS Syncer
 * Plugin URI:  https://github.com/jimyaghi/efs-syncer
 * Description: This plugin needs to reside in the mu-plugins directory of Wordpress and enables you to sync
 * your local Wordpress back to your EFS drive on AWS so other instances can pick up changes.
 * Author:      YaghiLabs
 * Author URI:  https://jimyaghi.com
 * Version:     2.5.14
 */

namespace YL {
	/**
	 * بِسْمِ اللَّهِ الرَّحْمَنِ الرَّحِيم
	 *
	 * Created by Jim Yaghi
	 * Date: 2021-10-08
	 * Time: 10:11
	 *
	 */


// Basic security, prevents file from being loaded directly.
	defined( 'ABSPATH' ) or die( 'Cheatin&#8217; uh?' );
	require_once( __DIR__ . '/EFSSyncerPlugin.php' );
	$GLOBALS['efs_syncer_plugin'] = EFSSyncerPlugin::getInstance();

}