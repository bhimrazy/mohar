<?php

namespace Mohar\Simulators\Contracts;

use Mohar\Simulators\Http\Route;
use Mohar\Simulators\Kernel\Scenario;

/**
 * One provider's simulator (ADR 0016 §1). Responses follow the provider's public API reference, written in our own words
 * and shapes; conformance tests replay the driver's fixtures as the expected shapes.
 */
interface Simulator
{
    /** Registry key, e.g. `whm`, `stripe`. */
    public function key(): string;

    /**
     * The public API reference the simulator follows.
     *
     * @return array{url: string, version: string}
     */
    public function reference(): array;

    /**
     * @return list<Route>
     */
    public function routes(): array;

    /** Fill the scenario's state with what a fresh provider account holds. */
    public function seed(Scenario $scenario): void;

    /**
     * What the app needs to talk to this simulator: environment lines (INTEGRATION_ENDPOINTS, ...) and notes such as
     * the server row or gateway settings to create.
     *
     * @return array{env: array<string, string>, notes: list<string>}
     */
    public function appConfiguration(Scenario $scenario): array;

    /**
     * Operation names faults can target (Route::$operation values).
     *
     * @return list<string>
     */
    public function operations(): array;
}
