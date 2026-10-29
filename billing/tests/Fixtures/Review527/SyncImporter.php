<?php
namespace Tests\Fixtures\Review527;
final class SyncImporter { public function upsert(object $job): void { dispatch_sync($job); } }
