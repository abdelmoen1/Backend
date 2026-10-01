<?php

return [

'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['http://localhost:5173', 'https://go-traniee.netlify.app','https://frontend-seven-chi-86.vercel.app/'],
'allowed_headers' => ['*'],
'supports_credentials' => true,

];