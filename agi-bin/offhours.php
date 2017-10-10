#!/usr/bin/php  
<?php

define("AGIBIN_DIR", "/var/lib/asterisk/agi-bin");
define("AMPORTAL_CONF", "/etc/amportal.conf");

include(AGIBIN_DIR."/phpagi.php");
include_once('/etc/freepbx.conf');

global $db;
global $amp_conf;

function agiexit($prio) {
	global $agi;
	$agi->set_priority($prio);
	exit(0);
}

$agi = new AGI();
try {
    $tz=$amp_conf['timezone'];
    $dtz = new DateTimeZone($tz);
    $dt = new DateTime("now", $dtz);
} catch (Exception $e){
    $tz = date_default_timezone_get();
    $dtz = new DateTimeZone($tz);
    $dt = new DateTime("now", $dtz);
}
$utc_dtz = new DateTimeZone("UTC");
$utc_dt = new DateTime("now", $utc_dtz);

$offset = $dtz->getOffset($dt) - $utc_dtz->getOffset($utc_dt);

$agi->verbose("TimeZone=".$tz);

$night_id = $argv[1];
$test = $argv[2]; // 0 == TRUE

$sql="SELECT * FROM night WHERE night_id=$night_id";
$res=@$db->getRow($sql,DB_FETCHMODE_ASSOC);
if (@$db->isError($res)) {
    $agi->verbose("MySQL ERROR! ".$sql.$res->getMessage());
    exit(0);
}

$time = time()+$offset;
$tbegin=$res['tsbegin']+$offset;
$tend=$res['tsend']+$offset;
   
$agi->verbose("Night service {$res['displayname']} ($night_id)  -> enabled={$res['enabled']}, begin: $tbegin, now: $time, end: $tend");


//Decide if this condition is enabled or not
if ($res['enabled']==1 or (($res['enabled']==2) and ($time >= $tbegin) and ($time <=$tend)))
    $execagi=true;
else
    $execagi=false;

//Exit if time condition isn't respected or if nightservice is disabled
if (!$execagi) {
    //If testing agi, echo that night service is disable before exit
    if ($test == 0 ){
        $agi->answer();
	$agi->exec("wait","1");
        $agi->stream_file("night/night-state-disabled");
	$agi->exec("wait","1");
	$agi->exec("Macro","hangupcall");	
    }
    $agi->verbose("Night service not enabled. Exit.");
    $agi->noop();
    exit(0);
}
//Check if it is in CTI mode or NethVoice mode
if ($res['type']==0){
    //NethVoice mode
    $agi->verbose("Night service active in NethVoice mode! Going to {$res['didaction']}");
    $agi->exec_go_to($res['didaction']);
    exit(0);
} else {
    //NethCTI type
    $agi->verbose("Night service active in NethCTI mode!");
    switch ($res['ctiaction']) {
        case "0":
            //Message and hangup
    	    $agi->verbose("Night service NethCTI mode: message and hangup");
            $agi->answer();
            $agi->stream_file($res['message']);
            $agi->stream_file($res['message']);
            $agi->stream_file($res['message']);
            $agi->exec("Macro","hangupcall");
        break;
        case "1":
            //Message and voicemail
            $agi->verbose("Night service NethCTI mode: message and voicemail");
            $agi->answer();
            $agi->stream_file($res['message']);
            if ($res['param'] != '') {
                $agi->verbose("Messaggio su VoiceMail ".$res['param']);
                $agi->exec("Macro","vm,{$res['param']},NOMESSAGE");
            } 
            $agi->exec("Macro","hangupcall");
        break;
        case "2":
            //Call forward
            if ($res['param'] != '') {
            	$agi->verbose("Night service NethCTI mode: call forward to {$res['param']}");
                # Dial Local/$param...
                $agi->exec_dial("Local",$res['param']."@from-internal");
            } else {
                $agi->verbose("Night service NethCTI mode: call forward ERROR! MISSING DESTINATION!");
            }
            $agi->exec("Macro","hangupcall");
         break;
    }
}

exit(0);

?>

