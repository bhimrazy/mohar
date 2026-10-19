# mohar/simulators

Dev-only provider simulators (ADR 0016). This package is required only in `require-dev` and does nothing unless
`APP_ENV` is `local` or `testing`. Nothing under `app/` may reference it.

## Running one

```sh
php artisan simulate:list                  # installed simulators and the reference each follows
php artisan simulate:start whm             # prints the API URL, the control plane, the CA bundle and the env lines
php artisan simulate:stop <scenario-id>    # --keep leaves the state directory for inspection
php artisan simulate:clean                 # stop every scenario and remove leftovers
```

Each scenario runs as three processes on free 127.0.0.1 ports (never 8000):

- a TLS front with a leaf certificate signed by the scenario's own CA;
- the API backend (`php -S`, four workers);
- the control plane (`php -S`, plain HTTP).

State lives in `sys_get_temp_dir()/mohar-sim/<id>/`.

The control plane answers only requests that carry `X-Sim-Token`:

| Method and path                  | What it does                                                       |
| -------------------------------- | ------------------------------------------------------------------ |
| `GET /__sim/health`              | Liveness                                                           |
| `GET /__sim/log?operation=`      | Redacted request log, oldest first                                 |
| `POST /__sim/faults`             | `{"operation", "type", "params", "nth"}`; types are `Fault::TYPES` |
| `DELETE /__sim/faults`           | Clear the plan                                                     |
| `POST /__sim/rotate-certificate` | New leaf from the same CA (the pin changes)                        |
| `GET /__sim/outbox`              | Pending callbacks                                                  |
| `POST /__sim/actions/{name}`     | A simulator's own actions (`HasControlActions`)                    |

## Adding a provider

1. Implement `Contracts\Simulator` in `src/Providers/<Name>/`:
    - `routes()` lists one `Http\Route` per operation, named after the operation in the provider's reference;
    - `seed()` writes the state a fresh account holds;
    - `appConfiguration()` returns the env lines and notes the app needs.
2. Add the optional contracts it needs:
    - `SignsCallbacks` for webhooks;
    - `HasControlActions` for actions such as raising a dispute;
    - `RecordsLive` for `simulate:record`.
3. List the class in `SimulatorsServiceProvider::simulators()`.
4. Write the responses from the public reference, in our own words and shapes. Never copy SDK or documentation text.
5. Add conformance tests in `tests/` that replay the driver's fixtures as the expected shapes.
6. Keep recordings in `<key>/recordings/`, tagged `source: docs` or `source: live`; `Recorder::differences()` compares
   them with what the simulator serves.

The fixture in `tests/Fixtures/EchoSimulator.php` is the smallest working example.
