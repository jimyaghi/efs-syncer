#!/usr/bin/php
<?php
/**
 * بِسْمِ اللَّهِ الرَّحْمَنِ الرَّحِيم
 *
 * Created by Jim Yaghi
 * Date: 2021-10-08
 * Time: 10:20
 *
 */
namespace YL {
	if ( ( $argv[1] ?? false ) === false || ! file_exists( $argv[1] . '/wp-load.php' ) ) {
		die( 'Cheatin&#8217; uh?' );
	}

	// load wordpress environment
	require_once $argv[1] . '/wp-load.php';

	$plugin = EFSSyncerPlugin::getInstance();
	$plugin->releaseExpiredLocks();
	$plugin->deleteDeadInstances();
	$plugin->handleSyncJobs();
}