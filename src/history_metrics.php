<?php

declare(strict_types=1);

/**
 * Shared monthly-history metric list for the page and the cron builder.
 *
 * Keep this aligned with the visible history widgets so the summary table and
 * the live page always talk about the same logical metrics.
 *
 * @return list<array{
 *   field:string,
 *   label:string,
 *   unit?:string,
 *   unit_key?:string,
 *   decimals:int,
 *   palette:string
 * }>
 */
function history_metric_definitions(): array
{
    return [
        ['field' => 'outTemp', 'label_key' => 'metric.outTemp', 'label' => 'Outside Temperature', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'inTemp', 'label_key' => 'metric.inTemp', 'label' => 'Inside Temperature', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'dewpoint', 'label_key' => 'metric.dewpoint', 'label' => 'Outside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'inDewpoint', 'label_key' => 'metric.inDewpoint', 'label' => 'Inside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'outHumidity', 'label_key' => 'metric.outHumidity', 'label' => 'Outside Humidity', 'unit' => '%', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'inHumidity', 'label_key' => 'metric.inHumidity', 'label' => 'Inside Humidity', 'unit' => '%', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'windSpeed', 'label_key' => 'metric.windSpeed', 'label' => 'Wind Speed', 'unit_key' => 'wind', 'decimals' => 1, 'palette' => 'wind'],
        ['field' => 'windGust', 'label_key' => 'metric.windGust', 'label' => 'Wind Gust', 'unit_key' => 'wind', 'decimals' => 1, 'palette' => 'wind'],
        ['field' => 'barometer', 'label_key' => 'metric.barometer', 'label' => 'Barometer', 'unit_key' => 'pressure', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'rainRate', 'label_key' => 'metric.rainRate', 'label' => 'Rain Rate', 'unit_key' => 'rain_rate', 'decimals' => 2, 'palette' => 'rain'],
        ['field' => 'rain', 'label_key' => 'metric.rain', 'label' => 'Rain Total', 'unit_key' => 'rain', 'decimals' => 2, 'palette' => 'rain'],
        ['field' => 'radiation', 'label_key' => 'metric.radiation', 'label' => 'Solar Radiation', 'unit' => 'W/m²', 'decimals' => 0, 'palette' => 'default'],
        ['field' => 'UV', 'label_key' => 'metric.UV', 'label' => 'UV Index', 'unit' => 'index', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'ET', 'label_key' => 'metric.ET', 'label' => 'Evapotranspiration', 'unit_key' => 'rain', 'decimals' => 2, 'palette' => 'rain'],
        ['field' => 'pm2_5', 'label_key' => 'metric.pm2_5', 'label' => 'PM2.5', 'unit' => 'µg/m³', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'lightning_strike_count', 'label_key' => 'metric.lightning_strike_count', 'label' => 'Lightning Count', 'unit' => 'count', 'decimals' => 0, 'palette' => 'default'],
        ['field' => 'windBatteryStatus', 'label_key' => 'metric.windBatteryStatus', 'label' => 'Wind Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'rainBatteryStatus', 'label_key' => 'metric.rainBatteryStatus', 'label' => 'Rain Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'lightning_Batt', 'label_key' => 'metric.lightning_Batt', 'label' => 'Lightning Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'pm25_Batt1', 'label_key' => 'metric.pm25_Batt1', 'label' => 'PM2.5 Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'inTempBatteryStatus', 'label_key' => 'metric.inTempBatteryStatus', 'label' => 'Indoor Temp Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
    ];
}
