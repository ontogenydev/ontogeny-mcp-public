# Public release procedure

The public repository is a reviewed export, not a mirror of private Git history. Keep the canonical runtime and public export independently traceable. Record the source baseline and public commit, configuration differences, test results and archive checksum. Use Semantic Versioning and tag reviewed commits. Never push private refs, credentials, signing material, recipient configuration, logs or message contents. Review each export for secrets and run PHP syntax checks, `php tests/test.php` and `php tests/config-test.php`. Do not tag or deploy unverified changes. Publication does not itself deploy the site or establish live interoperability.

Source distribution terms require a separate licensing decision. Until a license is approved, no open-source license is granted.
