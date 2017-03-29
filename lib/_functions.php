<?php //functions.php

ini_set('display_errors', 1); 
error_reporting(E_ALL);

date_default_timezone_set('America/New_York');

//$appname = 'qNMS';
$globalErrorVar = '';


// Global variables
$gFolderData = realpath("../data");
$gFolderLogs = realpath("$gFolderData/logs");   //stores logs for the app and system. device logs stored in db or device folder
$gFolderScanData = realpath("$gFolderData/devices");
$gFolderBackups = realpath("$gFolderData/backups");

$gFolderConfigs = realpath("../config");
$gFolderMibs = realpath("$gFolderConfigs/mibs");
$gFileSettings = realpath("$gFolderConfigs/settings.json");
$gFileSNMPMap = realpath("$gFolderConfigs/snmpmap.json");
$gFileDevices = realpath("$gFolderConfigs/devices.json");
$gFileSecurity = realpath("$gFolderConfigs/security.json");

// Global variables for Ping and Traceroute
$ipListFile = "$gFolderScanData/ping.iplist"; //list of ip's that are allowing Ping monitoring.
$pingOutFile = "$gFolderScanData/ping.out"; //stores the results of the ping scan in json format.
$tracerouteFilename = "traceroute.out"; //this is stored in the directory of each device. contains current/last successful and failed trace.

/*
$baseUrl = "http://localhost/qnms";
$image_thumb_width = 200;
$user = "";
$loggedin = false;
 */

// Cron job variables: [minute 0-59] [hour 0-23] [day 1-31] [month 1-12] [day-of-week 0-7 (0=sunday)]
$phpBin = trim(`which php`); //"/usr/bin/php";
$curlBin = trim(`which curl`); //"/usr/bin/curl";
$wgetBin = trim(`which wget`); //"/usr/bin/wget";
$cron_file = "$gFolderData/crontab"; //"/etc/cron.d/neosentry";
$mibLocation = $gFolderMibs; //default is /usr/share/snmp/mibs but its write protected
//$snmpCommandsFile = "$gFolderData/snmp_commands";
$pingInterval = "*/5 * * * *"; //ping every minute
$trInterval = "0 0 */1 * *"; //traceroute every day at midnight
$snmpNetInterval = "0 */10 * * *"; //get snmp network information every hour
$snmpSysInterval = "0 */10 * * *"; //get snmp hardware information every hour
$snmpAllInterval = "0 1 */1 * *"; //get all snmp information every day at 1am
$serviceInterval = "0 */1 * * *"; //get service information every hour


// Create some necessary stuff to prevent errors
if (!is_dir($gFolderScanData)) mkdir($gFolderScanData, 0777, true);



function destroySession() {
	$_SESSION=array();
	
	if (session_id() != "" || isset($COOKIE[session_name()]))
		setcookie(session_name(), '', time()-2592000, '/');
		
	session_destroy();
}

function sanitizeString($var) {
	$var = strip_tags(trim($var));
	$var = htmlentities($var); //prevents XSS when displaying text in HTML
	$var = stripslashes($var); //removes \ from the text, \\ becomes \
	return $var; //turns returns into \r\n, and a few other character replacements
}

function get_string_between($string, $start, $end){
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    if ($len <= 0) return substr($string, $ini);
    return substr($string, $ini, $len);
}



// FUNCTIONS FOR READING/WRITING CONFIGS AND SETTINGS //

function getDevicesArray() {
    global $gFileDevices;
    return json_decode(file_get_contents($gFileDevices),true);
}
function getSettingsArray() {
    global $gFileSettings;
    return json_decode(file_get_contents($gFileSettings));
}
function getSettingValue($section, $settingName) {
    //JSON
    global $gFileSettings;
    $arrSettings = json_decode(file_get_contents($gFileSettings));
    return $arrSettings[$section][$settingName];

}
function writeSettingValue($section, $settingName, $settingValue) {
    //JSON
    global $gFileSettings;
    $arrSettings = json_decode(file_get_contents($gFileSettings));
    $arrSettings[$section][$settingName] = $settingValue;
    return file_put_contents($gFileSettings, json_encode($arrSettings));
}


// FUNCTIONS FOR LOGGING INFORMATION //

function writeLogFile($fileName, $line) {
    // writes log data related to the app or system
    global $gFolderLogs;
    $logFile = "$gFolderLogs/$fileName";

    // make sure we have a log file to write to
    if (!file_exists($gFolderLogs)) mkdir($gFolderLogs);
    if (!file_exists($logFile)) touch($logFile);

    //now write the output
    $output = date(DATE_ATOM).":\t$line\n";
    //echo $output;
    return file_put_contents($logFile,$output,FILE_APPEND);

}


// FUNCTIONS FOR SECURITY //

function encryptString($string) {
    // for more advanced encryption. install libsodium PECL, top rated encryption package
    // https://paragonie.com/book/pecl-libsodium/read/00-intro.md#what-is-libsodium

    //load the encryption variables
    global $gFileSecurity;
    $gSecurity = json_decode(file_get_contents($gFileSecurity));
    if ($gSecurity["secret_key"] == "" || $gSecurity["secret_iv"]) {
        $gSecurity["secret_key"] = bin2hex(openssl_random_pseudo_bytes(48));
        $gSecurity["secret_iv"] = bin2hex(openssl_random_pseudo_bytes(16));
        file_put_contents($gFileSecurity,json_encode($gSecurity));
    }

    // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
    $iv = substr(hash('sha256', $gSecurity["secret_iv"]), 0, 16);

    //encrypt the string and return it
    $output = base64_encode(openssl_encrypt($string, "AES-256-CBC", hash('sha256', $gSecurity["secret_key"]), 0, $iv));
    return $output;
}
function decryptString($string) use(&$gFileSecurity) {
    global $gFileSecurity;
    $gSecurity = json_decode(file_get_contents($gFileSecurity));

   // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
    $iv = substr(hash('sha256', $gSecurity["secret_iv"]), 0, 16);

    // decrypt the string
    $output = openssl_decrypt(base64_decode($string), "AES-256-CBC", hash('sha256', $gSecurity["secret_key"]), 0, $iv);
    return $output;
}
function hashString($string){
    /* SHA512 = crypt('rasmuslerdorf', '$6$rounds=5000$usesome16CHARsalt$') //up to 999,999,999 rounds
     * SHA256 = crypt('rasmuslerdorf', '$5$rounds=5000$usesome16CHARsalt$')
     * BlowFish = crypt('rasmuslerdorf', '$2a$07$usesomesillystringforsalt$')
     * MD5 = crypt('rasmuslerdorf', '$1$rasmusle$')
     * 
     * Hash a password with old hash to compare passwords.
     *  hash_equals($hashed_password, crypt($user_input, $hashed_password))
     * 
     * USE THESE BUILT-IN FUNCTIONS INSTEAD OF hashString(). THIS SERVES ONLY AS A PLACEHOLDER
     *  password_hash('mypass'); will hash with the best current standard
     *  password_verify('mypass', $hash); will verify a password hashes match, using the same algorithm
     * 
     */
    return password_hash($string,PASSWORD_DEFAULT);
}


// ALERTING AND EMAILING //

function sendMail($to, $subject, $body) {
    require_once 'phpmailer/PHPMailerAutoload.php';
    
    $strTo = "";
    $arrSettings = getSettingsArray();
    $mail = new PHPMailer;

    //$mail->SMTPDebug = 3;                                 // Enable verbose debug output

    $mail->isSMTP();                                        // Set mailer to use SMTP
    $mail->Host = $arrSettings["Mail Settings"]["Host"];    // Specify main and backup SMTP servers
    $mail->SMTPAuth = $arrSettings["Mail Settings"]["SMTPAuth"];    // Enable SMTP authentication
    $mail->Username = $arrSettings["Mail Settings"]["Username"];    // SMTP username
    $mail->Password = $arrSettings["Mail Settings"]["Password"];    // SMTP password
    $mail->SMTPSecure = $arrSettings["Mail Settings"]["Security"];  // Enable 'tls' or 'ssl'
    $mail->Port = $arrSettings["Mail Settings"]["Port"];    // TCP port to connect to

    $mail->setFrom($arrSettings["Mail Settings"]["From"]);
    
    if (is_array($to)) {                                    // Add a recipient
        foreach ($to as $value) {
            $mail->addAddress($value);
            $strTo .= $value.";";
        }
    } else {
        $mail->addAddress($to);
        $strTo = $to.";";
    }
    //$mail->addReplyTo('info@example.com', 'Information');
    //$mail->addCC('cc@example.com');
    //$mail->addBCC('bcc@example.com');

    //$mail->addAttachment('/var/tmp/file.tar.gz');         // Add attachments
    //$mail->addAttachment('/tmp/image.jpg', 'new.jpg');    // Optional name
    $mail->isHTML(true);                                    // Set email format to HTML

    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->AltBody = strip_tags($body);                     //non-html alternative for non-html mail clients
    
    $msg = 'Message has been sent to '.$strTo." Subject: $subject";
    if(!$mail->send()) {
        $msg = 'Error: Message could not be sent. ' . $mail->ErrorInfo;
    }
    
    echo $msg;
    writeLogFile('email.log',$msg);
    return $msg;
    
}

