#!/usr/bin/env sh
set -eu

REPO="abdelhamiderrahmouni/cnkill"
INSTALL_MODE="local"
INSTALL_DIR=""

usage() {
    cat <<'EOF'
Install cnkill from the latest GitHub release.

Usage:
  install.sh [--system] [--dir PATH]

Options:
  --system     Install to /usr/local/bin (uses sudo if needed)
  --dir PATH   Install to a custom directory
  -h, --help   Show this help message
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --system)
            INSTALL_MODE="system"
            ;;
        --dir)
            shift
            if [ "$#" -eq 0 ]; then
                echo "Error: --dir requires a path." >&2
                exit 1
            fi
            INSTALL_DIR="$1"
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Error: unknown option '$1'" >&2
            usage >&2
            exit 1
            ;;
    esac
    shift
done

if [ -z "$INSTALL_DIR" ]; then
    if [ "$INSTALL_MODE" = "system" ]; then
        INSTALL_DIR="/usr/local/bin"
    else
        INSTALL_DIR="$HOME/.local/bin"
    fi
fi

if [ -z "${HOME:-}" ]; then
    echo "Error: HOME is not set." >&2
    exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "Error: curl is required for installation." >&2
    exit 1
fi

UNAME_S=$(uname -s)
UNAME_M=$(uname -m)

case "$UNAME_S" in
    Linux)
        OS="linux"
        ;;
    Darwin)
        OS="macos"
        ;;
    *)
        echo "Error: unsupported OS '$UNAME_S'. Supported: Linux, macOS." >&2
        exit 1
        ;;
esac

case "$UNAME_M" in
    x86_64|amd64)
        ARCH="x86_64"
        ;;
    aarch64|arm64)
        ARCH="aarch64"
        ;;
    *)
        echo "Error: unsupported architecture '$UNAME_M'. Supported: x86_64, aarch64." >&2
        exit 1
        ;;
esac

ASSET_NAME="cnkill-${OS}-${ARCH}"
LATEST_RELEASE_API="https://api.github.com/repos/${REPO}/releases/latest"

TAG_NAME=$(
    curl -fsSL "$LATEST_RELEASE_API" \
    | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
    | sed -n '1p'
)

if [ -z "$TAG_NAME" ]; then
    echo "Error: failed to determine latest release tag." >&2
    exit 1
fi

DOWNLOAD_URL="https://github.com/${REPO}/releases/download/${TAG_NAME}/${ASSET_NAME}"

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT INT TERM

echo "Downloading ${ASSET_NAME} (${TAG_NAME})..."
if ! curl -fsSL "$DOWNLOAD_URL" -o "$TMP_DIR/cnkill"; then
    echo "Error: failed to download ${ASSET_NAME} from release ${TAG_NAME}." >&2
    exit 1
fi

mkdir -p "$INSTALL_DIR"

if [ "$INSTALL_MODE" = "system" ]; then
    if [ "$(id -u)" -eq 0 ]; then
        install -m 0755 "$TMP_DIR/cnkill" "$INSTALL_DIR/cnkill"
    elif command -v sudo >/dev/null 2>&1; then
        sudo install -m 0755 "$TMP_DIR/cnkill" "$INSTALL_DIR/cnkill"
    else
        echo "Error: system install requires root or sudo." >&2
        exit 1
    fi
else
    install -m 0755 "$TMP_DIR/cnkill" "$INSTALL_DIR/cnkill"
fi

echo "cnkill installed at: $INSTALL_DIR/cnkill"

if [ "$INSTALL_MODE" = "local" ]; then
    case ":$PATH:" in
        *":$INSTALL_DIR:"*)
            echo "You're all set. Run: cnkill"
            ;;
        *)
            RC_FILE="$HOME/.profile"
            case "${SHELL:-}" in
                */zsh) RC_FILE="$HOME/.zshrc" ;;
                */bash) RC_FILE="$HOME/.bashrc" ;;
            esac

            echo ""
            echo "Add this line to $RC_FILE so cnkill is available everywhere:"
            echo "  export PATH=\"$INSTALL_DIR:\$PATH\""
            echo "Then restart your shell or run:"
            echo "  export PATH=\"$INSTALL_DIR:\$PATH\""
            ;;
    esac
else
    case ":$PATH:" in
        *":$INSTALL_DIR:"*)
            echo "You're all set. Run: cnkill"
            ;;
        *)
            echo "Note: '$INSTALL_DIR' is not currently in PATH for this shell session."
            echo "Open a new terminal, or run cnkill via: $INSTALL_DIR/cnkill"
            ;;
    esac
fi
