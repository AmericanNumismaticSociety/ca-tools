<?php 
/*****
 * Author: Ethan Gruber
 * Date: March 2026
 * Function: Execute an API request to CollectiveAccess to get a list of object lot records edited the previous day.
 * Other Lucene-syntax queries can also be sent to update records that meet other search requirements.
 * 
 * This workflow is split into two processes: one that exports RDF/XML files from CA and zips them to send to the ANS production server.
 * The second script should be executed nightly on the ANS production server, to extract XML files from the zip file and upload them to eXist-db.
 * 
 * Note: You must request another authToken after a request in order to get updated data
 * 
 * Ensure that a ca_credentials.json includes an object with a 'username' and 'password' property. 
 * This file is not committed to Github.
 * 
 * This script requires the zip and ssh2 packages for PHP 8.x
 * 
 * This script must be run as user database with the SSH keys configured to connect to database@numismatics.org
 * 
 *****/

define("START", 0);
define("CA_URL", array("mantis"=>"https://test.numismatics.org/collectiveaccess/", "sitnam"=>"https://test.numismatics.org/sitnam/"));
define("CA_UTILS", array("mantis"=>"/usr/local/projects/providence-2.0/support/bin/caUtils", "sitnam"=>"/usr/local/projects/sitnam-providence-2.0/support/bin/caUtils"));
define("TMP_LOT", "/tmp/lots");

if (isset($argv[1])){
    $database = $argv[1];
    
    if ($database == 'mantis' || $database == 'sitnam') {
        
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
        
        query_ca($database, $q);
        
    } else {
        echo "Invalid argument.\n";
    }
} else {
    echo "CollectiveAccess database not set.\n";
}


/***** FUNCTIONS *****/
function query_ca($database, $q) {
    //ensure the ca_credentials.json exists
    if (($ca_file = fopen("ca_credentials.json", "r")) !== FALSE && ($ssh_file = fopen("ssh_credentials.json", "r")) !== FALSE) {
        //load credentials
        $ca_credentials = json_decode(file_get_contents("ca_credentials.json"), true);
        $ssh_credentials = json_decode(file_get_contents("ssh_credentials.json"), true);        
        
        //execute the login to get an authToken
        $authToken = login_to_ca($database, $ca_credentials['username'], $ca_credentials['password']);
        
        if (isset($authToken)){
            $apiURL = CA_URL[$database] . "service.php/json/find/ca_object_lots?q={$q}&pretty=1&authToken={$authToken}";
            
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
            process_response($database, $response, $q);
            
            //zip exported record after each object has been exported to RDF/XML from CA
            if (is_dir(TMP_LOT)) {
                zip_and_upload($database, $ssh_credentials);
            }
            
        } else {
            echo "CA authToken error.\n";
        }
    } else {
        echo "Credentials JSON file for CollectiveAccess API authorization and/or SSH does not exist.\n";
    }
}

//login to CollectiveAccess API to get an API key
function login_to_ca($database, $username, $password){
    $login = str_replace('https://', 'https://' . $username . ':' . $password . '@', CA_URL[$database]) . 'service.php/json/auth/login';
    
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
function process_response ($database, $response, $q){
    
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
                //evaluate accessibility of the record. If it is publicly accessible, then create an update. If it is not, execute a deletion from eXist-db and Solr.
                if ($record->access == 'public_access'){
                    
                    //create /tmp/lots if it doesn't exist
                    if (!file_exists(TMP_LOT)) {
                        mkdir(TMP_LOT, 0777, true);
                    }
                    
                    $lotnum = $record->idno_stub;                       
                    export_lot_record($database, $record, $count);
                    //update_record_in_numishare($record, $collection);
                    
                    
                } else {
                    $lotnum = $record->idno_stub;
                    echo "{$count}: Deleting {$lotnum}\n";
                    
                    //initiate a deletion from Numishare via curl
                    $url = "https://numismatics.org/cgi-bin/deletefromnumishare.php?lot={$lotnum}&collection={$database}";
                    $deleteFromNumishare=curl_init();
                    curl_setopt($deleteFromNumishare, CURLOPT_URL, $url);
                    curl_setopt($deleteFromNumishare, CURLOPT_HEADER, 0);
                    
                    $deleteResponse = curl_exec($deleteFromNumishare);
                    echo $deleteResponse;
                    curl_close($deleteFromNumishare);
                }
            }
            $count++;
        }
    } else {
        "No updated records since yesterday";
        //error_log("No updated records for query {$q} at " . date(DATE_W3C) . "\n", 3, "/var/log/numishare/process.log");
    }
}

/*****
 * Execute caUtils to generate a RDF/XML record to post to eXist-db
 *****/
//zip RDF files and then SCP them to the production server
function zip_and_upload($database, $ssh_credentials){
    $zip = new ZipArchive;
    if ($zip->open("/tmp/ca_{$database}_lots.zip", ZipArchive::CREATE) === TRUE) {
        if ($handle = opendir(TMP_LOT))
        {
            // Add all files inside the directory
            while (false !== ($file = readdir($handle)))
            {
                if ($file != "." && $file != ".." && !is_dir('/tmp/lots/' . $file))
                {
                    $zip->addFile('/tmp/lots/' . $file, 'lots/' . $file);
                }
            }
            closedir($handle);
        }
        
        $zip->close();
    }
    
    //upload zip to numismatics.org
    echo "Uploading zip.\n";
    $connection = ssh2_connect($ssh_credentials['server'], $ssh_credentials['port']);
    
    if (ssh2_auth_password($connection, $ssh_credentials['username'], $ssh_credentials['password'])) {
        echo "Public Key Authentication Successful\n";
        ssh2_scp_send($connection, "/tmp/ca_{$database}_lots.zip", "/tmp/ca_{$database}_lots.zip", 0644);
        ssh2_exec($connection, 'exit');
        
        echo "Zip file uploaded to production server. Numishare publication workflow commencing.\n";
        
        unlink("/tmp/ca_{$database}_lots.zip");
        rmdir_recursive(TMP_LOT);
    } else {
        die('Public Key Authentication Failed');
    }
}

function rmdir_recursive($dir) {    
    if (is_dir($dir)) {
        foreach(scandir($dir) as $file) {
            if ('.' === $file || '..' === $file) continue;
            if (is_dir("$dir/$file")) rmdir_recursive("$dir/$file");
            else unlink("$dir/$file");
        }
        rmdir($dir);
    }    
}

function export_lot_record($database, $record, $count){
    $id = $record->id;
    $lotnum = $record->idno_stub;   

    
    $fileName = TMP_LOT . "/{$lotnum}.rdf";
    
    $cmd = CA_UTILS[$database] . " export-data -m object_lots -i {$id} -f {$fileName}";
    
    //execute the command to generate RDF/XML from the CA database using caUtils
    echo "{$count}: Generating {$lotnum} RDF.\n";
    
    
    shell_exec('nohup ' . $cmd . ' 2>&1 &');
     
}


?>
