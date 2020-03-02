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
    include_once('/var/www/html/freepbx/rest/lib/libCTI.php');
    switch($engine) {
        case "asterisk":
            /*Configure conference*/
            $defaultVal = $amp_conf['ASTCONFAPP'];
            $amp_conf['ASTCONFAPP'] = 'app_meetme';
            $query='SELECT IF(customcode="",defaultcode,customcode) as defaultcode FROM featurecodes WHERE modulename="nethcti3" AND featurename="meetme_conf"';
            $conf_code=$db->getOne($query);
            if (isset($conf_code) && $conf_code != '') {
                $exten='_'.$conf_code.'X.';
                $exten2=$conf_code;
                $context='cti-conference';
                $ext->addInclude('from-internal-additional', $context);
                $ext->add($context, $exten, '', new ext_noop('conference'));
                $ext->splice($context, $exten, 'n', new ext_answer());
                $ext->splice($context, $exten, 'n', new ext_playback('beep'));
                $ext->splice($context, $exten, 'n', new ext_meetme('${EXTEN}', '1dMw'));
                $ext->splice($context, $exten, 'n', new ext_hangup());

                $ext->add($context, $exten2, '', new ext_noop('conference'));
                $ext->splice($context, $exten2, 'n', new ext_answer());
                $ext->splice($context, $exten2, 'n', new ext_playback('beep'));
                $ext->splice($context, $exten2, 'n', new ext_meetme('${EXTEN}${CALLERID(number)}', '1dMA'));
                $ext->splice($context, $exten2, 'n', new ext_hangup());

                $ext->add($context, 'h', '', new ext_hangup());
                $amp_conf['ASTCONFAPP'] = $defaultVal;
            }
            /*Intra company routes context*/
            $context='from-intracompany';
            $ext->add($context, '_X.', '', new ext_noop('intracompany'));
            $ext->add($context, '_X.', '', new ext_set('AMPUSERCIDNAME','${CALLERID(name)}'));
            $ext->add($context, '_X.', '', new ext_goto('1','${EXTEN}','from-internal'));
            /* Add Waiting Queues for Operator Panel*/
            $context = 'ctiopqueue';
            foreach (getCTIPermissionProfiles(false,false,false) as $profile){
                if (isset($profile['macro_permissions']['operator_panel']) && $profile['macro_permissions']['operator_panel']['value'] == true) {
                    $exten = "ctiopqueue".$profile['id'];
                    // Queue(queuename[,options[,URL[,announceoverride[,timeout[,AGI[,macro[,gosub[,rule[,position]]]]]]]]])
                    $ext->add($context, $exten,'',new ext_queue($exten, 't', '', '', '9999', '', '', '', '',''));
                }
            }
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
        $enableJanus = false;

        foreach ($users as $user) {
            try {
                if ($user['default_extension'] !== 'none') {

                    // Retrieve profile id and mobile
                    $stmt = $dbh->prepare('SELECT profile_id,mobile FROM rest_users WHERE user_id = ?');
                    $stmt->execute(array($user['id']));
                    $profileRes = $stmt->fetch();

                    // Skip user if he doesn't have a profile associated
                    if ($profileRes['profile_id'] == null) {
                        continue;
                    }

                    $endpoints = array(
                        'mainextension' => (array($user['default_extension'] => (object)array()))
                    );

                    // Retrieve physical extensions
                    $stmt = $dbh->prepare('SELECT extension, type, web_user, web_password, mac FROM rest_devices_phones WHERE user_id = ?');
                    $stmt->execute(array($user['id']));
                    $res = $stmt->fetchAll();

                    $extensions = array();
                    if (count($res) > 0) {
                        foreach ($res as $e) {
                            if ($e['type'] === 'temporaryphysical') {
                                $e['type'] = 'physical';
                            }
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
                                $settings['mac'] = $e['mac'];
                            } else if ($e['type'] === 'webrtc' || $e['type'] === 'mobile') {
                                // Retrieve webrtc sip credentials
                                $stmt = $dbh->prepare('SELECT data FROM sip WHERE keyword IN ("account", "secret") AND id = ?');
                                $stmt->execute(array($e['extension']));
                                $sipres = $stmt->fetchAll();

                                if ($sipres[0]['data'] && $sipres[1]['data']) {
                                    $settings['user'] = $sipres[0]['data'];
                                    $settings['password'] = $sipres[1]['data'];
                                } else {
                                    continue;
                                }
                                $enableJanus = true;
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

        //enable Janus Gateway if there are WebRTC extensions
        if ($enableJanus) {
            // enable janus in configuration database
            system('/usr/bin/sudo /sbin/e-smith/config setprop janus-gateway status enabled');
            system('/usr/bin/sudo /sbin/e-smith/signal-event runlevel-adjust');
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
        $results = getCTIPermissionProfiles(false,true,false);
        if (!$results) {
            error_log('Empty profile config');
        }
        foreach ($results as $r) {
            // Add oppanel waiting queue
            if ($r['macro_permissions']['operator_panel']['value']) {
                $r['macro_permissions']['operator_panel']['permissions'][] = array('name' => 'waiting_queue_'.$r['id'], 'value' => true);
            }

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
        $obj->transfer_context = $nethcti3->getTransferContext();
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
        //Move provisioning files from /var/lib/tftpnethvoice to /var/lib/tftpboot
        system("/usr/bin/sudo /usr/bin/scl enable rh-php56 -- php /var/www/html/freepbx/rest/lib/moveProvisionFiles.php");
        //Reload CTI
        system("/var/www/html/freepbx/rest/lib/ctiReloadHelper.sh > /dev/null 2>&1 &");
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

function nethcti3_get_config_early($engine) {
    include_once('/var/www/html/freepbx/rest/lib/libCTI.php');
    // Check proviosioning engine and continue only for Tancredi
    exec("/usr/bin/sudo /sbin/e-smith/config getprop nethvoice ProvisioningEngine", $out);
    if ($out[0] !== 'tancredi') return TRUE;

    global $astman;
    global $amp_conf;
    // Call Tancredi API to set variables that needs to be set on FreePBX retrieve conf
    // get featurecodes
    $dbh = FreePBX::Database();
    $sql = 'SELECT modulename,featurename,defaultcode,customcode FROM featurecodes';
    $stmt = $dbh->prepare($sql);
    $stmt->execute(array());
    $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $featurecodes = array();
    foreach ($res as $featurecode) {
        $featurecodes[$featurecode['modulename'].$featurecode['featurename']] = (!empty($featurecode['customcode'])?$featurecode['customcode']:$featurecode['defaultcode']);
    }

    /***********
    * Defaults *
    ************/
    $variables = array();

    //featurcodes
    $variables['cftimeouton'] = $featurecodes['callforwardcfuon'];
    $variables['cftimeoutoff'] = $featurecodes['callforwardcfuoff'];
    $variables['cfbusyoff'] = $featurecodes['callforwardcfboff'];
    $variables['cfbusyon'] = $featurecodes['callforwardcfbon'];
    $variables['cfalwaysoff'] = $featurecodes['callforwardcfoff'];
    $variables['cfalwayson'] = $featurecodes['callforwardcfon'];
    $variables['dndoff'] = $featurecodes['donotdisturbdnd_off'];
    $variables['dndon'] = $featurecodes['donotdisturbdnd_on'];
    $variables['dndtoggle'] = $featurecodes['donotdisturbdnd_toggle'];
    $variables['call_waiting_off'] = $featurecodes['callwaitingcwoff'];
    $variables['call_waiting_on'] = $featurecodes['callwaitingcwon'];
    $variables['pickup_direct'] = $featurecodes['corepickup'];
    $variables['pickup_group'] = $featurecodes['corepickupexten'];

    // FreePBX settings
    $variables['cftimeout'] = $amp_conf['CFRINGTIMERDEFAULT'];

    /*********************
    * Extension specific *
    *********************/
    $sql = 'SELECT userman_users.username as username,
                userman_users.default_extension as mainextension,
                rest_devices_phones.mac,
                rest_devices_phones.extension,
                rest_devices_phones.secret,
                rest_devices_phones.web_password,
                rest_users.profile_id
            FROM
                rest_devices_phones JOIN userman_users ON rest_devices_phones.user_id = userman_users.id
                JOIN rest_users ON rest_devices_phones.user_id = rest_users.user_id
            WHERE rest_devices_phones.type = "physical"';
    $stmt = $dbh->prepare($sql);
    $stmt->execute(array());
    $extdata = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Get CTI profile permissions
    $permissions = getCTIPermissionProfiles(false,true,true);

    $tancrediUrl = 'http://localhost/tancredi/api/v1/';

    // Get Tancredi authentication variables
    include_once '/var/www/html/freepbx/rest/config.inc.php';
    $user = 'admin';
    $secret = $config['settings']['secretkey'];

    $stmt = $dbh->prepare("SELECT * FROM ampusers WHERE sections LIKE '%*%' AND username = ?");
    $stmt->execute(array($user));
    $user = $stmt->fetchAll();
    $password_sha1 = $user[0]['password_sha1'];
    $username = $user[0]['username'];
    $secretkey = sha1($username . $password_sha1 . $secret);

    // loop for each physical device
    $index = 1;
    foreach ($extdata as $ext) {
        $extension = $ext['extension'];
        $mainextension = $ext['mainextension'];

        // Get extension sip parameters
        $sql = 'SELECT keyword,data FROM sip WHERE id = ?';
        $stmt = $dbh->prepare($sql);
        $stmt->execute(array($extension));
        $res = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $sip = array();
        foreach ($res as $value) {
            $sip[$value['keyword']] = $value['data'];
        }

        $user_variables = array();
        $user_variables['mainextension_'.$index] = $mainextension;
        $user_variables['extension_'.$index] = $extension;
        $user_variables['cftimeout_'.$index] = $astman->database_get("AMPUSER",$mainextension.'/followme/prering');

        // Set dnd and fwd permission from CTI permissions if they exists
        $user_variables['dnd_allow'] = '1';
        $user_variables['fwd_allow'] = '1';
        if (array_key_exists('profile_id',$ext)
            && is_array($permission)
            && array_key_exists($ext['profile_id'],$permission)
            && array_key_exists('macro_permissions',$permissions[$ext['profile_id']])
            && array_key_exists('settings',$permissions[$ext['profile_id']]['macro_permissions'])
            && array_key_exists('permissions',$$permissions[$ext['profile_id']]['macro_permissions']['settings']))
        {
            foreach ($permissions[$ext['profile_id']]['macro_permissions']['settings']['permissions'] as $permission) {
                if ($permission['name'] == 'dnd') {
                    $user_variables['dnd_allow'] = $permission['value'] ? '1' : '';
                } elseif ($permission['name'] == 'call_forward') {
                    $user_variables['fwd_allow'] = $permission['value'] ? '1' : '';
                }
            }
        }
        // srtp
        if (array_key_exists('media_encryption', $sip) && ( $sip['media_encryption'] == 'sdes' || $sip['media_encryption'] == 'dtls')) {
            $user_variables['account_encryption_1'] = '1';
        } else {
            $user_variables['account_encryption_1'] = '';
        }

        if (array_key_exists('callerid', $sip)) {
            $user_variables['account_display_name_1'] = preg_replace('/<[0-9]*>$/', "<$mainextension>", $sip['callerid']);
        } else {
            $user_variables['account_display_name_1'] = "<$mainextension>";
        }

        $user_variables['account_username_1'] = $extension;
        $user_variables['account_password_1'] = $sip['secret'];
        $user_variables['account_dtmf_type_1'] = 'rfc4733';
        if (array_key_exists('dtmfmode',$sip)) {
            if ($sip['dtmfmode'] == 'inband') $user_variables['account_dtmf_type_1'] = 'inband';
            elseif ($sip['dtmfmode'] == 'rfc2833') $user_variables['account_dtmf_type_1'] = 'rfc4733';
            elseif ($sip['dtmfmode'] == 'info') $user_variables['account_dtmf_type_1'] = 'sip_info';
            elseif ($sip['dtmfmode'] == 'rfc4733') $user_variables['account_dtmf_type_1'] = 'rfc4733';
        }
        if ($extension != $mainextension) {
	    $user_variables['voicemail_number_'.$index] = $featurecodes['voicemaildialvoicemail'].$mainextension;
        } else {
            $user_variables['voicemail_number_'.$index] = $featurecodes['voicemailmyvoicemail'];
        }
        $res = nethcti_tancredi_patch($tancrediUrl . 'phones/' . str_replace(':','-',$ext['mac']), $username, $secretkey, array("variables" => $user_variables));
    }
    /***********************************
    * call Tancredi /defaults REST API *
    ************************************/
    $res = nethcti_tancredi_patch($tancrediUrl . 'defaults', $username, $secretkey, $variables);
}

function nethcti_tancredi_patch($url, $username, $secretkey, $data) {
    $data = json_encode($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json;charset=utf-8",
        "Accept: application/json;charset=utf-8",
        "Content-length: ".strlen($data),
        "User: $username",
        "SecretKey: $secretkey",
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code'=>$httpCode, 'response' => $response);
}

