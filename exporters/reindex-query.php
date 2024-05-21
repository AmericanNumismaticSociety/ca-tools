<?php 
/*****
 * Author: Ethan Gruber
 * Date: May 2024
 * Function: Execute an API request to CollectiveAccess to get a list of records that match a given query
 * and initiate a reindex of the resulting IDs in mantis. This should be run on the production server.
 *****/

define("INDEX_COUNT", 500);
define("START", 0);
define("CA_URL", "https://test.numismatics.org/collectiveaccess/");
define("SOLR_URL", "http://localhost:8983/solr/numishare/update/");
define("NUMISHARE_URL", "http://localhost:8080/orbeon/numishare/");

//array of created or updated accession numbers
$accnums = array();

//ensure the ca_credentials.json exists
if (($ca_file = fopen("ca_credentials.json", "r")) !== FALSE && ($ssh_file = fopen("ssh_credentials.json", "r")) !== FALSE) {
    //load credentials
    $ca_credentials = json_decode(file_get_contents("ca_credentials.json"), true);
    $ssh_credentials = json_decode(file_get_contents("ssh_credentials.json"), true);
    
    //formulate the query to send to CollectiveAccess
    //first argument must be collection
    //read the second argument for the Lucene query. Default to 'yesterday'
    if (isset($argv[1])){
        $q = $argv[1];
        
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
            
            //begin parsing the JSON from CA
            process_response($response, $q);
            
        } else {
            echo "CA authToken error.\n";
        }
        
    } else {
        echo "No query.\n";
    }
} else {
    echo "Credentials JSON file for CollectiveAccess API authorization and/or SSH does not exist.\n";
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
    
    //testing static files below
    //zip_and_upload($ssh_credentials);
    
    if ($json->total > 0 ){
        echo "Processing {$json->total} edited item(s).\n";
        
        $count = 0;
        
        foreach ($json->results as $record){
            
            //var_dump($record);
            
            //enable a manual start position from a mass publication if there is an error
            if ($count >= START){
                //ignore Hoards as an object type
                if ($record->type_id != 'nmo:Hoard'){
                    //evaluate accessibility of the record. If it is publicly accessible, then create an update. If it is not, execute a deletion from eXist-db and Solr.
                    if ($record->access == 'public_access'){                        
                        $accnum = $record->idno;
                        $accnums[] = $accnum;
                        
                        //index records into Solr in increments of the INDEX_COUNT constant
                        if (count($accnums) > 0 && count($accnums) % INDEX_COUNT == 0 ){
                            $start = count($accnums) - INDEX_COUNT;
                            $toIndex = array_slice($accnums, $start, INDEX_COUNT);
                            
                            echo "Generating batch query starting from {$start}\n";
                            
                            //POST TO SOLR
                            generate_solr_shell_script($toIndex);
                        }
                        
                    } else {
                        $accnum = $record->idno;
                        echo "Deleting {$accnum}\n";
                        
                        //initiate a deletion from Numishare via curl
                        $url = "http://numismatics.org/cgi-bin/deletefromnumishare.php?accnum={$accnum}";
                        $deleteFromNumishare=curl_init();
                        curl_setopt($deleteFromNumishare, CURLOPT_URL, $url);
                        curl_setopt($deleteFromNumishare, CURLOPT_HEADER, 0);
                        
                        $deleteResponse = curl_exec($deleteFromNumishare);
                        echo $deleteResponse;
                        curl_close($deleteFromNumishare);
                    }
                }
            }
            $count++;
        }
        
        //execute process for remaining accnums.
        if (count($accnums) > 0){
            $start = floor(count($accnums) / INDEX_COUNT) * INDEX_COUNT;
            $toIndex = array_slice($accnums, $start);
            
            echo "Generating final Solr ingest query\n";
            
            //POST TO SOLR
            generate_solr_shell_script($toIndex);
        }        
        
    } else {
        "No records in query.\n";
        //error_log("No updated records for query {$q} at " . date(DATE_W3C) . "\n", 3, "/var/log/numishare/process.log");
    }
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
        
        //execute script: disable this; these should be executed on command line
        //shell_exec('sh /tmp/' . $uniqid . '.sh > /dev/null 2>/dev/null &');
        //commented out the line below because PHP seems to delete the file before it has had a chance to run in the shell
        //unlink('/tmp/' . $uniqid . '.sh');
    } else {
        error_log("Unable to create {$uniqid}.sh at " . date(DATE_W3C) . "\n", 3, "/var/log/numishare/error.log");
    }
}

?>