<?php
/** ---------------------------------------------------------------------
 * app/lib/Plugins/InformationService/Koha.php :
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

global $g_information_service_settings_Koha;
$g_information_service_settings_Koha= [];

class WLPlugInformationServiceKoha extends BaseInformationServicePlugin Implements IWLPlugInformationService {
	# ------------------------------------------------
	static $s_settings;
	# ------------------------------------------------
	/**
	 *
	 */
	public function __construct() {
		global $g_information_service_settings_Koha;

		WLPlugInformationServiceKoha::$s_settings = $g_information_service_settings_Koha;
		parent::__construct();
		$this->info['NAME'] = 'Koha';
		
		$this->description = _t('Provides access to Koha-based ILS data services');
	}
	# ------------------------------------------------
	/** 
	 * Get all settings settings defined by this plugin as an array
	 *
	 * @return array
	 */
	public function getAvailableSettings() {
		return WLPlugInformationServiceKoha::$s_settings;
	}
	# ------------------------------------------------
	# Data
	# ------------------------------------------------
	/** 
	 * Perform lookup on Koha-based data service
	 *
	 * @param array $pa_settings Plugin settings values
	 * @param string $ps_search The expression with which to query the remote data service
	 * @param array $pa_options Lookup options (none defined yet)
	 * @return array
	 */
	public function lookup($pa_settings, $ps_search, $pa_options=null) {
		$request = caGetOption('request', $pa_options, null);
		$maxcount = caGetOption('count', $pa_options, 20);
		
		$count = 0;
		$p = 1;
		$items = [];
		
		$service = $request ? $request->getParameter('service', pString) : null;
		if(strlen($service) && !self::validateService($service)) { 
			return ['results' => []];
		}
		
		$s = urldecode($ps_search);
		if (isURL($s) && preg_match("/^https?:\/\/numismatics.org\/library\/(.+)$/", $s, $m)) {
			$ps_search = 'biblionumber:' . $m[1];
		}
		while($count <= $maxcount) {
			$vs_data = caQueryExternalWebservice("https://donum.numismatics.org/cgi-bin/koha/opac-search.pl?idx=kw&count=20&format=rss&q=".urlencode($ps_search));

			if ($vs_data) {
				$o_xml = @simplexml_load_string($vs_data);

				if ($o_xml) {
					$o_entries = $o_xml->{'channel'}->{'item'};
					if ($o_entries && sizeof($o_entries)) {
						foreach($o_entries as $o_entry) {
							$o_links = $o_entry->{'link'};
							$va_attr = $o_links[0]->attributes();
							$label = trim(str_replace(' / ', ' ' , (string)$o_entry->{'title'}));
							$idno = explode('=', (string)$o_entry->{'link'})[1];
							$vs_url = "http://numismatics.org/library/" . $idno;
							$items[$label] = array('label' => $label, 'idno' => $idno, 'url' => $vs_url);
							$count++;
						}
					}
				}
			}
			break;
		}
		//ksort($items);
		
		return ['results' => array_values($items)];
	}
	# ------------------------------------------------
	/** 
	 * Fetch details about a specific item from a eol-based data service
	 *
	 * @param array $pa_settings Plugin settings values
	 * @param string $ps_url The URL originally returned by the data service uniquely identifying the item
	 * @return A link to the URL
	 */
	public function getExtendedInformation($pa_settings, $ps_url) {
	    return ['display' => "<p><a href='{$ps_url}' target='_blank'>{$ps_url}</a></p>"];
	}
	
	# ------------------------------------------------
}
