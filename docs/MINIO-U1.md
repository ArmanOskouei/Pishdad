# U1 — MinIO pinning: audit, resolution, and what comes next

> Status: **resolved by pinning to a content digest**, with a known-unpatched
> CVE recorded. The long-term migration is still open and is the real remainder
> of this task. Evidence below was collected from this machine on 2026-10-01.

`docs/TASKS.md` row `U1` asked for two things: pin the MinIO image to an exact
version, and record the CVE status of whatever we end up on. The first was
blocked; the blocker was partly wrong and partly unfixable. Both facts matter
for the next person who reads the compose file, so they are here.

## 1. Do the recorded blockers still hold?

| Claim in TASKS.md | Verdict | How it was checked |
| --- | --- | --- |
| `github.com/minio/minio` archived `2026-04-25` | **Confirmed** | GitHub API `repos/minio/minio` → `archived: true`, `pushed_at: 2026-04-24T17:54:39Z`, license `AGPL-3.0`. The repo is read-only and finished. |
| `RELEASE.2025-10-15` is the last release ever | **Confirmed** | GitHub API `releases/latest` → `tag_name: RELEASE.2025-10-15T17-29-55Z`, `published_at: 2025-10-16`. There is no later one, because the repo stopped. |
| quay.io returns `401` | **Confirmed, and it is the auth layer** | `GET https://quay.io/v2/auth?...scope=repository:minio/minio:pull` → `401 Unauthorized`. So does the v2 manifest fetch. This is why `docker manifest inspect` reports `no such manifest` for every tag: it never gets a token, so it cannot distinguish "absent" from "unauthorized". The compose file's old comment read as if the tag were known-missing; it was never actually testable. |
| Docker Hub has no `minio/minio` | **Confirmed, stronger than stated** | `GET https://hub.docker.com/v2/repositories/minio/minio/tags` → `404 Not Found`. The repository is *gone*, not frozen or rate-limited. |
| `bitnami/minio` has zero tags | Not re-checked | No longer load-bearing: bitnami never hosted the official server image anyway. |
| We are on `RELEASE.2025-09-07T16-13-09Z` | **Confirmed** | `docker exec cms-minio /usr/bin/minio --version` → `RELEASE.2025-09-07T16-13-09Z (commit-id=07c3a429bfed433e49018cb0f78a52145d4bedeb)`. |

### The finding that changes the decision

The image cached locally as `quay.io/minio/minio:latest` is **12 months old**,
yet it reports `RELEASE.2025-09-07T16-13-09Z`. A 12-month-old pull cannot
contain a release published after it, so this proves something important: the
`latest` tag that was in the compose file **no longer resolves to the newest
release even if quay were reachable.** It resolved once, to a version that is
now itself superseded. That is the actual argument for pinning, independent of
the 401: with a floating tag, the version you run is a function of when someone
last pulled, which is not recorded anywhere in the repo.

A second image on this machine, `minio/minio:RELEASE.2024-10-13T13-34-11Z`, is
where the earlier "Docker Hub has it" impression came from — a pull from before
the repository was removed. It is a historical artifact, not evidence of
current availability.

## 2. Why the pin is a digest, not the recommended tag

TASKS.md and the old compose comment both name
`quay.io/minio/minio:RELEASE.2025-10-15T17-29-55Z` as "the correct pin". It
cannot be used here:

- it is the last upstream release, but no reachable registry will serve it
  (quay `401`, Docker Hub `404`, ghcr has never carried `minio/minio`);
- pinning it anyway would make `docker compose up` fail on every host that
  shares this network's registry access, which is the exact failure mode the
  original row was trying to avoid.

So the compose file pins the immutable content that is **already verified
working here**:

```yaml
image: quay.io/minio/minio@sha256:14cea493d9a34af32f524e538b8346cf79f3321eff8e708c1e2960462bd8936e
```

This is a real lock, not a placeholder:

- it names exact bytes, so the version cannot drift between two hosts or two
  months, which is precisely what `latest` was failing to do;
- the digest is present in the local image store, so `docker compose up`
  resolves it without a network round-trip and the local stack keeps working;
- anyone with quay credentials can still `docker compose pull`, because the
  digest is a valid registry reference.

Verified after the change: `docker compose config` exits 0; `cms-minio` came
back up on the same `RELEASE.2025-09-07T16-13-09Z`, `/minio/health/live` → 200,
and the `cms` bucket still serves.

### Moving to the final upstream release, when a host can reach quay

```bash
docker pull quay.io/minio/minio:RELEASE.2025-10-15T17-29-55Z
docker image inspect quay.io/minio/minio:RELEASE.2025-10-15T17-29-55Z \
  --format '{{json .RepoDigests}}'
# replace the digest in docker-compose.yml with the one it prints
docker exec cms-minio /usr/bin/minio --version   # record the version
```

## 3. CVE status of the pinned release

- Pinned: `RELEASE.2025-09-07T16-13-09Z`.
- Last upstream: `RELEASE.2025-10-15T17-29-55Z`, which carries the fix for a
  privilege-escalation issue via session-policy bypass affecting service
  accounts and STS.
- **The pinned release is one release behind that fix, and the fix can never be
  obtained from upstream.** The repository is archived; there is no
  `2025-10-16` build, and no third party is shipping one.

There is no way to close this gap. The honest options are: accept it, or stop
depending on MinIO. Given it is a dev-local object store, accept it for now and
migrate before any data matters — but treat "we are one release behind, and the
release does not exist" as a permanent property of this dependency, not a
backlog item that will eventually be closed by an upstream release.

`pishdad:doctor` reports the MinIO dependency as `warn` rather than `MISSING`
while `FILESYSTEM_DISK=local`, so this is currently invisible to the CI gate in
`.github/workflows/tests.yml`. If `FILESYSTEM_DISK` is ever flipped to `s3`, the
doctor's storage check becomes load-bearing and this section should be revisited
first.

## 4. `mc` is already final

`quay.io/minio/mc:RELEASE.2025-08-13T08-35-41Z` — verified as the newest release
that will ever exist, because `github.com/minio/mc` is `archived: true` as well
(`pushed_at: 2025-11-20`). The binary confirms
`mc version RELEASE.2025-08-13T08-35-41Z (commit-id=7394ce0d)`. No upgrade is
pending; this dependency is finished, not stalled.

## 5. What should replace it

The requirement is S3-compatible object storage over HTTP, with `mc`-free
bucket bootstrap. Because the vendor is gone, the choice is between a managed
service and a maintained self-hosted server:

- **Managed S3** (AWS S3, or any compatible provider). Removes the CVE question
  and the registry question entirely. It is the right answer for production and
  the reason to stop treating this as a dev-only detail.
- **A maintained self-hosted S3 server**, if a local object store is genuinely
  required. Checked while writing this: `rustfs/rustfs` exists on Docker Hub
  (188 tags), which makes it the most viable self-hosted candidate found. Note
  its newest tag was last pushed `2025-07-01`, so maturity is unproven — treat
  it as a shortlist entry, not a decision. `seaweedfs/seaweedfs`, `garagehq/garage`
  and `opencloud-eu/opencloud` all returned `404` on Docker Hub and were ruled out.

Whichever is chosen, the migration is small: `FILESYSTEM_DISK=s3` plus `AWS_*`
keys already work today, and the app already talks S3 through Laravel's filesystem
layer. The compose service and the `minio-init` bucket bootstrap are the only
MinIO-specific pieces, and neither is load-bearing.
