# Upstream references

- `mbillow/localthings`: behavior reviewed from version `0.17.0`, commit
  `b8d04b4ae329d42001f3e86dac82a148c9710070`.
- `QuiteYellow/SmartThings-Local`: CoAP/DTLS behavior and Samsung OCF root
  certificate reviewed from package `smartthings-local 0.1.1`.

The `smartthings-local 0.1.1` package used to verify the bundled certificate
had SHA-256:

`cbe484f5e4fe335eebbd201ee5f980a5a443eef3150f117e2d2d345e0a2fa7fb`

No upstream runtime is embedded or downloaded by the plugin.

OCF discovery and stateless DTLS probing reviewed on 2026-09-17 against:

- https://github.com/QuiteYellow/SmartThings-Local/blob/main/docs/ocf-pki-laundry.md
- https://github.com/QuiteYellow/SmartThings-Local/blob/main/smartthings_local/protocol/ocf_discovery.py
- https://github.com/QuiteYellow/SmartThings-Local/blob/main/smartthings_local/protocol/dtls_probe.py

The PHP implementation supports IPv4 public discovery and first-flight probing.
It does not implement the model-specific ownership authorization, OTM or OwnerPSK.

Local review on 2026-09-18 of a user-provided SmartThings 1.8.47.24 JADX export
identified the Java provisioning confirmation entry points and the advertised
`x.com.samsung.provisioninginfo` resource type. Version 0.4.15 implements only
read-only inspection of the announced resource and a CA-verified DTLS session
without client identity. No APK, native library, account value or private key
from that export is included in the plugin.
