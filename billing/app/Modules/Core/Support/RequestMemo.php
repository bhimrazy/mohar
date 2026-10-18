<?php

namespace App\Modules\Core\Support;

use Closure;

/**
 * Per-request memo for figures several registries ask for in the same request (a nav badge and its Needs
 * attention item run the same count). Values live on the current request's attributes, so they never leak into
 * the next request, test or queued job.
 */
final class RequestMemo
{
    /**
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    public static function remember(string $key, Closure $resolver): mixed
    {
        $attributes = request()->attributes;
        $slot = 'memo.'.$key;

        if (! $attributes->has($slot)) {
            $attributes->set($slot, $resolver());
        }

        return $attributes->get($slot);
    }
}
