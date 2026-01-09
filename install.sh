#!/usr/bin/env bash
set -euo pipefail

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
  read -r -p "${text} [${def}]: " val
  val="${val:-$def}"
  printf -v "${__var}" '%s' "${val}"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -d "${SCRIPT_DIR}/www" ]] || die "Fehlt: ${SCRIPT_DIR}/www. Bitte ZIP vollständig entpacken und aus dem entpackten Ordner starten."
[[ -d "${SCRIPT_DIR}/bin" ]] || die "Fehlt: ${SCRIPT_DIR}/bin. Bitte ZIP vollständig entpacken und aus dem entpackten Ordner starten."

say "Pakete installieren"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y   rsync   apache2 apache2-utils   php libapache2-mod-php php-sqlite3   sqlite3   cups cups-client cups-filters   avahi-daemon avahi-utils   curl ripmime   msmtp ca-certificates   logrotate

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

# Avoid Apache 403: allow www-data to traverse APP_DIR (search/x)
chown root:root "${APP_DIR}" || true
chgrp www-data "${APP_DIR}" || true
chmod 750 "${APP_DIR}" || true

# Allow RUN_USER to traverse APP_DIR (group www-data)
usermod -aG www-data "${RUN_USER}" || true

chmod 755 "${WWW_DIR}" "${BIN_DIR}" || true
chmod 750 "${DATA_DIR}" "${LOG_DIR}" || true

chown -R www-data:www-data "${WWW_DIR}" "${DATA_DIR}"
# Keep binaries owned by root to prevent tampering; executed via sudo -u RUN_USER
chown -R root:root "${BIN_DIR}"

# Logs must be writable by RUN_USER and readable by www-data (log viewer)
touch "${LOG_DIR}/app.log"
chown -R "${RUN_USER}:www-data" "${LOG_DIR}"
chmod 2750 "${LOG_DIR}"
chmod 0640 "${LOG_DIR}/app.log"
chown -R "${RUN_USER}:www-data" "${DATA_DIR}/work"
chmod 2770 "${DATA_DIR}/work"

say "Web + Worker Dateien kopieren"
rsync -a --delete "${SCRIPT_DIR}/www/" "${WWW_DIR}/"
rsync -a --delete "${SCRIPT_DIR}/bin/" "${BIN_DIR}/"

chown -R www-data:www-data "${WWW_DIR}" "${DATA_DIR}"
# Keep binaries owned by root to prevent tampering; executed via sudo -u RUN_USER
chown -R root:root "${BIN_DIR}"

# Logs must be writable by RUN_USER and readable by www-data (log viewer)
touch "${LOG_DIR}/app.log"
chown -R "${RUN_USER}:www-data" "${LOG_DIR}"
chmod 2750 "${LOG_DIR}"
chmod 0640 "${LOG_DIR}/app.log"
chmod 755 "${BIN_DIR}/worker.sh" "${BIN_DIR}/worker.php" || true

say "Basic-Auth Admin anlegen (Pflicht)"
AUTH_FILE="/etc/apache2/.htpasswd_mailtoprint"
read -r -p "Admin Benutzername: " ADMIN_USER
[[ -n "${ADMIN_USER}" ]] || die "Admin Benutzername darf nicht leer sein."
htpasswd -c "${AUTH_FILE}" "${ADMIN_USER}"

say "Apache Alias/Config schreiben"
a2enmod alias >/dev/null 2>&1 || true

APACHE_CONF="/etc/apache2/conf-available/mailtoprint.conf"
cat > "${APACHE_CONF}" <<EOF
Alias ${URL_PATH} ${WWW_DIR}

<Directory ${WWW_DIR}>
  Options -Indexes
  AllowOverride None
  Require all granted
</Directory>

<Location ${URL_PATH}>
  AuthType Basic
  AuthName "MailToPrint Admin"
  AuthUserFile ${AUTH_FILE}
  Require valid-user
</Location>
EOF

a2enconf mailtoprint >/dev/null 2>&1 || true
systemctl reload apache2

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

say "Cron anlegen (minütlich)"
CRON_FILE="/etc/cron.d/mailtoprint"
cat > "${CRON_FILE}" <<EOF
* * * * * ${RUN_USER} ${BIN_DIR}/worker.sh >/dev/null 2>&1
EOF
chmod 644 "${CRON_FILE}"

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
  create 0640 ${RUN_USER} www-data
}
EOF
chmod 644 "${LOGROTATE_FILE}"
logrotate -d "${LOGROTATE_FILE}" >/dev/null 2>&1 || die "Logrotate-Konfiguration fehlerhaft."

say "Weblink(s)"
IPS="$(hostname -I | awk '{$1=$1;print}')"
echo
for ip in ${IPS}; do
  echo "  http://${ip}${URL_PATH}/"
done
echo
say "Fertig ✅"
echo "Beim ersten Aufruf öffnet sich automatisch der Installations-Wizard."
echo "Basic Auth Login: ${ADMIN_USER}"
echo "Logs: ${LOG_DIR}/app.log"
