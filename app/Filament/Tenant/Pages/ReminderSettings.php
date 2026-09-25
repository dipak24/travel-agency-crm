<?php

namespace App\Filament\Tenant\Pages;

use App\Services\BookingReminders;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;
use UnitEnum;

/**
 * The tenant's automated reminder schedule (see App\Services\BookingReminders). The wording of
 * each reminder is edited separately, as a transactional template under Email Templates.
 */
class ReminderSettings extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static UnitEnum|string|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Reminders';

    protected static ?string $title = 'Automated Reminders';

    protected static ?int $navigationSort = 3;

    private const LABELS = [
        BookingReminders::DOCUMENTS => [
            'Travel documents',
            'When a booking has no documents yet, or every upload of a document type was rejected.',
        ],
        BookingReminders::TRAVELER_INFO => [
            'Traveler details',
            'When a booking has fewer travelers than its pax count, or a traveler is missing a date of birth or passport number.',
        ],
        BookingReminders::BALANCE_DUE => [
            'Balance due',
            'When an issued invoice on the booking still has an unpaid balance.',
        ],
    ];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(BookingReminders::class)->settingsFor(auth('tenant')->user()->tenant);

        $this->form->fill(collect($settings)->map(fn (array $setting): array => [
            'enabled' => $setting['enabled'],
            'days_before' => array_map('strval', $setting['days_before']),
        ])->all());
    }

    public static function canAccess(): bool
    {
        $user = auth('tenant')->user();

        if (! $user) {
            return false;
        }

        app(TenantContext::class)->set($user->tenant);

        if (! Permission::query()->where('name', 'manage communications')->where('guard_name', 'tenant')->exists()) {
            return $user->hasRole('Tenant Owner');
        }

        return $user->hasPermissionTo('manage communications');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(
            collect(self::LABELS)->map(fn (array $label, string $type): Section => Section::make($label[0])
                ->description($label[1])
                ->schema([
                    Toggle::make("{$type}.enabled")->label('Send this reminder')->live(),
                    TagsInput::make("{$type}.days_before")
                        ->label('Days before the trip starts')
                        ->placeholder('e.g. 14')
                        ->helperText('Each number is one reminder. The customer only gets the most recent one that applies, and never the same one twice.')
                        ->nestedRecursiveRules(['integer', 'between:1,365'])
                        ->required(fn (Get $get): bool => (bool) $get("{$type}.enabled")),
                ]))->values()->all(),
        )->statePath('data')->columns(1);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = collect(BookingReminders::defaults())->map(fn (array $default, string $type): array => [
            'enabled' => (bool) ($data[$type]['enabled'] ?? false),
            'days_before' => collect($data[$type]['days_before'] ?? [])
                ->map(fn (mixed $days): int => (int) $days)
                ->unique()
                ->sortDesc()
                ->values()
                ->all(),
        ])->all();

        auth('tenant')->user()->tenant->update(['reminder_settings' => $settings]);

        Notification::make()->success()->title('Reminder schedule saved')->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Save')->submit('save')])->key('form-actions'),
                ]),
        ]);
    }
}
