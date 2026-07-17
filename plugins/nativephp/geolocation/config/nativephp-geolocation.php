<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Foreground location service
    |--------------------------------------------------------------------------
    |
    | Opt in to the Android foreground-location service (LocationWatchService)
    | and the iOS `location` background mode. This lets a watch keep recording
    | while the app is open OR backgrounded — but NOT after the process is
    | killed. Turning it on inserts FOREGROUND_SERVICE, FOREGROUND_SERVICE_LOCATION
    | and POST_NOTIFICATIONS into the Android manifest, which Google Play flags
    | for a foreground-service declaration.
    |
    | Leave this false for apps that only read a one-shot position or use a
    | plain (foreground-only) watchPosition() — that path needs no service.
    |
    */

    'foreground_service' => env('NATIVEPHP_GEOLOCATION_FOREGROUND_SERVICE', false),

    /*
    |--------------------------------------------------------------------------
    | Background location (killed-app + reboot survival)
    |--------------------------------------------------------------------------
    |
    | Opt in to recording that survives the app being backgrounded, killed, and
    | the device rebooting (the ->background() recorder). This implies the
    | foreground service above and additionally inserts ACCESS_BACKGROUND_LOCATION
    | + RECEIVE_BOOT_COMPLETED and the boot receiver on Android, and the
    | NSLocationAlwaysAndWhenInUseUsageDescription string on iOS.
    |
    | Google Play requires a background-location declaration form (and often a
    | demo video) when this is set; iOS App Review will ask why. Only enable it
    | if your app genuinely records location in the background.
    |
    */

    'background_location' => env('NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION', false),

];
