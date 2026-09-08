<?php

return [
    // Example settings only: copy this to config/mail.php and replace the password on the server.
    'smtp_host' => 'mail.lasodapci.com',
    'smtp_port' => 465,
    'smtp_encryption' => 'ssl',
    'smtp_username' => 'carriere@lasodapci.com',
    'smtp_password' => 'REMPLACER_SUR_LE_SERVEUR',
    'from_email' => 'carriere@lasodapci.com',
    'from_name' => 'Site SODAP-CI',
    'recipient_email' => 'carriere@lasodapci.com',
    'allowed_hosts' => [
        'lasodapci.com',
        'www.lasodapci.com',
    ],
    'rate_limit' => [
        'max_requests' => 5,
        'window_seconds' => 3600,
    ],
];
