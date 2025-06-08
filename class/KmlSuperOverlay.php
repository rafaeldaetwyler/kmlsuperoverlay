<?php
	class KmlSuperOverlay {

		/*
			PRIVATE
		*/

		private $kml;
		private $src;
		private $name;
		private $baseurl;
		private $groundOverlayLod;
		private $regionPolygons;
		private $brickEngine;
		private $isCrossingAntimeridian = false;
		private $transparency;
		// debug
		private $debug = false;
		private $debugUrl = "";
		private $startTime;
		private $ruTime;
		private $nbnl;

		/*
			STATIC
		*/

		private static $worldBbox = [
			"north" => 85,	/* maxLat */
			"south" => -85,	/* minLat */
			"east" => 180,	/* maxLon */
			"west" => -180,	/* minLon */
		];
		private static $tidyOptions = [
			"input-xml"=> true, 
			"output-xml" => true, 
			"indent" => true, 
			"indent-cdata" => true, 
			"wrap" => false,
			"clean" => true,
		];
		/*
			<PolyStyle><fill>0</fill></PolyStyle>			is buggy: don't follow longitude curve
			<PolyStyle><color>00ffffff</color></PolyStyle>	is OK
		*/
		private static $kmlformat = [
			"header" => 
				"<?xml version='1.0' encoding='utf-8'?>
				<kml xmlns='http://www.opengis.net/kml/2.2' xmlns:atom='http://www.w3.org/2005/Atom' xmlns:gx='http://www.google.com/kml/ext/2.2'>
				<Document>
				<Style id='linered'><LineStyle><color>ff0000ff</color></LineStyle><PolyStyle><color>00ffffff</color></PolyStyle></Style>
				<Style id='linegreen'><LineStyle><color>ff00ff00</color></LineStyle><PolyStyle><color>00ffffff</color></PolyStyle></Style>
				<Style id='folderCheckOffOnly'><ListStyle><listItemType>checkOffOnly</listItemType><bgColor>bbfcf7de</bgColor>
					<ItemIcon><state>open</state><href>http://maps.google.com/mapfiles/kml/shapes/donut.png</href></ItemIcon>
					<ItemIcon><state>closed</state><href>http://maps.google.com/mapfiles/kml/shapes/forbidden.png</href></ItemIcon>
				</ListStyle></Style>",
			"footer" => 
				"</Document></kml>"
		];
		private static $lod = [
				/* 
				--- Level of Detail ---
				
				https://developers.google.com/kml/documentation/regions#fade-extent
				https://www.google.com/intl/fr-CA_ALL/earth/outreach/learn/avoiding-overload-with-regions/

				minLodPixels: display tile when value is reached on the current view. 
				- groundOverlay
					* 256 correspond to tile size = 1:1
					* 128 (1:2) might result in too little (unreadable) tiles
				- networkLink:
					* 192 for loading link before groundOverlay can be displayed
					* 256 might result in latency to display groundOverlay
					* 64  might result unnecessary load on networkLink server and tiles concurrent request
				
				maxLodPixels: undisplay tile when value is reached on the current view. 
				- groundOverlay
					* 512 correspond to double tile size = 2:1
					* -1  to disable undisplay (high memory load for Google Earth)
				
				xxxFadeExtent: INSIDE minLodPixels <> maxLodPixels windows
					0%:		< minLodPixels
					100%:	> minLodPixels + minFadeExtent 
								&& 
							< maxLodPixels - maxFadeExtent
					0%:		> maxLodPixels - maxFadeExtent

				groundOverlay Level of Detail values might be different for opaque (readable) and transparent (precise) tiles
				- notile is for the default notile png when server doesn't have tile for low zoom resolution
				*/
			"groundOverlay" => [
				"transparent" => [
					"minLodPixels" => 128,
					"maxLodPixels" => 640, 
					"minFadeExtent" => -1, 
					"maxFadeExtent" => -1
				],
				"opaque" => [
					"minLodPixels" => 128,
					"maxLodPixels" => 1024, 
					"minFadeExtent" => -1, 
					"maxFadeExtent" => -1
				],
				"notile" => [
					"minLodPixels" => 256,
				"maxLodPixels" => 512, 
				"minFadeExtent" => -1, 
				"maxFadeExtent" => -1
				]
			],
			"networkLink" => [
				"minLodPixels" => 176, 
				"maxLodPixels" => -1, 
				"minFadeExtent" => -1, 
				"maxFadeExtent" => -1
			]
		];
		private static $altitude = [
			"minAltitude" => 0, 
			"maxAltitude" => 0
		];
		// https://gis.stackexchange.com/a/419505
		private static $groundOverlayAltitudeMode = "relativeToSeaFloor";
		// TODO externalize to official database (ex. json)
		private static $tileMatrixMin = [
			/* 
				For Google Earth compatibility in WGS84 (4326)
			* 2022-12-13
				< 7.3.6.9285: 3
				>= 7.3.6.9285: 5
		*/
			4326  => ["zoom" => 5],
			// https://api.os.uk/maps/raster/v1/wmts?request=getcapabilities&service=wmts l. 512
			27700 => [
				"zoom" => 0, 
				"minTileRow" => 0,
				"maxTileRow" => 6,
				"minTileCol" => 0,
				"maxTileCol" => 4
			]
		];
		private static $displayRegion = true;
		private static $debugHtml = true;
		// ".kml" || ".kmz"
		private static $outFormat = ".kml";

		// name for the default "notile" png (preferably transparent with alpha). must be in this script script
		private static $notilePngPath = "zoom.png";
		// defaut spatial reference in Google Earth
		private static $dstSrid = 4326;

		/*
			CONSTRUCTOR
		*/

		function __construct($debug, $baseurl,$src = null) {
			if(!in_array(self::$outFormat,[".kml",".kmz"]))
				throw new Exception("unknow outFormat '".self::$outFormat."'");
			if(!extension_loaded("zip") && self::$outFormat == ".kmz")
				throw new Exception("outFormat '".self::$outFormat."' not supported: zip extension not enabled");
			$this->kml = "";
			$this->src = $src;
			$this->debug = $debug;
			$this->nbnl=0;
				$this->startTime = microtime(true);
				$this->ruTime = getrusage();
			if($this->src && !array_key_exists("projection",$this->src))
				$this->src["projection"] = self::$dstSrid;
			if($this->debug){
				$this->debugUrl = "/debug";
				self::$outFormat = ".kml";
			}
			$this->baseurl = $baseurl;
			$this->src["overlay"] ? $this->groundOverlayLod = "transparent" : $this->groundOverlayLod = "opaque";
			($this->src["backgroundColor"] && strlen($this->src["backgroundColor"]) == 9) ? $this->transparency = substr($this->src["backgroundColor"],-2) : $this->transparency = "FF";
			if(is_array($this->src["region"])){
				$this->src["region_display"] = $this->src["region"];
				// region is crossing antimeridian
				if($this->src["region_display"]["west"] > $this->src["region_display"]["east"]){
					// "fake" displayed region for <LinearRing> and <LatLonAltBox>
					$this->src["region_display"]["west"] -= 360;
					$this->isCrossingAntimeridian = true;
				}
				if(extension_loaded("geos")){
					$this->brickEngine = new Brick\Geo\Engine\GEOSEngine();
					$this->regionPolygons[] = Brick\Geo\Polygon::fromText("POLYGON ((".Gis::bboxToWkt($this->src["region"])."))",self::$dstSrid);
					if($this->isCrossingAntimeridian)
						$this->regionPolygons[] = Brick\Geo\Polygon::fromText("POLYGON ((".Gis::bboxToWkt($this->src["region_display"])."))",self::$dstSrid);
				}
			}
		}

		/*
			PUBLIC
		*/

		public function display(){
			$this->kml = 
				self::$kmlformat["header"].
				$description.
				$this->kml.
				self::$kmlformat["footer"];
			if(TIDY_KML && extension_loaded("tidy")){
				$this->kml = tidy_repair_string($this->kml, self::$tidyOptions);
			} elseif (INDENT_KML){
				$doc = new DOMDocument();
				$doc->preserveWhiteSpace = false;
				$doc->formatOutput = true;
				if (@$doc->loadXML($this->kml) === false){
					ob_clean();
					header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
					echo "<span style='background: #d6002e; color: white; font-family: Arial;'>Kml error: ".json_encode(libxml_get_last_error(),JSON_PRETTY_PRINT)."</span><hr>";
					echo "<pre>".htmlentities($this->kml)."</pre>";
					exit(-1);
			}
				// https://bugs.php.net/bug.php?id=50989
				$this->kml = $doc->saveXML(null, LIBXML_NOXMLDECL);
			}
			if(!$this->debug){
				ob_clean();
				header("Content-Disposition: inline; filename=".$this->name.self::$outFormat);
				foreach($this->debugHeaders() as $debugHeader)
					header("X-Debug-".$debugHeader);
				if(self::$outFormat == ".kml"){
					header('Content-Type: application/vnd.google-earth.kml+xml kml');
					echo $this->kml;
				} elseif(self::$outFormat == ".kmz"){
					header('Content-Type: application/vnd.google-earth.kmz kmz');
					unlink($kmzfile = tempnam(sys_get_temp_dir(), 'FOO')); // https://stackoverflow.com/a/64698936
					($zip = new ZipArchive())->open($kmzfile, ZIPARCHIVE::CREATE);
					$zip->addFromString($this->name.".kml",$this->kml);
					$zip->close();
					readfile($kmzfile);
					unlink($kmzfile);
				}
			} else {
				foreach($this->debugHeaders() as $debugHeader)
					echo $debugHeader."<br>".PHP_EOL;
				if(self::$debugHtml){
					echo Common::htmlAsHtml($this->kml);
				} else {
					echo $this->kml;
				}
			}
			exit();
		}

		public function createFromZXY($z,$x,$y) {
			// Document Name
			$this->name = $z."-".$x."-".$y;
			$this->kml .= "<name>".$this->name."</name>";

			// Region
			$this->kml .= self::createElement("Region", [self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge(Gis::tileEdgesArray($x,$y,$z,$this->src["projection"]), self::$altitude)))]);

			$groundOverlay = "";
			$networkLink = "";
			$nz = $z + 1;
			for($nx = ($x * 2); $nx <= ($x * 2) + 1; $nx++){
				for($ny = ($y * 2); $ny <= ($y * 2) + 1; $ny++){
					$tilecoords = Gis::tileEdgesArray($nx,$ny,$nz,$this->src["projection"]);
					if(!is_null($this->regionPolygons)){
						$display = false;
						foreach($this->regionPolygons as $regionPolygon)
							$display = $display || $this->brickEngine->intersects($current = Brick\Geo\Polygon::fromText("POLYGON ((".Gis::bboxToWkt($tilecoords)."))",self::$dstSrid,self::$dstSrid),$regionPolygon);
					} else {
						$display = true;
					}
					if($display){
						$groundOverlay .= $this->getGroundOverlay($nz,$nx,$ny,$tilecoords);
						if($nz < $this->src["maxZoom"])
							$networkLink .= $this->getNetworkLink($nz,$nx,$ny,$tilecoords);
					}
				}
			}
			$this->kml .= $groundOverlay;
			if($nz < $this->src["maxZoom"])
				$this->kml .= $networkLink;
		}

		public function createFromBbox() {
			if(is_array($this->src["region_display"])){
				$bbox = $this->src["region_display"];
				if(self::$displayRegion){
					$placemarkItems = [
						self::createElement("styleUrl","#linegreen"),
						self::createElement("Polygon",[
							self::createElement("tessellate",1),
							self::createElement("outerBoundaryIs",
								self::createElement("LinearRing",
									self::createElement("coordinates",Gis::bboxToLinearRing($bbox))))
						])
					];
					$pmlsbbox =self::createElement("Placemark",$placemarkItems,"region");
				}
			} else {
				$bbox = self::$worldBbox;
			}

			// Document Name
			$this->name = $this->src["name"];
			$this->kml .= "<name>".$this->name."</name>";

			// Region
			$this->kml .= self::createElement("Region", [self::createElement("LatLonAltBox",Common::assocArrayToXml($bbox))]);
			$this->kml .= $pmlsbbox;

			// NetworkLink
			if(self::$dstSrid == $this->src["projection"]){
				foreach(Gis::ZXYFromBbox($bbox,self::$tileMatrixMin[$this->src["projection"]]["zoom"]-1)["tiles"] as $nl)
					$this->kml .= $this->getNetworkLink($nl["z"]-1,$nl["x"],$nl["y"],Gis::tileEdgesArray($nl["x"],$nl["y"],$nl["z"]-1,self::$dstSrid));
			} elseif(array_key_exists($this->src["projection"],self::$tileMatrixMin)) {
				for($x = self::$tileMatrixMin[$this->src["projection"]]["minTileCol"]; $x <= self::$tileMatrixMin[$this->src["projection"]]["maxTileCol"]; $x++)
					for($y = self::$tileMatrixMin[$this->src["projection"]]["minTileRow"]; $y <= self::$tileMatrixMin[$this->src["projection"]]["maxTileRow"]; $y++)
						$this->kml .= $this->getNetworkLink(self::$tileMatrixMin[$this->src["projection"]]["zoom"],$x,$y,Gis::tileEdgesArray($x,$y,self::$tileMatrixMin[$this->src["projection"]]["zoom"],$this->src["projection"]));
			} else {
				throw new exception("Unknow TileMatrixSet for EPSG:".$this->src["projection"]);
		}
		}

		public function createRoot($rootfolder,$name){
			$this->name = $name;
			$this->kml .= "<name>".$this->name."</name>";

			$nf = "<Folder><styleUrl>#folderCheckOffOnly</styleUrl>";
			$curfolder = null;

			// scandirRecursiveFilePattern ensure compatibility for unix & windows filesystems with only "/" as DIRECTORY_SEPARATOR
			foreach(Common::scandirRecursiveFilePattern($rootfolder,'/^.+\.xml$/i') as $name){
				$filear = explode("/",$uri = str_replace([$rootfolder,".xml"],["","/"],$name));
				$rootname = null;
				$xmlcontent = Common::getXmlFileAsAssocArray($name,$rootname);
				// filter 
				if($rootname == "customMapSource"){
					// to be complient with https://github.com/grst/geos, just prefixed filesystem folders with '-' to be in first on my layer list & remove it here for display
					$mapsource["folder"] = preg_replace("/^-/","",$filear[0]);
					if($xmlcontent["folder"])
						// see https://geos.readthedocs.io/en/latest/users.html#more-maps
						$mapsource["folder"] = $xmlcontent["folder"];
					// Construct KML
					if($mapsource["folder"] != $curfolder){
						$this->kml .= $nf."<name>".($curfolder = $mapsource["folder"])."</name>";
						$nf = "</Folder><Folder><styleUrl>#folderCheckOffOnly</styleUrl>";
					}
					$linkItems = [
						self::createElement("href",$this->baseurl.$uri.$this->debugUrl),
						self::createElement("viewRefreshMode","onRegion")
					];
					$networkItems = [
						self::createElement("visibility",0),
						self::createElement("open",0),
						self::createElement("Link",$linkItems),
					];
					$this->kml .= self::createElement("NetworkLink", $networkItems,$xmlcontent["name"]);
				}
			}
			$this->kml .= "</Folder>";
		}

		/*
			PRIVATE
		*/

		private function debugHeaders(){
			$ru = getrusage();
			return [
				"time: ".round((microtime(true)-$this->startTime)*1000),
				"time_usr: ".Common::rutime($ru, $this->ruTime, "utime"),
				"time_sys: ".Common::rutime($ru, $this->ruTime, "stime"),
				"mem_peak: ".Common::afficheOctets(memory_get_peak_usage()),
				"nbnl: ".$this->nbnl
			];
		}

		private function getGroundOverlay($z,$x,$y,$tilecoords){
			$lod = self::$lod["groundOverlay"][$this->groundOverlayLod];
			// maxZoom: we keep tile displayed even if zoom more
			if($z == $this->src["maxZoom"]){
				$lod["maxLodPixels"] = -1;
			// minZoom: we keep tile displayed even if unzoom more (self::$tileMatrixMin[$this->src["projection"]]["zoom"] for notile / $this->src["minZoom"] for served tile)
			} elseif($z == self::$tileMatrixMin[$this->src["projection"]]["zoom"] || $z == $this->src["minZoom"]){
				$lod["minLodPixels"] = -1;
			// default notile png when server doesn't have tile for low zoom resolution
			} elseif ($z < $this->src["minZoom"]){
				$lod = self::$lod["groundOverlay"]["notile"];
			}
			$regionItems = [
				self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge($tilecoords, self::$altitude))),
				self::createElement("Lod",Common::assocArrayToXml($lod))
				];
			$groundItems = [
				self::createElement("Region", $regionItems),
				// https://stackoverflow.com/a/57596928/6343707 [ff:opaque 00:unvisible]
				self::createElement("color", $this->transparency."ffffff"),
				self::createElement("drawOrder",$z),
				self::createElement(/*"gx:*/"altitudeMode",self::$groundOverlayAltitudeMode),
				self::createElement("Icon",self::createElement("href",$this->getUrl($z,$x,$y))),
				self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge($tilecoords, self::$altitude))),
			];
			return self::createElement("GroundOverlay", $groundItems,"go-".$z."-".$x."-".$y);
		}

		private function getNetworkLink($z,$x,$y,$tilecoords){
			$regionItems = [
				self::createElement("LatLonAltBox",Common::assocArrayToXml(array_merge($tilecoords, self::$altitude))),
				self::createElement("Lod",Common::assocArrayToXml(self::$lod["networkLink"])),
			];
			$linkItems = [
				self::createElement("href",$this->baseurl.$z."-".$x."-".$y.self::$outFormat.$this->debugUrl),
				self::createElement("viewRefreshMode","onRegion")
			];
			$networkItems = [
				self::createElement("open",1),
				self::createElement("Link",$linkItems),
				self::createElement("Region", $regionItems)
			];
			$this->nbnl++;
			return self::createElement("NetworkLink", $networkItems,"nl-".$z."-".$x."-".$y);
		}

		private function getUrl($z,$x,$y){
			if($z < $this->src["minZoom"])
				return $_SERVER['REQUEST_SCHEME']."://".$_SERVER['HTTP_HOST'].URL_BASE.self::$notilePngPath;
			// serverParts {$serverpart}
			if($this->src["serverParts"])
				$this->src["url"] = str_replace('{$serverpart}',($sp = explode(" ",$this->src["serverParts"]))[mt_rand(0, count($sp) - 1)],$this->src["url"]);
			// TMS {$ry}
			if(str_contains($this->src["url"],'{$ry}'))
				return str_replace(['{$z}','{$x}','{$ry}'],[$z,$x,Gis::revY($z,$y)],$this->src["url"]);
			// QUAD {$q}
			if(str_contains($this->src["url"],'{$q}'))
				return str_replace('{$q}',Gis::tileToQuadKey($x,$y,$z),$this->src["url"]);
			// ZXY {$y}
			if(str_contains($this->src["url"],'{$y}'))
				return str_replace(['{$z}','{$x}','{$y}'],[$z,$x,$y],$this->src["url"]);
			// WMS {$bbox}
			if(str_contains($this->src["url"],'{$bbox}')){
				// urldecode ':' <> '%20'
				preg_match("/rs=epsg:([0-9]*)/i",urldecode($this->src["url"]),$matches);
				return str_replace('{$bbox}',implode(",",Gis::tileEdgesArray($x, $y, $z,$matches[1])),$this->src["url"]);
					}
				}

		/*
			PRIVATE STATIC
		*/

		private static function createElement($itemName, $items, $namevalue = null){
			$ret = "";
			if(!is_null($namevalue))
				$ret .= "<name><![CDATA[".$namevalue."]]></name>";
			if(is_null($items))
				throw new Exception("items is null");
			if(is_array($items)){
				foreach($items as $item)
					$ret .= $item;
			} elseif(is_string($items) || is_numeric($items)){
				$ret .= $items;
			} else {
				throw new Exception("unknonw items type '".gettype($items)."'");
			}
			if(in_array($itemName,["href","description"]))
				return "<".$itemName."><![CDATA[".$ret."]]></".$itemName.">";
			return "<".$itemName.">".$ret."</".$itemName.">";
		}

		/*
			STATIC CONTROLLER
		*/

		public static function controller($params,$urlbase){
			$debug = false;
			if(sizeof($params) > 0 && strcasecmp(end($params),"debug") == 0){
				$debug = true;
				array_pop($params);
			}
			// KML for all mapsource overlays
			if(sizeof($params) == 0){
				$so = new KmlSuperOverlay($debug, $_SERVER['REQUEST_SCHEME'] .'://'. $_SERVER['HTTP_HOST'].$urlbase);
				$so->createRoot(MAP_SOURCE_ROOT,end(array_filter(explode("/",$urlbase))));
				$so->display();
			} else{
				if(preg_match("/(\.km.)$/",end($params),$matches))
					$zxy = explode("-",str_replace($matches[1],"",array_pop($params)));
				$path = implode("/",$params);
				if(is_file($file = MAP_SOURCE_ROOT.$path.".xml")){
					$so = new KmlSuperOverlay($debug, $_SERVER['REQUEST_SCHEME'] .'://'. $_SERVER['HTTP_HOST'].$urlbase.$path."/",Common::getXmlFileAsAssocArray($file));
					if($zxy){
						// KML region for an overlay
						$so->createFromZXY($zxy[0],$zxy[1],$zxy[2]);
					} else {
						// KML root for an overlay
						$so->createFromBbox();
					}
					$so->display();
				} elseif($path == self::$notilePngPath) {
					ob_flush();
					header('Content-Type: image/png');
					readfile(dirname(__FILE__).DIRECTORY_SEPARATOR.self::$notilePngPath);
				} else {
					header("HTTP/1.0 404 Not Found");
					echo "Layer '".$path."' doesn't exist";
				}
					exit();
				}
			}
		}
?>
