# Native KeliCore profiles

The `KeliCore` exporter serves both authenticated app config (`core=kelicore`) and
URL subscriptions (`X-Keli-Profile: kelicore-v1`, native client user agent). It uses
its own capability family and requires a recognized stable version >= 0.1.425.
It does not pretend to be mihomo or use sing-box version numbers.

## Response contract

Profiles contain `outbounds`, Mihomo-style `rules`, and metadata:

```json
{
  "keli": {
    "schema_version": 1,
    "core": "kelicore",
    "platform": "android",
    "default_outbound": "supported-node",
    "skipped": [
      {"name": "unsupported-node", "protocol": "unknown-protocol", "reason": "Unsupported protocol"}
    ]
  }
}
```

App config retains the standard API `data` wrapper. URL subscriptions return the
profile directly and preserve title, traffic, expiry and update interval headers.
Rules are copied from the same local template precedence as ClashMeta, replacing
`$app_name` with site branding. Other template settings are not imported; the
native engine supports only its existing rule semantics.

`KeliCoreCapabilities` is shared by export and generic capability queries. The
exporter receives authorized nodes before capability filtering so it can explain
every exclusion. Mixed subscriptions return usable nodes plus `skipped` metadata;
all-unsupported subscriptions return diagnostics rather than an exporter failure.
TrustTunnel and SS2022 require native client >= 0.1.426. Earlier clients continue
to receive exclusion reasons rather than incompatible nodes. TrustTunnel exports
H2 or H3 with PKI verification enabled, and includes `tls.certificate_sha256` for
automatic self-signed certificates using the existing node-scoped pin resolver.
An unavailable pin or enabled TrustTunnel ECH excludes the node; neither is
silently converted to an insecure connection. SS2022 supports all three SIP022
ciphers and AES multi-user identities, with base64 key length checks. ChaCha
identity chains, SS plugins and TUIC zero-RTT remain unsupported. Pending HY2 ECH
configuration is excluded, not silently downgraded.

Authentication, site context, token validation, account availability and group
visibility remain unchanged. Native subscriptions omit synthetic website/expiry
nodes, preventing extra fake outbound names in the application. Other client
exporters keep their existing filtering, templates and version rules.

## Rollout and verification

Deploy this panel version before releasing a rebuilt native client. Existing
sing-box endpoints and non-native clients continue to use their old exporters.
The client retries the previous account endpoint only when an older panel
explicitly rejects the new `core` field; authorization failures are not retried.

```powershell
php vendor/phpunit/phpunit/phpunit --filter 'KeliCore|ProtocolCapabilityService|ProtocolManager|ClientController|AppBootstrapContextPlan|ProtocolExportRegression'
php scripts/export-kelicore-acceptance.php
php scripts/export-kelicore-acceptance.php --extended
```

The exporter script uses synthetic nodes and no application bootstrap or database.
Its JSON is checked by native-client parser, download and routing tests. Packaged
Windows, macOS and Android network validation remains a separate acceptance step.
