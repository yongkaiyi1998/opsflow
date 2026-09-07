<?php

return [
    'disk' => env('ATTACHMENT_DISK', 'local'),
    'max_size' => '10mb',
    'types' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
];
