<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'openai-compatible'),
    'base_url' => env('AI_BASE_URL', ''),
    'model' => env('AI_MODEL', ''),
    'api_key' => env('AI_API_KEY', ''),
    'timeout' => (int) env('AI_TIMEOUT', 30),
    'max_response_bytes' => (int) env('AI_MAX_RESPONSE_BYTES', 1048576),
];
