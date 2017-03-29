<?php
/* ping.php
 *  Pings all monitored IP addresses
 */

include "_functions.php";
//include "_traceroute.php";

// Variables
$ipList = "";


// Create the device list with pingable IP's
$arrDevices = getDevicesArray();
foreach ($arrDevices as $field => $value) {
    //echo '\n'.$field." = ".$value['Monitoring']['Ping']."\n";
    if ($value['Monitoring']['Ping']=="yes") {
        $ipList .= $field."\n";
    }
}
//echo print_r($deviceList, true);



nmapScanAndParseTo($pingOutFile, $ipList, $ipListFile);



function nmapScanAndParseTo($outFile, $ipList, $ipListFile) {
    // Write the file to be used with nmap
    file_put_contents($ipListFile, $ipList);

    // Load previous results into an array
    if (!file_exists($outFile)) file_put_contents($outFile,"");
    $arrPingOld = json_decode(file_get_contents($outFile),true);

    // Create the initial new array and set each result as unreachable since unreachable nodes won't show a result.
    $arrPingNew = [];
    foreach (explode("\n",$ipList) as $key=>$val) {
        if ($val!='') $arrPingNew[$val] = "down";
    }

    // Run the command and parse
    $res = shell_exec("nmap -sn -iL $ipListFile");
    $rows = explode("\n", $res);
    for ($i=0;$i<sizeof($rows);$i++) {
        $look = "Nmap scan report for ";
        if (strpos($rows[$i],$look)) {
            //found a result, first get the device name / ip
            $dev = get_string_between($rows[$i],$look," (");

            //now get the latency and convert to milliseconds
            $i++;
            $latency = floatval(get_string_between($rows[$i],"(","s")) * 1000;

            //add to array
            $arrPingNew[$dev] = $latency;
        }
    }

    //write the output in json format
    file_put_contents($outFile,json_encode($arrPingNew));

    /*
     * TODO: NOW WE CAN COMPARE THE OLD AND NEW FILES AND WRITE TO THE CHANGE LOG
     */
    // detectChangesForPing($arrPingOld, $arrPingNew) // This function will trigger an alert action which will run a traceroute

}



// NOTE: Old Function, not used, here only for reference.
function fpingScanAndParseTo($pingFile) {
    $res = shell_exec("fping -e -f $ipListFile");
    echo $res;

    //put the output into an array for easier parsing
    $rows = explode("\n", $res);

    //load previous results into an array
    if (!file_exists($pingFile)) file_put_contents($pingFile,"");
    $arrPingOld = json_decode(file_get_contents($pingFile),true);

    //cycle through the results
    foreach($rows as $row) {
        $row = trim($row);
        $sIp = trim(substr($row,0,stripos($row," ")));

        if ($sIp!="") {
            //$pstat = getSqlValue("SELECT pingstatus FROM devicelist WHERE ip='$sIp' limit 1");
            $pstat = $arrPingOld["$sIp"];
            echo "old ping for $sIp = $pstat\n";

            if (strrpos($row,"(")>0) {
                //ping was a success
                $sPing = substr($row,strrpos($row,"(")+1,-1); //the response time

                //if there's a failed traceroute, lets move the file since we can now get to it.
                $file_tr = "$gFolderScanData/traceroute_".$sIp."_fail";
                if (file_exists($file_tr)) rename($file_tr,$file_tr."_".date("Y-m-d_H.i.s"));

                //If the state changed, lets do some stuff
                if ($pstat == "unreachable" || $pstat == "") {
                    //the state is changing from a ping to unavailable, lets do the following once
                    doTraceroute($sIp);
                    writeLog("ping",$sIp,"Device changed state to UP.");
                }

            } else {
                //ping failed
                $sPing = "unreachable";

                //start a traceroute, if one wasn't performed already, so we can see where the break happened
                if ($pstat != "unreachable" && $pstat != "") {
                    //the state is changing from a ping to unavailable, lets do the following once
                    //if (!file_exists($file_tr.$sIp."_fail")) doTraceroute($sIp); //this is included from .traceroute.php
                    doTraceroute($sIp);
                    writeLog("ping",$sIp,"Device changed state to DOWN.");
                }

                //add an alert or log here... or rather, at the end call the alerts check
            }

            //lets add the ping stats to the devicelist table
            echo "New Ping for $sIp = $sPing\n";
            $arrPingNew[$sIp] = $sPing;
            //$qry = "UPDATE devicelist SET pingstatus='$sPing', pingtimestamp=NOW() WHERE ip='$sIp'";
            //$retval = queryMysql($qry);
            //echo " - ret = $retval";

            //add to the history table
            $qry = "INSERT INTO history_ping (device, value) VALUES ('$sIp', '$sPing');";
            $retval = queryMysql($qry);
        }
    }

    //write the current ping stats
    file_put_contents($pingFile,json_encode($arrPingNew));
}

//alerts should be called here so if a device goes down and then back up, we can determine that it came back up.
//alerts_check_ping();
//	**** The alert should cycle through each IP and check for changes in status. If a change is made then:
//		1. write to the log for that device
//		2. either create or remove the alert depending on the new status
//			3a. If its creating a new alert then make sure only to call an action once per x hours/days (set in admin settings)
//			3b. the alert should: call a traceroute to see where the interruption is, write a log entry, send email, add entry in alert sql db

?>