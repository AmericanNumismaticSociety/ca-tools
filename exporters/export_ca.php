<?php 
/*****
 * Author: Ethan Gruber
 * Date: January 2024
 * Function: Execute an API request to CollectiveAccess to get a list of records edited the previous day.
 * Other Lucene-syntax queries can also be sent to update records that meet other search requirements.
 * 
 * This workflow is split into two processes: one that exports NUDS/XML files from CA and zips them to send to the ANS production server.
 * The second script should be executed nightly on the ANS production server, to extract XML files from the zip file and upload them to eXist-db and index into Solr.
 * 
 * Note: You must request another authToken after a request in order to get updated data
 * 
 * Ensure that a ca_credentials.json includes an object with a 'username' and 'password' property. 
 * This file is not committed to Github.
 *****/

define("INDEX_COUNT", 500);
define("CA_URL", "https://test.numismatics.org/collectiveaccess/");
define("CA_UTILS", "/usr/local/projects/providence-2.0/support/bin/caUtils");

//read the image file list
$image_files = array();
$fp=fopen('/data/images/files.list', 'r');
while (!feof($fp)){
    $line=fgets($fp);
    
    if (preg_match('/^\d{4}\.\d+\.\d+\..*\.noscale\.jpg$/', $line)){
        $image_files[] = trim($line);
    }
}
fclose($fp);

//array of created or updated accession numbers
$accnums = array();

//ensure the ca_credentials.json exists
if (($handle = fopen("ca_credentials.json", "r")) !== FALSE) {
    //load credentials
    $ca_credentials = json_decode(file_get_contents("ca_credentials.json"), true);
        
    //formulate the query to send to CollectiveAccess
    //first argument must be collection
    //read the second argument for the Lucene query. Default to 'yesterday'
    if (isset($argv[1])){
        if ($argv[1] == 'yesterday'){
            $q = "modified:" . date("Y-m-d", strtotime("yesterday"));
        } elseif ($argv[1] == 'today') {
            $q = "modified:" . date("Y-m-d", strtotime("today"));
        } else {
            $q = $argv[1];
        }
    } else {
        $q = "modified:" . date("Y-m-d", strtotime("yesterday"));
    }
    
    //execute the login to get an authToken
    $authToken = login_to_ca($ca_credentials['username'], $ca_credentials['password']);
    
    if (isset($authToken)){
        $apiURL = CA_URL . "service.php/json/find/ca_objects?q={$q}&pretty=1&authToken={$authToken}";
        
        $bundle = array("bundles"=>
            array("access"=>
                array("convertCodesToIdno" => true),
                "type_id" =>
                array('convertCodesToIdno' => true)
            )
        );
        
        //execute curl to get a list of items edited yesterday (or other Lucene query)
        //error_log("{$collection} indexing process begun at " . date(DATE_W3C) . ".\n", 3, "/var/log/numishare/process.log");
        
        $ch = curl_init( $apiURL );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($bundle) );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $response = curl_exec($ch);
        curl_close($ch);
        
        //var_dump($response);
        
        //begin parsing the JSON from CA
        process_response($response, $q);
        
        //execute process for remaining accnums.
        /*if (count($accnums) > 0){
            $start = floor(count($accnums) / INDEX_COUNT) * INDEX_COUNT;
            $toIndex = array_slice($accnums, $start);
            
            //POST TO SOLR
            generate_solr_shell_script($toIndex);
        }*/
    } else {
        echo "CA authToken error.\n";
    }
} else {
    echo "Credentials JSON file for CollectiveAccess API authorization does not exist.\n";
}

/***** FUNCTIONS *****/
function login_to_ca($username, $password){
    $login = str_replace('https://', 'https://' . $username . ':' . $password . '@', CA_URL) . 'service.php/json/auth/login';
    
    $ch = curl_init($login);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);    
    $http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == '200'){
        $json = json_decode($response);
        echo "Acquired authToken: {$json->authToken}.\n";
        
        return $json->authToken; 
    } else {
        echo "Unable to reach CollectiveAccess API with {$login}.\n";
        error_log("Unable to reach CollectiveAccess API with {$login} at " . date(DATE_W3C) .  "\n", 3, "/var/log/numishare/process.log");
        return null;
    }
        
}

/***** 
 * Process the JSON response from CollectiveAccess API
 *****/
function process_response ($response, $q){
    
    $json = json_decode($response);    
    
    
    if ($json->total > 0 ){
        echo "Processing {$json->total} edited item(s).\n";
        
        //create /tmp/nuds if it doesn't exist
        if (!file_exists('/tmp/nuds')) {
            mkdir('/tmp/nuds', 0777, true);
        }
        
        foreach ($json->results as $record){
            
            //var_dump($record);
            
            //ignore Hoards as an object type
            if ($record->type_id != 'nmo:Hoard'){
                //evaluate accessibility of the record. If it is publicly accessible, then create an update. If it is not, execute a deletion from eXist-db and Solr.                
                if ($record->access == 'public_access'){
                    $accnum = $record->idno;                    
                    
                    export_record($record);
                    //update_record_in_numishare($record, $collection);
                    
                    
                } else {
                    //delete_record_from_numishare($record, $collection);
                }
            }
        }
        
        //zip exported records
        
    } else {
        "No updated records since yesterday";
        //error_log("No updated records for query {$q} at " . date(DATE_W3C) . "\n", 3, "/var/log/numishare/process.log");
    }
}

/*****
 * Execute caUtils to generate a NUDS XML record to post to eXist-db
 *****/
function export_record($record){
    GLOBAL $image_files;
    
    $images = array();
    $id = $record->id;
    $accnum = $record->idno;
    
    //images are now read from files.list generated from image files on disk rather than from FileMaker
    foreach ($image_files as $image){
        $pattern = '/^' . str_replace('.', '\.', $accnum) . '\.(.*)\.noscale\.jpg$/';
        if (preg_match($pattern, $image, $matches)){
            echo "Found image {$image}: {$matches[1]}\n";
            $images[$matches[1]] = $image;
        }
    }
    
    
    
    $fileName = "/tmp/nuds/{$accnum}.xml";
    
    $cmd = CA_UTILS . " export-data -m nuds -i {$id} -f {$fileName}";
    
    //execute the command to generate NUDS from the CA database using caUtils
    echo "Generating {$accnum} NUDS.\n";
    
    
    //shell_exec($cmd);
    
    //generate images XML
    if (count($images) > 0){
        $accession_array = explode('.', $accnum);
        $collection_year = $accession_array[0];
        
        switch ($collection_year) {
            case $collection_year < 1900:
                $image_path = '00001899';
                break;
            case $collection_year >= 1900 && $collection_year < 1950:
                $image_path = '19001949';
                break;
            case $collection_year >= 1950 && $collection_year < 2000:
                $image_path = '19501999';
                break;
            case $collection_year >= 2000 && $collection_year < 2050:
                $image_path = '20002049';
                break;
        }
        
        $writer = new XMLWriter();
        $writer->openURI("/tmp/nuds/{$accnum}-images.xml");
        //$writer->openURI('php://output');
        $writer->startDocument('1.0','UTF-8');
        $writer->setIndent(true);
        //now we need to define our Indent string,which is basically how many blank spaces we want to have for the indent
        $writer->setIndentString("    ");
        
        $writer->startElement('digRep');
            $writer->writeAttribute('xmlns', 'http://nomisma.org/nuds');
            $writer->writeAttribute('xmlns:xs', "http://www.w3.org/2001/XMLSchema");
            $writer->writeAttribute('xmlns:xlink', "http://www.w3.org/1999/xlink");
            $writer->writeAttribute('xmlns:mets', "http://www.loc.gov/METS/");
            $writer->writeAttribute('xmlns:tei', "http://www.tei-c.org/ns/1.0");
            
            $writer->startElement('mets:fileSec');
        
            //obverse images
            if (array_key_exists('obv', $images)){
                $writer->startElement('mets:fileGrp');
                    $writer->writeAttribute('USE', 'obverse');
                    //IIIF
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'iiif');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://images.numismatics.org/collectionimages%2F{$image_path}%2F{$collection_year}%2F{$accnum}.obv.noscale.jpg");
                        $writer->endElement();
                    $writer->endElement();
                    //archive
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'archive');
                        $writer->writeAttribute('MIMETYPE', 'image/jpeg');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://numismatics.org/collectionimages/{$image_path}/{$collection_year}/{$accnum}.obv.noscale.jpg");
                        $writer->endElement();
                    $writer->endElement();
                    //reference
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'reference');
                        $writer->writeAttribute('MIMETYPE', 'image/jpeg');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://numismatics.org/collectionimages/{$image_path}/{$collection_year}/{$accnum}.obv.width350.jpg");
                        $writer->endElement();
                    $writer->endElement();
                    //thumbnail
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'thumbnail');
                        $writer->writeAttribute('MIMETYPE', 'image/jpeg');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://numismatics.org/collectionimages/{$image_path}/{$collection_year}/{$accnum}.obv.width175.jpg");
                        $writer->endElement();
                    $writer->endElement();
                $writer->endElement();
            }
            
            //reverse images
            if (array_key_exists('rev', $images)){
                $writer->startElement('mets:fileGrp');
                    $writer->writeAttribute('USE', 'reverse');
                    //IIIF
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'iiif');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://images.numismatics.org/collectionimages%2F{$image_path}%2F{$collection_year}%2F{$accnum}.rev.noscale.jpg");
                        $writer->endElement();
                    $writer->endElement();
                    //archive
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'archive');
                        $writer->writeAttribute('MIMETYPE', 'image/jpeg');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://numismatics.org/collectionimages/{$image_path}/{$collection_year}/{$accnum}.rev.noscale.jpg");
                        $writer->endElement();
                    $writer->endElement();
                    //reference
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'reference');
                        $writer->writeAttribute('MIMETYPE', 'image/jpeg');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://numismatics.org/collectionimages/{$image_path}/{$collection_year}/{$accnum}.rev.width350.jpg");
                        $writer->endElement();
                    $writer->endElement();
                    //thumbnail
                    $writer->startElement('mets:file');
                        $writer->writeAttribute('USE', 'thumbnail');
                        $writer->writeAttribute('MIMETYPE', 'image/jpeg');
                        $writer->startElement('mets:FLocat');
                            $writer->writeAttribute('LOCYPE', 'URL');
                            $writer->writeAttribute('xlink:href', "https://numismatics.org/collectionimages/{$image_path}/{$collection_year}/{$accnum}.rev.width175.jpg");
                        $writer->endElement();
                    $writer->endElement();
                $writer->endElement();
            }
            
            //iterate through additional images
            foreach ($images as $k=>$v){
                $use = explode('.', $v)[3];
                
                if ($k != 'obv' && $k != 'rev'){
                    $writer->startElement('mets:fileGrp');
                        $writer->writeAttribute('USE', $use);
                        //IIIF
                        $writer->startElement('mets:file');
                            $writer->writeAttribute('USE', 'iiif');
                            $writer->startElement('mets:FLocat');
                                $writer->writeAttribute('LOCYPE', 'URL');
                                $writer->writeAttribute('xlink:href', "https://images.numismatics.org/collectionimages%2F{$image_path}%2F{$collection_year}%2F{$v}");
                        $writer->endElement();
                    $writer->endElement();
                    
                    $writer->endElement();
                }
            }
            //end mets:fileSec and digRep
            $writer->endElement();
        $writer->endElement();
        
        //close file
        $writer->endDocument();
        $writer->flush();
    
        //merge the digRep XML document into the NUDS file
        $nuds = new DOMDocument;
        $nuds->load($fileName);
        $digRep = new DOMDocument;
        $digRep->load("/tmp/nuds/{$accnum}-images.xml");
        $nuds->documentElement->appendChild($nuds->importNode($digRep->documentElement, true));
        $nuds->save($fileName);
        unlink("/tmp/nuds/{$accnum}-images.xml");
        
    }
    
}


?>