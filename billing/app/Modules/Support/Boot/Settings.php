<?php

namespace App\Modules\Support\Boot;

use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Department;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Contracts\Container\Container;

final class Settings
{
    public function __invoke(Container $app): void
    {
        $statuses = fn (): array => collect(TicketStatus::active())->mapWithKeys(fn (TicketStatus $status): array => [$status->value => $status->label()])->all();

        $app->make(SettingsRegistry::class)->section('support', 'Support', [
            new SettingDefinition('support.ticket_number_format', 'Ticket number format', help: 'Tokens: %A A–Z, %a a–z, %n 0–9, %y %m %d date, %i id. Max 20 characters.', rules: ['max:20']),
            new SettingDefinition('support.auto_close_hours', 'Auto-close idle tickets after (hours)', 'integer', '0 turns auto-close off.', rules: ['min:0']),
            new SettingDefinition('support.auto_close_statuses', 'Statuses that auto-close', 'multiselect', 'Idle tickets in these statuses are closed after the hours above.', options: $statuses),
            new SettingDefinition('support.awaiting_reply_statuses', 'Statuses awaiting a staff reply', 'multiselect', 'Tickets in these statuses count as waiting for staff.', options: $statuses),
            new SettingDefinition('support.attachment_extensions', 'Client attachment types', help: 'Comma-separated extensions.'),
            new SettingDefinition('support.staff_attachment_extensions', 'Staff attachment types', help: 'Comma-separated extensions.'),
            new SettingDefinition('support.attachment_max_mb', 'Max attachment size (MB)', 'integer', rules: ['min:1', 'max:100']),
            new SettingDefinition('support.attachment_max_files', 'Max attachments per message', 'integer', rules: ['min:1', 'max:20']),
            new SettingDefinition('support.prune_attachments_after_months', 'Delete files of tickets closed more than (months)', 'integer', '0 keeps files forever.', rules: ['min:0']),
            new SettingDefinition('support.reply_ratings_enabled', 'Let clients rate staff replies', 'boolean'),
            new SettingDefinition('support.show_clients_only_to_guests', 'Show clients-only departments to guests', 'boolean'),
            new SettingDefinition('support.network_status_requires_login', 'Network status requires login', 'boolean'),
            new SettingDefinition('support.inbound_max_per_sender', 'Max inbound emails per sender per 15 minutes', 'integer', rules: ['min:1']),
            new SettingDefinition('support.allow_unverified_email_replies', 'Accept email replies from unknown senders', 'boolean'),
            new SettingDefinition('support.prevent_email_reopen', 'Email replies cannot reopen closed tickets', 'boolean'),
            new SettingDefinition('support.default_department_id', 'Default department for unknown addresses', 'select', 'Email sent to an address no department uses opens a ticket here.',
                options: fn (): array => ['0' => 'None'] + Department::query()->ordered()->pluck('name', 'id')->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all()),
            new SettingDefinition('support.reply_marker', 'Reply marker line'),
            new SettingDefinition('support.auto_add_cc', 'Add Cc addresses of inbound emails to the ticket', 'boolean'),
        ], 'support.settings.manage');

        // The inbound webhook's only credential: write-only, encrypted at rest, and behind a sensitive permission, so a
        // support.* role can neither read nor change it and a change needs a recent password confirmation.
        $app->make(SettingsRegistry::class)->section('support_inbound', 'Inbound email webhook', [
            new SettingDefinition('support.inbound_secret', 'Inbound email webhook secret', help: 'HMAC-SHA256 secret for the X-Support-Signature header. Write-only: enter a new value to replace it.', rules: ['min:32'], secret: true),
        ], 'support.inbound_webhook.manage');
    }
}
