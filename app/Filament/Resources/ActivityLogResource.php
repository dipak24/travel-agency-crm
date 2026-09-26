<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\PresentsActivityLog;
use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\Activity;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only view of the platform's own activity log (spatie/laravel-activitylog): only entries
 * with no tenant_id. Tenant entries — including changes a platform admin made inside a tenant's
 * data — are private to that tenant and only appear in App\Filament\Tenant\Resources\ActivityLogResource.
 * Entries can be filtered by user / event / record type / date and opened to see which fields
 * changed — never created, edited, or deleted (see ActivityPolicy).
 */
class ActivityLogResource extends Resource
{
    use PresentsActivityLog;

    protected static ?string $model = Activity::class;

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $modelLabel = 'audit log entry';

    protected static ?string $pluralModelLabel = 'audit log';

    protected static ?string $slug = 'audit-log';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static UnitEnum|string|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 90;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('tenant_id');
    }

    public static function table(Table $table): Table
    {
        return static::activityTable($table)
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return static::activityInfolist($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
