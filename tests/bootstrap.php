<?php

/*
 * This file is part of PackageConnector.
 *
 * (c) Andrew Matsovkin <macik.spb@gmail.com>
 *
 */

error_reporting(E_ALL);
define('COT_CODE',true);

$pc_file = 'PackageConnector.php';
$base_dir = str_replace('\\','/',__DIR__);
if (is_file($path = $base_dir .'/../'. $pc_file))
{
	require $path;
}
else
{
	require $base_dir.'/../src/PackageConnector.php';
}

