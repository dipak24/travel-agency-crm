<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Concerns\PresentsActivityLog;
use App\Filament\Tenant\Resources\ActivityLogResource\Pages;
use App\Models\Activity;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tenant's own audit log: every logged change to this tenant's records, whoever made it
 * (staff, customers, or a platform admin). Activity isn't BelongsToTenant — the admin panel reads
 * it cross-tenant — so the tenant scoping is applied here. Read-only (see ActivityPolicy), gated
 * by `view audit log`.
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

    protected static ?int $navigationSort = 110;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', app(TenantContext::class)->id() ?? 0);
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
