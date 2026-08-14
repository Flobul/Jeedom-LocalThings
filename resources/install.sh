#!/bin/bash
set -euo pipefail

PROGRESS_FILE="${1:-/tmp/dependancy_localthings_in_progress}"
RESOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$(dirname "${PROGRESS_FILE}")"
echo 0 > "${PROGRESS_FILE}"

cleanup() {
    rm -f "${PROGRESS_FILE}"
}
trap cleanup EXIT

echo "Installation des dépendances LocalThings"
echo 10 > "${PROGRESS_FILE}"

echo 45 > "${PROGRESS_FILE}"
OPENSSL_BIN=""
for candidate in "$(command -v openssl 2>/dev/null || true)" /usr/bin/openssl /usr/local/bin/openssl; do
    if [ -x "${candidate}" ] && "${candidate}" s_client -help 2>&1 | grep -q -- "-dtls1_2"; then
        OPENSSL_BIN="${candidate}"
        break
    fi
done

if [ -z "${OPENSSL_BIN}" ]; then
    echo "Cette version d’OpenSSL ne prend pas en charge DTLS 1.2."
    exit 1
fi

echo 75 > "${PROGRESS_FILE}"
if ! "${OPENSSL_BIN}" s_client -help 2>&1 | grep -q -- "-cert_chain"; then
    echo "Cette version d’OpenSSL ne sait pas transmettre une chaîne cliente."
    exit 1
fi
if ! "${OPENSSL_BIN}" s_client -help 2>&1 | grep -q -- "-bind"; then
    echo "Cette version d’OpenSSL ne sait pas fixer le port DTLS local."
    exit 1
fi

chmod +x "${RESOURCE_DIR}/discover.php"
echo 100 > "${PROGRESS_FILE}"
echo "Dépendances LocalThings installées."
