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
                $this->SetStoveControl(
