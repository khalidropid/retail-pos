<?php

namespace App\Filament\Resources\Backups;

use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Filament\Support\Domain;
use App\Models\Backup;
use App\Services\BackupService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $modelLabel = 'نسخة احتياطية';

    protected static ?string $pluralModelLabel = 'النسخ الاحتياطي واستعادة قاعدة البيانات';

    protected static ?string $navigationLabel = 'النسخ الاحتياطي والاستعادة';

    protected static ?int $navigationSort = 15;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label('التاريخ والوقت')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('filename')->label('اسم الملف')->copyable()->fontFamily('mono')->size('xs'),
                TextColumn::make('type')->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state) => Backup::TYPES[$state] ?? $state),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (string $state) => Backup::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success', 'failed' => 'danger', default => 'warning'
                    }),
                TextColumn::make('size')->label('حجم قاعدة البيانات')->formatStateUsing(fn ($state) => Backup::humanSize($state)),
                TextColumn::make('files_size')->label('حجم الملفات المرفقة')->formatStateUsing(fn ($state) => Backup::humanSize($state))->placeholder('-'),
                TextColumn::make('creator.name')->label('بواسطة')->placeholder('المجدول'),
                TextColumn::make('error')->label('الخطأ')->limit(60)->placeholder('-')->color('danger')->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')->label('النوع')->options(Backup::TYPES),
                SelectFilter::make('status')->label('الحالة')->options(Backup::STATUSES),
            ])
            ->recordActions([
                Action::make('download')->label('تنزيل')->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (Backup $record) => $record->isCompleted() && auth()->user()->can('backups.manage'))
                    ->url(fn (Backup $record) => route('backups.download', $record)),
                Action::make('downloadFiles')->label('تنزيل الصور والملفات')->icon(Heroicon::OutlinedPhoto)->color('gray')
                    ->visible(fn (Backup $record) => $record->isCompleted() && filled($record->files_archive) && auth()->user()->can('backups.manage'))
                    ->url(fn (Backup $record) => route('backups.download', ['backup' => $record, 'part' => 'files'])),
                self::restoreAction(),
                Action::make('delete')->label('حذف')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (Backup $record) => $record->status !== 'running' && auth()->user()->can('backups.manage'))
                    ->requiresConfirmation()
                    ->modalHeading('حذف النسخة الاحتياطية نهائيًا؟')
                    ->modalDescription(fn (Backup $record) => "سيُحذف الملف {$record->filename} من القرص ولا يمكن استرجاعه.")
                    ->action(fn (Backup $record) => Domain::run(fn () => app(BackupService::class)->delete($record, auth()->user()), 'تم حذف النسخة')),
            ]);
    }

    public static function restoreAction(): Action
    {
        return Action::make('restore')->label('استعادة')->icon(Heroicon::OutlinedArrowPath)->color('danger')
            ->visible(fn (Backup $record) => $record->isCompleted() && auth()->user()->can('backups.restore'))
            ->modalHeading('استعادة قاعدة البيانات من هذه النسخة')
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalDescription(fn (Backup $record) => 'تحذير: ستُستبدل كل بيانات المتجر الحالية (المبيعات، المخزون، العملاء، الدفعات، المستخدمون، الإعدادات...) '
                .'ببيانات النسخة المؤرخة '.$record->created_at->format('Y-m-d H:i').'. '
                .'ستُنشأ نسخة أمان من البيانات الحالية تلقائيًا قبل البدء، وتُمنع أي عمليات أخرى أثناء الاستعادة، '
                .'وقد يُطلب منك تسجيل الدخول مجددًا بعدها. صور المنتجات والملفات لا تُستعاد من هنا.')
            ->modalSubmitActionLabel('نفّذ الاستعادة')
            ->schema([
                TextInput::make('confirmation')->label('للتأكيد اكتب: '.BackupService::CONFIRMATION)->required(),
            ])
            ->action(function (Backup $record, array $data, $livewire) {
                $result = Domain::run(fn () => app(BackupService::class)->restore($record, auth()->user(), (string) $data['confirmation']));
                Notification::make()->success()->persistent()->title('تمت استعادة قاعدة البيانات بنجاح')
                    ->body('نسخة الأمان للبيانات السابقة: '.$result['safety_backup'].' — عدد المبيعات بعد الاستعادة: '.$result['counts']['sales'])
                    ->send();
                $livewire->redirect(static::getUrl('index'));
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListBackups::route('/')];
    }
}
