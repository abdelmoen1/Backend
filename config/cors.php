<?php

return [

'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => [ 'https://go-traniee.netlify.app','http://localhost:5173',"https://gotrainee-five.vercel.app"],
'allowed_headers' => ['*'],
'supports_credentials' => true,

];