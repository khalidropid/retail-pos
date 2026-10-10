<?php

namespace App\Filament\Resources\Backups\Pages;

use App\Filament\Resources\Backups\BackupResource;
use App\Filament\Support\Domain;
use App\Services\BackupService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListBackups extends ListRecords
{
    protected static string $resource = BackupResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'النسخ الاحتياطي واستعادة قاعدة البيانات';
    }

    public function getSubheading(): ?string
    {
        $heartbeat = Cache::get('scheduler.heartbeat');
        $schedulerRunning = $heartbeat && Carbon::parse($heartbeat)->gt(now()->subMinutes(5));
        $enabled = (bool) settings('backup.schedule_enabled', false);

        $schedule = match (true) {
            ! $enabled => 'النسخ التلقائي: معطّل في الإعدادات.',
            $schedulerRunning => 'النسخ التلقائي: مفعّل يوميًا الساعة '.settings('backup.schedule_time', '02:00').' (المجدول يعمل).',
            default => 'تنبيه: النسخ التلقائي مفعّل في الإعدادات لكن مجدول النظام لا يعمل على هذا الخادم، فلن تُنشأ نسخ تلقائية. راجع دليل التشغيل.',
        };

        return $schedule.' — مدة الاحتفاظ بالنسخ التلقائية: '.settings('backup.retention_days', 14).' يومًا.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('إنشاء نسخة احتياطية الآن')->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()->can('backups.manage'))
                ->requiresConfirmation()
                ->modalDescription('سيتم إنشاء نسخة كاملة من قاعدة البيانات (وصور المنتجات إن كان ذلك مفعّلًا في الإعدادات).')
                ->action(fn () => Domain::run(fn () => app(BackupService::class)->create('manual', auth()->user()), 'تم إنشاء النسخة الاحتياطية')),
            Action::make('upload')->label('رفع نسخة للاستعادة')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->visible(fn () => auth()->user()->can('backups.restore'))
                ->modalDescription('ارفع ملف نسخة (.dump) أنشأه هذا النظام. سيتم فحصه ولن يُستعاد إلا بعد تأكيدك.')
                ->schema([
                    FileUpload::make('file')->label('ملف النسخة (.dump)')->required()->storeFiles(false)
                        ->maxSize(config('backup.max_upload_mb') * 1024),
                ])
                ->action(function (array $data) {
                    /** @var TemporaryUploadedFile $file */
                    $file = $data['file'];
                    Domain::run(fn () => app(BackupService::class)->importUpload($file, auth()->user()), 'تم رفع النسخة والتحقق منها. يمكنك الآن استعادتها من القائمة.');
                }),
        ];
    }
}
