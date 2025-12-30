<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmailNotVerifiedException extends Exception
{
    public function __construct(string $message = 'Please verify your email address before logging in.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], Response::HTTP_FORBIDDEN);
    }
}
