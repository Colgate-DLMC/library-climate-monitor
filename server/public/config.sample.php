<?php
// Copy this file to ONE of:
//   ~/climate-config.php            (best: your home directory, outside the web root)
//   public_html/climate/config.php  (works; .htaccess blocks direct access)
// then fill in your own values. Never commit the real file.

return [
    // 'mysql' on Domains / cPanel. 'sqlite' is for local development only.
    'db' => [
        'driver' => 'mysql',
        'host'   => 'localhost',
        'name'   => 'cpaneluser_climate',   // cPanel prefixes DB and user names with your account name
        'user'   => 'cpaneluser_climate',
        'pass'   => 'CHANGE-ME',

        // 'driver' => 'sqlite',
        // 'path'   => __DIR__ . '/dev.sqlite',
    ],

    // Reject ingest requests that did not arrive over HTTPS.
    // Turn off only for local development with `php -S`.
    'require_https' => true,

    // Range checks applied to every reading before it is stored.
    'limits' => [
        'temp_c_min' => -40.0, 'temp_c_max' => 85.0,
        'rh_min'     =>   0.0, 'rh_max'     => 100.0,
        'batt_v_min' =>   0.0, 'batt_v_max' =>   6.0,
    ],
];
