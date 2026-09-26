<?php

namespace App\Filament\Concerns;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\SuperAdmin;
use App\Models\TenantUser;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared table/infolist building blocks for the admin (platform entries only) and tenant (own
 * tenant only) Audit Log resources. Each resource scopes `getEloquentQuery()`, and the option
 * lists are built from it, so neither panel can even list users/record types from the other's data.
 */
trait PresentsActivityLog
{
    public const CAUSER_TYPES = [
        SuperAdmin::class => 'Platform admin',
        TenantUser::class => 'Staff',
        Customer::class => 'Customer',
    ];

    public const EVENTS = ['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted'];

    /**
     * @var array<string, ?string>
     */
    private static array $causerNames = [];

    protected static function activityTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('causer_id')
                    ->label('User')
                    ->state(fn (Activity $record): string => static::causerLabel($record))
                    ->description(fn (Activity $record): string => self::CAUSER_TYPES[$record->causer_type] ?? 'System'),
                TextColumn::make('event')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('subject_type')
                    ->label('Record')
                    ->state(fn (Activity $record): string => static::subjectLabel($record)),
                TextColumn::make('description')->limit(50)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('causer')
                    ->label('User')
                    ->options(fn (): array => static::causerOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        [$type, $id] = explode('|', (string) $data['value'], 2) + [null, null];

                        return $query->where('causer_type', $type)->where('causer_id', (int) $id);
                    }),
                SelectFilter::make('event')->options(self::EVENTS),
                SelectFilter::make('subject_type')
                    ->label('Record type')
                    ->options(fn (): array => static::getEloquentQuery()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->orderBy('subject_type')
                        ->pluck('subject_type')
                        ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
                        ->all()),
                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? 'From '.$data['from'] : null,
                        filled($data['until'] ?? null) ? 'Until '.$data['until'] : null,
                    ]))),
            ]);
    }

    protected static function activityInfolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')
                ->schema([
                    TextEntry::make('created_at')->label('When')->dateTime(),
                    TextEntry::make('causer_id')->label('User')
                        ->state(fn (Activity $record): string => static::causerLabel($record).' ('.(self::CAUSER_TYPES[$record->causer_type] ?? 'System').')'),
                    TextEntry::make('event')->badge()->placeholder('—'),
                    TextEntry::make('subject_type')->label('Record')->state(fn (Activity $record): string => static::subjectLabel($record)),
                    TextEntry::make('log_name')->label('Log')->placeholder('—'),
                    TextEntry::make('description')->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
            Section::make('Changes')
                ->schema([
                    RepeatableEntry::make('changes')
                        ->hiddenLabel()
                        ->state(fn (Activity $record): array => static::changes($record))
                        ->schema([
                            TextEntry::make('field'),
                            TextEntry::make('old')->label('Before')->placeholder('—'),
                            TextEntry::make('new')->label('After')->placeholder('—'),
                        ])
                        ->columns(3)
                        ->contained(false),
                ])
                ->visible(fn (Activity $record): bool => static::changes($record) !== [])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Field-by-field before/after values from the entry's `attribute_changes`.
     *
     * @return list<array{field: string, old: ?string, new: ?string}>
     */
    public static function changes(Activity $record): array
    {
        $new = (array) ($record->attribute_changes?->get('attributes') ?? []);
        $old = (array) ($record->attribute_changes?->get('old') ?? []);

        return collect(array_keys($new + $old))
            ->map(fn (string $field): array => [
                'field' => $field,
                'old' => static::displayValue($old[$field] ?? null),
                'new' => static::displayValue($new[$field] ?? null),
            ])
            ->values()
            ->all();
    }

    public static function subjectLabel(Activity $record): string
    {
        return $record->subject_type === null ? '—' : class_basename($record->subject_type).' #'.$record->subject_id;
    }

    /**
     * The acting user's name, looked up without tenant scopes (a scoped lookup would miss platform
     * admins and, outside a tenant context, everyone). Memoized per request.
     */
    public static function causerLabel(Activity $record): string
    {
        if ($record->causer_type === null || ! isset(self::CAUSER_TYPES[$record->causer_type])) {
            return 'System';
        }

        $key = "{$record->causer_type}|{$record->causer_id}";

        if (! array_key_exists($key, self::$causerNames)) {
            /** @var class-string<Model> $type */
            $type = $record->causer_type;
            self::$causerNames[$key] = $type::query()->withoutGlobalScopes()->whereKey($record->causer_id)->value('name');
        }

        return self::$causerNames[$key] ?? "Deleted user #{$record->causer_id}";
    }

    /**
     * Every user who appears in the visible entries, as "type|id" => "Name (Staff)" filter options.
     *
     * @return array<string, string>
     */
    private static function causerOptions(): array
    {
        return static::getEloquentQuery()
            ->whereIn('causer_type', array_keys(self::CAUSER_TYPES))
            ->select(['causer_type', 'causer_id'])
            ->distinct()
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Activity $activity): array => [
                "{$activity->causer_type}|{$activity->causer_id}" => static::causerLabel($activity).' ('.self::CAUSER_TYPES[$activity->causer_type].')',
            ])
            ->sort()
            ->all();
    }

    private static function displayValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
