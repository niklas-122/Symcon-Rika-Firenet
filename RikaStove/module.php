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

        // 2. NEU: Eigenes Profil für die Soll-Temperatur (Float)
        if (!IPS_VariableProfileExists('Rika.TargetTemp')) {
            // Profiltyp 2 ist Float (wichtig für die Temperatur-Variable)
            IPS_CreateVariableProfile('Rika.TargetTemp', 2); 
            // Wertebereich: Min 14.0, Max 28.0, Schrittweite 1.0
            IPS_SetVariableProfileValues('Rika.TargetTemp', 14.0, 28.0, 1.0);
            // 1 Nachkommastelle anzeigen und " °C" als Suffix anhängen
            IPS_SetVariableProfileDigits('Rika.TargetTemp', 1);
            IPS_SetVariableProfileText('Rika.TargetTemp', "", " °C");
            // Ein passendes Icon (Thermometer) zuweisen
            IPS_SetVariableProfileIcon('Rika.TargetTemp', "Temperature");
        }

        // 3. Variablen registrieren
        $this->RegisterVariableBoolean('Status', 'Ofen An/Aus', '~Switch');
        $this->EnableAction('Status');

        $this->RegisterVariableFloat('RoomTemperature', 'Raumtemperatur', '~Temperature');
        $this->RegisterVariableFloat('FlameTemperature', 'Flammentemperatur', '~Temperature');
        
        // HIER DIE ÄNDERUNG: Wir nutzen jetzt unser neues Profil 'Rika.TargetTemp'
        $this->RegisterVariableFloat('TargetTemperature', 'Soll-Temperatur', 'Rika.TargetTemp');
        $this->EnableAction('TargetTemperature');

        $this->RegisterVariableInteger('StoveState', 'Ofen Zustand', 'Rika.Status');

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

            if (isset($data['sensors'])) {
                $this->SetValue('RoomTemperature', floatval($data['sensors']['inputRoomTemperature']));
                $this->SetValue('FlameTemperature', floatval($data['sensors']['inputFlameTemperature']));
                $this->SetValue('StoveState', intval($data['sensors']['statusMainState']));
            }

            if (isset($data['controls'])) {
                $this->SetValue('Status', (bool)$data['controls']['onOff']);
                $this->SetValue('TargetTemperature', floatval($data['controls']['targetTemperature']));
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
