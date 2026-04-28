"""
API key authentication middleware for MiroFish.

Validates the X-API-Key header on all requests except health-check paths.
When MIROFISH_API_KEY is not set, auth is skipped with a warning (permissive
dev mode so the service still works out-of-the-box without configuration).
"""

import hmac
from flask import Flask, request, jsonify

from ..config import Config
from ..utils.logger import get_logger

logger = get_logger("mirofish.auth")

# Paths that bypass authentication
_EXEMPT_PREFIXES = ("/health",)


def register_api_key_auth(app: Flask) -> None:
    """Register the X-API-Key before_request guard on *app*."""

    @app.before_request
    def _check_api_key():
        # Skip exempt paths (health-check, etc.)
        for prefix in _EXEMPT_PREFIXES:
            if request.path.startswith(prefix):
                return None

        configured_key: str | None = Config.MIROFISH_API_KEY

        # No key configured → permissive dev mode
        if not configured_key:
            logger.warning(
                "MIROFISH_API_KEY is not set — skipping auth (dev/permissive mode). "
                "Set MIROFISH_API_KEY in .env to enable authentication."
            )
            return None

        provided = request.headers.get("X-API-Key", "")

        # Timing-safe comparison to prevent timing attacks
        if not hmac.compare_digest(provided.encode("utf-8"), configured_key.encode("utf-8")):
            logger.warning(
                "Rejected request to %s — invalid or missing X-API-Key", request.path
            )
            return jsonify({"error": "Unauthorized", "message": "Invalid or missing X-API-Key"}), 401

        return None
