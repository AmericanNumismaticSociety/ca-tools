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
$g_information_service_settings_Koha = array(
    'user' => array(
        'formatType' => FT_TEXT,
        'displayType' => DT_FIELD,
        'default' => '',
        'width' => 90, 'height' => 1,
        'label' => _t('Koha username'),
        'description' => _t('Koha username for JSON API access.')
    ),
    'password' => array(
        'formatType' => FT_TEXT,
        'displayType' => DT_FIELD,
        'default' => '',
        'width' => 90, 'height' => 1,
        'label' => _t('Koha password'),
        'description' => _t('Koha password for JSON API access.')
    )
);


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
	    $user = caGetOption('user', $pa_settings, null);
	    $password = caGetOption('password', $pa_settings, null);
	    
		$request = caGetOption('request', $pa_options, null);
		$maxcount = caGetOption('count', $pa_options, 20);
		
		$count = 0;
		$p = 1;
		$items = [];
		
		if (caCurlIsAvailable()) {
		    $service = $request ? $request->getParameter('service', pString) : null;
		    if(strlen($service) && !self::validateService($service)) {
		        return ['results' => []];
		    }
		    
		    //evaluate whether there is a URI in the field already or a string
		    $s = urldecode($ps_search);
		    if (preg_match("/^https?:\/\/numismatics.org\/library\/(.+)$/", $s, $m)) {
		        $json_query = '{"biblio_id":"' . $m[1] . '"}';
		    } else {
		        //if there is a colon, then query on both author and title
		        if (strpos($s, ':') !== FALSE) {
		            $author = explode(':', $s)[0];
		            $title = explode(':', $s)[1];
		            
		            $json_query = '{"-and": [{"title": {"-like": "' . $title . '%"}},{"author": {"-like": "' . $author . '%"}}]}';
		        } else {
		            $json_query = '{"title": {"-like": "' . $s . '%"}}';
		        }
		    }
		    
		    while($count <= $maxcount) {
		        $ch = curl_init();
		        curl_setopt($ch, CURLOPT_URL, "https://{$user}:{$password}@intra.donum.numismatics.org/api/v1/biblios?_per_page=20&q=".urlencode($json_query));
		        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		            'Accept: application/marcxml+xml'
		        ));
		        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		        
		        $vs_data = curl_exec($ch);
		        curl_close($ch);
		        
		        if ($vs_data) {
		            //$o_xml = @simplexml_load_string($vs_data);
		            $o_doc = new DOMDocument();
		            $o_doc->loadXML($vs_data);
		            $o_xpath = new DOMXPath($o_doc);
		            $o_xpath->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');
		            
		            $o_records = $o_doc->getElementsByTagNameNS('http://www.loc.gov/MARC21/slim', 'record');
		            
		            foreach ($o_records as $record) {
		                
		                //get IDNO
		                $idno = $o_xpath->query("marc:datafield[@tag='999']/marc:subfield[@code='c']", $record)->item(0)->nodeValue;
		                
		                // Get title for display
		                $va_title = array();
		                $o_node_list = $o_xpath->query("marc:datafield[@tag='245']/marc:subfield[@code='a' or @code='b' or @code='n' or @code='p']", $record);
		                foreach($o_node_list as $o_node) {
		                    $va_title[] = trim((string)$o_node->nodeValue);
		                }
		                $vs_title = trim(str_replace("/", " ", join(" ", $va_title)));
		                
		                
		                // Get author for display
		                $va_author = array();
		                $o_node_list = $o_xpath->query("marc:datafield[@tag='100']/marc:subfield[@code='a']|marc:datafield[@tag='700']/marc:subfield[@code='a']", $record);
		                foreach($o_node_list as $o_node) {
		                    //get last names
		                    preg_match("/^([^,]+),/", trim((string)$o_node->nodeValue), $m);
		                    
		                    if (isset($m[1])) {
		                        $va_author[] = $m[1];
		                    }
		                }
		                
		                //combine author last names into string
		                if (count($va_author) == 1) {
		                    $vs_author = $va_author[0];
		                } elseif (count($va_author) == 2) {
		                    $vs_author = implode(' and ', $va_author);
		                } elseif (count($va_author) > 2) {
		                    $count = count($va_author);
		                    $vs_author = '';
		                    for ($author_index = 0; $author_index <= $count - 1; $author_index++) {
		                        if ($author_index > 0 && $author_index < $count - 1) {
		                            $vs_author .= ', ';
		                        } elseif ($author_index == $count -1) {
		                            $vs_author .= ', and ';
		                        }
		                        
		                        $vs_author .= $va_author[$author_index];
		                    }
		                }
		                
		                $label = (isset($vs_author) ? $vs_author . ' (' : '') . $vs_title . (isset($vs_author) ? ')' : '');
		                
		                $vs_url = "http://numismatics.org/library/" . $idno;
		                $items[$label] = array('label' => $label, 'idno' => $idno, 'url' => $vs_url);
		            }
		        }
		        break;
		    }
		    //ksort($items);
		    
		    return ['results' => array_values($items)];
		} else {
		    throw new Exception(_t('CURL is required for Koha web API usage but not available on this server'));
		}
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
