<?php
namespace Tests\Fixtures\Review527;
use Illuminate\Contracts\Bus\Dispatcher;
final class BusImporter { public function __construct(private Dispatcher $bus) {} public function upsert(object $job): void { $this->bus->dispatch($job); } }
