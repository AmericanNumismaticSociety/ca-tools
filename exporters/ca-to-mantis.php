<?php 
/*****
 * Author: Ethan Gruber
 * Date: July 2021
 * Function: Execute an API request to CollectiveAccess to get a list of records edited the previous day.
 * Among these, of the numismatic objects, either delete objects that are not public or execute CaUtils
 * to export each record as NUDS and write to eXist-db. Batches of 500 records will be indexed into Solr.
 * 
 * The first arument passed on the command line must be an eXist-db collection name for the Numishare project.
 * 
 * The second argument, optional, can be a Lucene query to pass to the API, or the word 'yesterday' or 'today'.
 * If the second argument is not included, it defaults to 'yesterday'
 * 
 * Note: You must request another authToken after a request in order to get updated data
 * 
 * Ensure that a ca_credentials.json includes an object with a 'username' and 'password' property. 
 * This file is not committed to Github.
 *****/

define("INDEX_COUNT", 500);
define("CA_URL", "http://localhost/collectiveaccess/");
define("CA_UTILS", "/usr/local/projects/providence-1.7.12/support/bin/caUtils");
define("SOLR_URL", "http://localhost:8983/solr/numishare/update/");
define("NUMISHARE_URL", "http://localhost:8080/orbeon/numishare/");

//errors
$errors = array();

//array of created or updated accession numbers for batch Solr updating
$accnums = array();

//eXist-db credentials
$eXist_config_path = '/usr/local/projects/numishare/exist-config.xml';
$eXist_config = simplexml_load_file($eXist_config_path);

//ensure the ca_credentials.json exists
if (($handle = fopen("ca_credentials.json", "r")) !== FALSE) {
    //load credentials
    $ca_credentials = json_decode(file_get_contents("ca_credentials.json"), true);
        
    //execute
    if (isset($argv[1])){
        $collection = $argv[1];
        
        //evaluate the collection string and confirm it exists in eXist-db
        $file_headers = @get_headers($eXist_config->url . $collection);
        if (strpos($file_headers[0], '200') !== FALSE){
            echo "Found collection {$collection}.\n";
            
            //formulate the query to send to CollectiveAccess
            //first argument must be collection
            //read the second argument for the Lucene query. Default to 'yesterday'
            if (isset($argv[2])){
                if ($argv[2] == 'yesterday'){
                    $q = "modified:" . date("Y-m-d", strtotime("yesterday"));
                } elseif ($argv[2] == 'today') {
                    $q = "modified:" . date("Y-m-d", strtotime("today"));
                } else {
                    $q = $argv[2];
                }
            } else {
                $q = "modified:" . date("Y-m-d", strtotime("yesterday"));
            }
            
            //execute the login to get an authToken
            $authToken = login_to_ca($ca_credentials['username'], $ca_credentials['password']);
            
            if (isset($authToken)){
                $apiURL = CA_URL . "service.php/find/ca_objects?q={$q}&pretty=1&authToken={$authToken}";
                
                $bundle = array("bundles"=>
                    array("access"=>
                        array("convertCodesToIdno" => true),
                        "type_id" =>
                        array('convertCodesToIdno' => true)
                    )
                );
                
                //execute curl to get a list of items edited yesterday (or other Lucene query)
                error_log("{$collection} indexing process begun at " . date(DATE_W3C) . ".\n", 3, "/var/log/numishare/process.log");
                $ch = curl_init( $apiURL );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($bundle) );
                curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                $response = curl_exec($ch);
                curl_close($ch);
                
                //begin parsing the JSON from CA
                process_response($response, $collection, $q);
                
                //execute process for remaining accnums.
                if (count($accnums) > 0){
                    $start = floor(count($accnums) / INDEX_COUNT) * INDEX_COUNT;
                    $toIndex = array_slice($accnums, $start);
                    
                    //POST TO SOLR
                    generate_solr_shell_script($toIndex);
                }
            }
            
        } else {
            echo "Collection {$collection} is not found in eXist-db.\n";
            error_log("Collection {$collection} is not found in eXist-db at " . date(DATE_W3C) . ".\n", 3, "/var/log/numishare/process.log");
        }
    } else {
        echo "eXist-db collection name is required.\n";
        error_log('Batch export process executed without a collection argument at ' . date(DATE_W3C) . "\n", 3, "/var/log/numishare/process.log");
    }
} else {
    echo "Credentials JSON file for CollectiveAccess API authorization does not exist.\n";
}

/***** FUNCTIONS *****/
function login_to_ca($username, $password){
    $login = "http://{$username}:{$password}@localhost/collectiveaccess/service.php/auth/login";
    
    $ch = curl_init($login);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);    
    $http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == '200'){
        $json = json_decode($response);
        echo "Acquired authToken.\n";
        
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
function process_response ($response, $collection, $q){
    $json = json_decode($response);    
    
    
    if ($json->total > 0 ){
        echo "Processing {$json->total} edited item(s).\n";
        
        foreach ($json->results as $record){
            
            //var_dump($record);
            
            //ignore Hoards as an object type
            if ($record->type_id != 'nmo:Hoard'){
                //evaluate accessibility of the record. If it is publicly accessible, then create an update. If it is not, execute a deletion from eXist-db and Solr.                
                if ($record->access == 'public_access'){
                    update_record_in_numishare($record, $collection);
                } else {
                    delete_record_from_numishare($record, $collection);
                }
            }
        }
    } else {
        "No updated records since yesterday";
        error_log("No updated records for query {$q} at " . date(DATE_W3C) . "\n", 3, "/var/log/numishare/process.log");
    }
}

/*****
 * Execute caUtils to generate a NUDS XML record to post to eXist-db
 *****/
function update_record_in_numishare ($record, $collection){
    GLOBAL $accnums;
    GLOBAL $errors;
    GLOBAL $eXist_config;
    
    //eXist-db credentials
    $eXist_url = $eXist_config->url;
    $eXist_credentials = $eXist_config->username . ':' . $eXist_config->password;
    
    $id = $record->id;
    $accnum = $record->idno;
    
    if ($collection == 'mantis') {
        $accYear = substr($accnum, 0, 4);
        $fileURL = $eXist_url . $collection . '/objects/' . $accYear . '/' . $accnum . '.xml';
    } else {
        $fileURL = $eXist_url . $collection . '/objects/' . $accnum . '.xml';
    }
    
    $fileName = "/tmp/{$accnum}.xml";
    
    $cmd = CA_UTILS . " export-data -m nuds -i {$id} -f {$fileName}";
    
    //execute the command to generate NUDS from the CA database using caUtils
    echo "Generating {$accnum} NUDS.\n";
    shell_exec($cmd);
    
    if (($readFile = fopen($fileName, 'r')) === FALSE){
        $error = $accnum . ' failed to open temporary file (accnum likely broken) at ' . date(DATE_W3C) . "\n";
        error_log($error, 3, "/var/log/numishare/error.log");
        $errors[] = $error;
    } else {
        //PUT xml to eXist
        $putToExist=curl_init();
        
        //set curl opts
        curl_setopt($putToExist,CURLOPT_URL, $fileURL);
        curl_setopt($putToExist,CURLOPT_HTTPHEADER, array("Content-Type: text/xml; charset=utf-8"));
        curl_setopt($putToExist,CURLOPT_CONNECTTIMEOUT,2);
        curl_setopt($putToExist,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($putToExist,CURLOPT_PUT,1);
        curl_setopt($putToExist,CURLOPT_INFILESIZE,filesize($fileName));
        curl_setopt($putToExist,CURLOPT_INFILE,$readFile);
        curl_setopt($putToExist,CURLOPT_USERPWD,$eXist_credentials);
        $response = curl_exec($putToExist);
        
        $http_code = curl_getinfo($putToExist,CURLINFO_HTTP_CODE);
        
        //error and success logging
        if (curl_error($putToExist) === FALSE){
            $error = "{$accnum}  failed to upload to eXist at " . date(DATE_W3C) . "\n";
            error_log($error, 3, "/var/log/numishare/error.log");
            $errors[] = $error;
        } else {
            if ($http_code == '201'){
                $datetime = date(DATE_W3C);
                echo "Writing {$accnum}.\n";
                error_log("{$collection}: {$accnum} written at {$datetime}\n", 3, "/var/log/numishare/success.log");
                
                //if file was successfully PUT to eXist, add the accession number to the array for Solr indexing.
                $accnums[] = $accnum;    
                
                //index records into Solr in increments of the INDEX_COUNT constant
                if (count($accnums) > 0 && count($accnums) % INDEX_COUNT == 0 ){
                    $start = count($accnums) - INDEX_COUNT;
                    $toIndex = array_slice($accnums, $start, INDEX_COUNT);
                    
                    //POST TO SOLR
                    generate_solr_shell_script($toIndex);
                }
            }            
        }
        
        //close eXist curl
        curl_close($putToExist);
        
        //close files and delete from /tmp
        fclose($readFile);
        unlink($fileName);
    }
}

//delete record from Numishare
function delete_record_from_numishare($record, $collection){
    GLOBAL $errors;
    GLOBAL $eXist_config;
    
    //eXist-db credentials
    $eXist_url = $eXist_config->url;
    $eXist_credentials = $eXist_config->username . ':' . $eXist_config->password;
    
    $accnum = $record->idno;
    
    if ($collection == 'mantis') {
        $accYear = substr($accnum, 0, 4);
        $fileURL = $eXist_url . $collection . '/objects/' . $accYear . '/' . $accnum . '.xml';
    } else {
        $fileURL = $eXist_url . $collection . '/objects/' . $accnum . '.xml';
    }
    
    //DELETE xml from eXist
    $deleteFromExist=curl_init();
    //set curl opts
    curl_setopt($deleteFromExist,CURLOPT_URL, $fileURL);
    curl_setopt($deleteFromExist,CURLOPT_HTTPHEADER, array("Content-Type: text/xml; charset=utf-8"));
    curl_setopt($deleteFromExist,CURLOPT_CONNECTTIMEOUT,2);
    curl_setopt($deleteFromExist,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($deleteFromExist,CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($deleteFromExist,CURLOPT_USERPWD,$eXist_credentials);
    $response = curl_exec($deleteFromExist);
    
    $http_code = curl_getinfo($deleteFromExist,CURLINFO_HTTP_CODE);
    
    //error and success logging
    if (curl_error($deleteFromExist) === false){
        error_log($accnum . ' failed to delete from eXist at ' . date(DATE_W3C) . "\n", 3, "/var/log/numishare/error.log");
    } else {
        echo "Deleted {$accnum}.\n";        
        error_log($accnum . ' deleted at ' . date(DATE_W3C) . "\n", 3, "/var/log/numishare/success.log");
        
        //DELETE FROM SOLR
        $solrDeleteXml = '<delete><query>recordId:"' . $accnum . '"</query></delete>';
        
        //post solr doc
        $deleteFromSolr=curl_init();
        curl_setopt($deleteFromSolr,CURLOPT_URL, SOLR_URL);
        curl_setopt($deleteFromSolr,CURLOPT_POST,1);
        curl_setopt($deleteFromSolr,CURLOPT_HTTPHEADER, array("Content-Type: text/xml; charset=utf-8"));
        curl_setopt($deleteFromSolr,CURLOPT_POSTFIELDS, $solrDeleteXml);
        
        $solrResponse = curl_exec($deleteFromSolr);
        echo $solrResponse;
        curl_close($deleteFromSolr);
        
        //post commit
        $commitToSolr=curl_init();
        curl_setopt($commitToSolr,CURLOPT_URL, SOLR_URL);
        curl_setopt($commitToSolr,CURLOPT_POST,1);
        curl_setopt($commitToSolr,CURLOPT_HTTPHEADER, array("Content-Type: text/xml; charset=utf-8"));
        curl_setopt($commitToSolr,CURLOPT_POSTFIELDS, '<commit/>');
        
        $solrResponse = curl_exec($commitToSolr);
        echo $solrResponse;
        curl_close($commitToSolr);
    }
    //close eXist curl
    curl_close($deleteFromExist);
}

/***** PUBLICATION AND REPORTING FUNCTIONS *****/
//generate a shell script to activate batch ingestion
function generate_solr_shell_script($array){
    $uniqid = uniqid();
    $solrDocUrl = NUMISHARE_URL . 'mantis/ingest?identifiers=' . implode('%7C', $array);
    
    //generate content of bash script
    $sh = "#!/bin/sh\n";
    $sh .= "curl {$solrDocUrl} > /tmp/{$uniqid}.xml\n";
    $sh .= "curl " . SOLR_URL . " --data-binary @/tmp/{$uniqid}.xml -H 'Content-type:text/xml; charset=utf-8'\n";
    $sh .= "curl " . SOLR_URL . " --data-binary '<commit/>' -H 'Content-type:text/xml; charset=utf-8'\n";
    $sh .= "rm /tmp/{$uniqid}.xml\n";
    
    $shFileName = '/tmp/' . $uniqid . '.sh';
    $file = fopen($shFileName, 'w');
    if ($file){
        fwrite($file, $sh);
        fclose($file);
        
        //execute script
        shell_exec('sh /tmp/' . $uniqid . '.sh > /dev/null 2>/dev/null &');
        //commented out the line below because PHP seems to delete the file before it has had a chance to run in the shell
        //unlink('/tmp/' . $uniqid . '.sh');
    } else {
        error_log("Unable to create {$uniqid}.sh at " . date(DATE_W3C) . "\n", 3, "/var/log/numishare/error.log");
    }
}


?>