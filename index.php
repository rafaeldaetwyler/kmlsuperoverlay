<?php
	error_reporting(E_ERROR);

	include_once(__DIR__ ."/class/Gis.php");
	include_once(__DIR__ ."/class/Common.php");
	include_once(__DIR__ ."/class/KmlSuperOverlay.php");
	if(extension_loaded("geos")){
		if(!class_exists("Brick\Geo\Engine\GEOSEngine")){
			if(is_file(__DIR__ ."/vendor/autoload.php")) {
				include_once(__DIR__ ."/vendor/autoload.php");
			} else {
				throw new Exception("'geos' extension loaded but 'Brick\Geo' missing. Please run composer install");
			}
		}
	}
	// !! with ending '/'
	define("MAP_SOURCE_ROOT","./mapsources/");
	// !! with ending '/'
	define("URL_BASE","/");
	// enable (slow & higher memory usage) or disable kml clean & format with tidy extension
	define("TIDY_KML",false);
	// enable kml indent if TIDY_KML == false
	define("INDENT_KML",true);
	/* 
		allow WMS mapsource with EPSG not in [4326, 3857, 900913, 3587, 54004, 41001, 102113, 102100, 3785]
		
		null disable
		'PHPPROJ' require phpng-proj6+ extension 
			https://github.com/swen100/phpng-proj
		'GDALTRANSFORM' require gdaltransform in path
			https://gdal.org/programs/gdaltransform.html
		'CS2CS' require PROJ cs2cs in path
			https://proj.org/en/9.2/apps/cs2cs.html
	*/
	define("PROJ_BACKEND","PHPPROJ");

	KmlSuperOverlay::controller(array_filter(explode("/",$_REQUEST['qs'])),URL_BASE);
?>
