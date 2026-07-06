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

        
        $this->RegisterPropertyString('BaseTopic', 'Pool/Chemiewaage');
        $this->RegisterPropertyInteger('ScaleCount', 2);

        $this->RegisterPropertyString('Scale1Type', 'Chlor');
        $this->RegisterPropertyString('Scale2Type', 'pH Minus');
        $this->RegisterPropertyString('Scale3Type', 'Flockungsmittel');
        $this->RegisterPropertyString('Scale4Type', 'Aktivsauerstoff');

        $this->RegisterAttributeString('LastDailyResetDate', '');
        $this->RegisterTimer('DailyResetTimer', 60000,'POOLCHEMIE_CheckDailyReset($_IPS["TARGET"]);');

        // Begrenzung der Verarbeitung
        $this->RegisterPropertyFloat('MinWeightDelta', 0.02);     // 20 g
        $this->RegisterPropertyInteger('MinUpdateInterval', 5);   // 5 Sekunden

        $this->RegisterPropertyFloat('MinTareDelta', 0.001);
        $this->RegisterPropertyInteger('TareUpdateInterval', 5);  // 5 Sekunden

        // Nachrichtensystem        
        $this->RegisterPropertyString('NotificationTime', '09:00');
        $this->RegisterAttributeString('LastThresholdNotificationDate', '');
        
        $this->RegisterPropertyBoolean('NotificationEnabled', true);
        $this->RegisterPropertyInteger('NotificationViewID', 0);
        $this->RegisterPropertyInteger('NotificationControlID', 0); // 0 = automatisch finde

        $this->RegisterTimer('ThresholdNotificationTimer',60000,'POOLCHEMIE_CheckThresholdNotification($_IPS["TARGET"]);');


        for ($i = 1; $i <= 4; $i++) {
            $this->RegisterAttributeFloat('LastProcessedWeight_' . $i, 0.0);
            $this->RegisterAttributeInteger('LastProcessedTime_' . $i, 0);
            $this->RegisterAttributeBoolean('HasProcessedWeight_' . $i, false);

            $this->RegisterAttributeFloat('LastProcessedTare_' . $i, 0.0);
            $this->RegisterAttributeInteger('LastProcessedTareTime_' . $i, 0);
            $this->RegisterAttributeBoolean('HasProcessedTare_' . $i, false);            

            $this->RegisterPropertyString('Scale' . $i . 'Items',json_encode($this->GetDefaultScaleItems()));

            $this->RegisterPropertyFloat('Scale' . $i . 'Threshold', 5.0);
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

private function GetDefaultScaleItems(): array
{
    return [
        [
            'Index'  => 1,
            'Ident'  => 'Weight',
            'Name'   => 'Gewicht',
            'Active' => true
        ],
        [
            'Index'  => 2,
            'Ident'  => 'Tare',
            'Name'   => 'Tara',
            'Active' => true
        ],
        [
            'Index'  => 3,
            'Ident'  => 'ConsumptionToday',
            'Name'   => 'Verbrauch Heute',
            'Active' => true
        ],
        [
            'Index'  => 4,
            'Ident'  => 'ConsumptionDay',
            'Name'   => 'Verbrauch Tag',
            'Active' => true
        ],
        [
            'Index'  => 5,
            'Ident'  => 'ConsumptionTotal',
            'Name'   => 'Verbrauch Gesamt',
            'Active' => true
        ],
        [
            'Index'  => 6,
            'Ident'  => 'ConsumptionEnabled',
            'Name'   => 'Verbrauch aktiv',
            'Active' => true
        ],
        [
            'Index'  => 7,
            'Ident'  => 'TareButton',
            'Name'   => 'Tara auslösen',
            'Active' => true
        ],
        [
            'Index'  => 8,
            'Ident'  => 'ClearTareButton',
            'Name'   => 'Tara löschen',
            'Active' => true
        ],
        [
            'Index'  => 9,
            'Ident'  => 'ResetTotalButton',
            'Name'   => 'Gesamtverbrauch löschen',
            'Active' => true
        ]
    ];
}

private function GetScaleItems(int $scale): array
{
    $json = $this->ReadPropertyString('Scale' . $scale . 'Items');
    $items = json_decode($json, true);

    if (!is_array($items)) {
        return $this->GetDefaultScaleItems();
    }

    return $items;
}

private function IsScaleItemActive(int $scale, string $ident): bool
{
    foreach ($this->GetScaleItems($scale) as $item) {
        if (($item['Ident'] ?? '') === $ident) {
            return (bool)($item['Active'] ?? false);
        }
    }

    return false;
}

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
        $this->SendDebug(
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

    $this->SendDebug(
        'Archiv',
        'Archivierung für ' . $ident . ' aktiviert. Archiv-ID=' . $archiveID,
        0
    );
}

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

private function GetChemicalName(int $scale): string
{
    return $this->ReadPropertyString('Scale' . $scale . 'Type');
}

private function CreateScaleVariables(int $scale): void
{
    $name = $this->GetChemicalName($scale);

    if ($this->IsScaleItemActive($scale, 'Weight')) {
        $this->RegisterVariableFloat(
            'Weight_' . $scale,
            $name . ' Gewicht',
            'POOLCHEMIE.Kilogramm',
            10 + $scale
        );
        IPS_SetHidden($this->GetIDForIdent('Weight_' . $scale), false);
    } else {
        $this->HideVariableIfExists('Weight_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'Tare')) {
        $this->RegisterVariableFloat(
            'Tare_' . $scale,
            $name . ' Tara',
            'POOLCHEMIE.Kilogramm',
            20 + $scale
        );
        IPS_SetHidden($this->GetIDForIdent('Tare_' . $scale), false);
    } else {
        $this->HideVariableIfExists('Tare_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionDay')) {
        $this->RegisterVariableFloat(
            'ConsumptionDay_' . $scale,
            $name . ' Verbrauch Tag',
            'POOLCHEMIE.Kilogramm',
            30 + $scale
        );
        IPS_SetHidden($this->GetIDForIdent('ConsumptionDay_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionDay_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionToday')) {
        $this->RegisterVariableFloat(
            'ConsumptionToday_' . $scale,
            $name . ' Verbrauch Heute',
            'POOLCHEMIE.Kilogramm',
            40 + $scale
        );
        IPS_SetHidden($this->GetIDForIdent('ConsumptionToday_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionToday_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionTotal')) {
        $this->RegisterVariableFloat(
            'ConsumptionTotal_' . $scale,
            $name . ' Verbrauch Gesamt',
            'POOLCHEMIE.Kilogramm',
            50 + $scale
        );
        IPS_SetHidden($this->GetIDForIdent('ConsumptionTotal_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionTotal_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ConsumptionEnabled')) {
        $this->RegisterVariableBoolean(
            'ConsumptionEnabled_' . $scale,
            $name . ' Verbrauch aktiv',
            '~Switch',
            60 + $scale
        );
        $this->EnableAction('ConsumptionEnabled_' . $scale);
        IPS_SetHidden($this->GetIDForIdent('ConsumptionEnabled_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ConsumptionEnabled_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'TareButton')) {
        $this->RegisterVariableInteger(
            'TareButton_' . $scale,
            $name . ' Tara auslösen',
            'POOLCHEMIE.Button',
            70 + $scale
        );
        $this->EnableAction('TareButton_' . $scale);
        IPS_SetHidden($this->GetIDForIdent('TareButton_' . $scale), false);
    } else {
        $this->HideVariableIfExists('TareButton_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ClearTareButton')) {
        $this->RegisterVariableInteger(
            'ClearTareButton_' . $scale,
            $name . ' Tara löschen',
            'POOLCHEMIE.DeleteButton',
            80 + $scale
        );
        $this->EnableAction('ClearTareButton_' . $scale);
        IPS_SetHidden($this->GetIDForIdent('ClearTareButton_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ClearTareButton_' . $scale);
    }

    if ($this->IsScaleItemActive($scale, 'ResetTotalButton')) {
        $this->RegisterVariableInteger(
            'ResetTotalButton_' . $scale,
            $name . ' Gesamtverbrauch löschen',
            'POOLCHEMIE.DeleteButton',
            90 + $scale
        );
        $this->EnableAction('ResetTotalButton_' . $scale);
        IPS_SetHidden($this->GetIDForIdent('ResetTotalButton_' . $scale), false);
    } else {
        $this->HideVariableIfExists('ResetTotalButton_' . $scale);
    }
}

private function HideVariableIfExists(string $ident): void
{
    $id = @$this->GetIDForIdent($ident);

    if ($id !== false) {
        IPS_SetHidden($id, true);
    }
}

public function RequestAction($Ident, $Value)
{
    $this->SendDebug(
        'RequestAction',
        'Ident=' . $Ident . ' Value=' . print_r($Value, true),
        0
    );

    
    IPS_LogMessage(
        'PoolChemie RequestAction',
        'Ident=' . $Ident . ' Value=' . print_r($Value, true)
    );


    if (preg_match('/^ConsumptionEnabled_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        SetValue($this->GetIDForIdent($Ident), (bool)$Value);

        // Bei jeder Änderung des Verbrauchsmodus wird die Verbrauchsbasis verworfen.
        // Dadurch erzeugt ein Kanisterwechsel keinen falschen Verbrauch.
        $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, false);

        $this->SendDebug(
            'Verbrauchsmodus',
            'Waage ' . $scale . ' Verbrauch aktiv = ' . ((bool)$Value ? 'JA' : 'NEIN') . '. Verbrauchsbasis wurde zurückgesetzt.',
            0
        );

        return;
    }

    if (preg_match('/^TareButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        $this->SendDebug('Tare', 'TareButton gedrückt für Waage ' . $scale, 0);

        $this->SendTare($scale);

        SetValue($this->GetIDForIdent($Ident), 0);
        return;
    }

    if (preg_match('/^ClearTareButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        $this->SendDebug('TareClear', 'ClearTareButton gedrückt für Waage ' . $scale, 0);

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
    IPS_LogMessage('PoolChemie', 'Tara auslösen Waage ' . $scale);

    $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, false);

    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $this->PublishMQTT(
        $baseTopic . '/cmd/waage' . $scale . '/tare',
        '1',
        false
    );
}

private function SendClearTare(int $scale): void
{
    IPS_LogMessage('PoolChemie', 'Tara löschen Waage ' . $scale);

    $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, false);

    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $this->PublishMQTT(
        $baseTopic . '/cmd/waage' . $scale . '/tare_clear',
        '1',
        false
    );
}

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

    $now = time();

    $minDelta = $this->ReadPropertyFloat('MinWeightDelta');
    $minInterval = $this->ReadPropertyInteger('MinUpdateInterval');

    $hasLast = $this->ReadAttributeBoolean('HasProcessedWeight_' . $scale);
    $lastWeight = $this->ReadAttributeFloat('LastProcessedWeight_' . $scale);
    $lastTime = $this->ReadAttributeInteger('LastProcessedTime_' . $scale);

    if ($hasLast) {
        $delta = abs($weight - $lastWeight);
        $age = $now - $lastTime;

        if ($delta < $minDelta && $age < $minInterval) {
            return;
        }
    }

    // Gewicht immer aktualisieren
    $this->UpdateWeightVariable($scale, $weight);

    $enabledID = @$this->GetIDForIdent('ConsumptionEnabled_' . $scale);
    $consumptionEnabled = false;

    if ($enabledID !== false) {
        $consumptionEnabled = GetValue($enabledID);
    }

    // Wenn Verbrauch deaktiviert ist:
    // keine Berechnung, keine Basis speichern
    if (!$consumptionEnabled) {
        $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, false);
        $this->WriteAttributeInteger('LastProcessedTime_' . $scale, $now);
        return;
    }

    // Wenn Verbrauch gerade erst aktiviert wurde:
    // aktuelles Gewicht nur als neue Basis speichern
    if (!$hasLast) {
        $this->WriteAttributeFloat('LastProcessedWeight_' . $scale, $weight);
        $this->WriteAttributeInteger('LastProcessedTime_' . $scale, $now);
        $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, true);

        $this->SendDebug(
            'Verbrauchsbasis',
            'Waage ' . $scale . ': Neue Basis = ' . number_format($weight, 3) . ' kg',
            0
        );

        return;
    }

    // Verbrauch berechnen
    $this->CalculateConsumption($scale, $lastWeight, $weight);

    // Danach neues Gewicht als Basis speichern
    $this->WriteAttributeFloat('LastProcessedWeight_' . $scale, $weight);
    $this->WriteAttributeInteger('LastProcessedTime_' . $scale, $now);
    $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, true);
}

private function ProcessTare(int $scale, float $tare): void
{
    $scaleCount = $this->ReadPropertyInteger('ScaleCount');

    if ($scale > $scaleCount) {
        return;
    }

    if (!$this->IsScaleItemActive($scale, 'Tare')) {
        return;
    }

    $now = time();

    $minTareDelta = $this->ReadPropertyFloat('MinTareDelta');
    $tareUpdateInterval = $this->ReadPropertyInteger('TareUpdateInterval');

    $hasLast = $this->ReadAttributeBoolean('HasProcessedTare_' . $scale);
    $lastTare = $this->ReadAttributeFloat('LastProcessedTare_' . $scale);
    $lastTime = $this->ReadAttributeInteger('LastProcessedTareTime_' . $scale);

    if ($hasLast) {
        $delta = abs($tare - $lastTare);
        $age = $now - $lastTime;

        if ($delta < $minTareDelta && $age < $tareUpdateInterval) {
            return;
        }
    }

    $ident = 'Tare_' . $scale;
    $id = @$this->GetIDForIdent($ident);

    if ($id !== false) {
        SetValue($id, $tare);
    }

    $this->WriteAttributeFloat('LastProcessedTare_' . $scale, $tare);
    $this->WriteAttributeInteger('LastProcessedTareTime_' . $scale, $now);
    $this->WriteAttributeBoolean('HasProcessedTare_' . $scale, true);

    $this->SendDebug(
        'Tara',
        'Waage ' . $scale . ': Tara aktualisiert = ' . number_format($tare, 3) . ' kg',
        0
    );
}

private function UpdateWeightVariable(int $scale, float $weight): void
{
    $ident = 'Weight_' . $scale;

    $id = @$this->GetIDForIdent($ident);

    if ($id !== false) {
        SetValue($id, $weight);
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

    $this->SendDebug('MQTT TX', $json, 0);
    IPS_LogMessage('PoolChemie TX', $json);

    $this->SendDataToParent($json);
}

private function CalculateConsumption(int $scale, float $oldWeight, float $newWeight): void
{
    $diff = $oldWeight - $newWeight;

    // Gewicht ist gestiegen oder gleich geblieben:
    // kein Verbrauch
    if ($diff <= 0) {
        return;
    }

    $minDelta = $this->ReadPropertyFloat('MinWeightDelta');

    // Kleine Schwankungen ignorieren
    if ($diff < $minDelta) {
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

    $this->SendDebug(
        'Verbrauch',
        'Waage ' . $scale .
        ': Alt=' . number_format($oldWeight, 3) .
        ' kg Neu=' . number_format($newWeight, 3) .
        ' kg Verbrauch=' . number_format($diff, 3) . ' kg',
        0
    );
}

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
    IPS_LogMessage($title, $message);

    $this->SendDebug(
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
        $threshold = $this->ReadPropertyFloat('Scale' . $scale . 'Threshold');

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

    $this->SendDebug(
        'Tageswechsel',
        'Tagesverbrauch wurde abgeschlossen und Verbrauch Heute wurde zurückgesetzt.',
        0
    );
}

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

        $elements[] = [
            'type' => 'ValidationTextBox',
            'name' => 'BaseTopic',
            'caption' => 'MQTT Basistopic'
        ];

        $elements[] = [
            'type' => 'ValidationTextBox',
            'name' => 'NotificationTime',
            'caption' => 'Benachrichtigungszeit HH:MM'
        ];

        $elements[] = [
            'type' => 'CheckBox',
            'name' => 'NotificationEnabled',
            'caption' => 'Benachrichtigung aktivieren'
        ];

        $elements[] = [
            'type' => 'SelectInstance',
            'name' => 'NotificationViewID',
            'caption' => 'WebFront für Push-Benachrichtigung'
        ];

        
        $elements[] = [
            'type' => 'SelectInstance',
            'name' => 'NotificationControlID',
            'caption' => 'Notification Control Instanz (optional)'
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
            'name' => 'MinTareDelta',
            'caption' => 'Minimale Tara-Änderung',
            'suffix' => ' kg',
            'digits' => 3,
            'minimum' => 0,
            'maximum' => 10
        ];

        $elements[] = [
            'type' => 'NumberSpinner',
            'name' => 'TareUpdateInterval',
            'caption' => 'Tara Aktualisierungsintervall',
            'suffix' => ' Sekunden',
            'minimum' => 1,
            'maximum' => 3600
        ];

        for ($i = 1; $i <= $scaleCount; $i++) {
            $elements[] = [
                'type' => 'Select',
                'name' => 'Scale' . $i . 'Type',
                'caption' => 'Waage ' . $i . ' Chemie',
                'options' => $this->GetChemicalOptions()
            ];

            $elements[] = [
                'type' => 'NumberSpinner',
                'name' => 'Scale' . $i . 'Threshold',
                'caption' => 'Waage ' . $i . ' unterer Schwellwert Nachricht',
                'suffix' => ' kg',
                'digits' => 3,
                'minimum' => 0,
                'maximum' => 1000
            ];

            $elements[] = [
                'type' => 'List',
                'name' => 'Scale' . $i . 'Items',
                'caption' => 'Waage ' . $i . ' Variablen',
                'rowCount' => 9,
                'add' => false,
                'delete' => false,
                'columns' => [
                    [
                        'caption' => 'Index',
                        'name' => 'Index',
                        'width' => '70px',
                        'edit' => [
                            'type' => 'NumberSpinner',
                            'enabled' => false
                        ]
                    ],
                    [
                        'caption' => 'Name',
                        'name' => 'Name',
                        'width' => '300px',
                        'edit' => [
                            'type' => 'ValidationTextBox',
                            'enabled' => false
                        ]
                    ],
                    [
                        'caption' => 'Ident',
                        'name' => 'Ident',
                        'width' => '180px',
                        'edit' => [
                            'type' => 'ValidationTextBox',
                            'enabled' => false
                        ]
                    ],
                    [
                        'caption' => 'Aktiv',
                        'name' => 'Active',
                        'width' => '100px',
                        'edit' => [
                            'type' => 'CheckBox'
                        ]
                    ]
                ],
                'values' => $this->GetScaleItems($i)
            ];
        }


        $actions = [];

        $actions[] = [
            'type' => 'Button',
            'caption' => 'Push-Nachricht prüfen',
            'onClick' => 'POOLCHEMIE_CheckThresholdNotification(' . $this->InstanceID . ', true);'
        ];

        $actions[] = [
            'type' => 'Label',
            'caption' => 'Hinweis: Nach Änderung der Anzahl Waagen bitte Übernehmen klicken. Danach wird die Anzahl der Chemie-Auswahllisten angepasst.'
        ];

        return json_encode([
            'elements' => $elements,
            'actions' => $actions
        ]);
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
}
