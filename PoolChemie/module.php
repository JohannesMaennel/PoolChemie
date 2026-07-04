<?php

declare(strict_types=1);

class PoolChemie extends IPSModule
{
    private const MQTT_SERVER_MODULE = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    private const MQTT_RX_DATA_ID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MQTT_TX_DATA_ID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('BaseTopic', 'Pool/Chemiewaage');

        $this->ConnectParent(self::MQTT_SERVER_MODULE);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $baseTopic = $this->ReadPropertyString('BaseTopic');

        /*
         * Erstmal bewusst breiter Filter.
         * Sobald wir das echte JSON aus ReceiveData kennen,
         * machen wir den Filter enger.
         */
        $this->SetReceiveDataFilter('.*' . preg_quote($baseTopic, '/') . '.*');

        IPS_LogMessage('PoolChemie', 'ApplyChanges ausgefuehrt. MQTT Filter: ' . $baseTopic);
    }

    public function ReceiveData($JSONString)
    {
        IPS_LogMessage('PoolChemie RX RAW', $JSONString);

        $data = json_decode($JSONString, true);

        if (!is_array($data)) {
            IPS_LogMessage('PoolChemie RX Fehler', 'JSON konnte nicht gelesen werden');
            return '';
        }

        IPS_LogMessage('PoolChemie RX DATA', print_r($data, true));

        return '';
    }

    public function TestPublishWaage1Tare()
    {
        $this->PublishMQTT(
            'Pool/Chemiewaage/cmd/waage1/tare',
            '1'
        );
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
}