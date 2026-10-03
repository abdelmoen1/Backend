<?php

return [

'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => [ 'https://go-traniee.netlify.app'],
'allowed_headers' => ['*'],
'supports_credentials' => true,

];