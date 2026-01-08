# MailToPrint v2.1 (Bootstrap + Web UI)

## Install
1. Unzip:
   unzip mailtoprint_v2_1.zip
2. Run installer with sudo from the extracted folder:
   sudo ./install.sh

The installer prints the local IP URL like:
  http://<IP>/mailtoprint/

Login via Basic Auth (created during install), then run the Install Wizard.

## Uninstall
sudo ./uninstall.sh

## Subject controls (in email subject)
- Print mode: `sw-1`, `sw-2`, `bunt-1`, `bunt-2` (default: sw-1)
- Copies: `x3` or `kopien=3` or `copies=3` (min 1, max 50)

## Whitelist
If enabled, non-allowed senders are moved to IMAP_BLOCKED (default INBOX.Blocked).

## Notes
- Cron runs every minute but the worker exits immediately unless:
  installed=1 AND automation_enabled=1 AND printer configured.
