#!/usr/bin/env bash
set -euo pipefail

# MailToPrint v2.1 Installer (Ubuntu) - uses files from this folder.
# Run: sudo ./install.sh

if [[ "${EUID}" -ne 0 ]]; then
  echo "Bitte mit sudo ausführen."
  exit 1
fi

say(){ echo -e "\n==> $*"; }
die(){ echo -e "\nFEHLER: $*" >&2; exit 1; }
prompt(){
  local __var="$1"; shift
  local text="$1"; shift
  local def="${1:-}"
  local val=""
  if [[ -n "${def}" ]]; then
    read -r -p "${text} [${def}]: " val
  else
    read -r -p "${text}: " val
  fi
  val="${val:-$def}"
  printf -v "${__var}" '%s' "${val}"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say "Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y \
  apache2 apache2-utils \
  php libapache2-mod-php php-sqlite3 \
  sqlite3 \
  cups cups-client cups-filters \
  avahi-daemon avahi-utils \
  curl ripmime \
  msmtp ca-certificates \
  logrotate

systemctl enable --now apache2
systemctl enable --now cups avahi-daemon

DEFAULT_INSTALL_DIR="/srv/hostedbyfsc/mailtoprint"
DEFAULT_URL_PATH="/mailtoprint"
DEFAULT_RUN_USER="${SUDO_USER:-root}"

say "Installationsparameter"
prompt INSTALL_DIR "Installationspfad" "${DEFAULT_INSTALL_DIR}"
prompt URL_PATH "Webpfad" "${DEFAULT_URL_PATH}"
prompt RUN_USER "Run-User für Worker/Cron" "${DEFAULT_RUN_USER}"
id "${RUN_USER}" >/dev/null 2>&1 || die "User '${RUN_USER}' existiert nicht."

APP_DIR="${INSTALL_DIR}"
WWW_DIR="${APP_DIR}/www"
DATA_DIR="${APP_DIR}/data"
BIN_DIR="${APP_DIR}/bin"
LOG_DIR="${APP_DIR}/logs"

say "Verzeichnisse anlegen"
mkdir -p "${WWW_DIR}" "${DATA_DIR}" "${BIN_DIR}" "${LOG_DIR}" "${DATA_DIR}/work"
chmod 750 "${APP_DIR}" || true
chmod 750 "${DATA_DIR}" || true
chmod 755 "${WWW_DIR}" "${BIN_DIR}" || true
chmod 750 "${LOG_DIR}" || true
chown -R www-data:www-data "${WWW_DIR}" "${DATA_DIR}"
chown -R "${RUN_USER}:${RUN_USER}" "${BIN_DIR}" "${LOG_DIR}"
chown -R "${RUN_USER}:www-data" "${DATA_DIR}/work"
chmod 2770 "${DATA_DIR}/work"

say "Web + Worker Dateien kopieren"
rsync -a --delete "${SCRIPT_DIR}/www/" "${WWW_DIR}/"
rsync -a --delete "${SCRIPT_DIR}/bin/" "${BIN_DIR}/"

# Ensure permissions
chown -R www-data:www-data "${WWW_DIR}" "${DATA_DIR}"
chown -R "${RUN_USER}:${RUN_USER}" "${BIN_DIR}" "${LOG_DIR}"
chmod 755 "${BIN_DIR}/worker.sh" "${BIN_DIR}/worker.php" || true

# Basic Auth
say "Basic-Auth Admin anlegen (Pflicht)"
AUTH_FILE="/etc/apache2/.htpasswd_mailtoprint"
read -r -p "Admin Benutzername: " ADMIN_USER
[[ -n "${ADMIN_USER}" ]] || die "Admin Benutzername darf nicht leer sein."
htpasswd -c "${AUTH_FILE}" "${ADMIN_USER}"

# Apache conf
say "Apache Alias/Config schreiben"
a2enmod alias >/dev/null 2>&1 || true

APACHE_CONF="/etc/apache2/conf-available/mailtoprint.conf"
cat > "${APACHE_CONF}" <<EOF
Alias ${URL_PATH} ${WWW_DIR}

<Directory ${WWW_DIR}>
  Options -Indexes
  AllowOverride None
  Require all granted

  AuthType Basic
  AuthName "MailToPrint Admin"
  AuthUserFile ${AUTH_FILE}
  Require valid-user
</Directory>
EOF

a2enconf mailtoprint >/dev/null 2>&1 || true
systemctl reload apache2

# Wrapper + sudoers for manual run
say "Wrapper für 'Jetzt prüfen' (sudoers minimal) schreiben"
WRAP="/usr/local/sbin/mailtoprint-run-once"
cat > "${WRAP}" <<EOF
#!/usr/bin/env bash
set -euo pipefail
sudo -u ${RUN_USER} ${BIN_DIR}/worker.sh
EOF
chmod 755 "${WRAP}"

SUDOERS="/etc/sudoers.d/mailtoprint"
cat > "${SUDOERS}" <<EOF
www-data ALL=(root) NOPASSWD: ${WRAP}
EOF
chmod 440 "${SUDOERS}"

# Cron
say "Cron anlegen (minütlich)"
CRON_FILE="/etc/cron.d/mailtoprint"
cat > "${CRON_FILE}" <<EOF
* * * * * ${RUN_USER} ${BIN_DIR}/worker.sh >/dev/null 2>&1
EOF
chmod 644 "${CRON_FILE}"

# Logrotate
say "Logrotate anlegen"
LOGROTATE_FILE="/etc/logrotate.d/mailtoprint"
cat > "${LOGROTATE_FILE}" <<EOF
${LOG_DIR}/app.log {
  daily
  rotate 14
  compress
  delaycompress
  missingok
  notifempty
  copytruncate
  create 0640 ${RUN_USER} adm
}
EOF
chmod 644 "${LOGROTATE_FILE}"
logrotate -d "${LOGROTATE_FILE}" >/dev/null 2>&1 || die "Logrotate-Konfiguration fehlerhaft."

# Print access URLs
say "Weblink(s)"
IPS="$(hostname -I | awk '{$1=$1;print}')"
echo
for ip in ${IPS}; do
  echo "  http://${ip}${URL_PATH}/"
done
echo
say "Fertig ✅"
echo "Beim ersten Aufruf öffnet sich automatisch der Installations-Wizard."
echo "Basic Auth Login: ${ADMIN_USER} (Passwort wurde eben gesetzt)"
echo "Logs: ${LOG_DIR}/app.log"
