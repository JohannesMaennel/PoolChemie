<?php

declare(strict_types=1);

class PoolChemie extends IPSModule
{

    private const MQTT_SERVER_MODULE = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    // Daten vom MQTT Server zum PoolChemie Modul
    private const MQTT_RX_DATA_ID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    // Daten vom PoolChemie Modul zum MQTT Server
    private const MQTT_TX_DATA_ID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'



    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('BaseTopic', 'Pool/Chemiewaage');
        $this->RegisterPropertyInteger('ScaleCount', 2);

        $this->RegisterPropertyString('Scale1Type', 'Chlor');
        $this->RegisterPropertyString('Scale2Type', 'pH Minus');
        $this->RegisterPropertyString('Scale3Type', 'Flockungsmittel');
        $this->RegisterPropertyString('Scale4Type', 'Aktivsauerstoff');

        // Begrenzung der Verarbeitung
        $this->RegisterPropertyFloat('MinWeightDelta', 0.02);      // 10 g
        $this->RegisterPropertyInteger('MinUpdateInterval', 5);   // Sekunden

        for ($i = 1; $i <= 4; $i++) {
            $this->RegisterAttributeFloat('LastProcessedWeight_' . $i, 0.0);
            $this->RegisterAttributeInteger('LastProcessedTime_' . $i, 0);
            $this->RegisterAttributeBoolean('HasProcessedWeight_' . $i, false);
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
    }

    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $pattern = '.*' . preg_quote($baseTopic, '/') . '.*';

    $this->SetReceiveDataFilter($pattern);

    IPS_LogMessage('PoolChemie', 'ApplyChanges ausgeführt. MQTT Filter: ' . $pattern);
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

    $this->RegisterVariableFloat(
        'Weight_' . $scale,
        $name . ' Gewicht',
        'POOLCHEMIE.Kilogramm',
        1
    );

    $this->RegisterVariableFloat(
        'Tare_' . $scale,
        $name . ' Tara',
        'POOLCHEMIE.Kilogramm',
        1
    );

    $this->RegisterVariableBoolean(
        'ConsumptionEnabled_' . $scale,
        $name . ' Verbrauch aktiv',
        '~Switch',
        1
    );
    $this->EnableAction('ConsumptionEnabled_' . $scale);

    $this->RegisterVariableFloat(
        'ConsumptionToday_' . $scale,
        $name . ' Verbrauch Heute',
        'POOLCHEMIE.Kilogramm',
        1
    );

    $this->RegisterVariableFloat(
        'ConsumptionTotal_' . $scale,
        $name . ' Verbrauch Gesamt',
        'POOLCHEMIE.Kilogramm',
        1
    );


    this->RegisterVariableInteger(
        'TareButton_' . $scale,
        $name . ' Tara auslösen',
        'POOLCHEMIE.Button',
        1
    );
    $this->EnableAction('TareButton_' . $scale);



    $this->RegisterVariableInteger(
        'ClearTareButton_' . $scale,
        $name . ' Tara löschen',
        'POOLCHEMIE.DeleteButton',
        1
    );
    $this->EnableAction('ClearTareButton_' . $scale);
    ;


    $this->RegisterVariableInteger(
        'ResetTotalButton_' . $scale,
        $name . ' Gesamtverbrauch löschen',
        'POOLCHEMIE.DeleteButton',
        1
    );
    $this->EnableAction('ResetTotalButton_' . $scale);

}

public function RequestAction($Ident, $Value)
{
    if (preg_match('/^ConsumptionEnabled_([1-4])$/', $Ident, $matches)) {
        SetValue($this->GetIDForIdent($Ident), (bool)$Value);
        return;
    }

    if (preg_match('/^TareButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

        $this->SendTare($scale);

        SetValue($this->GetIDForIdent($Ident), 0);
        return;
    }

    if (preg_match('/^ClearTareButton_([1-4])$/', $Ident, $matches)) {
        $scale = (int)$matches[1];

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

    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $this->PublishMQTT(
        $baseTopic . '/cmd/waage' . $scale . '/tare',
        '1'
    );
}

private function SendClearTare(int $scale): void
{
    IPS_LogMessage('PoolChemie', 'Tara löschen Waage ' . $scale);

    $baseTopic = rtrim($this->ReadPropertyString('BaseTopic'), '/');

    $this->PublishMQTT(
        $baseTopic . '/cmd/waage' . $scale . '/tare_clear',
        '1'
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

        $this->WriteAttributeFloat('LastProcessedWeight_' . $scale, $weight);
        $this->WriteAttributeInteger('LastProcessedTime_' . $scale, $now);
        $this->WriteAttributeBoolean('HasProcessedWeight_' . $scale, true);

        $this->UpdateWeightVariable($scale, $weight);
    }

    private function ProcessTare(int $scale, float $tare): void
    {
        $scaleCount = $this->ReadPropertyInteger('ScaleCount');

        if ($scale > $scaleCount) {
            return;
        }

        $ident = 'Tare_' . $scale;

        if (@$this->GetIDForIdent($ident)) {
            SetValue($this->GetIDForIdent($ident), $tare);
        }
    }


    private function UpdateWeightVariable(int $scale, float $weight): void
    {
        $ident = 'Weight_' . $scale;

        if (@$this->GetIDForIdent($ident)) {
            SetValue($this->GetIDForIdent($ident), $weight);
        }

        $this->CalculateConsumption($scale, $weight);
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

    IPS_LogMessage('PoolChemie TX', json_encode($data));

    $this->SendDataToParent(json_encode($data));
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

        for ($i = 1; $i <= $scaleCount; $i++) {
            $elements[] = [
                'type' => 'Select',
                'name' => 'Scale' . $i . 'Type',
                'caption' => 'Waage ' . $i . ' Chemie',
                'options' => $this->GetChemicalOptions()
            ];
        }

        $actions = [];

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