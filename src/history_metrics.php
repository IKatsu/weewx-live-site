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
        ['field' => 'outTemp', 'label' => 'Outside Temperature', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'inTemp', 'label' => 'Inside Temperature', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'dewpoint', 'label' => 'Outside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'inDewpoint', 'label' => 'Inside Dew Point', 'unit_key' => 'temperature', 'decimals' => 1, 'palette' => 'temperature'],
        ['field' => 'outHumidity', 'label' => 'Outside Humidity', 'unit' => '%', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'inHumidity', 'label' => 'Inside Humidity', 'unit' => '%', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'windSpeed', 'label' => 'Wind Speed', 'unit_key' => 'wind', 'decimals' => 1, 'palette' => 'wind'],
        ['field' => 'windGust', 'label' => 'Wind Gust', 'unit_key' => 'wind', 'decimals' => 1, 'palette' => 'wind'],
        ['field' => 'barometer', 'label' => 'Barometer', 'unit_key' => 'pressure', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'rainRate', 'label' => 'Rain Rate', 'unit_key' => 'rain_rate', 'decimals' => 2, 'palette' => 'rain'],
        ['field' => 'rain', 'label' => 'Rain Total', 'unit_key' => 'rain', 'decimals' => 2, 'palette' => 'rain'],
        ['field' => 'radiation', 'label' => 'Solar Radiation', 'unit' => 'W/m²', 'decimals' => 0, 'palette' => 'default'],
        ['field' => 'UV', 'label' => 'UV Index', 'unit' => 'index', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'ET', 'label' => 'Evapotranspiration', 'unit_key' => 'rain', 'decimals' => 2, 'palette' => 'rain'],
        ['field' => 'pm2_5', 'label' => 'PM2.5', 'unit' => 'µg/m³', 'decimals' => 1, 'palette' => 'default'],
        ['field' => 'lightning_strike_count', 'label' => 'Lightning Count', 'unit' => 'count', 'decimals' => 0, 'palette' => 'default'],
        ['field' => 'windBatteryStatus', 'label' => 'Wind Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'rainBatteryStatus', 'label' => 'Rain Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'lightning_Batt', 'label' => 'Lightning Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'pm25_Batt1', 'label' => 'PM2.5 Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
        ['field' => 'inTempBatteryStatus', 'label' => 'Indoor Temp Battery', 'unit' => 'V', 'decimals' => 2, 'palette' => 'default'],
    ];
}
