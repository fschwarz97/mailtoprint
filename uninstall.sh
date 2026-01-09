#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Bitte mit sudo ausführen."
  exit 1
fi

say(){ echo -e "\n==> $*"; }
prompt(){
  local __var="$1"; shift
  local text="$1"; shift
  local def="${1:-}"
  local val=""
  read -r -p "${text} [${def}]: " val
  val="${val:-$def}"
  printf -v "${__var}" '%s' "${val}"
}

DEFAULT_INSTALL_DIR="/srv/hostedbyfsc/mailtoprint"
DEFAULT_URL_PATH="/mailtoprint"

say "Deinstallation MailToPrint v2.2"
prompt INSTALL_DIR "Installationspfad" "${DEFAULT_INSTALL_DIR}"
prompt URL_PATH "Webpfad" "${DEFAULT_URL_PATH}"

rm -f /etc/cron.d/mailtoprint /etc/logrotate.d/mailtoprint
rm -f /etc/sudoers.d/mailtoprint /usr/local/sbin/mailtoprint-run-once

a2disconf mailtoprint >/dev/null 2>&1 || true
rm -f /etc/apache2/conf-available/mailtoprint.conf
systemctl reload apache2 || true

if [[ -d "${INSTALL_DIR}" ]]; then
  rm -rf "${INSTALL_DIR}"
else
  echo "Hinweis: ${INSTALL_DIR} existiert nicht."
fi

AUTH_FILE="/etc/apache2/.htpasswd_mailtoprint"
read -r -p "Soll ${AUTH_FILE} gelöscht werden? (y/N): " ans
ans="${ans:-N}"
if [[ "${ans}" =~ ^[Yy]$ ]]; then
  rm -f "${AUTH_FILE}"
  echo "htpasswd entfernt."
else
  echo "htpasswd behalten."
fi

say "Fertig ✅"
