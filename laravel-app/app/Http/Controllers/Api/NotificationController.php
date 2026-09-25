<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\OptionallyPaginates;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use AuthorizesRequests;
    use OptionallyPaginates;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Notification::class);

        $user = $request->user();

        $query = Notification::query()
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->orderByDesc('id');

        if ($request->query('unread') === '1' || $request->query('unread') === 'true') {
            $query->whereNull('read_at');
        }

        // Always bound listing: default page size when per_page omitted.
        if (! $request->filled('per_page')) {
            $request->query->set('per_page', 25);
        }

        return $this->optionallyPaginate(
            $query,
            $request,
            'notifications',
            fn (Notification $notification) => $notification->toApiArray(),
        );
    }

    public function show(Notification $notification): JsonResponse
    {
        $this->authorize('view', $notification);

        return response()->json([
            'notification' => $notification->toApiArray(),
        ]);
    }

    public function markRead(Notification $notification, NotificationService $service): JsonResponse
    {
        $this->authorize('markRead', $notification);

        $notification = $service->markRead($notification);

        return response()->json([
            'notification' => $notification->toApiArray(),
        ]);
    }

    public function markAllRead(Request $request, NotificationService $service): JsonResponse
    {
        $this->authorize('markAllRead', Notification::class);

        $updated = $service->markAllReadForUser($request->user());

        return response()->json([
            'message' => 'Notifications marked as read.',
            'updated' => $updated,
        ]);
    }
}
