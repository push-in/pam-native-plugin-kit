# PAM Native Plugin Kit

The official toolchain for building real PAM Native ecosystem packages. It
validates plugin manifests, compiles one typed IDL into PHP, Kotlin and Swift,
and scaffolds cross-platform packages with CI from the first commit.

```bash
pam add plugin-kit
pam doctor

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


## What installation does

`pam add plugin-kit` resolves the official compatible package, performs a non-mutating Composer preflight, updates the normal `composer.json` and `composer.lock`, refreshes generated native integration when required, and leaves the project ready for `pam doctor` validation.

Use `pam packages` to inspect availability and `pam remove plugin-kit` to uninstall the capability safely. Direct Composer commands are an advanced interoperability path; PAM is the supported application workflow.

## API guide

| API | Responsibility |
| --- | --- |
| `Scaffolder` | Create a cross-platform plugin repository with CI. |
| `ManifestValidator` | Validate plugin metadata, native sources, and declared contracts. |
| `IdlCompiler` | Generate deterministic PHP, Kotlin, and Swift types from one IDL. |
| `Diagnostic` / `ValidationResult` | Consume typed validation findings in tooling or CI. |

All coded states, kinds, and variants are sequential integer-backed enums. Use enum cases in application code; do not depend on raw wire numbers.

## Production checklist

- Commit generated sources and verify regeneration is clean in CI.
- Use sequential integer enums beginning at `1` in every wire contract.
- Pin native requirements and record manifest/IDL digests.
- Run `pam doctor`, `pam test`, and a signed release build on every supported platform.
- Exercise denial, cancellation, backgrounding, process restart, and offline behavior before release.

## Troubleshooting

- **Validation reports missing files:** compare paths with the package root in the manifest.
- **Generated languages disagree:** remove handwritten copies and regenerate from the IDL.
- **CI has a dirty diff:** run compile locally and commit deterministic output.
- **Native integration is stale:** run `pam doctor --fix`, rebuild the native host, and inspect the first reported diagnostic.

## Compatibility and support

This package targets PAM Native `0.6.x`, Android API 26+, and iOS 15+ unless a platform-specific section above states a stricter requirement. Platform SDKs, credentials, entitlements, physical hardware, and store configuration remain application responsibilities.

- [PAM documentation](https://push-in.github.io/pam-docs/introduction/)
- [PAM Native overview](https://push-in.github.io/pam-docs/native/overview/)
- [Plugin and native capability model](https://push-in.github.io/pam-docs/native/plugins/)
- [Report an issue](https://github.com/push-in/pam-native-plugin-kit/issues)

Security vulnerabilities should be reported through the repository security policy or GitHub private vulnerability reporting, not a public issue.
