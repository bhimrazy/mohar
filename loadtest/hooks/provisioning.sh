#!/usr/bin/env bash
# Provisioning measurement hook, called by loadtest/bin/run after the queue drain:
#
#   LOADTEST_COMPOSE="docker compose -p ... -f ... --env-file ..." loadtest/hooks/provisioning.sh <results dir>
#
# It must write <results dir>/provisioning.json. Until the WHM simulator (ADR 0016, simulator S2) is merged it only
# records that the measurement was skipped. Once S2 lands, this is where to: add the simulator service to the stack
# (a compose overlay next to compose.loadtest.yaml), point a server and the products at it, queue N create commands
# on the provisioning queue (worker-critical-blue) and time how long they take to drain, as bin/run does for e-mail.
set -euo pipefail

results="${1:?usage: provisioning.sh <results dir>}"

if [ -z "${LOADTEST_WHM_SIMULATOR:-}" ]; then
    printf '{"status": "skipped", "reason": "WHM simulator (S2) not merged yet; set LOADTEST_WHM_SIMULATOR once it is"}\n' > "$results/provisioning.json"
    exit 0
fi

printf '{"status": "not implemented", "reason": "LOADTEST_WHM_SIMULATOR is set but the S2 measurement is not written yet"}\n' > "$results/provisioning.json"
exit 1
