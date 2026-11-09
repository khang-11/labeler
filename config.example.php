<?php

// Copy to config.php next to this file and change what you need. Every key can also be set
// as an environment variable LABELER_<KEY>, e.g. LABELER_PTOUCH_BIN=/usr/local/bin/ptouch,
// which wins over this file. Keys not listed here keep their default.

return [
    // ptouch executable: a name on PATH or an absolute path.
    'ptouch_bin' => 'ptouch',

    // Printers to use. A network printer only needs its host; ptouch detects the
    // model and the loaded tape over SNMP. Add 'model' or 'tape_width' only when
    // detection is wrong (for example a heat-shrink tube reported as TZe tape).
    'printers' => [
        'office' => [
            'host' => '192.168.8.235',
            // 'model' => 'P900W',       // optional override
            // 'tape_width' => 24,       // optional override
            // 'community' => 'public',  // SNMP read community
        ],
        // A USB printer: omit 'host' and set 'usb'.
        // 'desk' => ['usb' => true, 'serial' => '000F1Z401234'],
    ],

    // Default printer id from the map above. Empty: the first one.
    'printer' => 'office',

    // Shorthand for a single network printer (used when 'printers' is empty):
    // 'printer_host' => '192.168.8.235',
    // 'printer_model' => 'P900W',
    // 'printer_tape_width' => 24,

    // SNMP read community for network printers and scans.
    'printer_community' => 'public',

    // Optional vertical offset in pixels for printing. Leave this out to let
    // ptouch centre the printable area on the tape automatically.
    // 'vertical_offset' => 0,

    // Optional network for discovery (a follow-up UI); empty uses the local /24.
    'discovery_cidr' => '',

    // Seconds a single ptouch run may take before it is stopped.
    'timeout' => 60,

    // Writable folder for the print log (the last ptouch runs and their output).
    // Empty switches the log off.
    'data_dir' => __DIR__ . '/data',

    // Folder with .ttf, .otf, .woff or .woff2 files offered in the font list.
    'fonts_dir' => __DIR__ . '/fonts',

    // Largest accepted label image in bytes, and most copies per print.
    'max_upload_bytes' => 2000000,
    'max_copies' => 50,

    // Entries kept in the print log.
    'log_entries' => 50,

    // Password for the interface: plain text or, better, a hash made with
    //   php -r 'echo password_hash("your password", PASSWORD_DEFAULT);'
    // Empty: no login (fine on a home network).
    'password' => '',

    // Iconify API for the icon search (Material Design Icons, Simple Icons). The browser asks
    // it directly; the chosen icon is stored in the label. Use your own Iconify server, or ''
    // to switch icons off.
    'icon_api' => 'https://api.iconify.design',
];
