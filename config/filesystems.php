<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // Private business files: attachments, import uploads, report exports, brand sources.
        // Never web-reachable; served only through authorized streamed routes (Chapter 14 §14.4).
        'private' => [
            'driver' => 'local',
            // An empty value must not become an empty (working-directory-relative) root.
            'root' => env('PRIVATE_STORAGE_ROOT') ?: storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // Public derived brand assets (icons, theme.css, logos) served under /brand/*.
        'brand' => [
            'driver' => 'local',
            'root' => storage_path('app/public/brand'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/brand',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
