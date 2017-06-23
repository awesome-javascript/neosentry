<?php
/**
 * Used to get raw data for the front end to display.
 * For an example of a REST api in php see: https://www.leaseweb.com/labs/2015/10/creating-a-simple-rest-api-in-php/
 *
 * This requires no other files or folders within this path
  */

//required libraries
include "../../lib/_functions.php";
include "../../lib/_db_flatfiles.php";

//start the session and require authentication

sessionStart();
sessionProtect(true,''); //redirect to the login page if not logged in (aka no roles assigned)


// get the HTTP method, path and body of the request
$remoteIP = $_SERVER['REMOTE_ADDR'];
$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'],'/') : "";
$request = explode('/', $path);
$input = json_decode(file_get_contents('php://input'),true);
//print_r($_SERVER);

// retrieve the table and key from the path. in /api/device/10.1.1.1 :: $table='device', $key='10.1.1.1'
$table = preg_replace('/[^a-z0-9_]+/i','',array_shift($request));
$key = trim(substr(array_shift($request),0,128));  // to get only a number use: array_shift($request)+0;

//if a url path wasn't used, then lets try the GET variables
if ($table=='') $table = trim(substr(filter_input(INPUT_GET, 'table', FILTER_SANITIZE_STRING),0,64));
if ($key=='') $key = trim(substr(filter_input(INPUT_GET, 'key', FILTER_SANITIZE_STRING),0,128));

//look for additional GET variables
$apiIP = trim(substr(filter_input(INPUT_GET, 'ip', FILTER_SANITIZE_STRING),0,128));
$apiSearch = trim(substr(filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING),0,512));


//perform actions based on what REST action the user wants to take
$retJson = '';
switch ($method) {
  case 'GET':
    //first handle special case GET requests
    if ($table == "sessiondata") {
      $retJson = json_encode($_SESSION);
    } elseif ($table == "dashboard") {
      $diskUsed[0] = round(1-disk_free_space(getcwd())/disk_total_space(getcwd()),4) * 100;
      $diskUsed[1] = "Used " . $diskUsed[0] . "% of " . round(disk_total_space(getcwd()) / 1024 / 1024 / 1024,1) . "Gb, " . round(disk_free_space(getcwd()) / 1024 / 1024 / 1024,1) . "Gb Free" ;
      $ramUsed[0] = memory_get_usage(true);
      $ramUsed[1] = "Used " . $ramUsed[0] / 1024 / 1024 . "Mb";
      $cpuUsed[0] = rand(0,100);//sys_getloadavg();
      $cpuUsed[1] = "Used ".$cpuUsed[0]."% of 2Ghz";
      $retJson = '{"Server Stats":{"serverHD":['.$diskUsed[0].',"'.$diskUsed[1].'"],"serverRAM":['.$ramUsed[0].',"'.$ramUsed[1].'"],"serverCPU":['.$cpuUsed[0].',"'.$cpuUsed[1].'"]}}';

    } elseif ($table == "devices") {
    	if ($key == '') {
        //get all devices
        $devices = getDevicesArray(); $retArr = [];
        foreach ($devices as $dev=>$devData) {
          $devices[$dev]["ping"] = getDeviceData($dev,ACTION_PING);
          if ( !isset($devices[$dev]["ip"]) ) $devices[$dev]["ip"] = $dev;
          $retArr[] = $devices[$dev];
        }
        $retJson = json_encode($retArr);
        //$ret["columns"] =

      } else {
        //get the one device
        $dev["settings"] = getDeviceSettings($key);
				$dev["data"]["Ping"] = getDeviceData($key, ACTION_PING);
        $dev["data"]["Traceroute"] = getDeviceData($key, ACTION_TRACEROUTE);
        $dev["data"]["Configuration"] = getDeviceData($key, ACTION_CONFIGURATION);
        $dev["data"]["SNMP"] = getDeviceData($key, ACTION_SNMP);

        $retJson = json_encode($dev);

      }

    } elseif ($table == "settings") {
        $ret = getSettingsArray();
        foreach ($ret[SETTING_CATEGORY_PROFILES] as $k=>$v) {
            if (is_array($v)) $ret[SETTING_CATEGORY_PROFILES][$k]['password'] = '';
        }
        $ret["Users"] = getUsers();
        foreach ($ret["Users"] as $k=>$user) {
            unset($ret['Users'][$k]["api_key"]);
            unset($ret['Users'][$k]['password']);
        }

        $retJson = json_encode($ret);

    } else { //get the table requested

    }
    break;


  case 'PUT':     // UPDATE
    sessionProtect(false,ROLE_ADMIN);

    break;

  case 'POST':    // CREATE New / Overwrite Old
    sessionProtect(false,ROLE_ADMIN);

    break;

  case 'DELETE':
    sessionProtect(false,ROLE_ADMIN);

    break;
}

//FOR TESTING
if ($retJson=='') $retJson = json_encode(array( "date_atom" => date(DATE_ATOM), "method" => $method, "request" => $request, "path" => $path, "input"=> $input, "table" => $table, "key" => $key, "remote-ip" => $remoteIP ));

//SHOW THE JSON
echo $retJson;
//print_r($_SERVER);

