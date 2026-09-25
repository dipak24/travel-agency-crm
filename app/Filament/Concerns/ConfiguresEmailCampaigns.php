<?php

namespace App\Filament\Concerns;

use App\Models\EmailCampaign;
use App\Services\Mail\CampaignSender;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Table columns and send/schedule actions shared by the tenant-panel and admin-panel campaign
 * resources — they differ only in audience, template source, and which rows they show.
 */
trait ConfiguresEmailCampaigns
{
    /**
     * @return array<int, TextColumn>
     */
    protected static function campaignColumns(string $ownerType): array
    {
        return [
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('audience_type')
                ->label('Audience')
                ->formatStateUsing(fn (string $state): string => CampaignSender::AUDIENCES[$ownerType][$state] ?? $state),
            TextColumn::make('status')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'sent' => 'success',
                    'scheduled', 'queued', 'sending' => 'info',
                    'failed' => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('scheduled_at')->dateTime()->placeholder('—')->sortable(),
            TextColumn::make('total_recipients')->label('Recipients')->numeric(),
            TextColumn::make('sent_count')->label('Sent')->numeric(),
            TextColumn::make('failed_count')->label('Failed')->numeric(),
        ];
    }

    /**
     * @return array<int, ActionGroup>
     */
    protected static function campaignActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('sendNow')
                    ->label('Send now')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (EmailCampaign $record): bool => Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('Send this campaign to its whole audience now? Anyone who has unsubscribed is skipped automatically.')
                    ->action(function (EmailCampaign $record): void {
                        try {
                            app(CampaignSender::class)->sendNow($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Campaign queued for sending')->send();
                    }),
                Action::make('schedule')
                    ->label('Schedule')
                    ->icon('heroicon-o-clock')
                    ->visible(fn (EmailCampaign $record): bool => Gate::allows('update', $record))
                    ->form([
                        DateTimePicker::make('scheduled_at')->label('Send at')->required()->minDate(now())->seconds(false),
                    ])
                    ->action(function (EmailCampaign $record, array $data): void {
                        try {
                            app(CampaignSender::class)->schedule($record, CarbonImmutable::parse($data['scheduled_at']));
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Campaign scheduled')->send();
                    }),
                Action::make('cancelSchedule')
                    ->label('Cancel schedule')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (EmailCampaign $record): bool => $record->status === 'scheduled' && Gate::allows('update', $record))
                    ->action(fn (EmailCampaign $record) => app(CampaignSender::class)->cancelSchedule($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]),
        ];
    }
}
