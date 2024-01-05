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
    $id = $record->id;
    $accnum = $record->idno;
    
    $fileName = "/tmp/nuds/{$accnum}.xml";
    
    $cmd = CA_UTILS . " export-data -m nuds -i {$id} -f {$fileName}";
    
    //execute the command to generate NUDS from the CA database using caUtils
    echo "Generating {$accnum} NUDS.\n";
    shell_exec($cmd);
}


?>