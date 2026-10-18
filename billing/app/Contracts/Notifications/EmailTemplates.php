<?php

namespace App\Contracts\Notifications;

/**
 * Read-only list of email templates other modules can offer in their forms (e.g. a product's welcome
 * email), so staff pick a template by name instead of typing its key. Core binds an empty default;
 * the Notifications module binds the real list.
 */
interface EmailTemplates
{
    /**
     * Client-facing templates of the given types (all types when empty), ordered by name.
     *
     * @param  list<string>  $types  e.g. ['product', 'general']
     * @return list<array{value: string, label: string}> value = template key
     */
    public function options(array $types = []): array;
}
