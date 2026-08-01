# PAM Native Plugin Kit

The official toolchain for building real PAM Native ecosystem packages. It
validates plugin manifests, compiles one typed IDL into PHP, Kotlin and Swift,
and scaffolds cross-platform packages with CI from the first commit.

```bash
composer require --dev pushinbr/pam-native-plugin-kit

vendor/bin/pam-native-plugin new acme/pam-native-biometric ./pam-native-biometric
vendor/bin/pam-native-plugin validate ./pam-native-biometric/pam-native.plugin.json
vendor/bin/pam-native-plugin compile ./pam-native-biometric/pam-native.idl.json ./generated
```

## Typed IDL

Coded variants are integer enums with sequential values beginning at `1`.
The compiler rejects gaps and string variants so PHP, Kotlin and Swift cannot
silently disagree about wire values.

```json
{
    "version": 1,
    "namespace": "Acme.Biometric",
    "enums": {
        "AuthenticationState": {
            "Pending": 1,
            "Authenticated": 2,
            "Rejected": 3
        }
    },
    "records": {
        "AuthenticationResult": {
            "state": "AuthenticationState",
            "reason": "string?"
        }
    }
}
```

Generated sources are deterministic and suitable for committing or checking
in CI. The plugin manifest and IDL digests are recorded by PAM Native in
`.pam-native/plugins.lock.json`.
