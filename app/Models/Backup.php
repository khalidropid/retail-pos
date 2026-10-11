<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    public const TYPES = [
        'manual' => 'يدوية',
        'scheduled' => 'مجدولة',
        'pre_restore' => 'نسخة أمان قبل الاستعادة',
        'pre_upgrade' => 'نسخة أمان قبل التحديث',
        'uploaded' => 'مرفوعة',
    ];

    public const STATUSES = ['running' => 'جارية', 'completed' => 'مكتملة', 'failed' => 'فشلت'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'size' => 'integer', 'files_size' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public static function humanSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < 3) {
            $size /= 1024;
            $i++;
        }

        return number_format($size, $i ? 1 : 0).' '.$units[$i];
    }
}
