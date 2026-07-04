<?php

declare(strict_types=1);

class PoolChemie extends IPSModule
{
    private const MQTT_SERVER_DEVICE_MODULE = '{01C00ADD-D04E-452E-B66A-D253278743FE}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('MQTTInstance', 0);
        $this->RegisterPropertyString('BaseTopic', 'Pool/Chemiewaage');
        $this->RegisterPropertyInteger('ScaleCount', 2);

        $this->RegisterPropertyString('Scale1Type', 'Chlor');
        $this->RegisterPropertyString('Scale2Type', 'pH Minus');
        $this->RegisterPropertyString('Scale3Type', 'Flockungsmittel');
        $this->RegisterPropertyString('Scale4Type', 'Aktivsauerstoff');

        for ($i = 1; $i <= 4; $i++) {
            $this->RegisterAttributeFloat('LastWeight_' . $i, 0.0);
            $this->RegisterAttributeBoolean('HasLastWeight_' . $i, false);
        }
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        $count = $this->ReadPropertyInteger('ScaleCount');

        for ($i = 1; $i <= $count; $i++) {
            $this->CreateScaleVariables($i);
            $this->CreateMQTTDevices($i);
        }
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('POOLCHEMIE.Kilogramm')) {
            IPS_CreateVariableProfile('POOLCHEMIE.Kilogramm', 2);
            IPS_SetVariableProfileText('POOLCHEMIE.Kilogramm', '', ' kg');
            IPS_SetVariableProfileDigits('POOLCHEMIE.Kilogramm', 3);
        }
    }

    private function GetChemicalName(int $scale): string
    {
        return $this->ReadPropertyString('Scale' . $scale . 'Type');
    }

    private function CreateScaleVariables(int $scale): void
    {
        $name = $this->GetChemicalName($scale);

        $this->RegisterVariableFloat(
            'Weight_' . $scale,
            $name . ' Gewicht',
            'POOLCHEMIE.Kilogramm',
            10 + $scale
        );

        $this->RegisterVariableFloat(
            'Tare_' . $scale,
            $name . ' Tara',
            'POOLCHEMIE.Kilogramm',
            20 + $scale
        );

        $this->RegisterVariableBoolean(
            'ConsumptionEnabled_' . $scale,
            $name . ' Verbrauch aktiv',
            '',
            30 + $scale
        );
        $this->EnableAction('ConsumptionEnabled_' . $scale);

        $this->RegisterVariableFloat(
            'ConsumptionToday_' . $scale,
            $name . ' Verbrauch Heute',
            'POOLCHEMIE.Kilogramm',
            40 + $scale
        );

        $this->RegisterVariableFloat(
            'ConsumptionTotal_' . $scale,
            $name . ' Verbrauch Gesamt',
            'POOLCHEMIE.Kilogramm',
            50 + $scale
        );

        $this->RegisterVariableBoolean(
            'TareButton_' . $scale,
            $name . ' Tara',
            '~Switch',
            60 + $scale
        );
        $this->EnableAction('TareButton_' . $scale);

        $this->RegisterVariableBoolean(
            'ClearTareButton_' . $scale,
            $name . ' Tara löschen',
            '~Switch',
            70 + $scale
        );
        $this->EnableAction('ClearTareButton_' . $scale);

        $this->RegisterVariableBoolean(
            'ResetTotalButton_' . $scale,
            $name . ' Gesamtverbrauch löschen',
            '~Switch',
            80 + $scale
        );
        $this->EnableAction('ResetTotalButton_' . $scale);
    }

    private function CreateMQTTDevices(int $scale): void
    {
        $base = rtrim($this->ReadPropertyString('BaseTopic'), '/');

        $this->EnsureMQTTDevice(
            'MQTT_W' . $scale . '_WEIGHT',
            $base . '/sensor/waage' . $scale . '/state',
            2
        );

        $this->EnsureMQTTDevice(
            'MQTT_W' . $scale . '_TARA',
            $base . '/sensor/waage' . $scale . '_tara/state',
            2
        );

        $this->EnsureMQTTDevice(
            'MQTT_W' . $scale . '_TARE_CMD',
            $base . '/cmd/waage' . $scale . '/tare',
            3
        );

        $this->EnsureMQTTDevice(
            'MQTT_W' . $scale . '_TARE_CLEAR_CMD',
            $base . '/cmd/waage' . $scale . '/tare_clear',
            3
        );
    }

    private function EnsureMQTTDevice(
        string $ident,
        string $topic,
        int $type,
        bool $retain = false
    ): int {
        $serverID = $this->ReadPropertyInteger('MQTTInstance');

        if ($serverID <= 0 || !IPS_InstanceExists($serverID)) {
            IPS_LogMessage('PoolChemie', 'MQTT Server Instanz fehlt oder ist ungültig.');
            return 0;
        }

        $deviceID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($deviceID === false) {
            $deviceID = IPS_CreateInstance(self::MQTT_SERVER_DEVICE_MODULE);
            IPS_SetParent($deviceID, $this->InstanceID);
            IPS_SetIdent($deviceID, $ident);
            IPS_SetName($deviceID, $ident);
            IPS_SetHidden($deviceID, true);
        }

        if (!IPS_IsInstanceCompatible($deviceID, $serverID)) {
            IPS_LogMessage(
                'PoolChemie',
                'MQTT Device ist nicht kompatibel mit MQTT Server Instanz ' . $serverID
            );
            return 0;
        }

        $instance = IPS_GetInstance($deviceID);

        if ($instance['ConnectionID'] !== $serverID) {
            @IPS_DisconnectInstance($deviceID);
            IPS_ConnectInstance($deviceID, $serverID);
        }

        $config = [
            'Topic'  => $topic,
            'Type'   => $type,
            'Retain' => $retain
        ];

        IPS_SetConfiguration($deviceID, json_encode($config));
        IPS_ApplyChanges($deviceID);

        $valueID = @IPS_GetObjectIDByIdent('Value', $deviceID);

        if ($valueID !== false) {
            $this->RegisterMessage($valueID, VM_UPDATE);
        }

        return $deviceID;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message !== VM_UPDATE) {
            return;
        }

        $deviceID = IPS_GetParent($SenderID);
        $object = IPS_GetObject($deviceID);
        $ident = $object['ObjectIdent'];

        $value = GetValue($SenderID);

        if (preg_match('/^MQTT_W([1-4])_WEIGHT$/', $ident, $matches)) {
            $this->HandleWeight((int)$matches[1], (float)$value);
            return;
        }

        if (preg_match('/^MQTT_W([1-4])_TARA$/', $ident, $matches)) {
            $this->HandleTara((int)$matches[1], (float)$value);
            return;
        }
    }

    private function HandleWeight(int $scale, float $newWeight): void
    {
        SetValue($this->GetIDForIdent('Weight_' . $scale), $newWeight);

        $enabled = GetValue($this->GetIDForIdent('ConsumptionEnabled_' . $scale));

        if (!$enabled) {
            $this->WriteAttributeFloat('LastWeight_' . $scale, $newWeight);
            $this->WriteAttributeBoolean('HasLastWeight_' . $scale, true);
            return;
        }

        $hasLast = $this->ReadAttributeBoolean('HasLastWeight_' . $scale);
        $lastWeight = $this->ReadAttributeFloat('LastWeight_' . $scale);

        if (!$hasLast) {
            $this->WriteAttributeFloat('LastWeight_' . $scale, $newWeight);
            $this->WriteAttributeBoolean('HasLastWeight_' . $scale, true);
            return;
        }

        $diff = $lastWeight - $newWeight;

        if ($diff > 0) {
            $todayID = $this->GetIDForIdent('ConsumptionToday_' . $scale);
            $totalID = $this->GetIDForIdent('ConsumptionTotal_' . $scale);

            SetValue($todayID, GetValue($todayID) + $diff);
            SetValue($totalID, GetValue($totalID) + $diff);
        }

        $this->WriteAttributeFloat('LastWeight_' . $scale, $newWeight);
    }

    private function HandleTara(int $scale, float $tare): void
    {
        SetValue($this->GetIDForIdent('Tare_' . $scale), $tare);
    }

    public function RequestAction($Ident, $Value)
    {
        if (preg_match('/^ConsumptionEnabled_([1-4])$/', $Ident, $matches)) {
            SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            return;
        }

        if (preg_match('/^TareButton_([1-4])$/', $Ident, $matches)) {
            $this->SendTare((int)$matches[1]);
            SetValue($this->GetIDForIdent($Ident), false);
            return;
        }

        if (preg_match('/^ClearTareButton_([1-4])$/', $Ident, $matches)) {
            $this->SendClearTare((int)$matches[1]);
            SetValue($this->GetIDForIdent($Ident), false);
            return;
        }

        if (preg_match('/^ResetTotalButton_([1-4])$/', $Ident, $matches)) {
            $scale = (int)$matches[1];
            SetValue($this->GetIDForIdent('ConsumptionTotal_' . $scale), 0.0);
            SetValue($this->GetIDForIdent($Ident), false);
            return;
        }

        throw new Exception('Invalid Ident: ' . $Ident);
    }

    private function SendTare(int $scale): void
    {
        $this->PublishCommand('MQTT_W' . $scale . '_TARE_CMD', '1');
    }

    private function SendClearTare(int $scale): void
    {
        $this->PublishCommand('MQTT_W' . $scale . '_TARE_CLEAR_CMD', '1');
    }

    private function PublishCommand(string $deviceIdent, string $payload): void
    {
        $deviceID = @IPS_GetObjectIDByIdent($deviceIdent, $this->InstanceID);

        if ($deviceID === false) {
            IPS_LogMessage('PoolChemie', 'MQTT Command Device fehlt: ' . $deviceIdent);
            return;
        }

        $valueID = @IPS_GetObjectIDByIdent('Value', $deviceID);

        if ($valueID === false) {
            IPS_LogMessage('PoolChemie', 'MQTT Command Value fehlt: ' . $deviceIdent);
            return;
        }

        RequestAction($valueID, $payload);
    }
}