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

	// load wordpress environment
	require_once $argv[1] . '/wp-load.php';

	EFSSyncerPlugin::getInstance()->handleSyncJobs();
}