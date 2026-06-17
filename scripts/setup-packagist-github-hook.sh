#!/usr/bin/env bash
# Configure GitHub push webhooks for Packagist auto-update on this repository.
#
# Docs: https://packagist.org/about#how-to-update-packages
#
# Required environment variables:
#   PACKAGIST_USERNAME  — packagist.org username (default: wpdev)
#   PACKAGIST_API_TOKEN — API token from https://packagist.org/profile/
#   GITHUB_TOKEN        — GitHub PAT with "Webhooks" write access on this repo
#
# Optional:
#   GITHUB_REPO         — owner/repo (auto-detected from git remote if omitted)
#
# Usage:
#   export PACKAGIST_USERNAME=wpdev   # optional, this is the default
#   export PACKAGIST_API_TOKEN=xxxxxxxx
#   export GITHUB_TOKEN=ghp_xxxxxxxx
#   ./scripts/setup-packagist-github-hook.sh

set -euo pipefail

PACKAGIST_USERNAME="${PACKAGIST_USERNAME:-wpdev}"
: "${PACKAGIST_API_TOKEN:?Set PACKAGIST_API_TOKEN}"
: "${GITHUB_TOKEN:?Set GITHUB_TOKEN}"

if [[ -z "${GITHUB_REPO:-}" ]]; then
    origin="$(git remote get-url origin)"
    if [[ "$origin" =~ github\.com[:/]([^/]+)/([^/.]+) ]]; then
        GITHUB_REPO="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
    else
        echo "Could not detect GITHUB_REPO from origin: $origin" >&2
        exit 1
    fi
fi

payload_url="https://packagist.org/api/github?username=${PACKAGIST_USERNAME}"
api_base="https://api.github.com/repos/${GITHUB_REPO}/hooks"

echo "Repository: ${GITHUB_REPO}"
echo "Packagist hook URL: ${payload_url}"

existing="$(curl -fsS \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    "${api_base}" | python3 -c "
import json, sys
hooks = json.load(sys.stdin)
for h in hooks:
    if 'packagist.org/api/github' in h.get('config', {}).get('url', ''):
        print(h['id'])
        break
" 2>/dev/null || true)"

if [[ -n "$existing" ]]; then
    echo "Packagist webhook already exists (id=${existing}). Updating..."
    curl -fsS -X PATCH \
        -H "Authorization: Bearer ${GITHUB_TOKEN}" \
        -H "Accept: application/vnd.github+json" \
        "${api_base}/${existing}" \
        -d "$(python3 -c "
import json
print(json.dumps({
    'active': True,
    'events': ['push'],
    'config': {
        'url': '${payload_url}',
        'content_type': 'json',
        'insecure_ssl': '0',
        'secret': '${PACKAGIST_API_TOKEN}',
    },
}))
")" >/dev/null
    echo "Updated webhook ${existing}."
else
    echo "Creating Packagist webhook..."
    curl -fsS -X POST \
        -H "Authorization: Bearer ${GITHUB_TOKEN}" \
        -H "Accept: application/vnd.github+json" \
        "${api_base}" \
        -d "$(python3 -c "
import json
print(json.dumps({
    'name': 'web',
    'active': True,
    'events': ['push'],
    'config': {
        'url': '${payload_url}',
        'content_type': 'json',
        'insecure_ssl': '0',
        'secret': '${PACKAGIST_API_TOKEN}',
    },
}))
")" >/dev/null
    echo "Webhook created."
fi

echo "Done. Push a commit or tag to verify Packagist updates within seconds."