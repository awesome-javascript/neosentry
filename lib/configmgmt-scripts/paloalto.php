#!/usr/bin/php
<?php

error_reporting(E_COMPILE_ERROR);


// Collects the firewall and NAT rules from a Palo Alto device

/* An example format is:
{
    "Firewall Rules": {
        "rule-1": {
            "Disabled": false,
			"Rulenum": 1,
			"Hits": 0,
			"Name": "",
			"Source": ["101.200.173.48\/32"],
			"Destination": ["Any"],
			"VPN": "Any",
			"Services": ["Any"],
			"Action": "drop",
			"Track": "Log",
			"Install On": "Test-Firewall",
			"Time": ["Any"]
		}
    },
	"NAT Rules": {
        "rule-1": {
            "Disabled": false,
			"Hits": 0,
			"Name": "",
			"Original Source": ["192.168.0.111\/32"],
			"Original Destination": ["Any"],
			"Original Service": ["Any"],
			"Translated Source": ["!method_hide!",  "10.10.10.10\/32"],
			"Translated Destination": ["!method_static!", "Any"],
			"Translated Services": ["!method_static!", "Any"],
			"Install On": "Any"
		}
    },
    "Configuration": "String of configuration output, or optionally an array. each array entry will be a row"
}
*/


set_include_path('%includes%/phpseclib'); //required since these libraries include other libraries
include('Net/SSH2.php');
include('Net/SCP.php');


//this script will be copied to ~/data/devices/%device%/tmp so lets use this scripts current folder as a scratch directory
$scratchFolder = dirname(__FILE__);    //a temporary working directory to store and work with files.
const LOG_FILE = "configuration.log";

// these %variable% strings will be replaced with the appropriate informatoin before running.
$device = "%device%";           //the device IP or hostname we're connecting to
$username = "%username%";
$password = "%password%";
$password2 = "%password2%";     //this 2nd password is an optional variable only used if a 2nd level password is needed


// print the json of the data. The return data will be compared to the previous data for configuration change tracking
// and will be written to the configuration storage file/location/db-table/etc.
$configArr = runCollector($device, $username, $password, $password2);

//print the output so the parent program can collect it.
if (is_array($configArr)) echo json_encode($configArr,JSON_PRETTY_PRINT);
else echo $configArr;



exit;


// ALL OTHER FUNCTIONS BELOW THIS

function runCollector($device, $username, $password, $password2="") {
    /* palo collection commands:
        set cli pager off   //without this you can pipe to the except command to bypass pagers. ex: | except "some text not found"
        show config merged
        show running nat-policy
        show running security-policy
    */

    global $scratchFolder;
    $arrConfig = [];

    outputText("connecting to $device");
    $ssh = new Net_SSH2($device);
    if (!$ssh->login($username, $password)) {
        return "Error: Login Failed using username '$username'. " . $ssh->getLastError();
    }
    $scp = new Net_SCP($ssh);


    //turn off paging so we get the whole output at once without line breaks
    $ret = sshRunCommand($ssh,'set cli pager off');

    //get Configuration
    $ret = trim(sshRunCommand($ssh,'show config merged')); //get the local policy and panorama policy
    file_put_contents($scratchFolder."/collected_configuration.original",$ret);
    $arrConfig["Configuration"] = $ret;

    //get NAT Rules
    $ret = trim(sshRunCommand($ssh,'show running nat-policy'));
    file_put_contents($scratchFolder."/collected_nat_rules.original",$ret);
    $outputArr = explode("\n",$ret);
    $arrRules["nat"] = paloJsonToArray($outputArr);
    file_put_contents($scratchFolder."/collected_nat_rules.parsed",json_encode($arrRules["nat"],JSON_PRETTY_PRINT));

    $cnt = 0;
    foreach ($arrRules["nat"] AS $key => $item) {
        $id = $key; //key($item);
        $section = "NAT Rules";
        $arrConfig[$section][$id]["Disabled"] = isset($item["disabled"]) ? $item["disabled"] : "no";
        $arrConfig[$section][$id]["Rulenum"] = $cnt;
        $arrConfig[$section][$id]["Hits"] = 0;
        $arrConfig[$section][$id]["Name"] = $key;
        $arrConfig[$section][$id]["Original Source"] = $item['source'];
        $arrConfig[$section][$id]["Source Zone"] = $item['from'];
        $arrConfig[$section][$id]["Original Destination"] = $item['destination'];
        $arrConfig[$section][$id]["Destination Zone"] = $item['to'];
        $arrConfig[$section][$id]["Original Service"] = $item['service'];
        $arrConfig[$section][$id]["Translated To"] = $item['translate-to'];
        $arrConfig[$section][$id]["Nat Type"] = $item['nat-type'];

    }



    //get Firewall Rules
    $ret = trim(sshRunCommand($ssh,'show running security-policy'));
    file_put_contents($scratchFolder."/collected_firewall_rules.original",$ret);
    $outputArr = explode("\n",$ret);
    $arrRules["fw"] = paloJsonToArray($outputArr);
    file_put_contents($scratchFolder."/collected_firewall_rules.parsed",json_encode($arrRules["fw"],JSON_PRETTY_PRINT));

    $cnt = 0;
    foreach ($arrRules["fw"] AS $key => $item) {
        $id = $key; //key($item);
        $section = "Firewall Rules";
        $arrConfig[$section][$id]["Disabled"] = isset($item["disabled"]) ? $item["disabled"] : "no";
        $arrConfig[$section][$id]["Rulenum"] = $cnt;
        $arrConfig[$section][$id]["Hits"] = 0;
        $arrConfig[$section][$id]["Name"] = $key;
        $arrConfig[$section][$id]["Source"] = $item['source'];
        $arrConfig[$section][$id]["Source Zone"] = $item['from'];
        $arrConfig[$section][$id]["Destination"] = $item['destination'];
        $arrConfig[$section][$id]["Destination Zone"] = $item['to'];
        $arrConfig[$section][$id]["Destination Region"] = $item['destination-region'];
        $arrConfig[$section][$id]["Services"] = $item['application/service'];
        $arrConfig[$section][$id]["Action"] = $item['action'];
        $arrConfig[$section][$id]["User"] = $item['user'];
        $arrConfig[$section][$id]["Category"] = $item['category'];
        $arrConfig[$section][$id]["Terminal"] = $item['terminal'];

        $cnt++;
    }


    //traffic logs for rule hit count?
    //show log traffic
    $stime = file_exists($scratchFolder."/collected_logs.out")?filemtime($scratchFolder."/collected_logs.out"):0;
    $etime = filemtime($scratchFolder."/collected_firewall_rules.out");
    $dayAgo = $etime - 86400;
    if ($stime < $dayAgo || $stime >= $etime - 60) $stime = $dayAgo; //we only want to collect a max of 24 hours of logs due to resource utilization
    $strStart = date("Y/M/d@h:m:s", $stime);
    $strEnd = date("Y/M/d@h:m:s", $etime);
    $cmd = 'show log traffic start-time equal ' . $strStart . ' end-time equal ' . $strEnd . ' csv-output equal yes';
    outputText("Collecting logs to see hitcounts: " . $cmd);
    $ret = "";
    //$ret = sshRunCommand($ssh,$cmd,1800);
    foreach (explode("\n",$ret) as $line) {
        $a = explode(",",$line);
        $rulename = isset($a[11]) ? $a[11] : "";
        if (isset($arrConfig["Firewall Rules"][$rulename])) $arrConfig["Firewall Rules"][$rulename]["Hits"]++;
    }


    //return the array
    return $arrConfig;
}

function sshRunCommand($sshSession, $cmd, $timeout = 10){
    static $sshReadTo;
    if (!isset($sshReadTo)) {
        //get the command prompt
        outputText("Detecting prompt...");
        $sshSession->read(); //clear out the buffer
        $sshSession->write("\n");
        $sshSession->setTimeout(3);
        $read = "\n" . $sshSession->read('>');
        $sshReadTo = trim(substr($read, strrpos($read,"\n")+1));
        outputText("Found prompt: $sshReadTo");
    }

    //run the command
    outputText("> ".$cmd);
    outputText("+ Reading until prompt: $sshReadTo");
    $sshSession->write($cmd);
    $sshSession->read(); //clear out the command echo
    $sshSession->write("\n");
    $sshSession->setTimeout($timeout);
    $ret = $sshSession->read($sshReadTo); //$ssh->read('_.*@.*[$#>]_', NET_SSH2_READ_REGEX);
    //if($sshSession->isTimeout()) { outputText("+ Timeout reached."); $sshReadTo = substr($ret, strrpos($ret,"\n")+1); }
    $ret = str_replace($sshReadTo,"",$ret); //remove the prompt

    outputText("+ " . strlen($ret) . " bytes read.");
    //outputText("+ Prompt is now: $sshReadTo");
    return $ret;

}
function outputText($string){
    //echo $string . "\n";
    file_put_contents(LOG_FILE,date(DATE_ATOM) . ": " . $string . "\n",FILE_APPEND);
}

function paloJsonToArray(&$outputArray) {
    static $level = 0; //the inception level
    $retArr = [];

    $level++;
    outputText("starting json conversion on level $level");
    do {
        //echo "in do statement\n";
        while (count($outputArray) > 0) {
            $line = trim(array_shift($outputArray));
            //outputText("processing line: $line");
            if ($line == "}") break;

            //split the line by words
            preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', $line, $words);
            $words = isset($words[0]) ? $words[0] : [""]; //each parenthesized pattern gets its own array, we only have 1 () so lets move it over
            //print_r($words);

            //get the array key and value
            $f = substr($words[0], 0, 1);
            $key = ($f == '{') ? 0 : trim(array_shift($words), '"');
            $val = trim(array_shift($words), ';"');
            if ($val == "[") {
                array_pop($words); //remove the last "];"
                $val = $words; //set the value to the array
                $words = [];
            }

            //assign
            if ($key != "") {
                $retArr[$key] = ($val == "{") ? paloJsonToArray($outputArray) : $val;
            }

        }
    } while($level <= 1 && count($outputArray) > 0);
    $level--;


    return $retArr;

}