# Changelog

## 0.2.0 — 2026-11-25

- Public correspondence without registration or manually issued credentials.
- Automatically issued ten-minute tickets bound to exact subject, message and claimed reply email.
- Fixed recipient, client-enforced human approval, single-use reservations, source/address/global quotas and global admission cap; fail-closed storage behavior.
- Unverified reply addresses appear only in the message body.
- Legacy credentials and tickets no longer authorize transmission; refresh tool discovery for the new `reply_email` argument.
- Preserve three protocol revisions and all six tools. Production read, negative-send and controlled delivery tests passed; concurrent real-DB audit remains open.

## 0.1.1

Credential administration concurrency correction; GPL-2.0-or-later public release and configuration separation.

## 0.1.0

Initial restricted pilot.
