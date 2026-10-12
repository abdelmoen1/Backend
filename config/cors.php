<?php

return [

'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
// FRONTEND_URL (واجهة Google OAuth) مسموح دائمًا بجانب الأصول الثابتة
'allowed_origins' => array_values(array_unique(array_filter([
    'https://gotrainee.netlify.app',
    'http://localhost:5173',
    'https://gotrainee-five.vercel.app',
    rtrim((string) env('FRONTEND_URL'), '/'),
]))),
'allowed_headers' => ['*'],
'supports_credentials' => true,

];
