<?php
// vim: set ai ts=4 sw=4 ft=php:
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
        $res = file_put_contents($config['settings']['cti_config_path']. $filename,json_encode($obj, JSON_PRETTY_PRINT));
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
                $result[$trunk['name']] = (object) array();
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
}
