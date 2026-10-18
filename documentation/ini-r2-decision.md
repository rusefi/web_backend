# Use Cloudflare R2 for INI Storage

Related issue: [rusefi/web_backend#229](https://github.com/rusefi/web_backend/issues/229)

## Bottom Line

### TF is R2/S3/etc

think this as a Google Drive, but billed per gb, per 1M operation:

| Type | Cost |
| --- | --- |
| Storage	| $0.015 / GB-month |
| Write Operations	| $4.50 / million requests |
| Read Operations	| $0.36 / million requests |

the R2 buckets can be public or privated


### What we want to do with it?
* we want to use it as the backing store for historical INI
files. (and the current ones after we finish with all the migration) 

* Keep the existing public `/online/ini/` URLs and use PHP to retrieve the
matching private R2 object. During migration, an R2 miss or failure redirects
to the existing file server URL.

This solves the web host's 256,000-file limit without changing rusEFI Console, nor TunerStudio,
making the bucket public, or adding a database.

## Decision

The storage and request mapping is:

```text
Request:
/ini_test/{whitelabel}/{branch...}/{year}/{month}/{day}/{board}/{hash}.ini

R2 object key:
{whitelabel}/{branch...}/{year}/{month}/{day}/{board}/{hash}.ini

Fallback:
/online/ini/{whitelabel}/{branch...}/{year}/{month}/{day}/{board}/{hash}.ini
```


For the proof of concept, the tested object is:

```text
rusefi/master/2023/11/30/uaefi/2573691820.ini
```

## Why R2

### It Removes the File Count Constraint

Issue #229 is caused by the hosting account's file count limit, not by the
total byte size of the INIs. Object storage is designed for large numbers of
independent objects and does not consume the web server's filesystem inodes.

### It Preserves Existing Client Behavior

The current rusEFI Console derives an HTTP URL from the ECU signature. It
expects raw INI bytes from a successful HTTP response and does not inspect the
response `Content-Type`.

The PHP handler returns:

```http
HTTP/1.1 200 OK
Content-Type: application/octet-stream
Cache-Control: public, max-age=31536000, immutable
```

No Java, Python MCP, or TunerStudio change is required.

### The Bucket Can Remain Private

R2 public development URLs and public custom domains remain disabled. PHP uses
a bucket-scoped credential to sign the R2 request and proxies the object bytes.
Clients never receive:

- An R2 hostname.
- A presigned R2 URL.
- An R2 access key or signature.

The public compatibility URL remains under `rusefi.com`, where request
validation, logging, caching policy, and future authorization can be controlled.

### Existing Tools Support It

R2 exposes an S3-compatible API, which works with tools already suitable for
this migration:

- `rclone` copies and verifies historical files using checksums.
- PHP 8 signs private `GET` and `HEAD` requests with AWS Signature Version 4.
- Cloudflare R2 supports the existing immutable object-per-signature model.

No new database, metadata service, or custom migration protocol is necessary.

### The Existing Path Is Already the Lookup Key

The request contains all information needed to locate an object. The complete
validated path can be used directly as the R2 key.

This avoids maintaining a second source of truth such as:

```text
URL -> database row -> object key
```

It also avoids per-board databases or tables. The object store performs the
key lookup, and the fallback uses the same path under `/online/ini/`.

### It Supports a Low-Risk Migration

R2 can be introduced without a flag:

1. Upload and verify an old INI in R2.
2. Request it through the test PHP handler.
3. Fall back to the current local URL when R2 does not contain an object.
4. Migrate old years incrementally with `rclone`.
5. Delete local files only after count, size, checksum, and application checks
   pass.

## Evidence from the Proof of Concept

The oldest currently indexed exact `uaefi` INI was uploaded to a sample private
bucket and retrieved through PHP.

```text
Object key: rusefi/master/2023/11/30/uaefi/2573691820.ini
Size:       506686 bytes
MD5:        1a929837d74bf71fcddc07dce246dde0
SHA-256:    aded5d083c329f4919cf9ed47ea37c0c77586c0dcc023bcf19dac5ac783a0ebc
```

Observed R2 hit:

```http
HTTP/1.1 200 OK
Content-Type: application/octet-stream
Content-Length: 506686
ETag: "1a929837d74bf71fcddc07dce246dde0"
```

Observed R2 miss for another ini:

```http
HTTP/1.1 302 Found
Location: /online/ini/rusefi/development/2026/07/21/proteus_f7/123456789.ini
Cache-Control: no-store
```

The downloaded R2 response matched the original SHA-256 and byte count.

## Alternatives Considered

### Store INI Contents in SQL

Rejected because exact-key object lookup already solves the problem. SQL blobs
would enlarge database backups, couple file delivery to the database, and
require a path-to-row mapping with no current query requirement.

### Create a Database or Table per Board

Rejected because it adds schema, deployment, backup, and cross-board query
complexity. The number of metadata rows would not be a scaling problem for one
indexed table, but no table is needed for this lookup at all.

### Combine INIs into Archives

Rejected because ZIP or tar files make individual random retrieval and
incremental updates harder. PHP would need an archive index and extraction
logic, replacing a filesystem problem with an application-maintained storage
format.

### Expose a Public R2 URL

Rejected because it creates a second public origin and bypasses the PHP
compatibility and control layer.

### Redirect Clients to Presigned R2 URLs

this will only work for our console, not for TunerStudio

### Move to Another Local File Server

It may provide temporary capacity but is hiding the problem under the carpet?

## Security and Operations

- Keep the R2 bucket private.
- Use a read-only, bucket-scoped credential in PHP.
- Use separate temporary migration and build-publisher credentials.
- Store PHP R2 constants in the existing deployment-only
  `/online/config_rusefi.php` path.
- Never log authorization headers, signatures, or secret keys.
- Validate the relative path before sending any R2 request.
- Permit only `GET` and `HEAD`.
- Return `400` for malformed or traversal paths.
- Revoke temporary migration credentials after use.
- Keep credentials out of Git history.

## Tradeoffs

PHP proxying means the web server still handles response bandwidth and depends
on R2 availability.

R2 pricing and Cloudflare service terms can change. Cost should be monitored,
but this does not change the technical need to move historical objects away
from the inode-limited host.

## Success Criteria

- Historical local files can be deleted after verified migration.
- Existing Console-generated URLs continue to resolve.
- R2 objects are not publicly addressable.
- R2 hits return byte-identical INIs.
- R2 misses safely fall back during migration.
- No database is required for exact-path resolution.