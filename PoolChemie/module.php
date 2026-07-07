<?php

declare(strict_types=1);

class PoolChemie extends IPSModule
{

private const MQTT_SERVER_MODULE = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    // Daten vom MQTT Server zum PoolChemie Modul
private const MQTT_RX_DATA_ID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    // Daten vom PoolChemie Modul zum MQTT Server
private const MQTT_TX_DATA_ID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';



public function Create()
{
    parent::Create();

    //MQTT Topic    
    $this->RegisterPropertyString('BaseTopic', 'Pool/Chemiewaage');
    //Anzahl Waagen
    $this->RegisterPropertyInteger('ScaleCount', 2);
    $this->RegisterPropertyString('ScaleConfig',json_encode($this->GetDefaultScaleConfig()));

    // Verbrauchslogging
    $this->RegisterAttributeString('LastDailyResetDate', '');
    $this->RegisterTimer('DailyResetTimer', 60000,'POOLCHEMIE_CheckDailyReset($_IPS["TARGET"]);');

    // Begrenzung der Verarbeitung
    $this->RegisterPropertyFloat('MinWeightDelta', 0.02);     // 20 g
    $this->RegisterPropertyInteger('MinUpdateInterval', 5);   // 5 Sekunden
    $this->RegisterPropertyFloat('MaxUpdateDiff', 0.5);       // Gewichtssprünge größer 0,5kg werden ignoriert in der Verbrauchsberechnung

    // Nachrichtensystem        
    $this->RegisterPropertyString('NotificationTime', '09:00');
    $this->RegisterAttributeString('LastThresholdNotificationDate', '');
    $this->RegisterTimer('ThresholdNotificationTimer',60000,'POOLCHEMIE_CheckThresholdNotification($_IPS["TARGET"],false);');
    $this->RegisterPropertyBoolean('NotificationEnabled', true);
    $this->RegisterPropertyInteger('NotificationViewID', 0);
    $this->RegisterPropertyInteger('NotificationControlID', 0); // 0 = automatisch finde

    // Debuging einschalen
    $this->RegisterPropertyBoolean('DebugEnabled', false);

    for ($i = 1; $i <= 4; $i++) {               
        $this->RegisterAttributeBoolean('IgnoreNextConsumption_' . $i, false);
        
    }

    $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
}

public function Destroy()
{
    parent::Destroy();
}

public function ApplyChanges()
{
    parent::ApplyChanges();

    $this->RegisterProfiles();

    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scaleCount < 1) {
        $scaleCount = 1;
    }

    if ($scaleCount > 4) {
        $scaleCount = 4;
    }


    for ($i = 1; $i <= $scaleCount; $i++) {
        $this->CreateScaleVariables($i);


        if ($this->IsScaleItemActive($i, 'ConsumptionTotal')) {
            $this->EnableArchiveForConsumptionTotal($i);
        }
    }


    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $pattern = '.*' . preg_quote($baseTopic, '/') . '.*';

    $this->SetReceiveDataFilter($pattern);

    IPS_LogMessage('PoolChemie', 'ApplyChanges ausgeführt. MQTT Filter: ' . $pattern);
}
// Variablenprofile Registrienen
private function RegisterProfiles(): void
{
    if (!IPS_VariableProfileExists('POOLCHEMIE.Kilogramm')) {
        IPS_CreateVariableProfile('POOLCHEMIE.Kilogramm', 2);
        IPS_SetVariableProfileText('POOLCHEMIE.Kilogramm', '', ' kg');
        IPS_SetVariableProfileDigits('POOLCHEMIE.Kilogramm', 3);
    }

    if (!IPS_VariableProfileExists('POOLCHEMIE.Button')) {
        IPS_CreateVariableProfile('POOLCHEMIE.Button', 1);
        IPS_SetVariableProfileAssociation(
            'POOLCHEMIE.Button',
            1,
            'Auslösen',
            '',
            0x00AA00
        );
    }

    if (!IPS_VariableProfileExists('POOLCHEMIE.DeleteButton')) {
        IPS_CreateVariableProfile('POOLCHEMIE.DeleteButton', 1);
        IPS_SetVariableProfileAssociation(
            'POOLCHEMIE.DeleteButton',
            1,
            'Löschen',
            '',
            0xCC0000
        );
    }
}
// Variablen angelegen / umbenennen / anzeigen
private function CreateScaleVariables(int $scale): void
{    
    $name = $this->GetChemicalName($scale);

    if ($this->IsScaleItemActive($scale, 'Weight')) {
        $this->RegisterVariableFloat(
            'Weight_' . $scale,
            $name . ' Gewicht',
            'POOLCHEMIE.Kilogramm',
            1
        );
        $this->RenameVariableIfExists('Weight_' . $scale,$name . ' Gewicht');
        IPS_SetHidden($this->GetIDForIdent('Weight_' . $scale), false);
    } else {
        $this->HideVariableIfExists('Weight_' . $scale);
    }


    if ($this->IsScaleItemActive($scale, 'Tare')) {
        $this->RegisterVariableFloat(
            'Tare_' . $scale,
            $name . ' Tara',
            'POOLCHEMIE.Kilogramm',
            1
        );
        $this->RenameVariableIfExists('Tare_' . $scale,$name . ' Tara');
        IPS_SetHidden($this->GetIDForIdent('Tare_' . $scale), false);
    } else {
        $this->HideVariableIfExists('Tare_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionDay')) {
        $this->RegisterVariableFloat(
            'ConsumptionDay_' . $scale,
            $name . ' Verbrauch Tag',
            'POOLCHEMIE.Kilogramm',
            1
        );
        $this->RenameVariableIfExists('ConsumptionDay_' . $scale,$name . ' Verbrauch Tag');
        IPS_SetHidden($this->GetIDForIdent('ConsumptionDay_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionDay_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionToday')) {
        $this->RegisterVariableFloat(
            'ConsumptionToday_' . $scale,
            $name . ' Verbrauch Heute',
            'POOLCHEMIE.Kilogramm',
            1
        );
        $this->RenameVariableIfExists('ConsumptionToday_' . $scale,$name . ' Verbrauch Heute');
        IPS_SetHidden($this->GetIDForIdent('ConsumptionToday_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionToday_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionTotal')) {
        $this->RegisterVariableFloat(
            'ConsumptionTotal_' . $scale,
            $name . ' Verbrauch Gesamt',
            'POOLCHEMIE.Kilogramm',
            1
        );
        $this->RenameVariableIfExists('ConsumptionTotal_' . $scale,$name . ' Verbrauch Gesamt');
        IPS_SetHidden($this->GetIDForIdent('ConsumptionTotal_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionTotal_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionEnabled')) {
        $this->RegisterVariableBoolean(
            'ConsumptionEnabled_' . $scale,
            $name . ' Verbrauch aktiv',
            '~Switch',
            1
        );
        $this->EnableAction('ConsumptionEnabled_' . $scale);
        $this->RenameVariableIfExists('ConsumptionEnabled_' . $scale,$name . ' Verbrauch aktiv');
        IPS_SetHidden($this->GetIDForIdent('ConsumptionEnabled_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionEnabled_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'TareButton')) {
        $this->RegisterVariableInteger(
            'TareButton_' . $scale,
            $name . ' Tara auslösen',
            'POOLCHEMIE.Button',
            1
        );        
        $this->EnableAction('TareButton_' . $scale);
        $this->RenameVariableIfExists('TareButton_' . $scale,$name . ' Tara auslösen');
        IPS_SetHidden($this->GetIDForIdent('TareButton_' . $scale), false);
    } else {
        $this->HideVariableIfExists('TareButton_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ClearTareButton')) {
        $this->RegisterVariableInteger(
            'ClearTareButton_' . $scale,
            $name . ' Tara löschen',
            'POOLCHEMIE.DeleteButton',
            1
        );        
        $this->EnableAction('ClearTareButton_' . $scale);
        $this->RenameVariableIfExists('ClearTareButton_' . $scale,$name . ' Tara löschen');
        IPS_SetHidden($this->GetIDForIdent('ClearTareButton_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ClearTareButton_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ResetTotalButton')) {
        $this->RegisterVariableInteger(
            'ResetTotalButton_' . $scale,
            $name . ' Gesamtverbrauch löschen',
            'POOLCHEMIE.DeleteButton',
            1
        );
        $this->EnableAction('ResetTotalButton_' . $scale);
        $this->RenameVariableIfExists('ResetTotalButton_' . $scale,$name . ' Gesamtverbrauch löschen');
        IPS_SetHidden($this->GetIDForIdent('ResetTotalButton_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ResetTotalButton_' . $scale);
    }
}
//Hilfsfunktion umbenennen der Variablenm, falls existent
private function RenameVariableIfExists(string $ident, string $name): void
{
    $id = @$this->GetIDForIdent($ident);

    if ($id !== false) {
        IPS_SetName($id, $name);
    }
}

//Hilfsfunktion Logging für Verbrauch einschalten
private function EnableArchiveForConsumptionTotal(int $scale): void
{
    $ident = 'ConsumptionTotal_' . $scale;
    $variableID = @$this->GetIDForIdent($ident);

    if ($variableID === false) {
        return;
    }

    $archiveID = @IPS_GetInstanceIDByName('Archive', 0);

    if ($archiveID === false) {
        $archiveID = @IPS_GetInstanceIDByName('Archiv', 0);
    }

    if ($archiveID === false) {
        $this->Debug(
            'Archiv',
            'Keine Archiv-Control-Instanz gefunden.',
            0
        );
        return;
    }

    AC_SetLoggingStatus($archiveID, $variableID, true);
    AC_SetAggregationType($archiveID, $variableID, 1);
    AC_SetCounterIgnoreZeros($archiveID, $variableID, true);
    IPS_ApplyChanges($archiveID);

    $this->Debug(
        'Archiv',
        'Archivierung für ' . $ident . ' aktiviert. Archiv-ID=' . $archiveID,
        0
    );
}

//Hilfsfunktion Ausblenden der Variable falls Existen
private function HideVariableIfExists(string $ident): void
{
    $id = @$this->GetIDForIdent($ident);

    if ($id !== false) {
        IPS_SetHidden($id, true);
    }
}

private function IsScaleItemActive(int $scale, string $ident): bool
{
    $row = $this->GetScaleConfigRow($scale);

    return (bool)($row[$ident] ?? false);
}

public function RequestAction($Ident, $Value)
{
    $this->Debug(
        'RequestAction',
        'Ident=' . $Ident . ' Value=' . print_r($Value, true),
        0
    );

    
    if (preg_match('/^ConsumptionEnabled_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        SetValue($this->GetIDForIdent($Ident), (bool)$Value);

        // Beim Umschalten wird die nächste Verbrauchsberechnung übersprungen.
        $this->WriteAttributeBoolean('IgnoreNextConsumption_' . $scale, true);

        $this->Debug(
            'Verbrauchsmodus',
            'Waage ' . $scale .
            ' Verbrauch aktiv = ' .
            ((bool)$Value ? 'JA' : 'NEIN') .
            '. Naechste Verbrauchsberechnung wird uebersprungen.',
            0
        );

        return;
    }

    if (preg_match('/^TareButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        $this->Debug('Tare', 'TareButton gedrückt für Waage ' . $scale, 0);

        $this->SendTare($scale);

        SetValue($this->GetIDForIdent($Ident), 0);
        return;
    }

    if (preg_match('/^ClearTareButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        $this->Debug('TareClear', 'ClearTareButton gedrückt für Waage ' . $scale, 0);

        $this->SendClearTare($scale);

        SetValue($this->GetIDForIdent($Ident), 0);
        return;
    }

    if (preg_match('/^ResetTotalButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        SetValue($this->GetIDForIdent('ConsumptionTotal_' . $scale), 0.0);
        SetValue($this->GetIDForIdent($Ident), 0);
        return;
    }

    throw new Exception('Ungültiger Ident: ' . $Ident);
}

private function SendTare(int $scale): void
{
    $this->WriteAttributeBoolean('IgnoreNextConsumption_' . $scale, true);
    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $this->PublishMQTT(
        $baseTopic . '/cmd/waage' . $scale . '/tare',
        '1',
        false
    );
}

private function SendClearTare(int $scale): void
{
    $this->WriteAttributeBoolean('IgnoreNextConsumption_' . $scale, true);
    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $this->PublishMQTT(
        $baseTopic . '/cmd/waage' . $scale . '/tare_clear',
        '1',
        false
    );
}

// Daten von der MQTT-Schnittstelle empfangen
public function ReceiveData($JSONString)
{
    $data = json_decode($JSONString, true);

    if (!is_array($data)) {
        return '';
    }

    $topic = $data['Topic'] ?? '';
    $payload = $data['Payload'] ?? null;

    if ($topic === '' || $payload === null) {
        return '';
    }

    $this->Debug('ReceiveData',$payload,0);
    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    for ($scale = 1; $scale <= 4; $scale++) {
        if ($topic === $baseTopic . '/sensor/waage' . $scale . '/state') {
            $this->ProcessWeight($scale, (float)$payload);
            return '';
        }

        if ($topic === $baseTopic . '/sensor/waage' . $scale . '_tara/state') {
            $this->ProcessTare($scale, (float)$payload);
            return '';
        }
    }

    return '';
}

//Aktualisiert die Gewichtsvariable
private function ProcessWeight(int $scale, float $weight): void
{
    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scale > $scaleCount) {
        return;
    }

    if (!$this->IsScaleItemActive($scale, 'Weight')) {
        return;
    }

    $this->CheckDailyReset();

    $weightID = @$this->GetIDForIdent('Weight_' . $scale);

    if ($weightID === false) {
        return;
    }

    $oldWeight = (float)GetValue($weightID);

    $variableInfo = IPS_GetVariable($weightID);
    $lastChanged = (int)$variableInfo['VariableChanged'];

    $now = time();

    $minDelta = $this->ReadPropertyFloat('MinWeightDelta');
    $minInterval = $this->ReadPropertyInteger('MinUpdateInterval');

    $deltaReached = abs($oldWeight - $weight) >= $minDelta;
    $intervalReached = ($now - $lastChanged) >= $minInterval;

    if (!$intervalReached) {
        return;
    }

    if (!$deltaReached) {
        return;
    }

    // Gewicht schreiben
    SetValue($weightID, $weight);

    $this->Debug(
        'ProcessWeight',
        'Waage ' . $scale .
        ': Gewicht von ' . number_format($oldWeight, 3) .
        ' kg auf ' . number_format($weight, 3) . ' kg gesetzt.',
        0
    );

    // Verbrauch prüfen
    $enabledID = @$this->GetIDForIdent('ConsumptionEnabled_' . $scale);

    if ($enabledID === false || !GetValue($enabledID)) {
        return;
    }

    // Direkt nach Umschalten Verbrauch aktiv: einmal überspringen
    if ($this->ReadAttributeBoolean('IgnoreNextConsumption_' . $scale)) {
        $this->WriteAttributeBoolean('IgnoreNextConsumption_' . $scale, false);

        $this->Debug(
            'Verbrauch',
            'Waage ' . $scale .
            ': Erste Messung nach Umschaltung ignoriert.',
            0
        );

        return;
    }

    // Verbrauch berechnen
    $this->CalculateConsumption($scale, $oldWeight, $weight);
}

private function CalculateConsumption(int $scale, float $oldWeight, float $newWeight): void
{
    $diff = $oldWeight - $newWeight;

    // Gewicht gestiegen oder gleich geblieben = kein Verbrauch
    $maxupdatediff = $this->ReadPropertyFloat('MaxUpdateDiff');

    if (abs($diff) >= $maxupdatediff) {
        return;
    }

    $todayID = @$this->GetIDForIdent('ConsumptionToday_' . $scale);
    $totalID = @$this->GetIDForIdent('ConsumptionTotal_' . $scale);

    if ($todayID !== false) {
        SetValue($todayID, GetValue($todayID) + $diff);
    }

    if ($totalID !== false) {
        SetValue($totalID, GetValue($totalID) + $diff);
    }

    $this->Debug(
        'Verbrauch',
        'Waage ' . $scale .
        ': Alt=' . number_format($oldWeight, 3) .
        ' kg Neu=' . number_format($newWeight, 3) .
        ' kg Verbrauch=' . number_format($diff, 3) . ' kg',
        0
    );
}

//Aktualisiert die Tarevariable
private function ProcessTare(int $scale, float $tare): void
{
    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scale > $scaleCount) {
        return;
    }

    if (!$this->IsScaleItemActive($scale, 'Tare')) {
        return;
    }
 
    $ident = 'Tare_' . $scale;
    $id = @$this->GetIDForIdent($ident);

    
    if ($id !== false) {
        $oldTare = (float)GetValue($id);

        if (abs($oldTare - $tare) > 0.01) {
            SetValue($id, $tare);
        }
    }
}

private function PublishMQTT(string $topic, string $payload, bool $retain = false, int $qos = 0): void
{
    $data = [
        'DataID'           => self::MQTT_TX_DATA_ID,
        'PacketType'       => 3,
        'QualityOfService' => $qos,
        'Retain'           => $retain,
        'Topic'            => $topic,
        'Payload'          => $payload
    ];

    $json = json_encode($data);

    $this->Debug('MQTT TX', $json, 0);

    $this->SendDataToParent($json);
}


public function CheckDailyReset(): void
{
    $today = date('Y-m-d');
    $lastReset = $this->ReadAttributeString('LastDailyResetDate');

    if ($lastReset === $today) {
        return;
    }

    // Beim allerersten Start nur Datum setzen, aber nichts verschieben
    if ($lastReset === '') {
        $this->WriteAttributeString('LastDailyResetDate', $today);
        return;
    }

    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scaleCount < 1) {
        $scaleCount = 1;
    }

    if ($scaleCount > 4) {
        $scaleCount = 4;
    }

    for ($scale = 1; $scale <= $scaleCount; $scale++) {
        $todayID = @$this->GetIDForIdent('ConsumptionToday_' . $scale);
        $dayID = @$this->GetIDForIdent('ConsumptionDay_' . $scale);

        if ($todayID !== false && $dayID !== false) {
            $todayValue = GetValue($todayID);

            SetValue($dayID, $todayValue);
            SetValue($todayID, 0.0);
        }
    }

    $this->WriteAttributeString('LastDailyResetDate', $today);

    $this->Debug(
        'Tageswechsel',
        'Tagesverbrauch wurde abgeschlossen und Verbrauch Heute wurde zurückgesetzt.',
        0
    );
}

// Benachrichtigungssystem für Bestandsschwelle unterschritten
private function GetNotificationControlID(): int
{
    $manualID = $this->ReadPropertyInteger('NotificationControlID');

    if ($manualID > 0 && IPS_InstanceExists($manualID)) {
        return $manualID;
    }

    foreach (IPS_GetInstanceList() as $instanceID) {
        $instance = IPS_GetInstance($instanceID);

        if (!isset($instance['ModuleInfo']['ModuleID'])) {
            continue;
        }

        $moduleID = $instance['ModuleInfo']['ModuleID'];
        $module = IPS_GetModule($moduleID);

        $moduleName = $module['ModuleName'] ?? '';
        $prefix = $module['Prefix'] ?? '';

        if ($moduleName === 'Notification Control' || $prefix === 'NC') {
            return $instanceID;
        }
    }

    return 0;
}

private function SendModuleNotification(string $title, string $message): void
{

    $this->Debug(
        'Benachrichtigung',
        $title . ': ' . $message,
        0
    );

    if (!$this->ReadPropertyBoolean('NotificationEnabled')) {
        return;
    }

    if (!function_exists('NC_PushNotification')) {
        IPS_LogMessage(
            'PoolChemie Benachrichtigung',
            'NC_PushNotification ist auf diesem System nicht verfügbar. Nachricht wurde nur ins Log geschrieben: ' . $message
        );
        return;
    }

    $notificationControlID = $this->GetNotificationControlID();

    if ($notificationControlID <= 0 || !IPS_InstanceExists($notificationControlID)) {
        IPS_LogMessage(
            'PoolChemie Benachrichtigung',
            'Keine gültige Notification-Control-Instanz gefunden.'
        );
        return;
    }

    $viewID = $this->ReadPropertyInteger('NotificationViewID');

    if ($viewID <= 0 || !IPS_ObjectExists($viewID)) {
        IPS_LogMessage(
            'PoolChemie Benachrichtigung',
            'Keine gültige View-ID für Push-Benachrichtigung konfiguriert.'
        );

        return;
    }

    // NC_PushNotification erwartet kurze Texte.
    $title = mb_substr($title, 0, 32);
    $message = mb_substr($message, 0, 256);

    NC_PushNotification(
        $notificationControlID,
        $viewID,
        $title,
        $message,
        'alarm'
    );
}

public function CheckThresholdNotification(bool $force = false): void
{
    $configuredTime = $this->ReadPropertyString('NotificationTime');

    if (!$force) {
        if (!preg_match('/^\d{2}:\d{2}$/', $configuredTime)) {
            return;
        }

        $nowTime = date('H:i');

        if ($nowTime !== $configuredTime) {
            return;
        }

        $today = date('Y-m-d');
        $lastNotificationDate = $this->ReadAttributeString('LastThresholdNotificationDate');

        if ($lastNotificationDate === $today) {
            return;
        }
    } else {
        $today = date('Y-m-d');
    }

    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scaleCount < 1) {
        $scaleCount = 1;
    }

    if ($scaleCount > 4) {
        $scaleCount = 4;
    }

    $chemicals = [];

    for ($scale = 1; $scale <= $scaleCount; $scale++) {
        if (!$this->IsScaleItemActive($scale, 'Weight')) {
            continue;
        }

        $weightID = @$this->GetIDForIdent('Weight_' . $scale);

        if ($weightID === false) {
            continue;
        }

        $weight = GetValue($weightID);
        $threshold = $this->GetScaleThreshold($scale);

        if ($weight < $threshold) {
            $chemicals[] = $this->GetChemicalName($scale);
        }
    }

    if (count($chemicals) === 0) {
        if (!$force) {
            $this->WriteAttributeString('LastThresholdNotificationDate', $today);
        }

        return;
    }

    $message = $this->BuildThresholdMessage($chemicals);

    $this->SendModuleNotification(
        '⚠️ PoolChemie:',
        $message
    );

    if (!$force) {
        $this->WriteAttributeString('LastThresholdNotificationDate', $today);
    }
}

private function GetScaleThreshold(int $scale): float
{
    $row = $this->GetScaleConfigRow($scale);

    return (float)($row['Threshold'] ?? 0.0);
}
private function BuildThresholdMessage(array $chemicals): string
{
    if (count($chemicals) === 1) {
        $chemical = $chemicals[0];

        return 'Chemie "' . $chemical . '" Schwellwert unterschritten. Bitte Chemie "' . $chemical . '" nachbestellen.';
    }

    $list = implode(', ', $chemicals);

    return 'Folgende Chemikalien haben den Schwellwert unterschritten: ' .
        $list .
        '. Bitte diese Chemikalien nachbestellen.';
}

private function Debug(string $title, string $message): void
{
    if ($this->ReadPropertyBoolean('DebugEnabled')) {
        $this->Debug($title, $message, 0);
    }
}


// Konfigurationsformular Konsole
public function GetConfigurationForm()
{
    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scaleCount < 1) {
        $scaleCount = 1;
    }

    if ($scaleCount > 4) {
        $scaleCount = 4;
    }

    $elements = [];
    $actions = [];

    /*
     * Allgemein
     */
    $elements[] = [
        'type' => 'Label',
        'caption' => 'Allgemein'
    ];

    $elements[] = [
        'type' => 'ValidationTextBox',
        'name' => 'BaseTopic',
        'caption' => 'MQTT Basistopic'
    ];

    $elements[] = [
        'type' => 'Select',
        'name' => 'ScaleCount',
        'caption' => 'Anzahl Waagen',
        'options' => [
            [
                'caption' => '1',
                'value' => 1
            ],
            [
                'caption' => '2',
                'value' => 2
            ],
            [
                'caption' => '3',
                'value' => 3
            ],
            [
                'caption' => '4',
                'value' => 4
            ]
        ]
    ];

       $elements[] = [
        'type' => 'CheckBox',
        'name' => 'DebugEnabled',
        'caption' => 'Debuging  aktivieren'
    ];

    /*
     * Benachrichtigung
     */
    $elements[] = [
        'type' => 'Label',
        'caption' => 'Benachrichtigung'
    ];

    $elements[] = [
        'type' => 'CheckBox',
        'name' => 'NotificationEnabled',
        'caption' => 'Benachrichtigung aktivieren'
    ];

    $elements[] = [
        'type' => 'ValidationTextBox',
        'name' => 'NotificationTime',
        'caption' => 'Benachrichtigungszeit HH:MM'
    ];

    $elements[] = [
        'type' => 'NumberSpinner',
        'name' => 'NotificationViewID',
        'caption' => 'View-ID für Push-Benachrichtigung',
        'minimum' => 0,
        'maximum' => 999999
    ];

    $elements[] = [
        'type' => 'SelectInstance',
        'name' => 'NotificationControlID',
        'caption' => 'Notification Control Instanz optional'
    ];

    /*
     * Verarbeitung
     */
    $elements[] = [
        'type' => 'Label',
        'caption' => 'Verarbeitung'
    ];

    $elements[] = [
        'type' => 'NumberSpinner',
        'name' => 'MinUpdateInterval',
        'caption' => 'Minimales Aktualisierungsintervall',
        'suffix' => ' Sekunden',
        'minimum' => 1,
        'maximum' => 3600
    ];

    $elements[] = [
        'type' => 'NumberSpinner',
        'name' => 'MinWeightDelta',
        'caption' => 'Minimale Gewichtsänderung',
        'suffix' => ' kg',
        'digits' => 3,
        'minimum' => 0,
        'maximum' => 10
    ];

    $elements[] = [
        'type' => 'NumberSpinner',
        'name' => 'MaxUpdateDiff',
        'caption' => 'Maximale Gewichtsänderung',
        'suffix' => ' kg',
        'digits' => 3,
        'minimum' => 0,
        'maximum' => 1000
    ];

    /*
     * Waagen
     */
    $elements[] = [
        'type' => 'Label',
        'caption' => 'Waagen-Konfiguration'
    ];

    $scaleConfig = $this->GetScaleConfigForForm($scaleCount);

    $elements[] = [
        'type' => 'List',
        'name' => 'ScaleConfig',
        'caption' => 'Waagen',
        'rowCount' => $scaleCount,
        'add' => false,
        'delete' => false,
        'columns' => [
            [
                'caption' => 'Waage',
                'name' => 'Scale',
                'width' => '70px',
                'edit' => [
                    'type' => 'NumberSpinner',
                    'enabled' => false
                ]
            ],
            [
                'caption' => 'Chemie',
                'name' => 'Chemical',
                'width' => '180px',
                'edit' => [
                    'type' => 'Select',
                    'options' => $this->GetChemicalOptions()
                ]
            ],
            [
                'caption' => 'Schwellwert',
                'name' => 'Threshold',
                'width' => '130px',
                'edit' => [
                    'type' => 'NumberSpinner',
                    'digits' => 3,
                    'minimum' => 0,
                    'maximum' => 1000
                ]
            ],
            [
                'caption' => 'Gewicht',
                'name' => 'Weight',
                'width' => '90px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Tara',
                'name' => 'Tare',
                'width' => '80px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Heute',
                'name' => 'ConsumptionToday',
                'width' => '90px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Tag',
                'name' => 'ConsumptionDay',
                'width' => '80px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Gesamt',
                'name' => 'ConsumptionTotal',
                'width' => '90px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Berechnung',
                'name' => 'ConsumptionEnabled',
                'width' => '110px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Tara setzen',
                'name' => 'TareButton',
                'width' => '110px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Tara löschen',
                'name' => 'ClearTareButton',
                'width' => '110px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ],
            [
                'caption' => 'Gesamt löschen',
                'name' => 'ResetTotalButton',
                'width' => '130px',
                'edit' => [
                    'type' => 'CheckBox'
                ]
            ]
        ],
        'values' => $scaleConfig
    ];

    /*
     * Aktionen
     */
    $actions[] = [
        'type' => 'Button',
        'caption' => 'Push-Nachricht prüfen',
        'onClick' => 'POOLCHEMIE_CheckThresholdNotification(' . $this->InstanceID . ', true);'
    ];

    $actions[] = [
        'type' => 'Label',
        'caption' => 'Hinweis: Nach Änderung der Anzahl Waagen bitte Übernehmen klicken. Danach wird die Tabelle angepasst.'
    ];

    return json_encode([
        'elements' => $elements,
        'actions' => $actions
    ]);
}

private function GetScaleConfigForForm(int $scaleCount): array
{
    $config = $this->GetScaleConfig();
    $defaults = $this->GetDefaultScaleConfig();

    $result = [];

    for ($i = 1; $i <= $scaleCount; $i++) {
        $row = null;

        foreach ($config as $configRow) {
            if ((int)($configRow['Scale'] ?? 0) === $i) {
                $row = $configRow;
                break;
            }
        }

        if ($row === null) {
            foreach ($defaults as $defaultRow) {
                if ((int)($defaultRow['Scale'] ?? 0) === $i) {
                    $row = $defaultRow;
                    break;
                }
            }
        }

        if ($row !== null) {
            $result[] = $row;
        }
    }

    return $result;
}

private function GetChemicalOptions(): array
{
    return [
        [
            'caption' => 'Chlor',
            'value' => 'Chlor'
        ],
        [
            'caption' => 'pH Minus',
            'value' => 'pH Minus'
        ],
        [
            'caption' => 'pH Plus',
            'value' => 'pH Plus'
        ],
        [
            'caption' => 'Flockungsmittel',
            'value' => 'Flockungsmittel'
        ],
        [
            'caption' => 'Aktivsauerstoff',
            'value' => 'Aktivsauerstoff'
        ],
        [
            'caption' => 'Algizid',
            'value' => 'Algizid'
        ],
        [
            'caption' => 'Sonstiges',
            'value' => 'Sonstiges'
        ]
    ];
}

private function GetChemicalName(int $scale): string
{
    $row = $this->GetScaleConfigRow($scale);

    return (string)($row['Chemical'] ?? ('Waage ' . $scale));
}

private function GetDefaultScaleConfig(): array
{
    return [
        [
            'Scale' => 1,
            'Chemical' => 'Chlor',
            'Threshold' => 5.0,
            'Weight' => true,
            'Tare' => true,
            'ConsumptionToday' => true,
            'ConsumptionDay' => true,
            'ConsumptionTotal' => true,
            'ConsumptionEnabled' => true,
            'TareButton' => true,
            'ClearTareButton' => true,
            'ResetTotalButton' => true
        ],
        [
            'Scale' => 2,
            'Chemical' => 'pH Minus',
            'Threshold' => 5.0,
            'Weight' => true,
            'Tare' => true,
            'ConsumptionToday' => true,
            'ConsumptionDay' => true,
            'ConsumptionTotal' => true,
            'ConsumptionEnabled' => true,
            'TareButton' => true,
            'ClearTareButton' => true,
            'ResetTotalButton' => true
        ],
        [
            'Scale' => 3,
            'Chemical' => 'Flockungsmittel',
            'Threshold' => 5.0,
            'Weight' => true,
            'Tare' => true,
            'ConsumptionToday' => true,
            'ConsumptionDay' => true,
            'ConsumptionTotal' => true,
            'ConsumptionEnabled' => true,
            'TareButton' => true,
            'ClearTareButton' => true,
            'ResetTotalButton' => true
        ],
        [
            'Scale' => 4,
            'Chemical' => 'Aktivsauerstoff',
            'Threshold' => 5.0,
            'Weight' => true,
            'Tare' => true,
            'ConsumptionToday' => true,
            'ConsumptionDay' => true,
            'ConsumptionTotal' => true,
            'ConsumptionEnabled' => true,
            'TareButton' => true,
            'ClearTareButton' => true,
            'ResetTotalButton' => true
        ]
    ];
}

private function GetScaleConfig(): array
{
    $json = $this->ReadPropertyString('ScaleConfig');
    $config = json_decode($json, true);

    if (!is_array($config)) {
        return $this->GetDefaultScaleConfig();
    }

    return $config;
}

private function GetScaleConfigRow(int $scale): array
{
    foreach ($this->GetScaleConfig() as $row) {
        if ((int)($row['Scale'] ?? 0) === $scale) {
            return $row;
        }
    }

    foreach ($this->GetDefaultScaleConfig() as $row) {
        if ((int)($row['Scale'] ?? 0) === $scale) {
            return $row;
        }
    }

    return [];
}

}
