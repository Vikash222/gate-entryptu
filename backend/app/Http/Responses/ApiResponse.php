<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponse
{
    public function success(mixed $data = null, string $message = 'Operation successful', int $status = Response::HTTP_OK): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data ?? (object) [],
        ];

        return response()->json($response, $status);
    }

    public function created(mixed $data = null, string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->success($data, $message, Response::HTTP_CREATED);
    }

    public function error(string $message = 'An error occurred', mixed $errors = null, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors' => $errors ?? (object) [],
        ];

        return response()->json($response, $status);
    }

    public function forbidden(string $message = 'Access denied'): JsonResponse
    {
        return $this->error($message, null, Response::HTTP_FORBIDDEN);
    }

    public function unauthorized(string $message = 'Unauthenticated'): JsonResponse
    {
        return $this->error($message, null, Response::HTTP_UNAUTHORIZED);
    }

    public function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, null, Response::HTTP_NOT_FOUND);
    }
}
