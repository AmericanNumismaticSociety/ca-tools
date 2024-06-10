<?php 
/*****
 * Author: Ethan Gruber
 * Date: January 2024
 * Function: Unzip the NUDS files in /tmp uploaded from the CA test server and publish them into eXist-db and trigger Solr indexing.
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

define("INDEX_COUNT", 250);
define("SOLR_URL", "http://localhost:8983/solr/numishare/update/");
define("NUMISHARE_URL", "http://localhost:8080/orbeon/numishare/");
define("TMP_NUDS", "/tmp/nuds");

//errors
$errors = array();

//array of created or updated accession numbers for batch Solr updating
$accnums = array();

//eXist-db credentials
$eXist_config_path = '/usr/local/projects/numishare/exist-config.xml';
$eXist_config = simplexml_load_file($eXist_config_path);

//execute
if (isset($argv[1])){
    $collection = $argv[1];
    
    //evaluate the collection string and confirm it exists in eXist-db
    $file_headers = @get_headers($eXist_config->url . $collection);
    if (strpos($file_headers[0], '200') !== FALSE){
        echo "Found collection {$collection}.\n";
        
        //purge /tmp/nuds before beginning new process
        rmdir_recursive(TMP_NUDS);
        
        //unzip file
        $zip = new ZipArchive();
        $result = $zip->open('/tmp/ca_upload.zip');
        if ($result === TRUE) {
            echo "Unzipping file.\n";
            $zip->extractTo("/tmp");
            $zip->close();
            
            //read /tmp/nuds and iterate through every XML file
            foreach(scandir(TMP_NUDS) as $file) {
                if ($file != '.' && $file != '..') {
                    $accnum = str_replace('.xml', '', $file);
                    
                    update_record_in_numishare($accnum, $collection);
                }
            }
            
            //execute process for remaining accnums.
            if (count($accnums) > 0){
                $start = floor(count($accnums) / INDEX_COUNT) * INDEX_COUNT;
                $toIndex = array_slice($accnums, $start);
                
                //POST TO SOLR
                generate_solr_shell_script($toIndex, $collection);
            }
            
            //delete zip file
            unlink('/tmp/ca_upload.zip');
            
            //remove nuds folder
            rmdir_recursive(TMP_NUDS);
        } else {
            echo "Error reading zip file.\n";
        }
        
    } else {
        echo "Collection {$collection} is not found in eXist-db.\n";
        error_log("Collection {$collection} is not found in eXist-db at " . date(DATE_W3C) . ".\n", 3, "/var/log/numishare/process.log");
    }
} else {
    echo "eXist-db collection name is required.\n";
    error_log('Batch export process executed without a collection argument at ' . date(DATE_W3C) . "\n", 3, "/var/log/numishare/process.log");
}

/***** FUNCTIONS *****/

/*****
 * Execute caUtils to generate a NUDS XML record to post to eXist-db
 *****/
function update_record_in_numishare ($accnum, $collection){
    GLOBAL $accnums;
    GLOBAL $errors;
    GLOBAL $eXist_config;
    
    //eXist-db credentials
    $eXist_url = $eXist_config->url;
    $eXist_credentials = $eXist_config->username . ':' . $eXist_config->password;
    
    if ($collection == 'mantis') {
        $accYear = substr($accnum, 0, 4);
        $fileURL = $eXist_url . $collection . '/objects/' . $accYear . '/' . $accnum . '.xml';
    } else {
        $fileURL = $eXist_url . $collection . '/objects/' . $accnum . '.xml';
    }
    
    $fileName = TMP_NUDS . '/' . $accnum . '.xml';    
    //echo "{$fileName}\n";
    
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
                    generate_solr_shell_script($toIndex, $collection);
                }
            }            
        }
        
        //close eXist curl
        curl_close($putToExist);
        
        //close files
        fclose($readFile);
    }
}

/***** PUBLICATION AND REPORTING FUNCTIONS *****/
//generate a shell script to activate batch ingestion
function generate_solr_shell_script($array, $collection){
    $uniqid = uniqid();
    $solrDocUrl = NUMISHARE_URL . $collection . '/ingest?identifiers=' . implode('%7C', $array);
    
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


?>