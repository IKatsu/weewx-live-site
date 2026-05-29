# Android Alerting Notes

> Command/path note: shell commands in this document were written in a development environment. Verify and adjust paths for your own host before running them.

This project does not send phone alerts yet. The recommended first design is a small server-side alert checker that runs from cron, reads local weather data, and sends push messages only when rules cross configured thresholds.

## Recommended transport

Use `ntfy` first.

- Android app exists.
- Messages can be sent with a simple HTTP POST.
- It can be self-hosted or use the public ntfy service.
- It does not require Firebase project setup inside this PHP app.

Other workable options:

- Gotify: good self-hosted option, but less frictionless for casual phone use.
- Home Assistant: best if the station is already integrated there.
- Pushover: simple and reliable, but commercial.
- Firebase Cloud Messaging: powerful, but too much setup for this project.

## Suggested architecture

1. Add a cron script such as `src/cli/check_alerts.php`.
2. Read recent observations from MySQL.
3. Compare against configured alert rules.
4. Store alert state in a small table so the same condition does not spam the phone.
5. Send a notification through `ntfy` or another configured provider.

The dashboard should remain read-only. Alerting should live in a CLI job with explicit credentials in `src/config.local.php`.

## Useful first rules

- Thunderstorm activity:
  - strike count in the last 5 minutes
  - strike count in the last 24 hours
  - closest strike distance in the last 5 minutes
- Rapid pressure change:
  - pressure drop over 1 hour
  - pressure drop over 3 hours
- Wind:
  - gust above threshold
  - rapid gust increase
- Rain:
  - rain rate above threshold
  - rain in the last hour above threshold
- Temperature:
  - rapid temperature drop
  - frost warning
- Air quality:
  - PM2.5 above configured alert level

## Anti-spam rules

- Keep a per-rule cooldown, for example 30 to 60 minutes.
- Send a recovery notification only for important rules.
- Include the measured value, threshold, and timestamp in every message.
- Prefer short titles and detailed bodies.

## Example notification shape

```text
Weather alert: lightning nearby
1 strike in 5 minutes, closest 12 km, observed 21:42.
```

## Future config sketch

```php
'alerts' => [
    'enabled' => false,
    'provider' => 'ntfy',
    'topic' => 'YOUR_PRIVATE_TOPIC',
    'cooldown_seconds' => 3600,
    'rules' => [
        'lightning_5m' => ['enabled' => true, 'min_strikes' => 1],
        'pressure_drop_1h' => ['enabled' => true, 'threshold_hpa' => 2.0],
        'wind_gust' => ['enabled' => true, 'threshold_ms' => 15.0],
        'rain_rate' => ['enabled' => true, 'threshold_mm_hr' => 10.0],
        'pm25' => ['enabled' => true, 'threshold_ugm3' => 75.0],
    ],
],
```
