# Concepts

## Search Is A Projection Layer

Search indexes safe projections from other packages. It must not own canonical storage records, files, links, workflow items or documentation pages. The source package owns the original data and decides which fields can be projected.

## Source Provider

`SourceProvider` identifies a package-owned searchable source. It requires:

- provider id;
- owner package;
- projection fields;
- access scope;
- no private payload marker.

If owner, projection or access scope is missing, the provider is invalid.

## Index Document

`IndexDocument` represents a searchable projection. It is indexable only when:

- document id exists;
- source provider is valid;
- projection is explicit;
- tokens are present;
- private payload is not included.

Raw secrets, protected payloads, private file contents and link tokens must not be indexed.

## Engine Profile

`EngineProfile` describes whether a backend can run. The current baseline supports a database profile as a contract, while external and semantic profiles require capability gates.

Unsupported or capability-gated engines degrade safely instead of silently running.

## Query Context

`QueryContext` carries query text, surface, actor reference and access scope. A search result cannot be evaluated safely without explicit access scope.

## Result Exposure Policy

`ResultExposurePolicy` decides whether a document can be returned to the caller:

- `allowed`: title and snippet may be returned;
- `redacted`: safe title can be returned, snippet is hidden;
- `denied`: result is denied;
- `hidden`: denied result existence is hidden.

The default posture is deny/hidden until access scope is matched.

## Reindex Job

`ReindexJob` is a descriptor, not a queue worker. It models planned, queued, running, paused, completed and failed states so future runtime batches can add queue/scheduler behavior safely.
