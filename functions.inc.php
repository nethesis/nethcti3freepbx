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

function nethcti3_get_config($engine) {
    global $ext;
    global $amp_conf;
    global $db;
    switch($engine) {
        case "asterisk":
            /*Configure conference*/
            $defaultVal = $amp_conf['ASTCONFAPP'];
            $amp_conf['ASTCONFAPP'] = 'app_meetme';
            $query='SELECT defaultcode FROM featurecodes WHERE modulename="nethcti3" AND featurename="meetme_conf"';
            $conf_code=$db->getOne($query);
            $exten='_'.$conf_code.'X.';
            $exten2=$conf_code;
            $context='cti-conference';
            $ext->addInclude('from-internal-additional', $context);
            $ext->add($context, $exten, '', new ext_noop('conference'));
            $ext->splice($context, $exten, 'n', new ext_answer());
            $ext->splice($context, $exten, 'n', new ext_playback('beep'));
            $ext->splice($context, $exten, 'n', new ext_meetme('${EXTEN}', '1dM'));
            $ext->splice($context, $exten, 'n', new ext_hangup());

            $ext->add($context, $exten2, '', new ext_noop('conference'));
            $ext->splice($context, $exten2, 'n', new ext_answer());
            $ext->splice($context, $exten2, 'n', new ext_playback('beep'));
            $ext->splice($context, $exten2, 'n', new ext_meetme('${EXTEN}${CALLERID(number)}', '1dM'));
            $ext->splice($context, $exten2, 'n', new ext_hangup());

            $ext->add($context, 'h', '', new ext_hangup());
            $amp_conf['ASTCONFAPP'] = $defaultVal;
        break;
    }
}

function nethcti3_get_config_late($engine) {
    global $ext;
    global $amp_conf;
    global $db;
    switch($engine) {
        case "asterisk":
            /*Off-Hour*/
            $routes = FreePBX::Core()->getAllDIDs();
            foreach ($routes as $did) {
                /*add off-hour agi for each inbound routes*/
                if($did['extension'] && $did['cidnum'])
                    $exten = $did['extension']."/".$did['cidnum'];
                else if (!$did['extension'] && $did['cidnum'])
                    $exten = "s/".$did['cidnum'];
                else if ($did['extension'] && !$did['cidnum'])
                    $exten = $did['extension'];
                else if (!$did['extension'] && !$did['cidnum'])
                    $exten = "s";

                if (($did['cidnum'] != '' && $did['extension'] != '') || ($did['cidnum'] == '' && $did['extension'] == '')) {
                    $pricid = true;
                } else {
                    $pricid = false;
                }
                $context = ($pricid) ? "ext-did-0001":"ext-did-0002";
                $ext->splice($context, $exten, "did-cid-hook", new ext_agi('offhour.php,'.$did['cidnum'].','.$did['extension']),'offhour',2);
            }
        break;
    }

    // Write cti configuration file
    include_once('/var/www/html/freepbx/rest/lib/libCTI.php');
    $nethcti3 = \FreePBX::Nethcti3();

    /*
    *    Write user configuration json
    */
    try {
        $json = array();
        $users = \FreePBX::create()->Userman->getAllUsers();
        $dbh = \FreePBX::Database();
        $freepbxVoicemails = \FreePBX::Voicemail()->getVoicemail();
        $enabledVoicemails = ($freepbxVoicemails['default'] != null) ? array_keys($freepbxVoicemails['default']) : array();
        $domainName = end(explode('.', gethostname(), 2));

        foreach ($users as $user) {
            try {
                if ($user['default_extension'] !== 'none') {
                    $endpoints = array(
                        'mainextension' => (array($user['default_extension'] => (object)array()))
                    );

                    // Retrieve physical extensions
                    $stmt = $dbh->prepare('SELECT extension, type, web_user, web_password FROM rest_devices_phones WHERE user_id = ?');
                    $stmt->execute(array($user['id']));
                    $res = $stmt->fetchAll();

                    $extensions = array();
                    if (count($res) > 0) {
                        foreach ($res as $e) {
                            $settings = array(
                                'type' => $e['type']
                            );

                            if ($e['type'] === 'physical') {
                                if (!is_null($e['web_user']) && !is_null($e['web_password'])) {
                                    $settings['web_user'] = $e['web_user'];
                                    $settings['web_password'] = $e['web_password'];
                                } else {
                                    $settings['web_user'] = 'admin';
                                    $settings['web_password'] = 'admin';
                                }
                            } else if ($e['type'] === 'webrtc' || $e['type'] === 'webrtc_mobile') {
                                // Retrieve webrtc sip credentials
                                $stmt = $dbh->prepare('SELECT data FROM sip WHERE keyword IN ("account", "secret") AND id = ?');
                                $stmt->execute(array($e['extension']));
                                $sipres = $stmt->fetchAll();

                                if ($sipres[0]['data'] && $sipres[1]['data']) {
                                    $settings['user'] = $sipres[0]['data'];
                                    $settings['password'] = $sipres[1]['data'];
                                }
                            }

                            $extensions[$e['extension']] = (object)$settings;
                        }

                    }
                    $endpoints['extension'] = (object)$extensions;

                    // Set voicemail
                    if (in_array($user['default_extension'], $enabledVoicemails)) {
                        $endpoints['voicemail'] = array($user['default_extension'] => (object)array());
                    }

                    // Set email
                    $endpoints['email'] = ($user['email'] ? array($user['email'] => (object) array()) : (object)array());
                    $endpoints['jabber'] = array($user['username']."@".$domainName => (object)array());

                    // Retrieve profile id and mobile
                    $stmt = $dbh->prepare('SELECT profile_id,mobile FROM rest_users WHERE user_id = ?');
                    $stmt->execute(array($user['id']));
                    $profileRes = $stmt->fetch();

                    if (!profileRes || !isset($profileRes['profile_id'])) {
                        error_log('no profile associated for '. $user['id']);
                    }

                    // Set cellphone
                    $endpoints['cellphone'] = ($profileRes['mobile'] ? array($profileRes['mobile'] => (object) array()) : (object)array());

                    // Join configuration
                    $userJson = array(
                        'name' => $user['displayname'],
                        'endpoints' => $endpoints,
                        'profile_id' => $profileRes['profile_id']
                    );

                    $json[preg_replace('/@[\.a-zA-Z0-9]*/','',$user['username'])] = $userJson;
                    // error_log(print_r($user, true));
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }

        // Write users.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/users.json',$json);

        if ($res === FALSE) {
            error_log('fail to write users config');
        }

        // Write operator.json configuration file
        $results = getCTIGroups();
        if (!$results) {
            error_log('Empty operator config');
        }
        foreach ($results as $r) {
            $out[$r['name']][] = $r['username'];
        }
        $final['groups'] = $out;
        // Write operator.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/operator.json',$final);
        if ($res === FALSE) {
            error_log('fail to write operator config');
        }

        /*
        *    Write permissions json
        */
        $out = [];
        $results = getCTIPermissionProfiles(false,true);
        if (!$results) {
            error_log('Empty profile config');
        }
        foreach ($results as $r) {
            $out[$r['id']] = $r;
        }
        // Write profiles.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/profiles.json',$out);
        if ($res === FALSE) {
            error_log('fail to write profiles config');
        }

        /*
        *    Write cti configuration in ast_objects.json: trunks, queues
        */
        $obj = new \stdClass();
        $obj->trunks = $nethcti3->getTrunksConfiguration();
        $obj->queues = $nethcti3->getQueuesConfiguration();
        $obj->feature_codes = $nethcti3->getFeaturecodesConfiguration();
        $res = $nethcti3->writeCTIConfigurationFile('/ast_objects.json',$obj);
        if ($res === FALSE) {
            error_log('fail to write trunks config');
        }

        // write streaming.json
        $out = [];
        $dbh = FreePBX::Database();
        $sql = 'SELECT * FROM rest_cti_streaming';
        $sth = $dbh->prepare($sql);
        $sth->execute();
        $results = $sth->fetchAll(PDO::FETCH_ASSOC);

        if (!$results) {
            error_log('Empty profile config');
        }
        foreach ($results as $r) {
            $pername = 'vs_'. strtolower(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s]/','',$r['descr'])));
            $out[$pername] = $r;
        }
        // Write streaming.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/streaming.json',(object) $out);
        if ($res === FALSE) {
            error_log('fail to write streaming config');
        }

        //Reload CTI
        system("/var/www/html/freepbx/rest/lib/ctiReloadHelper.sh > /dev/null 2>&1 &");
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

