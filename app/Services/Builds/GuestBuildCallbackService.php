<?php

namespace App\Services\Builds;

use App\Models\Builds\ImageBuild;
use App\Models\Builds\ImageBuildGuestEvent;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuestBuildCallbackService
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{token: string, url: string} */
    public function issueCredentials(ImageBuild $build): array
    {
        $token = Str::random(64);
        $url = $this->settings->buildApiUrl();

        $build->forceFill([
            'guest_callback_token_hash' => hash('sha256', $token),
            'guest_callback_url' => $url,
            'guest_last_sequence' => 0,
            'guest_stage_id' => null,
            'guest_last_callback_at' => null,
            'guest_outcome' => null,
            'guest_exit_code' => null,
            'guest_error' => null,
            'guest_finalizing_at' => null,
        ])->save();

        return ['token' => $token, 'url' => $url];
    }

    /** @param array{sequence: int, type: string, stage_id?: string|null, log?: string|null, outcome?: string|null, exit_code?: int|null, error?: string|null} $event */
    public function record(ImageBuild $build, string $token, array $event): bool
    {
        if ($build->guest_callback_token_hash === null || ! hash_equals($build->guest_callback_token_hash, hash('sha256', $token))) {
            abort(401);
        }

        return DB::transaction(function () use ($build, $event): bool {
            $build->refresh();

            if ($event['sequence'] <= $build->guest_last_sequence) {
                return false;
            }

            ImageBuildGuestEvent::create([
                'image_build_id' => $build->id,
                'sequence' => $event['sequence'],
                'type' => $event['type'],
                'payload' => $event,
                'received_at' => now(),
            ]);

            if (($event['log'] ?? null) !== null && $build->log_path !== null) {
                $directory = dirname($build->log_path);

                if (! is_dir($directory)) {
                    mkdir($directory, 0750, true);
                }

                file_put_contents($build->log_path, $event['log'], FILE_APPEND);
            }

            $attributes = [
                'guest_last_sequence' => $event['sequence'],
                'guest_last_callback_at' => now(),
                'guest_stage_id' => $event['stage_id'] ?? $build->guest_stage_id,
            ];

            if ($event['type'] === 'completed') {
                $attributes['guest_outcome'] = $event['outcome'];
                $attributes['guest_exit_code'] = $event['exit_code'] ?? 1;
                $attributes['guest_error'] = $event['error'] ?? null;
            }

            $build->forceFill($attributes)->save();

            return true;
        });
    }
}
