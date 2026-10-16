<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // FRONTEND_URL (واجهة Google OAuth) و CORS_ALLOWED_ORIGINS (قائمة مفصولة بفواصل) مسموحة دائمًا بجانب الأصول الثابتة
    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        fn (string $origin): string => rtrim(trim($origin), '/'),
        [
            'http://localhost:5173',
            'https://gotrainee-web.onrender.com',
            'https://gotrainee-five.vercel.app',
            (string) env('FRONTEND_URL'),
            ...explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
        ],
    )))),
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id'],
    'supports_credentials' => true,

];
