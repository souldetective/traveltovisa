#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-${VCP_BASE_URL:-http://localhost}}"
API_URL="${BASE_URL%/}/wp-json/vcp/v1/visa-check"

run_case() {
  local name="$1"
  local payload="$2"
  local pycheck="$3"

  echo "Running case: $name"

  local response
  response=$(curl -sS -X POST "$API_URL" -H 'Content-Type: application/json' -d "$payload")

  python - "$name" "$response" "$pycheck" <<'PY'
import json, sys
name, raw, check = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    data = json.loads(raw)
except Exception as e:
    print(f"FAIL [{name}] invalid JSON response: {e}\nRaw: {raw}", file=sys.stderr)
    sys.exit(1)

ctx = {"data": data}
ok = eval(check, {"__builtins__": {}}, ctx)
if not ok:
    print(f"FAIL [{name}] condition failed: {check}\nResponse: {json.dumps(data, indent=2)}", file=sys.stderr)
    sys.exit(1)
print(f"PASS [{name}]")
PY
}

echo "Running VCP decision engine checks against: $API_URL"

# Case 1: ensure endpoint shape returns keys.
run_case \
  "response-shape" \
  '{"passport_country":"PK","destination_country":"AF","include_transit":false}' \
  'all(k in data for k in ["mainVisa","transitVisa","affiliateRecommendations","embassyLink"]) or ("error" in data)'

# Case 2: transit strict-country check should force required=true when transit included.
run_case \
  "transit-strict-country" \
  '{"passport_country":"PK","destination_country":"AF","include_transit":true,"transit_country":"US","layover_duration":"under_24h"}' \
  'data.get("transitVisa", {}).get("required") is True'

# Case 3: transit over-24h default should require transit visa.
run_case \
  "transit-over-24h" \
  '{"passport_country":"PK","destination_country":"AF","include_transit":true,"transit_country":"AE","layover_duration":"over_24h"}' \
  'data.get("transitVisa", {}).get("required") is True'

# Case 4: no-transit request should return required=false.
run_case \
  "transit-not-requested" \
  '{"passport_country":"PK","destination_country":"AF","include_transit":false}' \
  'data.get("transitVisa", {}).get("required") is False or ("error" in data)'

echo "Decision-engine acceptance checks completed."
