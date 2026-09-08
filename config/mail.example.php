<?php

return [
    // Example settings only: copy this as sodap-mail-config.php immediately above public_html.
    // Never place the real configuration under the web root; replace the password on the server.
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
    'rate_limit_count' => 5,
    'rate_limit_window_seconds' => 3600,
];
