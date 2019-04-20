<?php
//This file contains the debug and dev settings of the api
ini_set('display_errors','1'); // set to 0 in production

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require __DIR__ . '/vendor/autoload.php'; // Including composer dependencies

//Get Slim Settings
$slimsettings = require __DIR__ . '/config/config_slim.php';
$app = new \Slim\App($slimsettings);
//gvsComment
//set CrossDomain settings
// $corsOptions = array(
//     "origin" => "*",
//     "exposeHeaders" => array("Content-Type", "X-Requested-With", "X-authentication", "X-client"),
//     "allowMethods" => array('GET', 'POST', 'PUT', 'DELETE', 'OPTIONS')
// );
// $cors = new \CorsSlim\CorsSlim($corsOptions);
// $app->add($cors); // Fix for SlimFramework to allow cross domain requests
//gvsComment
// Set up dependencies
require __DIR__ . '/config/dependencies.php';

//Default route
$app->get('/', function ($request, $response, $args) {
// Render index view
    return $this->renderer->render($response, 'index.html', $args);
});
    //version group
    $app->group('/v1', function () use ($app) {
        $app->get('/about', 'about');
        $app->post('/login', 'authLogin');
        $app->get('/qrcode/{id}', 'fetchDetails');
    });
    $app->group('/v2', function() use ($app) {
        $app->get('/qrcode/{id}', 'fetchDetails2');
    });

// Check ./components/ for the functions.
$app->run();
// get db connections
function getConnection() {
    $dbhost="localhost";
    $dbuser="root";
    $dbpass="";
    $dbname="emeds";
    $dbh = new PDO("mysql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $dbh;
}

// about '/about' route function
function about($request, $response) {
    $rs = array(
        "name" =>"WhiteHats CSIR1 Mobile API",
        "version" => "0.0.2",
        "author" => "Guarav Sukhatme",
        "release" => "April 17, 2019",
    );
    $rs = json_encode($rs);
    return $rs;
}

// authLogin '/login' route function
function authLogin($request, $response) {
    $db = getConnection();
    $json = $request->getBody();
    $data = json_decode($json);
    $user = $data->user;
    $pass = md5($data->pass);
    $type = $data->type;
    if($type == "doctor") {
        $query = "SELECT * FROM doctors WHERE user=':user' AND pass=':pass'";
    } elseif($type == "patient") {
        $query = "SELECT * FROM patients WHERE user=':user' AND pass=':pass'";
    }
    $stmt = $db->prepare($query);
    $stmt->bindParam("user", $user);
    $stmt->bindParam("pass", $pass);
    $stmt->execute();
    $fetch = $stmt->fetchAll(PDO::FETCH_OBJ);
    $db = null;
    return json_encode($fetch);
}

// fetchDetails '/qrcode' route function
//NOTE : This is the main function of the API, WRITE CAREFULLY
function fetchDetails($request, $response) {
    $id = $request->getAttribute('id');
    $db = getConnection();
    //fetch the prescription details from the db
    $query = "SELECT * FROM prescriptions WHERE qrcode='$id'"; //get the pres from qrcode
    $stmt = $db->prepare($query);
    $stmt->execute();
    $fetch = $stmt->fetchAll(PDO::FETCH_OBJ);
    $fetch_arr = json_decode(json_encode($fetch), true); //a small fix to effectively convert the object to associative array
    $arr_pres = $fetch_arr[0]; //a small fix for fetchAll OBJ
    $arr_pres_name = $arr_pres['arr_name']; //set the file name of pres json
    $file_pres = '../web/data/prescriptions/'.$arr_pres_name; //construct the pres.json path
    $json_pres = file_get_contents($file_pres); //read the json file
    $arr_pres = json_decode($json_pres, true); //convert the json to array
    $medicines = $arr_pres['medicines']; //array to contain all the medicine names
    $m_ids = array(); //array to contain all the medicine ids used
    $m_price = array(); //array to contain the prices of all the medicines
    //Below code is to fetch the m_id,salts and prices of all the medicines in the prescription
    $query="SELECT * FROM medicines WHERE salt=:name"; //select the medicine via their salts
    $stmt=$db->prepare($query);
    foreach($medicines as $m_name) {
        $stmt->bindParam("name", $m_name);
        $stmt->execute();
        $m = $stmt->fetchAll(PDO::FETCH_OBJ);
        $m_arr = json_decode(json_encode($m), true);
        array_push($m_ids,$m_arr[0]['m_id']);
        $m_price[$m_arr[0]['m_id']] = $m_arr[0]['price'];
    }
    $arr_med_rs = array(); //array to containt the response array of medicines
    for($i = 0; $i<sizeof($medicines); $i++){
        $arr_med_rs[$m_ids[$i]] = $medicines[$i];
    }
    //get inventory json
    $file_inv = '../web/data/inventory/inventory.json'; // construct the file name of inventory json
    $json_inv = file_get_contents($file_inv); //read the json file
    $arr_inv = json_decode($json_inv, true); //convert json to array
    $arr_stores_av = array(); //array to contain the s_id of all the stores having the medicines
    for($i = 0; $i < sizeof($m_ids); $i++){
        $arr_stores_av[$m_ids[$i]] = $arr_inv[$m_ids[$i]];
    }
    $arr_store_ids_temp = array();
    $arr_store_ids = array(); //array to contain the store details of all the stores where the medicine is availabe
    foreach($arr_stores_av as $m_id => $s_id) {
        foreach($s_id as $s) {
            array_push($arr_store_ids_temp, $s);
        }
    }
    $arr_store_ids = array_unique($arr_store_ids_temp); //Contains all the unique values for stores
    $arr_store_ids_temp = null;
    $arr_store_details = array(); //contains the stores details ex LAT, LON etc
    //Get all the store details from the database
    $query = "SELECT * FROM stores WHERE s_id=:s_id";
    $stmt = $db->prepare($query);
    foreach($arr_store_ids as $s_id) {
        $stmt->bindParam("s_id", $s_id);
        $stmt->execute();
        $s_obj = $stmt->fetchAll(PDO::FETCH_OBJ);
        $s_arr = json_decode(json_encode($s_obj), true);
        $arr_store_details[$s_id] = $s_arr;
    }

    $arr_response = array(
        "pres_details" => $arr_pres,
        "medicines" => $arr_med_rs,
        "m_price" => $m_price,
        "stores_av" => $arr_stores_av,
        "stores_details" =>$arr_store_details,
    );
    return json_encode($arr_response);
}
//A very usefull functions to reverse an array
function reverse_array($array) {
    $return = array();
    foreach($array as $lhs => $rhs) {
        $return[$rhs] = $lhs;
    }
    return $return;
}
//Friday Mar 30, 17:31
function fetchDetails2($request, $response) {
    $id = $request->getAttribute('id');
    $db = getConnection();
    $query = "SELECT * FROM prescriptions WHERE qrcode='$id'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $fetch = $stmt->fetchAll(PDO::FETCH_OBJ);
    $fetch_arr = json_decode(json_encode($fetch), true);
    $arr_pres = $fetch_arr[0];
    $arr_pres_name = $arr_pres['arr_name'];
    $file_pres = '../web/data/prescriptions/'.$arr_pres_name;
    $json_pres = file_get_contents($file_pres);
    $arr_pres = json_decode($json_pres, true);
    $medicines = $arr_pres['medicines'];
    $m_ids = array();
    $sub_ids = array(); //This is new
    $m_salts = array(); // This is new
    $m_price = array();
    $query = "SELECT * FROM medicines WHERE name=:name";
    $stmt = $db->prepare($query);
    foreach($medicines as $m_name) {
        $stmt->bindParam("name", $m_name);
        $stmt->execute();
        $m = $stmt->fetchAll(PDO::FETCH_OBJ);
        $m_arr = json_decode(json_encode($m), true);
        array_push($m_ids, $m_arr[0]['m_id']);
        $m_price[$m_arr[0]['m_id']] = $m_arr[0]['price'];
        $m_salts[$m_arr[0]['m_id']] = $m_arr[0]['salt']; // This is new
    }
    $arr_med_rs = array();
    for($i = 0; $i<sizeof($medicines); $i++) {
        $arr_med_rs[$m_ids[$i]] = $medicines[$i];
    }
    $med_rev = reverse_array($arr_med_rs);
    //To check for subtitutes
    $query = "SELECT * FROM medicines WHERE salt=:salt";
    $stmt = $db->prepare($query);
    foreach($m_salts as $m_id => $ms) {
        $stmt->bindParam("salt", $ms);
        $stmt->execute();
        $r = $stmt->fetchAll(PDO::FETCH_OBJ);
        $r = json_decode(json_encode($r), true);
        //$sub_ids = $r;
        $sub_ids[$m_id] = $r;
    }
    //get inventory json
    $file_inv = '../web/data/inventory/inventory.json'; // construct the file name of inventory json
    $json_inv = file_get_contents($file_inv); //read the json file
    $arr_inv = json_decode($json_inv, true); //convert json to array
    $arr_stores_av = array(); //array to contain the s_id of all the stores having the medicines
    for($i = 0; $i < sizeof($m_ids); $i++){
        $arr_stores_av[$m_ids[$i]] = $arr_inv[$m_ids[$i]];
    }
    $arr_store_ids_temp = array();
    $arr_store_ids = array(); //array to contain the store details of all the stores where the medicine is availabe
    foreach($arr_stores_av as $m_id => $s_id) {
        foreach($s_id as $s) {
            array_push($arr_store_ids_temp, $s);
        }
    }
    $arr_store_ids = array_unique($arr_store_ids_temp); //Contains all the unique values for stores
    $arr_store_ids_temp = null;
    $arr_store_details = array(); //contains the stores details ex LAT, LON etc
    //Get all the store details from the database
    $query = "SELECT * FROM stores WHERE s_id=:s_id";
    $stmt = $db->prepare($query);
    foreach($arr_store_ids as $s_id) {
        $stmt->bindParam("s_id", $s_id);
        $stmt->execute();
        $s_obj = $stmt->fetchAll(PDO::FETCH_OBJ);
        $s_arr = json_decode(json_encode($s_obj), true);
        $arr_store_details[$s_id] = $s_arr;
    }

    $arr_response = array(
        "pres_details" => $arr_pres,
        "medicines" => $arr_med_rs,
        "m_price" => $m_price,
        "stores_av" => $arr_stores_av,
        "stores_details" =>$arr_store_details,
        "subtitutes" => $sub_ids
    );
    return json_encode($arr_response);
}
?>

