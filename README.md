# PAM Native Plugin Kit

## Start here

This is a Composer extension for PAM Native. Install the PAM Runtime, create a native project, and then add this package through PAM’s verified Composer toolchain:

```bash
curl --proto '=https' --proto-redir '=https' --tlsv1.2 \
    --connect-timeout 15 --max-time 60 --max-filesize 1048576 -fsSL \
    https://github.com/push-in/pam/releases/latest/download/install.sh | sh

pam init my-app --template native
cd my-app
pam composer require pushinbr/pam-native-plugin-kit
pam doctor --fix
```


The official toolchain for building real PAM Native ecosystem packages. It
validates plugin manifests, compiles one typed IDL into PHP, Kotlin and Swift,
and scaffolds cross-platform packages with CI from the first commit.

```bash
pam composer require pushinbr/pam-native-plugin-kit
pam doctor --fix

vendor/bin/pam-native-plugin new acme/pam-native-biometric ./pam-native-biometric
vendor/bin/pam-native-plugin validate ./pam-native-biometric/pam-native.plugin.json
vendor/bin/pam-native-plugin compile ./pam-native-biometric/pam-native.idl.json ./generated
vendor/bin/pam-native-plugin conformance ./pam-native-biometric --json > conformance.json
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

## Portable contributor certification

`conformance` is a non-mutating, dependency-free package audit that can run
before native SDK jobs. It emits schema `1`, Native `surfaceCode: 2`, sequential
integer result/check codes, and SHA-256 evidence for seven bounded checks:

1. manifest validation;
2. typed IDL compilation;
3. byte-for-byte PHP/Kotlin/Swift generation determinism;
4. Composer plugin metadata;
5. declared Kotlin source evidence;
6. declared Swift source evidence;
7. the portable PHP test entrypoint.

The runner reads at most 1 MiB per document, 256 native source files and 16 MiB
per platform. Package paths are confined segment-by-segment; symlinks,
traversal, duplicate source evidence and empty native source sets fail closed.
The JSON contract is
`resources/pam-native-conformance.schema.json`. This portable report does not
replace Android/iOS compilation, device tests, signing or store validation.


## What installation does

`pam composer require pushinbr/pam-native-plugin-kit` installs the package through the project's normal `composer.json` and `composer.lock`. Run `pam doctor --fix` afterward to validate the environment and regenerate native integration when required.

Use `pam packages` to inspect direct installed Composer dependencies and `pam composer remove pushinbr/pam-native-plugin-kit` to uninstall the capability.

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

This package targets PHP 8.5 and PAM Native `0.8.x`, Android API 26+, and iOS 15+ unless a platform-specific section above states a stricter requirement. Platform SDKs, credentials, entitlements, physical hardware, and store configuration remain application responsibilities.

- [PAM documentation](https://push-in.github.io/pam-docs/introduction/)
- [PAM Native overview](https://push-in.github.io/pam-docs/native/overview/)
- [Plugin and native capability model](https://push-in.github.io/pam-docs/native/plugins/)
- [Report an issue](https://github.com/push-in/pam-native-plugin-kit/issues)

Security vulnerabilities should be reported through the repository security policy or GitHub private vulnerability reporting, not a public issue.
