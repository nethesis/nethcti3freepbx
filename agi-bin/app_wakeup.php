#!/usr/bin/env php
<?php
#
# Copyright (C) 2020 Nethesis S.r.l.
# http://www.nethesis.it - nethserver@nethesis.it
#
# This script is part of NethServer.
#
# NethServer is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License,
# or any later version.
#
# NethServer is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with NethServer.  If not, see COPYING.
#

include_once '/etc/freepbx_db.conf';
define("AGIBIN_DIR", "/var/lib/asterisk/agi-bin");
include(AGIBIN_DIR."/phpagi.php");
$agi = new AGI();
$call_id = $agi_uniqueid;

# read extension from argv
$ext_string = get_var($agi, 'ARG3');
$extensions = explode('-',$ext_string);

// check CF
foreach ($extensions as $extension) {
    $cf = $agi->database_get('CF',$extension);
    $cf = $cf['data'];
    if (!empty($cf)) {
        $extensions[] = $cf;
    }
}
$extensions = array_unique($extensions,SORT_REGULAR);

// check DND
foreach ($extensions as $index => $extension) {
    $dnd = $agi->database_get('DND',$extension);
    $dnd = $dnd['data'];
    if (!empty($dnd)) {
        unset($extensions[$index]);
    }
}

// Get all extensions from mainextensions
$devices = array();
foreach ($extensions as $extension) {
    $device_str = sprintf("%s/device", $extension);
    $device = $agi->database_get('AMPUSER',$device_str);
    $device = $device['data'];
    $devices = array_merge($devices,explode('&',$device));
}
$devices = array_unique($devices,SORT_REGULAR);
# get user for the extensions in 
$query_parts = array();
foreach ($devices as $device) {
    $query_parts[] = '(rest_devices_phones.extension = ?  AND rest_devices_phones.type = "mobile")';
}

$query = 'SELECT DISTINCT userman_users.username,rest_devices_phones.extension FROM userman_users JOIN rest_devices_phones on userman_users.id = rest_devices_phones.user_id WHERE ';
$query .= implode(' OR ',$query_parts);
$sth = $db->prepare($query);
$sth->execute($devices);
$results = $sth->fetchAll(\PDO::FETCH_ASSOC);
if (empty($results)) {
    // There aren't mobile extensions
    $agi->verbose('No mobile extensions in '.implode(',',$devices));
    //echo('No mobile extensions in ['.implode(',',$extensions)."]\n");
    exit(0);
}

$extensions_to_wake = array();
$request_errors = array();
foreach ($results as $result) {
    $username = $result['username'];
    $extension = $result['extension'];
    $agi->verbose("username = $username");

    // check if extension is already registered
    if (get_var($agi,"EXTENSION_STATE($extension)") == "NOT_INUSE") {
        $agi->verbose("extension $extension is available");
        continue;
    }

    // Call notification service
    $data = array(
        "AuthKey" => "b2eb0b53-3247-436f-ab95-33aeea803ebb",
        "ApplicationKey" => "NETHCTI_APP",
        "Message" => "",
        "TypeMessage" => 2,
        "UserName" => $username.'@'.gethostname(),
        "Sound" => "",
        "Badge" => 0,
        "CustomField1" => "IC_MSG",
        "CustomField2" => $call_id
    );
    $data = json_encode($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://notificatore.apexnet.it/NotificaPush");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "X-HTTP-Method-Override: SendPush",
        "Content-length: ".strlen($data),
        ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
   
    if ($httpCode != 200) {
        $agi->verbose("error: notification server answered $httpCode");
    } else {
        $agi->verbose("Sent wake up notification for extension $extension");
        $extensions_to_wake[] = $extension;
    }
}

// wait for extension to register
for ($i = 0; $i<10 ; $i++) {
    usleep(500000); // sleep for 0.5 seconds
    foreach ($extensions_to_wake as $index => $ext) {
        if (get_var($agi,"EXTENSION_STATE($ext)") == "NOT_INUSE") {
            $agi->verbose("extension $ext is now available");
            unset($extensions_to_wake[$index]);
        }
    }
    if (count($extensions_to_wake) == 0) {
        // all extension woke up
        exit(0);
    }
}
//Timeout
$agi->verbose("extension(s) ".implode(',',$extensions_to_wake)." not available, timeout");
exit(0);

function get_var( $agi, $value) {
        $r = $agi->get_variable( $value );
        if ($r['result'] == 1) {
                $result = $r['data'];
                return $result;
        }
        return '';
}
