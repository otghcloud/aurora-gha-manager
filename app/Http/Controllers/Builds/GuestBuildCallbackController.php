<?php

namespace App\Http\Controllers\Builds;

use App\Http\Controllers\Controller;
use App\Models\Builds\ImageBuild;
use App\Services\Builds\GuestBuildCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuestBuildCallbackController extends Controller
{
    public function __invoke(Request $request, ImageBuild $imageBuild, GuestBuildCallbackService $callbacks): JsonResponse
    {
        $event = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(['started', 'stage_started', 'stage_completed', 'completed'])],
            'stage_id' => ['nullable', 'string', 'max:255'],
            'log' => ['nullable', 'string', 'max:262144'],
            'outcome' => ['nullable', 'string', Rule::in(['succeeded', 'failed', 'cancelled'])],
            'exit_code' => ['nullable', 'integer'],
            'error' => ['nullable', 'string', 'max:4096'],
        ]);

        if ($event['type'] === 'completed') {
            $request->validate(['outcome' => ['required']]);
        }

        $recorded = $callbacks->record($imageBuild, (string) $request->bearerToken(), $event);

        return response()->json(['recorded' => $recorded]);
    }
}
