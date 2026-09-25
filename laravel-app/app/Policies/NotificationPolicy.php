<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NotificationPolicy
{
    public function viewAny(User $user): Response
    {
        if (! $user->hasPermission(Permission::NOTIFICATION_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function view(User $user, Notification $notification): Response
    {
        if (! $user->hasPermission(Permission::NOTIFICATION_READ)) {
            return Response::deny();
        }

        return $this->ownedByRecipient($user, $notification);
    }

    public function markRead(User $user, Notification $notification): Response
    {
        if (! $user->hasPermission(Permission::NOTIFICATION_MARK_READ)) {
            return Response::deny();
        }

        return $this->ownedByRecipient($user, $notification);
    }

    public function markAllRead(User $user): Response
    {
        if (! $user->hasPermission(Permission::NOTIFICATION_MARK_READ)) {
            return Response::deny();
        }

        return $user->company_id !== null ? Response::allow() : Response::denyAsNotFound();
    }

    private function ownedByRecipient(User $user, Notification $notification): Response
    {
        if (
            $user->company_id !== null
            && $user->company_id === $notification->company_id
            && $user->id === $notification->user_id
        ) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }
}
