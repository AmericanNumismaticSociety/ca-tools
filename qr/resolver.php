<?php 

/*****
 * Author: Ethan Gruber
 * Date: May 2024
 * Function: Accept an accession number and query the relevant CollectiveAccess database API in order to extract the internal
 * database object number and forward to the record in the curatorial database. 2222.1 accession numbers forward to the Unregistered
 * Object database and all others forward to the main curatorial database. 
 *****/

define("CA_URL", "https://test.numismatics.org/");

//ensure the ca_credentials.json exists
if (($ca_file = fopen("/usr/local/projects/ca-tools/exporters/ca_credentials.json", "r")) !== FALSE) {
    //load credentials
    $ca_credentials = json_decode(file_get_contents("/usr/local/projects/ca-tools/exporters/ca_credentials.json"), true);
    
    if (isset($_GET["accnum"])) {
        $accnum = $_GET["accnum"];
    } elseif (isset($argv[1])){
        $accnum = $argv[1];
    }
    
    if (isset($accnum)){
        $q = 'idno:' . $accnum;
        
        if (preg_match("/^2222\.1\.\d+/", $accnum)){
            $db = 'uro';
        } else {
            $db = 'collectiveaccess';   
        }
        
        //execute the login to get an authToken
        $authToken = login_to_ca($db, $ca_credentials['username'], $ca_credentials['password']);
        
        if (isset($authToken)){
            $apiURL = CA_URL . $db . "/service.php/json/find/ca_objects?q={$q}&pretty=1&authToken={$authToken}";
            
            $bundle = array("bundles"=>
                array("access"=>
                    array("convertCodesToIdno" => true),
                    "type_id" =>
                    array('convertCodesToIdno' => true)
                )
            );
            
            $ch = curl_init( $apiURL );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($bundle) );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            $response = curl_exec($ch);
            curl_close($ch);
            
            process_response($db, $response);
        }
    } else {
        http_error('400', "No accession number specified.");
    }
} else {
    http_error('400', "Unable to load CA credentials.");
}

/***** FUNCTIONS *****/
function login_to_ca($db, $username, $password){
    $login = str_replace('https://', 'https://' . $username . ':' . $password . '@', CA_URL) . $db . '/service.php/json/auth/login';
    
    $ch = curl_init($login);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == '200'){
        $json = json_decode($response);
        //echo "Acquired authToken: {$json->authToken}.\n";
        
        return $json->authToken;
    } else {
        http_error('400', "Unable to reach CollectiveAccess API.\n");
        return null;
    }    
}

function process_response($db, $response) {
    $json = json_decode($response);
    
    if ($json->total == 1 ){
        foreach ($json->results as $record) {
            $id = $record->id;
            
            $redirect_url = CA_URL . $db . '/index.php/editor/objects/ObjectEditor/Edit/object_id/' . $id;
            
            header("HTTP/1.1 302 Found");
            header("Location: {$redirect_url}");
            
            $body = '<html><head><title>ANS Object Resolver</title></head><body><h1>302 Found</h1><p>This page should redirect to <a href="' . $redirect_url . '">' . $redirect_url . '</a></p></body></html>';
            
            echo $body;
            
            //echo $redirect_url . "\n";
        }
    } else {
        return http_error('404', "Accession number not found in {$db} database.");
    }
}

function http_error($code, $message) {
    if ($code == '404') {
        header("HTTP/1.1 404 Not Found");
        
        $body = "<html><head><title>ANS Object Resolver</title></head><body><h1>404 Not Found</h1><p>{$message}</p></body></html>";
        
        echo $body;
        
    } elseif ($code == '400') {
        header("HTTP/1.1 400 Bad Request");
        
        $body = "<html><head><title>ANS Object Resolver</title></head><body><h1>400 Bad request</h1><p>{$message}</p></body></html>";
        
        echo $body;
    }
}

?>