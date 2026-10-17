<?php

namespace App\Modules\Support\Database\Seeders;

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Support\Actions\AddNote;
use App\Modules\Support\Actions\CloseTicket;
use App\Modules\Support\Actions\OpenTicket;
use App\Modules\Support\Actions\PostReply;
use App\Modules\Support\Data\TicketAuthor;
use App\Modules\Support\Data\TicketDraft;
use App\Modules\Support\Enums\ClosedBy;
use App\Modules\Support\Enums\IncidentPriority;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\IncidentType;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Announcement;
use App\Modules\Support\Models\CannedReply;
use App\Modules\Support\Models\CannedReplyCategory;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Models\KbArticle;
use App\Modules\Support\Models\KbCategory;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\NetworkIncidentUpdate;
use App\Modules\Support\Models\StaffSignature;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketEvent;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\IncomingFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Demo helpdesk: departments, canned replies, knowledge base, announcements, incidents and
 * tickets with conversations for the demo client. Safe to re-run (skips when departments exist).
 */
class SupportSeeder extends Seeder
{
    public function run(): void
    {
        if (Department::query()->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@example.com')->first();
        $departments = $this->departments($admin);
        $this->cannedReplies();
        $this->knowledgeBase();
        $this->announcements();
        $this->incidents();

        if ($admin !== null) {
            StaffSignature::query()->create(['user_id' => $admin->id, 'signature' => "Best regards,\nAdmin · Support team"]);
            $this->tickets($admin, $departments);
        }
    }

    /**
     * @return array<string, Department>
     */
    private function departments(?User $admin): array
    {
        $rows = [
            'technical' => ['name' => 'Technical Support', 'email' => 'support@example.com', 'description' => 'Hosting, email, DNS and server issues.', 'clients_only' => true, 'autoresponse_message' => "Thanks for contacting Technical Support. We usually reply within 2 hours.\n\nIn the meantime, the [knowledge base](/knowledgebase) may already have an answer."],
            'billing' => ['name' => 'Billing', 'email' => 'billing@example.com', 'description' => 'Invoices, payments, refunds and account questions.'],
            'sales' => ['name' => 'Sales', 'email' => 'sales@example.com', 'description' => 'Questions before you buy.'],
            'abuse' => ['name' => 'Abuse', 'email' => 'abuse@example.com', 'description' => 'Report spam, phishing or abuse.', 'is_hidden' => true, 'prevent_client_close' => true, 'send_autoresponse' => false],
        ];

        $departments = [];
        $order = 0;

        foreach ($rows as $key => $row) {
            $departments[$key] = Department::query()->create([
                'slug' => $key,
                'sort_order' => $order++,
                'send_autoresponse' => true,
                ...$row,
            ]);

            if ($admin !== null) {
                $departments[$key]->staff()->attach($admin->id);
            }
        }

        return $departments;
    }

    private function cannedReplies(): void
    {
        $general = CannedReplyCategory::query()->create(['name' => 'General']);
        $billing = CannedReplyCategory::query()->create(['name' => 'Billing']);
        $email = CannedReplyCategory::query()->create(['name' => 'Email', 'parent_id' => $general->id]);

        CannedReply::query()->create(['category_id' => $general->id, 'name' => 'Greeting', 'body' => "Hi {{ requester.first_name }},\n\nThanks for reaching out about ticket #{{ ticket.number }}."]);
        CannedReply::query()->create(['category_id' => $general->id, 'name' => 'Need more details', 'body' => "Hi {{ requester.first_name }},\n\nCould you send the exact error message and the time it happened? A screenshot helps too."]);
        CannedReply::query()->create(['category_id' => $email->id, 'name' => 'Mail client settings', 'body' => "Use these settings:\n\n- Incoming: `mail.example.com`, port 993 (SSL)\n- Outgoing: `mail.example.com`, port 465 (SSL)\n- Username: your full email address"]);
        CannedReply::query()->create(['category_id' => $billing->id, 'name' => 'Refund timeline', 'body' => 'Refunds reach your card within 5–10 business days, depending on your bank.']);
    }

    private function knowledgeBase(): void
    {
        $start = KbCategory::query()->create(['name' => 'Getting started', 'slug' => 'getting-started', 'description' => 'First steps with your new account.', 'sort_order' => 0]);
        $email = KbCategory::query()->create(['name' => 'Email', 'slug' => 'email', 'description' => 'Mailboxes, forwarding and mail clients.', 'sort_order' => 1]);
        $domains = KbCategory::query()->create(['name' => 'Domains & DNS', 'slug' => 'domains-dns', 'description' => 'Nameservers, records and transfers.', 'sort_order' => 2]);
        $billing = KbCategory::query()->create(['name' => 'Billing', 'slug' => 'billing', 'description' => 'Invoices, payment methods and refunds.', 'sort_order' => 3]);
        $outlook = KbCategory::query()->create(['name' => 'Mail clients', 'slug' => 'mail-clients', 'parent_id' => $email->id, 'description' => 'Outlook, Apple Mail, Thunderbird.']);

        $articles = [
            [$start, 'Your first login', "After your order is activated you receive a welcome email.\n\n1. Open the client area.\n2. Sign in with your email address.\n3. Turn on two-factor authentication under **Security**.", ['login', 'account'], 320, false],
            [$start, 'Uploading your website', "Upload files with SFTP to the `public_html` folder.\n\n```\nHost: sftp.example.com\nPort: 22\n```", ['sftp', 'upload'], 210, false],
            [$email, 'Create a mailbox', "Go to **Services → Email** and choose *Add mailbox*. Mailboxes include 10 GB of storage.", ['mailbox'], 145, false],
            [$outlook, 'Set up Outlook', "Use IMAP with SSL:\n\n| Setting | Value |\n| --- | --- |\n| Incoming | mail.example.com:993 |\n| Outgoing | mail.example.com:465 |", ['outlook', 'imap'], 98, false],
            [$domains, 'Point your domain to us', "Set these nameservers at your registrar:\n\n- ns1.example.com\n- ns2.example.com\n\nChanges can take up to 24 hours.", ['dns', 'nameservers'], 260, false],
            [$domains, 'Add a DNS record', 'Open **Domains → DNS zone**, choose the record type and save. TTL defaults to 3600 seconds.', ['dns', 'records'], 77, false],
            [$billing, 'Change your payment method', 'Payment methods are managed under **Billing → Payment methods**. The default method is charged automatically on renewal.', ['payments'], 64, false],
            [$billing, 'Reseller pricing sheet', 'Reseller price lists are available to signed-in partners only.', ['reseller'], 12, true],
        ];

        foreach ($articles as $order => [$category, $title, $body, $tags, $views, $private]) {
            $article = KbArticle::query()->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'body' => $body,
                'is_published' => true,
                'is_private' => $private,
                'tags' => $tags,
                'views' => $views,
                'votes_up' => intdiv($views, 10),
                'votes_down' => intdiv($views, 60),
                'sort_order' => $order,
            ]);
            $article->categories()->attach($category->id);
        }

        KbArticle::query()->create(['title' => 'Migrating from cPanel (draft)', 'slug' => 'migrating-from-cpanel', 'body' => 'Work in progress.', 'is_published' => false]);
    }

    private function announcements(): void
    {
        Announcement::query()->create(['title' => 'New datacenter in Frankfurt', 'slug' => 'new-datacenter-frankfurt', 'excerpt' => 'EU customers can now host closer to their visitors.', 'body' => "We're happy to announce our new **Frankfurt** location.\n\nNew orders can pick it at checkout; existing services can be migrated for free.", 'is_published' => true, 'published_at' => now()->subDays(2)]);
        Announcement::query()->create(['title' => 'PHP 8.4 now available', 'slug' => 'php-84-available', 'excerpt' => 'Switch versions per site from the control panel.', 'body' => 'PHP 8.4 is available on all shared hosting plans. PHP 8.1 reaches end of life on December 31.', 'is_published' => true, 'published_at' => now()->subDays(12)]);
        Announcement::query()->create(['title' => 'Scheduled maintenance window', 'slug' => 'scheduled-maintenance', 'excerpt' => 'Network upgrades next Sunday.', 'body' => 'Brief interruptions of up to 5 minutes are expected between 02:00 and 04:00 UTC.', 'is_published' => true, 'published_at' => now()->addDays(3)]);
        Announcement::query()->create(['title' => 'Holiday support hours (draft)', 'slug' => 'holiday-support-hours', 'body' => 'Draft', 'is_published' => false]);
    }

    private function incidents(): void
    {
        $open = NetworkIncident::query()->create([
            'title' => 'Degraded performance on web03',
            'type' => IncidentType::Server,
            'affected' => 'web03.example.net',
            'priority' => IncidentPriority::High,
            'status' => IncidentStatus::Identified,
            'description' => 'Some sites on **web03** respond slowly. Email is not affected.',
            'starts_at' => now()->subHours(2),
        ]);
        NetworkIncidentUpdate::query()->create(['incident_id' => $open->id, 'body' => 'We are investigating reports of slow responses.', 'status' => IncidentStatus::Investigating]);
        NetworkIncidentUpdate::query()->create(['incident_id' => $open->id, 'body' => 'A failing disk in the storage array was identified and is being replaced.', 'status' => IncidentStatus::Identified]);

        $resolved = NetworkIncident::query()->create([
            'title' => 'Outbound email delays',
            'type' => IncidentType::System,
            'affected' => 'Outbound mail relay',
            'priority' => IncidentPriority::Medium,
            'status' => IncidentStatus::Resolved,
            'description' => 'Outbound email was queued for up to 30 minutes.',
            'starts_at' => now()->subDays(4)->subHours(3),
            'ends_at' => now()->subDays(4),
        ]);
        NetworkIncidentUpdate::query()->create(['incident_id' => $resolved->id, 'body' => 'The relay queue has been cleared and mail flows normally.', 'status' => IncidentStatus::Resolved]);

        NetworkIncident::query()->create([
            'title' => 'Core router firmware upgrade',
            'type' => IncidentType::Other,
            'affected' => 'All services (brief)',
            'priority' => IncidentPriority::Low,
            'status' => IncidentStatus::Scheduled,
            'description' => 'Planned upgrade; expect up to 2 minutes of packet loss.',
            'starts_at' => now()->addDays(3),
        ]);
    }

    /**
     * @param  array<string, Department>  $departments
     */
    private function tickets(User $admin, array $departments): void
    {
        $open = app(OpenTicket::class);
        $reply = app(PostReply::class);
        $staff = TicketAuthor::staff($admin);
        $demoUser = User::query()->where('email', 'demo@example.com')->first();
        $demo = $demoUser?->clients()->first();

        if ($demoUser !== null && $demo !== null) {
            $customer = TicketAuthor::client($demoUser);
            $draft = fn (Department $department, string $subject, string $body, TicketPriority $priority = TicketPriority::Medium, ?int $serviceId = null) => new TicketDraft(
                departmentId: $department->id, subject: $subject, body: $body, author: $customer,
                requesterName: $demoUser->name, requesterEmail: $demoUser->email, clientId: $demo->id, userId: $demoUser->id,
                priority: $priority, serviceId: $serviceId,
            );

            $slow = $open->handle($draft($departments['technical'], 'Website loads very slowly since this morning', "Hi,\n\nSince about 8am our shop at **demo-labs.example** takes 10+ seconds to load. Nothing changed on our side.\n\nCan you check the server?", TicketPriority::High, 1));
            $this->attachLog($slow);
            $reply->handle($slow, $staff, "Hi {{ requester.first_name }},\n\nThanks for the report. We see elevated I/O on **web03** and a storage disk is being replaced right now — see the [network status](/network-status) page.\n\nI'll update you as soon as it's done.", status: TicketStatus::InProgress);
            app(AddNote::class)->handle($slow, $admin, 'Linked to the web03 disk incident. Follow up once the array has rebuilt.');
            $reply->handle($slow, $customer, 'Thanks for the quick answer! Do you have an ETA?');
            $this->age($slow, hours: 5);

            $mailbox = $open->handle($draft($departments['technical'], 'Cannot send email from Outlook', 'Outlook asks for my password again and again when sending. Receiving works fine.'));
            $reply->handle($mailbox, $staff, "Hi {{ requester.first_name }},\n\nPlease check the outgoing server uses port **465** with SSL and that *My outgoing server requires authentication* is ticked.\n\nOur guide: [Set up Outlook](/knowledgebase/set-up-outlook).");
            $this->age($mailbox, hours: 30);

            $invoice = $open->handle($draft($departments['billing'], 'Question about invoice for October', 'Why does my October invoice include a setup fee? I thought it was waived.', TicketPriority::Low));
            $reply->handle($invoice, $staff, 'You are right — the setup fee should have been waived. I have credited it to your account.');
            $reply->handle($invoice, $customer, 'Perfect, thank you!');
            app(CloseTicket::class)->handle($invoice, ClosedBy::Client, $customer);
            $this->age($invoice, hours: 24 * 9);

            $upgrade = $open->handle($draft($departments['sales'], 'Upgrade to a VPS plan', 'We are outgrowing shared hosting. What does a move to a VPS involve?'));
            $this->age($upgrade, hours: 1);
        }

        $guestEmail = 'jordan.prospect@example.org';
        $guest = $open->handle(new TicketDraft(
            departmentId: $departments['sales']->id,
            subject: 'Do you offer Windows hosting?',
            body: "Hello,\n\nWe need IIS and .NET 8. Is that something you offer?\n\nThanks,\nJordan",
            author: TicketAuthor::guest('Jordan Prospect', $guestEmail),
            requesterName: 'Jordan Prospect',
            requesterEmail: $guestEmail,
        ));
        $reply->handle($guest, $staff, "Hi {{ requester.first_name }},\n\nWe focus on Linux hosting, but our VPS plans can run Windows Server with a licence add-on. Would you like a quote?");
        $this->age($guest, hours: 50);

        $other = Client::query()->where('email', '!=', 'demo@example.com')->orderBy('id')->first();

        if ($other !== null) {
            $spam = $open->handle(new TicketDraft(
                departmentId: $departments['abuse']->id,
                subject: 'Phishing page hosted on your network',
                body: 'A phishing page imitating a bank is hosted at a site on your network. Please take it down.',
                author: TicketAuthor::emailSender(trim($other->first_name.' '.$other->last_name), $other->email, true),
                requesterName: trim($other->first_name.' '.$other->last_name),
                requesterEmail: $other->email,
                clientId: $other->id,
                priority: TicketPriority::Urgent,
            ));
            $spam->forceFill(['is_pinned' => true, 'tags' => ['abuse', 'phishing']])->save();
            $this->age($spam, hours: 3);
        }
    }

    /**
     * Spread the demo conversation over time so the queue and thread look realistic.
     */
    private function age(Ticket $ticket, int $hours): void
    {
        $start = CarbonImmutable::now()->subHours($hours);
        $messages = TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get();
        $step = max(1, intdiv($hours * 60, max(1, $messages->count())));

        foreach ($messages as $index => $message) {
            $at = $start->addMinutes($index * $step);
            $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }

        $last = $messages->last()?->created_at ?? $start;
        $staffReplies = $messages->filter(fn (TicketMessage $message): bool => $message->isStaffReply());
        $clientReplies = $messages->filter(fn (TicketMessage $message): bool => $message->author_type->isCustomer());

        $ticket->forceFill([
            'created_at' => $start,
            'last_reply_at' => $last,
            'first_staff_reply_at' => $staffReplies->first()?->created_at,
            'last_staff_reply_at' => $staffReplies->last()?->created_at,
            'last_client_reply_at' => $clientReplies->last()?->created_at,
            'closed_at' => $ticket->closed_at !== null ? $last : null,
        ])->saveQuietly();

        TicketEvent::query()->where('ticket_id', $ticket->id)->update(['created_at' => $last]);
    }

    private function attachLog(Ticket $ticket): void
    {
        try {
            $message = TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->firstOrFail();
            app(AttachmentStore::class)->store($ticket, $message, IncomingFile::fromContents('error.log', "[08:02:11] upstream timed out (110: Connection timed out)\n[08:02:15] upstream timed out (110: Connection timed out)\n"));
        } catch (Throwable) {
            // Object storage unavailable (e.g. RustFS not running): seed without the sample file.
        }
    }
}
