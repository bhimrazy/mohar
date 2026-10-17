<?php

use App\Modules\Domains\Enums\DomainStatus;

test('the transition table follows the lifecycle', function (DomainStatus $from, DomainStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    [DomainStatus::Pending, DomainStatus::Active, true],
    [DomainStatus::Pending, DomainStatus::PendingRegistration, true],
    [DomainStatus::PendingTransfer, DomainStatus::Pending, true],
    [DomainStatus::Active, DomainStatus::Grace, true],
    [DomainStatus::Grace, DomainStatus::Redemption, true],
    [DomainStatus::Redemption, DomainStatus::Active, true],
    [DomainStatus::Expired, DomainStatus::Grace, false],
    [DomainStatus::Cancelled, DomainStatus::Active, false],
    [DomainStatus::Active, DomainStatus::Pending, false],
]);

test('only live statuses allow client management and hold the name', function () {
    expect(DomainStatus::Active->allowsClientActions())->toBeTrue()
        ->and(DomainStatus::Expired->allowsClientActions())->toBeFalse()
        ->and(DomainStatus::PendingTransfer->allowsClientActions())->toBeFalse()
        ->and(DomainStatus::PendingTransfer->isTerminal())->toBeFalse()
        ->and(DomainStatus::TransferredAway->isTerminal())->toBeTrue();
});
