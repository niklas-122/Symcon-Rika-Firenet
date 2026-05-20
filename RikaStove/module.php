<?php

class RikaStove extends IPSModule
{
    private $baseUrl = "https://www.rika-firenet.com";

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('StoveID', '');
        $this->RegisterPropertyInteger('Interval', 300);

        $this->RegisterTimer('UpdateData', 0, 'RIKA_UpdateStatus($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // 1. Profil für den Ofen-Zustand (Integer)
        if (!IPS_VariableProfileExists('Rika.Status')) {
            IPS_CreateVariableProfile('Rika.Status', 1);
            IPS_SetVariableProfileAssociation('Rika.Status', 1, "Aus / Standby", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 2, "Zündphase", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 3, "Heizbetrieb", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 4, "Reinigung", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 5, "Ausbrand", "", -1);
        }

        // 2. Profil für die Soll-Temperatur (Float, 14-28°C, Schrittweite 1)
        if (!IPS_VariableProfileExists('Rika.TargetTemp')) {
            IPS_CreateVariableProfile('Rika.TargetTemp', 2); 
            IPS_SetVariableProfileValues('Rika.TargetTemp', 14.0, 28.0, 1.0);
            IPS_SetVariableProfileDigits('Rika.TargetTemp', 1);
            IPS_SetVariableProfileText('Rika.TargetTemp', "", " °C");
            IPS_SetVariableProfileIcon('Rika.TargetTemp', "Temperature");
        }

        // 3. Profil für Gebläsestufen (Integer, 0-4 oder Stufen nach Rika-Vorgabe)
        if (!IPS_VariableProfileExists('Rika.FanLevel')) {
            IPS_CreateVariableProfile('Rika.FanLevel', 1);
            IPS_SetVariableProfileValues('Rika.FanLevel', 0, 5, 1);
            IPS_SetVariableProfileIcon('Rika.FanLevel', "Ventilator");
        }

        // 4. Profil für Laufzeiten (Float / Stunden)
        if (!IPS_VariableProfileExists('Rika.Hours')) {
            IPS_CreateVariableProfile('Rika.Hours', 2);
            IPS_SetVariableProfileDigits('Rika.Hours', 1);
            IPS_SetVariableProfileText('Rika.Hours', "", " h");
            IPS_SetVariableProfileIcon('Rika.Hours', "Clock");
        }

        // 5. Profil für WLAN Signalstärke (Integer / dBm)
        if (!IPS_VariableProfileExists('Rika.Signal')) {
            IPS_CreateVariableProfile('Rika.Signal', 1);
            IPS_SetVariableProfileText('Rika.Signal', "", " dBm");
            IPS_SetVariableProfileIcon('Rika.Signal', "Signal");
        }

        // --- Variablen registrieren ---

        // Basiseinstellungen & Temperaturen
        $this->RegisterVariableBoolean('Status', 'Ofen An/Aus', '~Switch');
        $this->EnableAction('Status');

        $this->RegisterVariableFloat('RoomTemperature', 'Raumtemperatur', '~Temperature');
        $this->RegisterVariableFloat('FlameTemperature', 'Flammentemperatur', '~Temperature');
        
        $this->RegisterVariableFloat('TargetTemperature', 'Soll-Temperatur', 'Rika.TargetTemp');
        $this->EnableAction('TargetTemperature');

        $this->RegisterVariableInteger('StoveState', 'Ofen Zustand', 'Rika.Status');

        // MultiAir Gebläse (Sowohl lesbar als auch steuerbar!)
        $this->RegisterVariableInteger('ConvectionFan1Level', 'MultiAir 1 Stufe', 'Rika.FanLevel');
        $this->EnableAction('ConvectionFan1Level');
        $this->RegisterVariableInteger('ConvectionFan2Level', 'MultiAir 2 Stufe', 'Rika.FanLevel');
        $this->EnableAction('ConvectionFan2Level');

        // Diagnosedaten & Zähler
        $this->RegisterVariableFloat('ParameterRuntimePellets', 'Laufzeit Pellets', 'Rika.Hours');
        $this->RegisterVariableFloat('ParameterFeedRateTotal', 'Pelletsverbrauch Index', '');
        $this->RegisterVariableInteger('ParameterIgnitionCount', 'Anzahl Zündungen', '');
        $this->RegisterVariableInteger('WifiStrength', 'WLAN Signalstärke', 'Rika.Signal');
        
        // Fehler / Warnungen
        $this->RegisterVariableInteger('StatusError', 'Fehlercode', '');
        $this->RegisterVariableInteger('StatusWarning', 'Warnungscode', '');

        // Software-Versionen
        $this->RegisterVariableString('SoftwareMain', 'Software Hauptplatine', '');
        $this->RegisterVariableString('SoftwareDisplay', 'Software Display', '');

        // Timer setzen
        $this->SetTimerInterval('UpdateData', $this->ReadPropertyInteger('Interval') * 1000);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Status':
                $this->SetStoveControl(['onOff' => (bool)$Value]);
                break;
            case 'TargetTemperature':
                $this->SetStoveControl(['targetTemperature' => (string)$Value]);
                break;
            case 'ConvectionFan1Level':
                $this->SetStoveControl(['convectionFan1Level' => (int)$Value]);
                break;
            case 'ConvectionFan2Level':
                $this->SetStoveControl(['convectionFan2Level' => (int)$Value]);
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function UpdateStatus()
    {
        $stoveId = $this->ReadPropertyString('StoveID');
        if (empty($stoveId)) return;

        $statusUrl = $this->baseUrl . "/api/client/" . $stoveId . "/status";
        $request = $this->firenetRequest($statusUrl);

        if ($request['code'] == 401 || strpos($request['body'], 'login') !== false || empty($request['body'])) {
            if ($this->firenetLogin()) {
                $request = $this->firenetRequest($statusUrl);
            } else {
                $this->SetStatus(201);
                return;
            }
        }

        if ($request['code'] == 200) {
            $data = json_decode($request['body'], true);
            $this->SetStatus(102);

            // 1. Daten aus dem 'controls' Array (Soll-Werte / Stati)
            if (isset($data['controls'])) {
                $this->SetValue('Status', (bool)$data['controls']['onOff']);
                $this->SetValue('TargetTemperature', floatval($data['controls']['targetTemperature']));
                $this->SetValue('ConvectionFan1Level', intval($data['controls']['convectionFan1Level']));
                $this->SetValue('ConvectionFan2Level', intval($data['controls']['convectionFan2Level']));
            }

            // 2. Daten aus dem 'sensors' Array (Ist-Werte / Zähler)
            if (isset($data['sensors'])) {
                $this->SetValue('RoomTemperature', floatval($data['sensors']['inputRoomTemperature']));
                $this->SetValue('FlameTemperature', floatval($data['sensors']['inputFlameTemperature']));
                $this->SetValue('StoveState', intval($data['sensors']['statusMainState']));
                
                $this->SetValue('StatusError', intval($data['sensors']['statusError']));
                $this->SetValue('StatusWarning', intval($data['sensors']['statusWarning']));
                $this->SetValue('WifiStrength', intval($data['sensors']['statusWifiStrength']));

                // Parameter & Zähler exakt nach Ofen-Rohdaten mappen
                if (isset($data['sensors']['parameterRuntimePellets'])) {
                    $this->SetValue('ParameterRuntimePellets', floatval($data['sensors']['parameterRuntimePellets']));
                }
                if (isset($data['sensors']['parameterFeedRateTotal'])) {
                    $this->SetValue('ParameterFeedRateTotal', floatval($data['sensors']['parameterFeedRateTotal']));
                }
                if (isset($data['sensors']['parameterIgnitionCount'])) {
                    $this->SetValue('ParameterIgnitionCount', intval($data['sensors']['parameterIgnitionCount']));
                }

                // Software-Versionen
                if (isset($data['sensors']['parameterVersionMainBoard'])) {
                    $this->SetValue('SoftwareMain', strval($data['sensors']['parameterVersionMainBoard']));
                }
                if (isset($data['sensors']['parameterVersionTFT'])) {
                    $this->SetValue('SoftwareDisplay', strval($data['sensors']['parameterVersionTFT']));
                }
            }
        } else {
            $this->SetStatus(202);
        }
    }

    private function SetStoveControl($newData)
    {
        $stoveId = $this->ReadPropertyString('StoveID');
        $statusUrl = $this->baseUrl . "/api/client/" . $stoveId . "/status";
        
        $request = $this->firenetRequest($statusUrl);
        if ($request['code'] == 200) {
            $data = json_decode($request['body'], true);
            $controlsPayload = $data['controls'];

            foreach ($newData as $key => $val) {
                $controlsPayload[$key] = $val;
            }

            $controlUrl = $this->baseUrl . "/api/client/" . $stoveId . "/controls";
            $send = $this->firenetRequest($controlUrl, json_encode($controlsPayload));

            if ($send['code'] == 200) {
                IPS_Sleep(1000);
                $this->UpdateStatus();
            }
        }
    }

    private function firenetRequest($url, $postFields = null)
    {
        $cookieFile = sys_get_temp_dir() . '/rika_cookie_' . $this->InstanceID . '.txt';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);

        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (is_array($postFields)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => $response];
    }

    private function firenetLogin()
    {
        $loginPageUrl = $this->baseUrl . "/web/login";
        
        $initialRequest = $this->firenetRequest($loginPageUrl);
        if ($initialRequest['code'] != 200) return false;

        $postUrl = $loginPageUrl;
        if (preg_match('/action="([^"]+)"/', $initialRequest['body'], $actionMatches)) {
            $extractedAction = $actionMatches[1];
            $postUrl = (strpos($extractedAction, 'http') !== 0) ? $this->baseUrl . $extractedAction : $extractedAction;
        }

        $payload = [
            'email'    => $this->ReadPropertyString('Email'),
            'password' => $this->ReadPropertyString('Password')
        ];

        $result = $this->firenetRequest($postUrl, $payload);
        return (strpos($result['body'], 'logout') !== false || strpos($result['body'], '/web/logout') !== false);
    }
}
