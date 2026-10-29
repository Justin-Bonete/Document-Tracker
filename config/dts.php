<?php
return [
    'tracking_prefix' => 'DTS',
    'upload' => [
        'max_kb'     => (int) env('DTS_UPLOAD_MAX_KB', 20480),   // 20 MB
        'extensions' => ['pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg', 'txt'],
    ],
    // Phase 4 adds a real scanner. While false, 'pending_scan' files stay downloadable (dev only).
    'malware_scan_enabled' => (bool) env('DTS_MALWARE_SCAN', false),
];
