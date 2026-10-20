<?php

namespace App\Modules\Domains\Enums;

/**
 * Stages of a .np registration request (registrars.md §9). A registry rejection returns the request to
 * AwaitingDocuments with rejected_at set.
 */
enum NpStage: string
{
    case AwaitingDocuments = 'awaiting_documents';
    case DocumentsReview = 'documents_review';
    case ReadyToSubmit = 'ready_to_submit';
    case Submitted = 'submitted';
    case Approved = 'approved';
}
