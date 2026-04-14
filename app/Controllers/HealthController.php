<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Utils\Request;
use App\Utils\Response;

final class HealthController
{
    public function index(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'SISTEM_PAY',
            'method' => $request->method(),
            'timestamp_utc' => gmdate('c'),
        ]);
    }
}
