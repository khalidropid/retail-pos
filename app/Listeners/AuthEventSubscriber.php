<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

class AuthEventSubscriber
{
    public function __construct(private AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            $this->audit->log('login', $event->user, userId: $event->user->id);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->log('logout', $event->user, userId: $event->user->id);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->log(
            'login_failed',
            $event->user instanceof User ? $event->user : null,
            new: ['email' => $event->credentials['email'] ?? null],
            userId: null,
        );
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }
}
