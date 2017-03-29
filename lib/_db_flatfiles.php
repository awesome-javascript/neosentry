<?php

/**  Functions:
 *      writeLog(), getLog()
 *      updateDeviceData(), getDeviceData()
 *      updateDeviceHistory(), getDeviceHistory()
 */

include_once "_functions.php";



// FUNCTIONS FOR LOGGING INFORMATION //

/** Writes to the devices log file at /data/devices/[device]/log.log
 * @param string $category mapped to the data collections of the device, like [routes, if_table, configuration, ping, general, etc]
 * @param string $device the device name or ip we're updating.
 * @param string $text = a brief summary
 * @param string $beforeChange = [optional] if this is a change log then the old value of the data
 * @param string $afterChange = [optional] new value of the data that was changed
 * @return boolean whether it successfully wrote to the log or not
 */
function writeLog($category, $device, $text, $beforeChange = "", $afterChange = "") {
    //These logs will be stored in the devices data folder. each line entry will be a json array
    global $gFolderScanData;

    //create the data array and convert it to json
    $arrData = array(
        'date'=>date(DATE_ATOM),
        'category'=>$category,
        'text'=>$text,
        'before'=>$beforeChange,
        'after'=>$afterChange);
    $data = json_encode($arrData);

    //ensure there's a file to write to
    $logFolder = $gFolderScanData."/".$device;
    $logFile = $logFolder."/log.log";
    if (!file_exists($logFolder)) mkdir($logFolder);
    if (!file_exists($logFile)) touch($logFile);

    //write the log entry
    $retval = file_put_contents($logFile,$data."\n",FILE_APPEND);

    return $retval;
}

/** returns an array
 * @param string $device The device name or ip
 * @param integer $lineLimit = the number of lines in the log to return. default is all lines.
 * @return array The array of log entries in chronological order
 */
function getLog($device, $lineLimit = 0) {
    global $gFolderScanData;
    $logFile = $gFolderScanData."/".$device."/log.log";
    if (!file_exists($logFile)) return ""; //exit if the file doesn't exist

    //get the data we're working with
    $data = "";
    if($lineLimit > 0) {
        $data = shell_exec('tail -n '.$lineLimit.' '.$logFile);
    } else {
        $data = file_get_contents($logFile);
    }

    //format, sort, and return the reversed (chronologically sorted) array
    $arrData = json_decode("{[".$data."]}", true);
    return array_reverse($arrData); //the entries are added chronologically, so all we have to do is reverse the array, instead of sort

}


// FUNCTIONS FOR WRITING AND READING DEVICE DATA

/** writes a json file with variable data in the device data directory
 */
function updateDeviceData($device, $category, $arrayOfData) {
    global $gFolderScanData;
    $dataFile = $gFolderScanData."/".$device."/device_data.json";

    //add a "last updated" field
    $arrayOfData['date'] = date(DATE_ATOM);

    //read the data, update it and write it back to the file
    $dataArr = json_decode(file_get_contents($dataFile), true);
    $dataArr[$category] = $arrayOfData;
    $retval = file_put_contents($dataFile,json_encode($dataArr));

    return $retval;
}

/** returns the array of data
 */
function getDeviceData($device) {
    global $gFolderScanData;
    $dataFile = $gFolderScanData."/".$device."/device_data.json";

    return json_decode(file_get_contents($dataFile), true);
}

/** writes a new json line to the history file for that category
 */
function updateDeviceHistory($device, $category, $arrayOfData) {
    global $gFolderScanData;
    $dataFile = $gFolderScanData."/".$device."/device_history_".$category.".json";

    //Make sure the file exists
    if (!file_exists($dataFile)) touch($dataFile);

    //write the log entry
    $arrayOfData['date'] = date(DATE_ATOM);
    $retval = file_put_contents($dataFile,json_encode($arrayOfData)."\n",FILE_APPEND);
    return $retval;
}

/** returns an array of the device history
 */
function getDeviceHistory($device, $category, $lineLimit = 0) {
    global $gFolderScanData;
    $dataFile = $gFolderScanData."/".$device."/device_history_".$category.".json";

    //get the data we're working with
    $data = "";
    if($lineLimit > 0) {
        $data = shell_exec('tail -n '.$lineLimit.' '.$dataFile);
    } else {
        $data = file_get_contents($dataFile);
    }

    //format, sort, and return the reversed (chronologically sorted) array
    $arrData = json_decode("{[".$data."]}", true);
    return array_reverse($arrData); //the entries are added chronologically, so all we have to do is reverse the array, instead of sort
}



// MISCELLANEOUS FUNCTIONS

function compareArrays($oldTable, $newTable, $uniqueColumn, $logColumns, $device, $logGroup, $deleteFromTable) {
    //usage: compareArrays($oldArr, $newArr, "ifIndex", array('col1','col2',..), "10.11.12.13", "interface", "device_iftable");

    $removedEntries = $addedEntries = $changedEntries = "";
    echo date("Y-m-d H:i:s").": Logging any changes. Old Table had ".count($oldTable)." Rows. New table has ".count($newTable)." Rows.\n";

    //flatten the unique column for easy comparing
    $oldFlatIndex = $newFlatIndex = "";
    foreach ($oldTable as $i) $oldFlatIndex .= "(".getArrayVal($i,$uniqueColumn).")";
    foreach ($newTable as $i) $newFlatIndex .= "(".getArrayVal($i,$uniqueColumn).")";

    //see if any rows were REMOVED
    foreach($oldTable as $row) {
        //for ($a=0;$a<count($oldTable);$a++) {
        $oldIndx = getArrayVal($row,$uniqueColumn);

        if (strpos($newFlatIndex,"(".$oldIndx.")")===false) {
            //this index was REMOVED
            $removedEntries .= "<tr><td>".getArrayVal($row,$uniqueColumn)."</td>";
            foreach ($logColumns as $lc) $removedEntries .= "<td>".getArrayVal($row,$lc)."</td>";
            $removedEntries .= "</tr>";
            echo "$uniqueColumn ".$oldIndx." was REMOVED\n";

            //delete from sql if specified
            if ($deleteFromTable != "") queryMySql("DELETE FROM $deleteFromTable WHERE device='$device' AND $uniqueColumn = $oldIndx;");
        }
    }
    if ($removedEntries != "") $removedEntries = "<table><tr><td>$uniqueColumn</td><td>".implode("</td><td>",$logColumns)."</td></tr>$removedEntries</table>";

    //see if any rows were ADDED
    foreach($newTable as $row) {
        //for ($a=0;$a<count($newTable);$a++) {
        $newIndx = getArrayVal($row,$uniqueColumn);

        if (strpos($oldFlatIndex,"(".$newIndx.")")===false) {
            //this index was ADDED
            $addedEntries .= "<tr><td>".getArrayVal($row,$uniqueColumn)."</td>";
            foreach ($logColumns as $lc) $addedEntries .= "<td>".getArrayVal($row,$lc)."</td>";
            $addedEntries .= "</tr>";
            echo "$uniqueColumn ".$newIndx." was ADDED\n";
        }
    }
    if ($addedEntries != "") $addedEntries = "<table><tr><td>$uniqueColumn</td><td>".implode("</td><td>",$logColumns)."</td></tr>$addedEntries</table>";


    //see if any rows CHANGED
    $monitorColumns = $logColumns;
    foreach($newTable as $header => $val) {
        //for ($a=0; $a < count($newTable); $a++) {
        if (array_key_exists($header,$oldTable)) {
            $row = ""; $hasChanged = false;
            foreach ($monitorColumns as $mc) {
                $oldVal = getArrayVal($oldTable[$header],$mc); $newVal = getArrayVal($val,$mc);
                if ($oldVal != "" && $newVal != $oldVal) {
                    $hasChanged = true;
                    $row .= "<td>'$oldVal' -> '<b>$newVal</b>'</td>";
                    echo "Value CHANGED from '$oldVal' to '$newVal' on $uniqueColumn ".$val[$uniqueColumn]."\n";
                } else $row .= "<td></td>";
            }
            if ($hasChanged) $changedEntries .= "<tr><td>".$val[$uniqueColumn]."</td>$row</tr>";
        }
    }
    if ($changedEntries != "") $changedEntries = "<table><tr><td>$uniqueColumn</td><td>".implode("</td><td>",$monitorColumns)."</td></tr>$changedEntries</table>";

    //write to the log file
    if ($removedEntries !="") writeLog($logGroup,$device,"The following $logGroup rows have been REMOVED:<br><br>".trim($removedEntries));
    if ($addedEntries !="") writeLog($logGroup,$device,"The following $logGroup rows have been ADDED:<br><br>".trim($addedEntries));
    if ($changedEntries != "") writeLog($logGroup,$device, "The following $logGroup rows have CHANGED:<br><br>".trim($changedEntries));

}