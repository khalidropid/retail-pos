<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /** Attributes never written to the audit trail. */
    private const HIDDEN = ['password', 'remember_token', 'updated_at', 'created_at', 'name_normalized'];

    public function log(
        string $event,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?string $description = null,
        ?int $userId = null,
    ): AuditLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old === null ? null : $this->clean($old),
            'new_values' => $new === null ? null : $this->clean($new),
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /** Log a model update with only the changed attributes. */
    public function logChanges(string $event, Model $model, ?string $reason = null): ?AuditLog
    {
        $changes = collect($model->getChanges())->except(self::HIDDEN);

        if ($changes->isEmpty()) {
            return null;
        }

        $old = $changes->keys()->mapWithKeys(fn ($key) => [$key => $model->getOriginal($key)])->all();

        return $this->log($event, $model, $old, $changes->all(), $reason);
    }

    private function clean(array $values): array
    {
        return collect($values)->except(self::HIDDEN)->map(function ($value) {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            return $value;
        })->all();
    }
}
