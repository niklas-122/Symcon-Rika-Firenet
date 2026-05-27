<?php

class RikaStove extends IPSModule {

    private $baseUrl = "https://www.rika-firenet.com";

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften im Formular registrieren
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StoveID", "");
        $this->RegisterPropertyInteger("Interval", 300); // Standard: 5 Minuten

        // Timer registrieren
        $this->RegisterTimer("UpdateTimer", 0, 'Rika_UpdateStatus($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // ----------------------------------------------------
        // VARIABLENPROFILE ERSTELLEN
        // ----------------------------------------------------

        // Ofen-Betriebszustand (Ist-Zustand)
        if (!IPS_VariableProfileExists('Rika.Status')) {
            IPS_CreateVariableProfile('Rika.Status', 1);
            IPS_SetVariableProfileAssociation('Rika.Status', 1, "Aus / Standby", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 2, "Zündphase", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 3, "Heizbetrieb", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 4, "Reinigung", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 5, "Ausbrand", "", -1);
        }

        // Betriebsmodus (Soll-Modus umschaltbar)
        if (!IPS_VariableProfileExists('Rika.OperatingMode')) {
            IPS_CreateVariableProfile('Rika.OperatingMode', 1);
            IPS_SetVariableProfileValues('Rika.OperatingMode', 0, 2, 1);
            IPS_SetVariableProfileAssociation('Rika.OperatingMode', 0, "Manueller Modus", "", -1);
            IPS_SetVariableProfileAssociation('Rika.OperatingMode', 1, "Automatik Modus", "", -1);
            IPS_SetVariableProfileAssociation('Rika.OperatingMode', 2, "Komfort Modus", "", -1);
            IPS_SetVariableProfileIcon('Rika.OperatingMode', "Gear");
        }

        // Soll-Temperatur Profil (14-28°C)
        if (!IPS_VariableProfileExists('Rika.TargetTemp')) {
            IPS_CreateVariableProfile('Rika.TargetTemp', 2); 
            IPS_SetVariableProfileValues('Rika.TargetTemp', 14.0, 28.0, 1.0);
            IPS_SetVariableProfileDigits('Rika.TargetTemp', 1);
            IPS_SetVariableProfileText('Rika.TargetTemp', "", " °C");
            IPS_SetVariableProfileIcon('Rika.TargetTemp', "Temperature");
        }

        // MultiAir Gebläsestufen (0-5)
        if (!IPS_VariableProfileExists('Rika.FanLevel')) {
            IPS_CreateVariableProfile('Rika.FanLevel', 1);
            IPS_SetVariableProfileValues('Rika.FanLevel', 0, 5, 1);
            IPS_SetVariableProfileIcon('Rika.FanLevel', "Ventilator");
        }

        // Laufzeiten in Stunden
        if (!IPS_VariableProfileExists('Rika.Hours')) {
            IPS_CreateVariableProfile('Rika.Hours', 2);
            IPS_SetVariableProfileDigits('Rika.Hours', 1);
            IPS_SetVariableProfileText('Rika.Hours', "", " h");
            IPS_SetVariableProfileIcon('Rika.Hours', "Clock");
        }

        // Pelletsverbrauch in Kilogramm
        if (!IPS_VariableProfileExists('Rika.Kg')) {
            IPS_CreateVariableProfile('Rika.Kg', 2);
            IPS_SetVariableProfileDigits('Rika.Kg', 1);
            IPS_SetVariableProfileText('Rika.Kg', "", " kg");
            IPS_SetVariableProfileIcon('Rika.Kg', "Box");
        }

        // Verbrauch pro Stunde (kg/h)
        if (!IPS_VariableProfileExists('Rika.KgPerHour')) {
            IPS_CreateVariableProfile('Rika.KgPerHour', 2);
            IPS_SetVariableProfileDigits('Rika.KgPerHour', 2);
            IPS_SetVariableProfileText('Rika.KgPerHour', "", " kg/h");
            IPS_SetVariableProfileIcon('Rika.KgPerHour', "Graph");
        }

        // WLAN Signalstärke
        if (!IPS_VariableProfileExists('Rika.Signal')) {
            IPS_CreateVariableProfile('Rika.Signal', 1);
            IPS_SetVariableProfileText('Rika.Signal', "", " dBm");
            IPS_SetVariableProfileIcon('Rika.Signal', "Signal");
        }

        // Fehlercodes
        if (!IPS_VariableProfileExists('Rika.Error')) {
            IPS_CreateVariableProfile('Rika.Error', 1);
            IPS_SetVariableProfileAssociation('Rika.Error', 0, "OK", "", 0x00FF00);
            IPS_SetVariableProfileAssociation('Rika.Error', 1, "Keine Pellets", "", 0xFF0000);
            IPS_SetVariableProfileAssociation('Rika.Error', 2, "Zündung fehlgeschlagen", "", 0xFF0000);
            IPS_SetVariableProfileAssociation('Rika.Error', 3, "Brennkammer offen / Tür", "", 0xFF0000);
            IPS_SetVariableProfileAssociation('Rika.Error', 4, "Sicherheitsschalter", "", 0xFF0000);
            IPS_SetVariableProfileAssociation('Rika.Error', 5, "Übertemperatur", "", 0xFF0000);
            IPS_SetVariableProfileIcon('Rika.Error', "Alert");
        }


        // ----------------------------------------------------
        // VARIABLEN REGISTRIEREN & ERZWUNGENE SORTIERUNG
        // ----------------------------------------------------

        // BLOCK 1: Aktive Ofensteuerung (Positionen 10-30)
        $this->RegisterVariableBoolean("Status", "Ofen An/Aus", "~Switch");
        $this->EnableAction("Status");
        IPS_SetPosition($this->GetIDForIdent("Status"), 10);

        $this->RegisterVariableInteger('OperatingMode', 'Betriebsmodus', 'Rika.OperatingMode');
        $this->EnableAction('OperatingMode');
        IPS_SetPosition($this->GetIDForIdent("OperatingMode"), 20);

        $this->RegisterVariableFloat("TargetTemperature", "Soll-Temperatur", "Rika.TargetTemp");
        $this->EnableAction("TargetTemperature");
        IPS_SetPosition($this->GetIDForIdent("TargetTemperature"), 30);

        // BLOCK 2: Aktuelle Status- & Temperaturwerte (Positionen 40-60)
        $this->RegisterVariableInteger("StoveState", "Ofen Zustand", "Rika.Status");
        IPS_SetPosition($this->GetIDForIdent("StoveState"), 40);

        $this->RegisterVariableFloat("RoomTemperature", "Raumtemperatur", "~Temperature");
        IPS_SetPosition($this->GetIDForIdent("RoomTemperature"), 50);

        $this->RegisterVariableFloat("FlameTemperature", "Flammentemperatur", "~Temperature");
        IPS_SetPosition($this->GetIDForIdent("FlameTemperature"), 60);

        // BLOCK 3: MultiAir Erweiterungen (Positionen 70-80)
        $this->RegisterVariableInteger('ConvectionFan1Level', 'MultiAir 1 Stufe', 'Rika.FanLevel');
        $this->EnableAction('ConvectionFan1Level');
        IPS_SetPosition($this->GetIDForIdent("ConvectionFan1Level"), 70);

        $this->RegisterVariableInteger('ConvectionFan2Level', 'MultiAir 2 Stufe', 'Rika.FanLevel');
        $this->EnableAction('ConvectionFan2Level');
        IPS_SetPosition($this->GetIDForIdent("ConvectionFan2Level"), 80);

        // BLOCK 4: Energieverbrauch & Zähler (Positionen 90-120)
        $this->RegisterVariableFloat('ParameterFeedRateTotal', 'Pelletsverbrauch Gesamt', 'Rika.Kg');
        IPS_SetPosition($this->GetIDForIdent("ParameterFeedRateTotal"), 90);

        $this->RegisterVariableFloat('ParameterRuntimePellets', 'Laufzeit Pellets', 'Rika.Hours');
        IPS_SetPosition($this->GetIDForIdent("ParameterRuntimePellets"), 100);

        $this->RegisterVariableFloat('AverageConsumptionPerHour', 'Verbrauch pro Stunde Ø', 'Rika.KgPerHour');
        IPS_SetPosition($this->GetIDForIdent("AverageConsumptionPerHour"), 110);

        $this->RegisterVariableInteger('ParameterIgnitionCount', 'Anzahl Zündungen', '');
        IPS_SetPosition($this->GetIDForIdent("ParameterIgnitionCount"), 120);

        // BLOCK 5: Systemdiagnose & Wartung (Positionen 130-170)
        $this->RegisterVariableInteger('StatusError', 'Fehlerzustand', 'Rika.Error');
        IPS_SetPosition($this->GetIDForIdent("StatusError"), 130);

        $this->RegisterVariableInteger('StatusWarning', 'Warnungscode', '');
        IPS_SetPosition($this->GetIDForIdent("StatusWarning"), 140);

        $this->RegisterVariableInteger('WifiStrength', 'WLAN Signalstärke', 'Rika.Signal');
        IPS_SetPosition($this->GetIDForIdent("WifiStrength"), 150);

        $this->RegisterVariableString('SoftwareMain', 'Software Hauptplatine', '');
        IPS_SetPosition($this->GetIDForIdent("SoftwareMain"), 160);

        $this->RegisterVariableString('SoftwareDisplay', 'Software Display', '');
        IPS_SetPosition($this->GetIDForIdent("SoftwareDisplay"), 170);


        // Timer aktivieren anhand des konfigurierten Intervalls
        $interval = $this->ReadPropertyInteger("Interval");
        $this->SetTimerInterval("UpdateTimer", $interval * 1000);
    }

    // Ermöglicht das Schalten aus dem WebFront / Kachel-Visu
    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case "Status":
                $this->SetStoveControl(['onOff' => (bool)$Value]);
                break;
            case "OperatingMode":
                $this->SetStoveControl(['operatingMode' => (int)$Value]);
                break;
            case "TargetTemperature":
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

    // Öffentliche Funktion, um den Status manuell oder per Timer zu triggern
    public function UpdateStatus() {
        $stoveId = $this->ReadPropertyString("StoveID");
        if (empty($stoveId)) return;

        $statusUrl = $this->baseUrl . "/api/client/" . $stoveId . "/status";
        $request = $this->firenetRequest($statusUrl);

        // Bei 401 oder wenn wir die Login-Seite sehen -> neu einloggen
        if ($request['code'] == 401 || strpos($request['body'], 'login') !== false || empty($request['body'])) {
            if ($this->firenetLogin()) {
                $request = $this->firenetRequest($statusUrl);
            } else {
                $this->SetStatus(201); // Instanz-Fehler: Login fehlgeschlagen
                return;
            }
        }

        if ($request['code'] == 200) {
            $data = json_decode($request['body'], true);
            $this->SetStatus(102); // Instanz ist aktiv

            // 1. Zuweisung: Steuerungswerte (Controls)
            if (isset($data['controls'])) {
                $this->SetValue("Status", (bool)$data['controls']['onOff']);
                $this->SetValue('OperatingMode', intval($data['controls']['operatingMode']));
                $this->SetValue("TargetTemperature", floatval($data['controls']['targetTemperature']));
                $this->SetValue('ConvectionFan1Level', intval($data['controls']['convectionFan1Level']));
                $this->SetValue('ConvectionFan2Level', intval($data['controls']['convectionFan2Level']));
            }

            // 2. Zuweisung: Sensor- & Diagnosedaten (Sensors)
            if (isset($data['sensors'])) {
                $this->SetValue("RoomTemperature", floatval($data['sensors']['inputRoomTemperature']));
                $this->SetValue("FlameTemperature", floatval($data['sensors']['inputFlameTemperature']));
                $this->SetValue("StoveState", intval($data['sensors']['statusMainState']));
                
                $this->SetValue('StatusError', intval($data['sensors']['statusError']));
                $this->SetValue('StatusWarning', intval($data['sensors']['statusWarning']));
                $this->SetValue('WifiStrength', intval($data['sensors']['statusWifiStrength']));

                $runtime = 0.0;
                $feedRate = 0.0;

                if (isset($data['sensors']['parameterRuntimePellets'])) {
                    $runtime = floatval($data['sensors']['parameterRuntimePellets']);
                    $this->SetValue('ParameterRuntimePellets', $runtime);
                }
                if (isset($data['sensors']['parameterFeedRateTotal'])) {
                    $feedRate = floatval($data['sensors']['parameterFeedRateTotal']);
                    $this->SetValue('ParameterFeedRateTotal', $feedRate);
                }
                if (isset($data['sensors']['parameterIgnitionCount'])) {
                    $this->SetValue('ParameterIgnitionCount', intval($data['sensors']['parameterIgnitionCount']));
                }

                // Berechnung des Durchschnitt-Verbrauchs
                if ($runtime > 0 && $feedRate > 0) {
                    $avgConsumption = $feedRate / $runtime;
                    $this->SetValue('AverageConsumptionPerHour', $avgConsumption);
                } else {
                    $this->SetValue('AverageConsumptionPerHour', 0.0);
                }

                if (isset($data['sensors']['parameterVersionMainBoard'])) {
                    $this->SetValue('SoftwareMain', strval($data['sensors']['parameterVersionMainBoard']));
                }
                if (isset($data['sensors']['parameterVersionTFT'])) {
                    $this->SetValue('SoftwareDisplay', strval($data['sensors']['parameterVersionTFT']));
                }
            }
        } else {
            $this->SetStatus(202); // Instanz-Fehler: Datenabruf fehlgeschlagen
        }
    }

    // Interne Funktion zum Senden von Steuerbefehlen
    private function SetStoveControl($newData) {
        $stoveId = $this->ReadPropertyString("StoveID");
        $statusUrl = $this->baseUrl . "/api/client/" . $stoveId . "/status";
        
        // Erst aktuellen Zustand holen, um das vollständige Objekt zu manipulieren
        $request = $this->firenetRequest($statusUrl);
        if ($request['code'] == 200) {
            $data = json_decode($request['body'], true);
            $controlsPayload = $data['controls'];

            // Neue Werte einmischen (z.B. onOff oder targetTemperature)
            foreach ($newData as $key => $val) {
                $controlsPayload[$key] = $val;
            }

            $controlUrl = $this->baseUrl . "/api/client/" . $stoveId . "/controls";
            $send = $this->firenetRequest($controlUrl, json_encode($controlsPayload));

            if ($send['code'] == 200) {
                // Kurz warten, damit der Rika-Server den Zustand verarbeitet, dann Werte neu einlesen
                IPS_Sleep(1000);
                $this->UpdateStatus();
            }
        }
    }

    // Basis cURL Request-Handler
    private function firenetRequest($url, $postFields = null) {
        $cookieFile = sys_get_temp_dir() . '/rika_cookie_' . $this->InstanceID . '.txt';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);

        if ($postFields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (is_array($postFields)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => $response];
    }

    // Login-Prozess
    private function firenetLogin() {
        $loginPageUrl = $this->baseUrl . "/web/login";
        
        // Initiale Session holen
        $initialRequest = $this->firenetRequest($loginPageUrl);
        if ($initialRequest['code'] != 200) return false;

        $postUrl = $loginPageUrl;
        if (preg_match('/action="([^"]+)"/', $initialRequest['body'], $actionMatches)) {
            $extractedAction = $actionMatches[1];
            $postUrl = (strpos($extractedAction, 'http') !== 0) ? $this->baseUrl . $extractedAction : $extractedAction;
        }

        $payload = [
            'email'    => $this->ReadPropertyString("Email"),
            'password' => $this->ReadPropertyString("Password")
        ];

        $result = $this->firenetRequest($postUrl, $payload);
        
        // cURL folgt dem Redirect. Wenn wir eingeloggt sind, sind wir nicht mehr auf /login
        return (strpos($result['body'], 'logout') !== false || strpos($result['body'], '/web/logout') !== false);
    }
}
