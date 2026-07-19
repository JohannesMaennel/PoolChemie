<?php

class VictronForecast extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyInteger('SiteID', 0);

        $this->SetVisualizationType(1);
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    private function GetForecastData()
    {
        $token = $this->ReadPropertyString('Token');
        $siteId = $this->ReadPropertyInteger('SiteID');

        if ($token == '' || $siteId == 0) {
            return false;
        }

        $start = strtotime('today midnight');
        $end   = strtotime('midnight +7 days') - 1;

        $url =
            "https://vrmapi.victronenergy.com/v2/installations/$siteId/stats"
            . "?type=forecast"
            . "&start=$start"
            . "&end=$end";

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "x-authorization: Token $token"
            ]
        ]);

        $response = curl_exec($ch);

        curl_close($ch);

        return json_decode($response, true);
    }

    private function GetDailyForecast()
    {
        $data = $this->GetForecastData();

        if (!$data || !$data['success']) {
            return false;
        }

        $days = [];

        foreach ($data['records']['solar_yield_forecast'] as $row) {

            $day = date('d.m', $row[0] / 1000);

            if (!isset($days[$day])) {
                $days[$day] = [
                    'pv' => 0,
                    'consumption' => 0
                ];
            }

            $days[$day]['pv'] += $row[1];
        }

        foreach ($data['records']['vrm_consumption_fc'] as $row) {

            $day = date('d.m', $row[0] / 1000);

            $days[$day]['consumption'] += $row[1];
        }

        return $days;
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(
            __DIR__ . '/assets/main.html'
        );

        $css = file_get_contents(
            __DIR__ . '/assets/main.css'
        );

        $js = file_get_contents(
            __DIR__ . '/assets/app.js'
        );

        

        $forecast = $this->GetDailyForecast();

        $data = [];

        foreach ($forecast as $day => $row) {

            $pv =
                round($row['pv'] / 1000, 2);

            $consumption =
                round($row['consumption'] / 1000, 2);

            $data[] = [
                'day' => $day,
                'pv' => $pv,
                'consumption' => $consumption,
                'surplus' =>
                round(
                    $pv - $consumption,
                    2
                )
            ];
        }

        $html = str_replace(
            '{{CSS}}',
            $css,
            $html
        );

        $html = str_replace(
            '{{JS}}',
            $js,
            $html
        );

        $html = str_replace(
            '{{DATA}}',
            json_encode($data),
            $html
        );

        return $html;
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'Token',
                    'caption' => 'Victron API Token'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'SiteID',
                    'caption' => 'Victron Site ID'
                ]
            ]
        ]);
    }
}
