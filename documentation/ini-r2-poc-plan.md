# R2 INI Proof of Concept

Related issue: [rusefi/web_backend#229](https://github.com/rusefi/web_backend/issues/229)

## Goal

Prove R2 lookup (no exposed url) and Apache/PHP routing with one uaEFI INI and no change
to the production `/online/ini/` routes.

The test URL will be:

```text
https://rusefi.com/ini_test/rusefi/master/2023/11/30/uaefi/2573691820.ini
```

Resolution behavior:

```text
private R2 success -> PHP returns the INI bytes
private R2 failure -> 302 to the matching /online/ini/{whitelabel}/ URL
```

This is intentionally not the full migration. It proves the smallest useful
part of the proposal in the issue comment.

## Canary File

Use the oldest exact `uaefi` target currently visible in the public index:

```text
Public URL: https://rusefi.com/online/ini/rusefi/master/2023/11/30/uaefi/2573691820.ini
Signature:  rusEFI master.2023.11.30.uaefi.2573691820
Size:       506686 bytes (495K in the Apache index)
SHA-256:    aded5d083c329f4919cf9ed47ea37c0c77586c0dcc023bcf19dac5ac783a0ebc
R2 key:     rusefi/master/2023/11/30/uaefi/2573691820.ini
```

The public indexes show no exact `uaefi` target before November 30, 2023.
`uaefi_pro` is a different target and is excluded. A deleted historical file
cannot be detected from the current index, so this means oldest currently
indexed file.

## Scope

The proof of concept contains only:

- One private R2 bucket.
- One read-only PHP credential.
- One uploaded INI object.
- One PHP download handler.
- One `.htaccess` rewrite under `www/ini_test/`.
- Generic validated INI path lookup in R2.
- A fallback redirect to the matching current production file.
- HTTP and uaEFI/TunerStudio smoke tests.

It does not contain:

- Production `/online/ini/` changes.
- Bulk migration.
- CI publishing changes.
- Local file deletion.
- A generic artifact API.

## R2 Setup

1. Create a private bucket such as `rusefi-ini-test`.
2. Keep its development URL and public custom domains disabled.
3. Create a bucket-scoped read/write credential for the one-time upload.
4. Create a separate bucket-scoped read-only credential for PHP.
5. Upload the canary with this exact key:

```text
rusefi/master/2023/11/30/uaefi/2573691820.ini
```

6. Compare the uploaded object checksum and size with the existing public file.
7. Revoke the upload credential after verification.

## Files to Add

The implementation adds these files to `web_backend`:

```text
www/ini_test/.htaccess
www/ini_test/index.php
tests/test_ini_test.php
```

Add R2 endpoint, bucket, access key, and secret settings to the existing
deployment-only `/online/config_rusefi.php`; never commit real values.

Use the existing configuration chain:

```text
www/ini_test/index.php
-> require __DIR__ . '/../online/config_rusefi.php'
-> deployed /online/config_rusefi.php
```

Add these constants beside the existing database constants in the untracked
production file:

```php
define('R2_ENDPOINT', 'https://ACCOUNT_ID.r2.cloudflarestorage.com');
define('R2_BUCKET', 'rusefi-ini');
define('R2_ACCESS_KEY_ID', '');
define('R2_SECRET_ACCESS_KEY', '');
```

The `web_backend` deployment extracts `www/` without deleting existing files,
so the deployed `/online/config_rusefi.php` remains in place. Back it up before
adding these constants. Do not create a second R2 credentials file.

## Apache Route

Requests below this prefix go to the handler:

```text
/ini_test/{whitelabel}/...
```

`www/ini_test/.htaccess` should:

1. Disable directory listing.
2. Enable `mod_rewrite`.
3. Rewrite `.ini` requests to `index.php` while preserving the relative
   path.
4. Leave `/online/ini/` untouched.

The expected mapping is:

```text
/ini_test/rusefi/master/2023/11/30/uaefi/2573691820.ini
-> /ini_test/index.php?path=rusefi/master/2023/11/30/uaefi/2573691820.ini
```

The deployed Apache layout must be checked before finalizing the relative
rewrite target.

## PHP Handler

`www/ini_test/index.php` should perform only this flow:

1. Accept `GET` and `HEAD`; return `405` for other methods.
2. Read the normalized relative path.
3. Reject malformed or traversal paths.
4. Use the complete validated path as the R2 key, including its whitelabel.
5. Send a signed, server-side request to the private R2 S3 endpoint.
6. Buffer the INI so an incomplete R2 response can still fall back before any
   response bytes are sent, then return it on R2 `200`.
7. Return `Content-Type: application/octet-stream` and `Content-Length` when
   available.
8. Do not expose the R2 endpoint, signed request URL, or credentials.
9. On an R2 miss, timeout, authentication failure, or upstream `5xx`, redirect
   to the corresponding existing production URL.

For example, the canary fallback is:

```text
https://rusefi.com/online/ini/rusefi/master/2023/11/30/uaefi/2573691820.ini
```

Use a temporary redirect while testing:

```http
HTTP/1.1 302 Found
Location: /online/ini/rusefi/master/2023/11/30/uaefi/2573691820.ini
Cache-Control: no-store
```

The different `/ini_test/` and `/ini/` prefixes prevent a redirect loop.

Log only the requested relative path, R2 status class, fallback decision, and
request duration. Never log authorization headers or signed URLs.

## Automated Checks

Add one focused handler test with a replaceable HTTP/R2 call covering:

| Case | Expected result |
|---|---|
| Valid path and R2 `200` | Raw fixture bytes and `200` |
| Valid path and R2 `404` | `302` to matching `/online/ini/` URL |
| Valid path and R2 timeout | Same fallback `302` |
| Another board path | Query its matching R2 key, then use the same fallback |
| Traversal or malformed path | `400`, never sent upstream |
| `POST` | `405` |

Malformed input must be rejected at the HTTP boundary before any R2 request.

## Deployment and Smoke Test

Configure an rclone remote named `r2` with the test bucket's Cloudflare
endpoint and a bucket-scoped write credential. Enter credentials through
`rclone config`; do not put them in command arguments or shell history.

Download and verify the canary:

```bash
curl --fail --location \
  "https://rusefi.com/online/ini/rusefi/master/2023/11/30/uaefi/2573691820.ini" \
  --output "2573691820.ini"
```

Upload it using the complete bucket-relative key. The first path segment is
 rusefi; do not prepend `ini/`:

```bash
R2_BUCKET_NAME="rusefi-ini"

rclone copyto \
  "2573691820.ini" \
  "r2:${R2_BUCKET_NAME}/rusefi/master/2023/11/30/uaefi/2573691820.ini" \
  --checksum \
  --immutable \
  --verbose
```

Then test the deployed handler:

1. Deploy the handler, `.htaccess`, and deployment-only R2 settings.
2. Request the existing production URL and save its checksum.
3. Request the new `ini_test` URL.
4. Confirm status `200`, byte count, and checksum match production.
5. Confirm response headers do not contain an R2 hostname or signed URL.
6. Temporarily use an invalid R2 key or credential.
7. Confirm the test URL returns `302` to the production URL and the redirected
   download still matches.
8. Restore the valid setting.
9. Point a test console build or an equivalent download test at the `ini_test`
   URL.
10. Connect a uaEFI device or replay its signature and confirm the INI loads.

The existing Java downloader accepts a final `2xx` response and follows normal
HTTP redirects, so both success and fallback paths should work without a client
change.

## Rollback

Rollback requires only:

1. Remove or disable the `ini_test` rewrite.
2. Remove `www/ini_test/index.php`.
3. Revoke the test R2 credentials.
4. Delete the test bucket after logs are no longer needed.

Production `/online/ini/` remains unchanged throughout, so no production
INI restoration is required.

## Success Criteria

- The new `ini_test` URL returns the exact 506686 canary bytes from private R2.
- No R2 URL or credential is visible to the client.
- R2 credentials load through the existing `config_rusefi.php` path.
- Simulated R2 failure redirects to the existing local production URL.
- Existing production URLs behave exactly as before.
- The test can be removed without moving or restoring any production file.
