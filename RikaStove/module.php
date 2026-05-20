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

        // ----------------------------------------------------
        // VARIABLENPROFILE ERSTELLEN
        // ----------------------------------------------------

        // Ofen-Betriebszustand (Ist-Zustand vom Ofen)
        if (!IPS_VariableProfileExists('Rika.Status')) {
            IPS_CreateVariableProfile('Rika.Status', 1);
            IPS_SetVariableProfileAssociation('Rika.Status', 1, "Aus / Standby", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 2, "Zündphase", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 3, "Heizbetrieb", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 4, "Reinigung", "", -1);
            IPS_SetVariableProfileAssociation('Rika.Status', 5, "Ausbrand", "", -1);
        }

        // Betriebsmodus (Gewünschtes Programm zum Umschalten)
        if (!IPS_VariableProfileExists('Rika.OperatingMode')) {
            IPS_CreateVariableProfile('Rika.OperatingMode', 1);
            IPS_SetVariableProfileValues('Rika.OperatingMode', 0, 2, 1);
            IPS_SetVariableProfileAssociation('Rika.OperatingMode', 0, "Manueller Modus", "", -1);
            IPS_SetVariableProfileAssociation('Rika.OperatingMode', 1, "Automatik Modus", "", -1);
            IPS_SetVariableProfileAssociation('Rika.OperatingMode', 2, "Komfort Modus", "", -1);
            IPS_SetVariableProfileIcon('Rika.OperatingMode', "Gear");
        }

        // Soll-Temperatur (14-28°C)
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
        // VARIABLEN REGISTRIEREN (LOGISCH SORTIERT)
        // ----------------------------------------------------

        // BLOCK 1: Steuerung & Modus
        $this->RegisterVariableBoolean('Status', 'Ofen An/Aus', '~Switch');
        $this->EnableAction('Status');

        $this->RegisterVariableInteger('OperatingMode', 'Betriebsmodus', 'Rika.OperatingMode');
        $this->EnableAction('OperatingMode');

        $this->RegisterVariableFloat('TargetTemperature', 'Soll-Temperatur', 'Rika.TargetTemp');
        $this->EnableAction('TargetTemperature');


        // BLOCK 2: Ist-Werte & Temperaturen
        $this->RegisterVariableInteger('StoveState', 'Ofen Zustand', 'Rika.Status');
        $this->RegisterVariableFloat('RoomTemperature', 'Raumtemperatur', '~Temperature');
        $this->RegisterVariableFloat('FlameTemperature', 'Flammentemperatur', '~Temperature');


        // BLOCK 3: MultiAir Gebläse
        $this->RegisterVariableInteger('ConvectionFan1Level', 'MultiAir 1 Stufe', 'Rika.FanLevel');
        $this->EnableAction('ConvectionFan1Level');

        $this->RegisterVariableInteger('ConvectionFan2Level', 'MultiAir 2 Stufe', 'Rika.FanLevel');
        $this->EnableAction('ConvectionFan2Level');


        // BLOCK 4: Verbrauch & Zähler
        $this->RegisterVariableFloat('ParameterFeedRateTotal', 'Pelletsverbrauch', 'Rika.Kg');
        $this->RegisterVariableFloat('ParameterRuntimePellets', 'Laufzeit Pellets', 'Rika.Hours');
        $this->RegisterVariableInteger('ParameterIgnitionCount', 'Anzahl Zündungen', '');


        // BLOCK 5: System & Diagnose
        $this->RegisterVariableInteger('StatusError', 'Fehlerzustand', 'Rika.Error');
        $this->RegisterVariableInteger('StatusWarning', 'Warnungscode', '');
        $this->RegisterVariableInteger('WifiStrength', 'WLAN Signalstärke', 'Rika.Signal');
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
            case 'OperatingMode':
                $this->SetStoveControl(['operatingMode' => (int)$Value]);
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

            // 1. Mapping: Controls (Soll-Werte / Modi)
            if (isset($data['controls'])) {
                $this->SetValue('Status', (bool)$data['controls']['onOff']);
                $this->SetValue('OperatingMode', intval($data['controls']['operatingMode']));
                $this->SetValue('TargetTemperature', floatval($data['controls']['targetTemperature']));
                $this->SetValue('ConvectionFan1Level', intval($data['controls']['convectionFan1Level']));
                $this->SetValue('ConvectionFan2Level', intval($data['controls']['convectionFan2Level']));
            }

            // 2. Mapping: Sensors (Ist-Werte / Diagnose)
            if (isset($data['sensors'])) {
                $this->SetValue('RoomTemperature', floatval($data['sensors']['inputRoomTemperature']));
                $this
