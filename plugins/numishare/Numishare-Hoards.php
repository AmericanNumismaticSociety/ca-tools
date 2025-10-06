<?php
/** ---------------------------------------------------------------------
 * app/lib/Plugins/InformationService/Numishare-Hoards.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2022-2024 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage InformationService
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */
require_once(__CA_LIB_DIR__."/Plugins/IWLPlugInformationService.php");
require_once(__CA_LIB_DIR__."/Plugins/InformationService/BaseInformationServicePlugin.php");

global $g_information_service_settings_Numishare_Hoards;
$g_information_service_settings_Numishare_Hoards= [];

class WLPlugInformationServiceNumishareHoards extends BaseInformationServicePlugin Implements IWLPlugInformationService {
	# ------------------------------------------------
	static $s_settings;
	
	static $services = [
		'CoinHoards.org' => 'http://coinhoards.org/',
		'Coin Hoards of the Roman Republic' => 'http://numismatics.org/chrr/',		
	];
	# ------------------------------------------------
	/**
	 *
	 */
	public function __construct() {
	    global $g_information_service_settings_Numishare_Hoards;

	    WLPlugInformationServiceNumishareHoards::$s_settings = $g_information_service_settings_Numishare_Hoards;
		parent::__construct();
		$this->info['NAME'] = 'Numishare-Hoards';
		
		$this->description = _t('Provides access to Numishare-based coin hoard data services');
	}
	# ------------------------------------------------
	/** 
	 * Get all settings settings defined by this plugin as an array
	 *
	 * @return array
	 */
	public function getAvailableSettings() {
	    return WLPlugInformationServiceNumishareHoards::$s_settings;
	}
	# ------------------------------------------------
	# Data
	# ------------------------------------------------
	/** 
	 * Perform lookup on Numishare-based data service
	 *
	 * @param array $pa_settings Plugin settings values
	 * @param string $ps_search The expression with which to query the remote data service
	 * @param array $pa_options Lookup options:
	 *		count = Maximum number of records to return [Default is 30]
	 * @return array
	 */
	public function lookup($pa_settings, $ps_search, $pa_options=null) {
		$request = caGetOption('request', $pa_options, null);
		$maxcount = caGetOption('count', $pa_options, 30);
		
		$count = 0;
		$p = 1;
		$items = [];
		
		$service = $request ? $request->getParameter('service', pString) : null;
		if(strlen($service) && !self::validateService($service)) { 
			return ['results' => []];
		}
		
		$s = urldecode($ps_search);
		if (isURL($s) && ($service = self::isServiceUrl($s)) && preg_match("!^{$service}[/]*id/(.+)$!", $s, $m)) {
			$ps_search = 'recordId:"' . $m[1] . '"';
		}
		while($count <= $maxcount) {
			$vs_data = caQueryExternalWebservice("{$service}/apis/search?q=".urlencode($ps_search));

			if ($vs_data) {
			    $o_doc = new DOMDocument();
			    $o_doc->loadXML($vs_data);
			    $o_xpath = new DOMXPath($o_doc);
			    $o_xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
			    
			    $o_entries = $o_doc->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'entry');
			    
			    if ($o_entries && sizeof($o_entries)) {
			        foreach($o_entries as $o_entry) {
			            $title = $o_entry->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'title')->item(0)->nodeValue;
			            $vs_url = $o_xpath->query("atom:link[not(@rel)]", $o_entry)->item(0)->getAttribute('href');
			            $items[$title] = array('label' => $title, 'idno' => explode('/', $vs_url)[-1], 'url' => $vs_url);
			            $count++;
			        }
			    }
			}
			break;
		}
		ksort($items);
		
		return ['results' => array_values($items)];
	}
	# ------------------------------------------------
	/** 
	 * Fetch details about a specific item from a eol-based data service
	 *
	 * @param array $pa_settings Plugin settings values
	 * @param string $ps_url The URL originally returned by the data service uniquely identifying the item
	 * @return array An array of data from the data server defining the item.
	 */
	public function getExtendedInformation($pa_settings, $ps_url) {
		if (
			!preg_match("!^(https?://numismatics\.org/[A-Za-z_]+/)id/(.*)!", $ps_url, $matches)
			&&
			!preg_match("!^(https?://coinhoards\.org/)id/(.*)!", $ps_url, $matches)
		) { return []; }
		$service = $matches[1];
		$id = $matches[2];
		if(!self::validateService($service)) { 
			return ['display' => _t('Invalid service: %1', $service)];
		}
		$vs_result = caQueryExternalWebservice("{$service}id/{$id}.jsonld");
		
		if(!$vs_result) { return []; }
		if(!is_array($va_data = json_decode($vs_result, true))) { return []; }
			
		$va_display = ["<strong>"._t('Link')."</strong>: <a href='{$ps_url}' target='_blank'>{$ps_url}</a><br/>"];
		
		if (isset($va_data['@graph']) && is_array($va_data['@graph'])) {
			foreach($va_data['@graph'] as $g) {
				if(!is_array($g)) { continue; }
				foreach($g as $k => $v) {
					switch($k) {
						case '@id':
							$va_display[] = "<div><strong>ID</strong>: {$v}</div>";
							break;
						default:
							$k = str_replace("@", "", $k);
							if (strpos($k, ':') === false) { $k = caUcFirstUTF8Safe($k); }
							if (!is_array($v)) { $v = [$v]; }
					
							foreach($v as $vi) {
								if (is_array($vi)) {
									$d = [];
									foreach($vi as $kii => $vii) {
										$kii = caUcFirstUTF8Safe(str_replace("@", "", $kii));
										$d[] = "<em>{$kii}</em>: {$vii}";
									}
									$va_display[] = "<div style='margin-left: 10px;'><strong>{$k}</strong>: ".join("; ", $d)."</div>";
								} else {
									$va_display[] = "<div style='margin-left: 10px;'><strong>{$k}</strong>: {$vi}</div>";
								}
							}
							break;
					}
				}
			}
		}
		return ['display' => join("<br/>\n", $va_display)];
	}
	# ------------------------------------------------
	/** 
	 * Add drop-down list of Numishare services to user interface
	 *
	 * @param array $pa_settings element settings
	 * @return array
	 */
	public function getAdditionalFields(array $pa_element_info) : array {
		$id = '{fieldNamePrefix}'.$pa_element_info['element_id'].'_service_{n}';
		return [['name' => 'service', 'id' => $id, 'html' => caHTMLSelect(
				$id, 
				self::$services, 
				['id' => $id, 'onchange' => "jQuery(\"#infoservice_".$pa_element_info['element_id']."_autocomplete{n}\").autocomplete(\"search\", null); return false;"]
        )]];
	}
	# ------------------------------------------------
	/** 
	 * Return a list of Numishare service values for an InformationService attribute
	 *
	 * @param array $pa_settings element settings
	 * @return array
	 */
	public function getAdditionalFieldValues($attribute_value) : array {
		$uri =  $attribute_value->getUri();
		$service = self::isServiceUrl($uri);
		if($service) {
			return [$attribute_value->getElementID().'_service' => $m[1]];
		}
		return [];
	}
	# ------------------------------------------------
	/**
	 * Check if URI is for a service we support and if so return the service identifier
	 *
	 * @param string $uri
	 *
	 * @return string Service identifier or null if not a valid service URI
	 */
	private static function isServiceUrl(?string $uri) : ?string {
		if(!$uri) { return null; }
		if(
			preg_match("!^(https?://numismatics.org/[A-Za-z_]+/)!", $uri, $m)
			||
			preg_match("!^(https?://coinhoards.org/)!", $uri, $m)
		) {
			return $m[1];
		}
		return null;
	}
	# ------------------------------------------------
	/**
	 * Is service string one we support?
	 *
	 * @param string $service
	 *
	 * @return bool
	 */
	private static function validateService(string $service) : bool {
		return in_array($service, self::$services, true);
	}
	# ------------------------------------------------
}

