<?php

/**
 * Deploy configuration — copy this to deploy-config.php and fill in your values.
 * deploy-config.php is ignored and will not be synced to the server.
 */
return [
    'ftp_host'     => 'ftp.xxx.xxx',
    'ftp_port'     => 21,
    'ftp_user'     => '<username>',
    'ftp_pass'     => '<password>',
    'ftp_ssl'      => false,
    'ftp_passive'  => true,

    // Remote path to wp-content/themes/ (with trailing slash)
    'remote_themes_path' => '/public_html/wp-content/themes/',

    // Set to true to preview without uploading
    'dry_run' => false,
];
