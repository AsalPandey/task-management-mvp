<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrowserPushEndpointRequest;
use App\Http\Requests\StoreBrowserPushSubscriptionRequest;
use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushSubscription;
use App\Support\BrowserPushMessageFactory;
use App\ValueObjects\BrowserPushMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BrowserPushController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'configured' => $this->configured(),
            'public_key' => $this->configured() ? config('webpush.vapid.public_key') : null,
            'enabled_devices' => $request->user()->browserPushSubscriptions()->enabled()->count(),
            'service_worker' => url('/service-worker.js'),
            'secure_context_required' => true,
        ]);
    }

    public function store(StoreBrowserPushSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $hash = BrowserPushSubscription::endpointHash($data['endpoint']);

        $persist = fn (): BrowserPushSubscription => DB::transaction(function () use ($data, $hash, $request) {
            $existing = BrowserPushSubscription::query()
                ->where('endpoint_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($existing && (int) $existing->user_id !== (int) $request->user()->id) {
                abort(Response::HTTP_CONFLICT, 'This browser subscription belongs to another account. Unsubscribe it before switching users.');
            }

            $subscription = $existing ?: new BrowserPushSubscription;
            $subscription->forceFill([
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'endpoint_hash' => $hash,
                'public_key' => $data['public_key'],
                'auth_secret' => $data['auth_secret'],
                'content_encoding' => $data['content_encoding'],
                'device_label' => $data['device_label'] ?? null,
                'failure_count' => 0,
                'last_failure_at' => null,
                'disabled_at' => null,
                'revoked_at' => null,
            ])->save();

            return $subscription;
        });

        try {
            $subscription = $persist();
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration may insert the endpoint between our
            // locked lookup and insert. Re-read it under the same ownership rule.
            $subscription = $persist();
        }

        return response()->json([
            'enabled' => true,
            'created' => $subscription->wasRecentlyCreated,
            'enabled_devices' => $request->user()->browserPushSubscriptions()->enabled()->count(),
        ], $subscription->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function destroy(BrowserPushEndpointRequest $request): JsonResponse
    {
        $updated = $request->user()->browserPushSubscriptions()
            ->enabled()
            ->where('endpoint_hash', BrowserPushSubscription::endpointHash($request->validated('endpoint')))
            ->update([
                'disabled_at' => now(),
                'revoked_at' => now(),
            ]);

        return response()->json([
            'enabled' => false,
            'disabled' => $updated > 0,
            'enabled_devices' => $request->user()->browserPushSubscriptions()->enabled()->count(),
        ]);
    }

    public function test(BrowserPushEndpointRequest $request): JsonResponse
    {
        abort_unless($this->configured(), Response::HTTP_SERVICE_UNAVAILABLE, 'Browser push is not configured.');

        $subscription = $request->user()->browserPushSubscriptions()
            ->enabled()
            ->where('endpoint_hash', BrowserPushSubscription::endpointHash($request->validated('endpoint')))
            ->firstOrFail();

        $notificationId = (string) Str::uuid();
        $message = new BrowserPushMessage(
            title: config('app.name', 'Task Management MVP').' notifications are enabled',
            body: 'This browser can now receive task updates.',
            type: 'browser_push_test',
            targetPath: BrowserPushMessageFactory::applicationPath('notifications/all'),
            tag: "browser-push-test-{$subscription->id}",
        );

        SendBrowserPushNotification::dispatch(
            $request->user()->id,
            $notificationId,
            $message->payload($notificationId),
            $subscription->id,
        )->afterCommit();

        return response()->json(['queued' => true]);
    }

    private function configured(): bool
    {
        $subject = (string) config('webpush.vapid.subject');

        return (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
