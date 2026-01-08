#!/usr/bin/env bash
set -euo pipefail

# MailToPrint v2.1 Uninstaller
# Run: sudo ./uninstall.sh

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

say "Deinstallation MailToPrint v2.1"
prompt INSTALL_DIR "Installationspfad" "${DEFAULT_INSTALL_DIR}"
prompt URL_PATH "Webpfad" "${DEFAULT_URL_PATH}"

CRON_FILE="/etc/cron.d/mailtoprint"
LOGROTATE_FILE="/etc/logrotate.d/mailtoprint"
APACHE_CONF="/etc/apache2/conf-available/mailtoprint.conf"
SUDOERS_FILE="/etc/sudoers.d/mailtoprint"
WRAP="/usr/local/sbin/mailtoprint-run-once"
AUTH_FILE="/etc/apache2/.htpasswd_mailtoprint"

say "Entferne Cron + Logrotate"
rm -f "${CRON_FILE}" "${LOGROTATE_FILE}"

say "Entferne sudoers + wrapper"
rm -f "${SUDOERS_FILE}" "${WRAP}"

say "Apache config entfernen"
a2disconf mailtoprint >/dev/null 2>&1 || true
rm -f "${APACHE_CONF}"
systemctl reload apache2 || true

say "Installationsverzeichnis entfernen"
if [[ -d "${INSTALL_DIR}" ]]; then
  rm -rf "${INSTALL_DIR}"
else
  echo "Hinweis: ${INSTALL_DIR} existiert nicht."
fi

read -r -p "Soll ${AUTH_FILE} gelöscht werden? (y/N): " ans
ans="${ans:-N}"
if [[ "${ans}" =~ ^[Yy]$ ]]; then
  rm -f "${AUTH_FILE}"
  echo "htpasswd entfernt."
else
  echo "htpasswd behalten."
fi

say "Fertig ✅"
echo "Pakete wurden NICHT entfernt (Apache/PHP/CUPS bleiben installiert)."
echo "Optional purge:"
echo "  sudo apt purge -y apache2 php libapache2-mod-php php-sqlite3 sqlite3 msmtp avahi-daemon avahi-utils"
echo "  sudo apt autoremove -y"
