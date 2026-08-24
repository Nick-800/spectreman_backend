<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'eventId' => ['required', 'string'],
            'seq' => ['required', 'integer'],
            'event' => ['required', 'string'],
            'timestamp' => ['nullable', 'string'],
            'data' => ['required', 'array'],
        ]);

        $eventId = (string) $validated['eventId'];
        $event = (string) $validated['event'];
        $data = (array) $validated['data'];

        // Queue processing asynchronously
        ProcessInboundWebhookJob::dispatch($event, $data);

        return response()->json([
            'data' => [
                'received' => true,
                'eventId' => $eventId,
            ],
        ], 202);
    }
}
