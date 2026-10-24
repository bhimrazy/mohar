<?php

namespace App\Support\Logging;

use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * The event dispatcher of every logger RedactingLogManager creates. Laravel's Logger fires MessageLogged with the
 * message and context it was given, before any processor; this masks both with RedactingProcessor first, so a
 * listener (`Log::listen`, `Event::listen(MessageLogged::class, …)`) sees what the handlers wrote. With no listener
 * nothing is masked twice. Every other call goes to the application's dispatcher.
 *
 * @internal
 */
final class RedactingLogEvents implements Dispatcher
{
    public function __construct(private readonly Dispatcher $events, private readonly RedactingProcessor $processor) {}

    public function dispatch($event, $payload = [], $halt = false)
    {
        if ($event instanceof MessageLogged && $this->events->hasListeners(MessageLogged::class)) {
            $record = ($this->processor)(new LogRecord(new DateTimeImmutable, 'event', Level::fromName((string) $event->level), (string) $event->message, $event->context));
            $event = new MessageLogged($event->level, $record->message, $record->context);
        }

        return $this->events->dispatch($event, $payload, $halt);
    }

    /**
     * @param  \Closure|string|array<int, mixed>  $events
     * @param  \Closure|string|array<int, mixed>|null  $listener
     */
    public function listen($events, $listener = null)
    {
        $this->events->listen($events, $listener);
    }

    public function hasListeners($eventName)
    {
        return $this->events->hasListeners($eventName);
    }

    public function subscribe($subscriber)
    {
        $this->events->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        return $this->events->until($event, $payload);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function push($event, $payload = [])
    {
        $this->events->push($event, $payload);
    }

    public function flush($event)
    {
        $this->events->flush($event);
    }

    public function forget($event)
    {
        $this->events->forget($event);
    }

    public function forgetPushed()
    {
        $this->events->forgetPushed();
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->events->{$method}(...$arguments);
    }
}
