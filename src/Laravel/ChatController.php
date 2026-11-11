<?php

namespace FaridM\StepChat\Laravel;

use FaridM\StepChat\Http\ChatEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin adapter: the JSON protocol lives in ChatEndpoint.
 */
class ChatController
{
    public function __invoke(Request $request, string $flow, ChatEndpoint $endpoint): JsonResponse
    {
        $result = $endpoint->handle($flow, $request->json()->all());

        return response()->json($result['body'], $result['status'], ['Cache-Control' => 'no-store']);
    }
}
