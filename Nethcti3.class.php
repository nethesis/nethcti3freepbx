<?php
#
# Copyright (C) 2017 Nethesis S.r.l.
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

namespace FreePBX\modules;

class Nethcti3 implements \BMO
{
    public function install() {
    }
    public function uninstall() {
    }
    public function backup() {
    }
    public function restore($backup) {
    }
    public function doConfigPageInit($page) {
    }

    /*Write a CTI configuration file in JSON format*/
    public function writeCTIConfigurationFile($filename, $obj) {
    try {
        // Write configuration file
            require('/var/www/html/freepbx/rest/config.inc.php');
        $res = file_put_contents($config['settings']['cti_config_path']. $filename,json_encode($obj, JSON_PRETTY_PRINT),LOCK_EX);
    } catch (Exception $e) {
        error_log($e->getMessage());
        return FALSE;
    }
        return $res;
    }

    /*Get trunks configuration*/
    public function getTrunksConfiguration() {
        try {
            $result = array();
            $trunks = \FreePBX::Core()->listTrunks();
            foreach($trunks as $trunk) {
                $result[$trunk['channelid']] = (object)array(
                    "tech"=>$trunk["tech"],
                    "trunkid"=>$trunk["trunkid"],
                    "name"=>$trunk["name"],
                    "usercontext"=>$trunk["usercontext"],
                    "maxchans"=>$trunk["maxchans"]
                );
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            return FALSE;
        }
        return $result;
    }

    /*Get queues configuration*/
    public function getQueuesConfiguration() {
        try {
            $result = array();
            $queues = \FreePBX::Queues()->listQueues();

            //get dynmembers
            global $astman;
            $dbqpenalities = $astman->database_show('QPENALTY');
            $penalities=array();
            //build an array of members for each queue
            foreach ($dbqpenalities as $dbqpenality => $tmp) {
                if (preg_match ('/\/QPENALTY\/([0-9]+)\/agents\/([0-9]+)/',$dbqpenality,$matches)) {
                    $penalities[$matches[1]][] = $matches[2];
                }
            }
            //create result object
            foreach($queues as $queue) {
                //dynmembers = array() if there isn't dynmembers in $penalities for this queue
                if (!isset($penalities[$queue[0]])) {
                    $penalities[$queue[0]] = array();
                }
                $result[$queue[0]] = (object) array("id" => $queue[0], "name" => $queue[1], "dynmembers" => $penalities[$queue[0]]);
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            return FALSE;
        }
        return $result;
    }

    /*Get FeatureCodes configuration*/
    public function getFeaturecodesConfiguration() {
    try {
        $result = array();
        $codes_to_pick = array("pickup","meetme_conf"); //Add here more codes
        $featurecodes = featurecodes_getAllFeaturesDetailed();
        foreach ($featurecodes as $featurcode) {
            if (in_array($featurcode['featurename'],$codes_to_pick)) {
                if (isset($featurcode['customcode']) && $featurcode['customcode'] != '') {
                    $results[$featurcode['featurename']] = $featurcode['customcode'];
                } else {
                    $results[$featurcode['featurename']] = $featurcode['defaultcode'];
                }
            }
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
        return FALSE;
    }
        return (object) $results;
    }
}
