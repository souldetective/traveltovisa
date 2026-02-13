# VCP QA Scripts

## Decision engine acceptance checks

Run these checks against a running WordPress instance with the plugin active:

```bash
./scripts/vcp-decision-engine-acceptance.sh https://traveltovisa.com
```

Or with environment variable:

```bash
VCP_BASE_URL=https://traveltovisa.com ./scripts/vcp-decision-engine-acceptance.sh
```

The script validates:
- Endpoint returns expected top-level keys (or explicit error payload)
- Transit strict-country rule (`US`) forces transit visa requirement
- Default transit rule requires transit visa when layover is over 24h
- Transit not requested returns `required=false`
