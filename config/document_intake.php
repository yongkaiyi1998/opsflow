<?php

return [
    'disk' => env('DOCUMENT_INTAKE_DISK', 'local'),
    'max_files' => 20,
    'max_size' => '10mb',
    'extraction_max_bytes' => 10 * 1024 * 1024,
    'types' => ['pdf', 'jpg', 'jpeg', 'png'],
    'mime_extensions' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ],
];
