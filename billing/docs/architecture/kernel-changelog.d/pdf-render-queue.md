### `Queues::DOCUMENT_EMAILS` (#600)

- `App\Support\Queues` has a new constant `DOCUMENT_EMAILS = 'document-emails'`: the queue for invoice emails that
  render their PDF while composing (#566). The notifications pool reads it last (`notifications,webhooks,document-emails`
  in `Queues::workerCommands()`). Additive: a deploy that runs its own worker commands adds the queue to the
  notifications worker, or those emails stay queued.
