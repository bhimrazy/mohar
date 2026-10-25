### Driver build failures are remembered per request or job (#PR)

- `App\Support\Integrations\DriverRegistry`: a driver that cannot be built (any failure other than the family's
  `LogicException` checks) is now left out until the current request or queued job ends, instead of being rebuilt on
  every call. New `forgetTransientFailures()`, which `AppServiceProvider` calls when a queued job starts and when the
  application terminates. Additive.
