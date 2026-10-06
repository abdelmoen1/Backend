<?php

namespace App\Contracts;

use Illuminate\Http\Client\Response;

interface AIProvider
{
    public function generateResponse(array $payload): Response;
}
