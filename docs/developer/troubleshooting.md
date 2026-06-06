# Troubleshooting

## Search Returns Nothing

For the current package version this is expected: no runtime engine or query endpoint exists yet. Only contracts and tests are implemented.

## Provider Is Invalid

Check that the provider has:

- `providerId`;
- `ownerPackage`;
- projection fields;
- access scope;
- no private payload marker.

## Document Is Not Indexable

Check that the document has:

- non-empty document id;
- valid source provider;
- non-empty projection;
- non-empty tokens;
- `containsPrivatePayload` set to false.

## Engine Is Degraded

External and semantic engines require capability gates. If the capability is not allowed or the engine is unavailable, `canRun()` returns false and `isDegraded()` returns true.

## Result Is Hidden Or Denied

This is the safe default when:

- query context is invalid;
- document is not indexable;
- access scope does not match;
- policy requires denied existence to be hidden.

## Scope Check Fails After Documentation Edits

Documentation files must be listed in `.larena/launch-context.json` for this traceability batch. Do not broaden the launch scope to runtime directories to fix a docs-only failure.
