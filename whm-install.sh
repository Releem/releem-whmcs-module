#!/usr/bin/env bash
# Releem Database Advisor - WHM Module Installer
# Install:   bash -c "$(curl -L https://releem.s3.amazonaws.com/v2/whm/whm-install.sh)" --api-key=YOUR_API_KEY
# Uninstall: bash -c "$(curl -L https://releem.s3.amazonaws.com/v2/whm/whm-install.sh)" --uninstall
# Re-running asks before replacing an existing agent. Answering no leaves it in place.

set -euo pipefail

LOGDIR="/var/log/releem"
LOGFILE="${LOGDIR}/whm-install.log"
RELEEM_API_KEY=""
UNINSTALL=0

S3_TARBALL_URL="https://releem.s3.amazonaws.com/v2/whm/releem-whm-plugin.tar.gz"
CGI_DEST="/usr/local/cpanel/whostmgr/docroot/cgi/addon_releem.cgi"
CONF_DEST="/var/cpanel/apps/addon_releem.conf"

# --- Argument parsing ---
# When invoked via bash -c "$(curl ...)" --arg, the first arg becomes $0.
# Merge $0 into the arg list when it looks like a flag.
_args=("$@")
if [[ "$0" == --* ]]; then
    _args=("$0" "$@")
fi

for arg in "${_args[@]}"; do
    case "$arg" in
        --api-key=*)
            RELEEM_API_KEY="${arg#*=}"
            ;;
        --uninstall)
            UNINSTALL=1
            ;;
        --*)
            echo "Unknown argument: $arg" >&2
            echo "Usage: bash whm-install.sh [--api-key=YOUR_API_KEY] [--uninstall]" >&2
            exit 1
            ;;
    esac
done

# --- Logging setup ---
mkdir -p "$LOGDIR"
log() {
    local ts
    ts="$(date '+%Y-%m-%dT%H:%M:%S%z')"
    echo "[$ts] $*" | tee -a "$LOGFILE"
}

# =====================================================
# UNINSTALL MODE
# =====================================================
if [[ "$UNINSTALL" -eq 1 ]]; then
    log "=== Releem WHM Uninstaller started ==="

    # Must be root
    if [[ "$(id -u)" -ne 0 ]]; then
        log "ERROR: This script must be run as root."
        exit 1
    fi

    # 1. Unregister WHM plugin
    if [[ -f "$CONF_DEST" ]]; then
        /usr/local/cpanel/bin/unregister_appconfig "$CONF_DEST" 2>&1 | tee -a "$LOGFILE" || true
        rm -f "$CONF_DEST"
        log "  AppConfig removed: $CONF_DEST"
    else
        log "  AppConfig not found: $CONF_DEST (skipping)"
    fi

    # 2. Remove CGI script
    if [[ -f "$CGI_DEST" ]]; then
        rm -f "$CGI_DEST"
        log "  CGI script removed: $CGI_DEST"
    else
        log "  CGI script not found: $CGI_DEST (skipping)"
    fi

    # 3. Uninstall Releem Agent
    log "  Uninstalling Releem Agent..."
    bash -c "$(curl -L https://releem.s3.amazonaws.com/v2/install.sh)" uninstall 2>&1 | tee -a "$LOGFILE" || true

    log "-------------------------------------------"
    log "=== Releem WHM Uninstaller finished ==="
    exit 0
fi

# =====================================================
# INSTALL MODE
# =====================================================
log "=== Releem WHM Installer started ==="

# =====================================================
# STEP 1: Preconditions
# =====================================================
log "Step 1: Checking preconditions..."

# 1a. Must be root
if [[ "$(id -u)" -ne 0 ]]; then
    log "ERROR: This script must be run as root."
    exit 1
fi
log "  Root check: OK"

# 1b. cPanel must be installed
if [[ ! -d "/usr/local/cpanel" ]]; then
    log "ERROR: /usr/local/cpanel not found. cPanel does not appear to be installed."
    exit 1
fi
log "  cPanel check: OK"

# 1c. Releem API reachability (warn only)
API_HTTP_CODE=$(curl --connect-timeout 5 -sS -o /dev/null -w "%{http_code}" https://api.releem.com/ 2>/dev/null || echo "000")
if [[ "$API_HTTP_CODE" =~ ^[23] ]]; then
    log "  Releem API check: OK (HTTP $API_HTTP_CODE)"
else
    log "  WARNING: Cannot reach Releem API (HTTP $API_HTTP_CODE). Continuing anyway."
fi

# =====================================================
# STEP 2: Agent Installation
# =====================================================
log "Step 2: Releem Agent..."

REINSTALL_AGENT=0
if [[ -x "/opt/releem/releem-agent" ]]; then
    log "  Agent binary found at /opt/releem/releem-agent."
    RELEEM_REINSTALL_ANSWER="n"
    if [[ -r /dev/tty ]]; then
        echo ""
        if ! read -rp "Releem Agent is already installed. Reinstall it? [y/N] " RELEEM_REINSTALL_ANSWER </dev/tty; then
            RELEEM_REINSTALL_ANSWER="n"
        fi
        echo ""
    else
        log "  No interactive terminal. Leaving the existing agent in place."
    fi

    case "$RELEEM_REINSTALL_ANSWER" in
        y|Y|yes|YES|Yes)
            REINSTALL_AGENT=1
            log "  Reinstall confirmed. The agent installer will replace the existing installation."
            ;;
        *)
            log "  Reinstall declined. Leaving the existing agent in place."
            if systemctl is-active releem-agent &>/dev/null; then
                log "  Service releem-agent: already running."
            else
                log "  Service releem-agent: not running. Attempting to start..."
                systemctl start releem-agent 2>&1 | tee -a "$LOGFILE" || true
                if systemctl is-active releem-agent &>/dev/null; then
                    log "  Service releem-agent: started successfully."
                else
                    log "  WARNING: Could not start releem-agent service."
                fi
            fi
            ;;
    esac
fi

if [[ "$REINSTALL_AGENT" -eq 1 || ! -x "/opt/releem/releem-agent" ]]; then
    if [[ "$REINSTALL_AGENT" -eq 1 ]]; then
        log "  Reinstalling Releem Agent..."
    else
        log "  Agent binary not found. Proceeding with installation."
    fi

    # On reinstall the agent installer reads the existing key itself.
    # Get API key if not provided via flag
    if [[ "$REINSTALL_AGENT" -eq 0 && -z "$RELEEM_API_KEY" ]]; then
        # Try reading from existing config
        if [[ -f "/opt/releem/releem.conf" ]]; then
            EXISTING_KEY=$(grep -oP '(?<=apikey=)\S+' /opt/releem/releem.conf 2>/dev/null || true)
            if [[ -n "$EXISTING_KEY" ]]; then
                RELEEM_API_KEY="$EXISTING_KEY"
                log "  API key found in existing config."
            fi
        fi
    fi

    if [[ "$REINSTALL_AGENT" -eq 0 && -z "$RELEEM_API_KEY" ]]; then
        echo ""
        read -rp "Enter your Releem API key: " RELEEM_API_KEY
        echo ""
    fi

    if [[ "$REINSTALL_AGENT" -eq 0 && -z "$RELEEM_API_KEY" ]]; then
        log "ERROR: No API key provided. Cannot install Releem Agent."
        exit 1
    fi

    # Read MySQL root password from /root/.my.cnf if available
    RELEEM_MYSQL_ROOT_PASSWORD=""
    if [[ -f "/root/.my.cnf" ]]; then
        MY_CNF_USER=$(awk -F '=' '/^user\s*=/{gsub(/[ "'\'']+/,"",$2); print $2}' /root/.my.cnf 2>/dev/null | head -1 || true)
        if [[ "$MY_CNF_USER" == "root" ]]; then
            RELEEM_MYSQL_ROOT_PASSWORD=$(awk -F '=' '/^password\s*=/{gsub(/^[ "'\'']+|[ "'\'']+$/,"",$2); print $2}' /root/.my.cnf 2>/dev/null | head -1 || true)
            if [[ -n "$RELEEM_MYSQL_ROOT_PASSWORD" ]]; then
                log "  MySQL root password: read from /root/.my.cnf"
            else
                log "  WARNING: /root/.my.cnf has user=root but could not extract password."
            fi
        else
            log "  WARNING: /root/.my.cnf user is '${MY_CNF_USER:-empty}', not 'root'. Skipping."
        fi
    else
        log "  WARNING: /root/.my.cnf not found. Releem installer may prompt for MySQL password."
    fi

    # Calculate MySQL memory limit as half of total server RAM (in MB)
    TOTAL_RAM_KB=$(awk '/^MemTotal:/{print $2}' /proc/meminfo 2>/dev/null || echo "0")
    RELEEM_MYSQL_MEMORY_LIMIT=$(( TOTAL_RAM_KB / 2 / 1024 ))
    if [[ "$RELEEM_MYSQL_MEMORY_LIMIT" -gt 0 ]]; then
        log "  MySQL memory limit: ${RELEEM_MYSQL_MEMORY_LIMIT} MB (half of $(( TOTAL_RAM_KB / 1024 )) MB total RAM)"
    else
        log "  WARNING: Could not detect server RAM. Setting RELEEM_MYSQL_MEMORY_LIMIT=0."
        RELEEM_MYSQL_MEMORY_LIMIT=0
    fi

    log "  Downloading and executing Releem install script..."
    RELEEM_API_KEY="$RELEEM_API_KEY" RELEEM_MYSQL_MEMORY_LIMIT="$RELEEM_MYSQL_MEMORY_LIMIT" RELEEM_CRON_ENABLE=1 RELEEM_QUERY_OPTIMIZATION=true RELEEM_MYSQL_ROOT_PASSWORD="$RELEEM_MYSQL_ROOT_PASSWORD" bash -c "$(curl -fsSL https://releem.s3.amazonaws.com/v2/install.sh)" 2>&1 | tee -a "$LOGFILE"

    # Verify installation
    if [[ ! -x "/opt/releem/releem-agent" ]]; then
        log "ERROR: releem-agent binary not found after installation."
        exit 1
    fi
    log "  Agent binary verified: /opt/releem/releem-agent"

    if systemctl is-active releem-agent &>/dev/null; then
        log "  Service releem-agent: running."
    else
        log "  WARNING: releem-agent service is not running after install."
    fi
fi

# =====================================================
# STEP 3: Disable cPanel MySQL auto-adjust (best effort)
# =====================================================
log "Step 3: Disabling cPanel MySQL auto-adjust settings (best effort)..."

TWEAK_KEYS=(
    "mycnf_auto_adjust_innodb_buffer_pool_size"
    "mycnf_auto_adjust_maxallowedpacket"
    "mycnf_auto_adjust_openfiles_limit"
)

for key in "${TWEAK_KEYS[@]}"; do
    if whmapi1 set_tweaksetting key="$key" value=0 &>/dev/null; then
        log "  Set $key=0: OK"
    else
        log "  WARNING: Failed to set $key=0. Continuing."
    fi
done

# Verify settings
for key in "${TWEAK_KEYS[@]}"; do
    VAL=$(whmapi1 get_tweaksetting key="$key" 2>/dev/null | grep -oP '(?<=value:\s)\S+' || echo "unknown")
    if [[ "$VAL" == "0" ]]; then
        log "  Verify $key=0: OK"
    else
        log "  WARNING: $key verification returned: $VAL"
    fi
done

# =====================================================
# STEP 4: Install WHM module
# =====================================================
log "Step 4: Installing WHM module..."

# 4a. Download and extract module tarball from S3
TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

log "  Downloading WHM module tarball from S3..."
if ! curl -fsSL "$S3_TARBALL_URL" -o "${TMPDIR}/releem-whm-plugin.tar.gz"; then
    log "ERROR: Failed to download tarball from ${S3_TARBALL_URL}"
    exit 1
fi

tar -xzf "${TMPDIR}/releem-whm-plugin.tar.gz" -C "$TMPDIR"
MODULE_DIR="${TMPDIR}/module"

# 4b. Verify extracted files exist
if [[ ! -f "${MODULE_DIR}/addon_releem.cgi" ]]; then
    log "ERROR: addon_releem.cgi not found in tarball."
    exit 1
fi
if [[ ! -f "${MODULE_DIR}/addon_releem.conf" ]]; then
    log "ERROR: addon_releem.conf not found in tarball."
    exit 1
fi
log "  Tarball extracted OK."

# 4c. Copy CGI script
if cp -f "${MODULE_DIR}/addon_releem.cgi" "$CGI_DEST"; then
    chmod 755 "$CGI_DEST"
    log "  CGI script installed: $CGI_DEST"
else
    log "ERROR: Failed to copy CGI script to $CGI_DEST"
    exit 1
fi

# 4d. Copy AppConfig file
mkdir -p /var/cpanel/apps
if cp -f "${MODULE_DIR}/addon_releem.conf" "$CONF_DEST"; then
    chmod 644 "$CONF_DEST"
    log "  AppConfig file installed: $CONF_DEST"
else
    log "ERROR: Failed to copy AppConfig file to $CONF_DEST"
    exit 1
fi

# 4e. Register with AppConfig
if /usr/local/cpanel/bin/register_appconfig "$CONF_DEST" 2>&1 | tee -a "$LOGFILE"; then
    log "  AppConfig registration: OK"
else
    log "ERROR: AppConfig registration failed."
    exit 1
fi

# =====================================================
# STEP 5: Final summary
# =====================================================
log ""
log "=== Installation Summary ==="
log "-------------------------------------------"

# Agent status
if systemctl is-active releem-agent &>/dev/null; then
    log "  Releem Agent:       Running"
else
    log "  Releem Agent:       Not running"
fi

# Auto-adjust status
DISABLED_COUNT=0
for key in "${TWEAK_KEYS[@]}"; do
    VAL=$(whmapi1 get_tweaksetting key="$key" 2>/dev/null | grep -oP '(?<=value:\s)\S+' || echo "unknown")
    if [[ "$VAL" == "0" ]]; then
        ((DISABLED_COUNT++)) || true
    fi
done

TWEAK_TOTAL=${#TWEAK_KEYS[@]}
if [[ "$DISABLED_COUNT" -eq "$TWEAK_TOTAL" ]]; then
    log "  cPanel auto-adjust: Disabled"
elif [[ "$DISABLED_COUNT" -gt 0 ]]; then
    log "  cPanel auto-adjust: Partially disabled ($DISABLED_COUNT/$TWEAK_TOTAL)"
else
    log "  cPanel auto-adjust: Enabled"
fi

# Module status
if [[ -f "$CGI_DEST" && -f "$CONF_DEST" ]]; then
    log "  WHM module:         Installed"
else
    log "  WHM module:         Incomplete"
fi

log "  Log file:           $LOGFILE"
log "-------------------------------------------"
log "=== Releem WHM Installer finished ==="
