<?php

function nethcti3_get_config($engine) {

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

        foreach ($users as $user) {
            try {
                if ($user['default_extension'] !== 'none') {
                    $endpoints = array(
                        'mainextension' => (array($user['default_extension'] => (object)array()))
                    );

                    // Retrieve physical extensions
                    $stmt = $dbh->prepare('SELECT extension, type FROM rest_devices_phones WHERE user_id = ?');
                    $stmt->execute(array($user['id']));
                    $res = $stmt->fetchAll();

                    if (count($res) > 0) {
                        $extensions = array();
                        foreach ($res as $e) {
                            $settings = array(
                                'type' => $e['type']
                            );

                            if ($e['type'] === 'physical') {
                                $settings['web_user'] = 'admin';
                                $settings['web_password'] = 'admin';
                            }
                            else if ($e['type'] === 'webrtc' || $e['type'] === 'webrtc_mobile') {
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

                        $endpoints['extension'] = $extensions;
                    }

                    // Set voicemail
                    if (in_array($user['default_extension'], $enabledVoicemails)) {
                        $endpoints['voicemail'] = array($user['default_extension'] => (object)array());
                    }

                    // Set email
                    $endpoints['email'] = ($user['email'] ? array($user['email'] => (object) array()) : (object)array());

                    // Retrieve profile id and mobile
                    $stmt = $dbh->prepare('SELECT profile_id,mobile FROM rest_users WHERE user_id = ?');
                    $stmt->execute(array($user['id']));
                    $profileRes = $stmt->fetch();

                    if (!profileRes || !isset($profileRes['profile_id'])) {
                        throw new Exception('no profile associated for '. $user['id']);
                    }

                    // Set cellphone
                    $endpoints['cellphone'] = ($profileRes['mobile'] ? array($profileRes['mobile'] => (object) array()) : (object)array());

                    // Join configuration
                    $userJson = array(
                        'name' => $user['displayname'],
                        'endpoints' => $endpoints,
                        'profile_id' => $profileRes['profile_id']
                    );

                    $json[$user['username']] = $userJson;
                    // error_log(print_r($user, true));
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }

        // Write users.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/users.json',$json);

        if ($res === FALSE) {
            throw new Exception('fail to write users config');
        }

        // Write operator.json configuration file
        $results = getCTIGroups();
        if (!$results) {
            throw new Exception('Empty operator config');
        }
        foreach ($results as $r) {
            $out[$r['name']][] = $r['username'];
        }
        $final['groups'] = $out;
        // Write operator.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/operator.json',$final);
        if ($res === FALSE) {
            throw new Exception('fail to write operator config');
        }

        /*
        *    Write permissions json
        */
        $results = getCTIPermissionProfiles(false,true);
        if (!$results) {
            throw new Exception('Empty profile config');
        }
        foreach ($results as $r) {
            $out[$r['id']] = $r;
        }
        // Write profiles.json configuration file
        $res = $nethcti3->writeCTIConfigurationFile('/profiles.json',$out);
        if ($res === FALSE) {
            throw new Exception('fail to write profiles config');
        }

        /*
        *    Write cti configuration in ast_objects.json: trunks, queues
        */
        $obj = new \stdClass();
        $obj->trunks = $nethcti3->getTrunksConfiguration();
        $obj->queues = $nethcti3->getQueuesConfiguration();
        $res = $nethcti3->writeCTIConfigurationFile('/ast_objects.json',$obj);
        if ($res === FALSE) {
            throw new Exception('fail to write trunks config');
        }

        //Restart CTI
        system("/usr/bin/sudo /usr/bin/systemctl restart nethcti-server &");
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

