-- Root entity custom CSS state before search-modern/apply-search-modern.php ran.
UPDATE glpi_entities SET enable_custom_css = 1, custom_css_code = '/* ITDEV-LOGO-START */
/*
 * ITSM logo override. Replaces GLPI\'s stock logo everywhere it renders
 * via CSS custom properties (--glpi-logo*) that .glpi-logo already
 * reads from — see css/includes/_base.scss. No core files touched;
 * applied through Entity > Custom CSS (root entity), same mechanism as
 * topbar-modern.css.
 *
 * v3 design (squircle badge, 3-stop gradient, status dot, black-weight
 * wordmark + underline bar). v4: much bigger login hero. v5: topbar
 * sized up to fill the 79px nav bar (--glpi-topbar-height) instead of
 * the old 54px — still can\'t literally match the 147px login hero
 * without overflowing the bar.
 *
 * Wrapped in ITDEV-LOGO-START/END markers so apply-logo.php can
 * replace just this block in place on a re-run, the same pattern
 * watermark/ and rebrand-it-dev/ use — doesn\'t clobber those blocks
 * elsewhere in custom_css_code.
 *
 * Source SVGs: logo/icon.svg, logo/lockup-light-text.svg,
 * logo/lockup-dark-text.svg (see logo/README.md).
 */

:root {
    --glpi-logo-light: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMTAgNTUiIHdpZHRoPSIyMTAiIGhlaWdodD0iNTUiPgogIDxkZWZzPgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzI1NjNlYiIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjAuNTUiIHN0b3AtY29sb3I9IiMwODkxYjIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMGQ5NDg4Ii8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogIDwvZGVmcz4KICA8cmVjdCB4PSIwIiB5PSI3LjUiIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCIgcng9IjEzIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHBhdGggZD0iTTEwLjUgMzEgQTkuNSA5LjUgMCAwIDEgMjkuNSAzMSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjMiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxyZWN0IHg9IjcuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHJlY3QgeD0iMjYuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHBhdGggZD0iTTI5LjcgMzUuOCBRMjcgNDEuNyAyMC41IDQxLjciIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSIyLjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxjaXJjbGUgY3g9IjIwLjUiIGN5PSI0MS43IiByPSIyIiBmaWxsPSIjZmZmZmZmIi8+CiAgPGNpcmNsZSBjeD0iMzMiIGN5PSIxNSIgcj0iNC40IiBmaWxsPSIjMjJjNTVlIiBzdHJva2U9IiNmZmZmZmYiIHN0cm9rZS13aWR0aD0iMiIvPgoKICA8dGV4dCB4PSI1MyIgeT0iMzYiIGZvbnQtZmFtaWx5PSJBcmlhbCBCbGFjaywgQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjYiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiNmZmZmZmYiIGxldHRlci1zcGFjaW5nPSItMC41Ij5JVFNNPC90ZXh0PgogIDxyZWN0IHg9IjUzIiB5PSI0MiIgd2lkdGg9IjQ2IiBoZWlnaHQ9IjMuMiIgcng9IjEuNiIgZmlsbD0idXJsKCNnKSIvPgo8L3N2Zz4K") !important;
    --glpi-logo-dark: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMTAgNTUiIHdpZHRoPSIyMTAiIGhlaWdodD0iNTUiPgogIDxkZWZzPgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzI1NjNlYiIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjAuNTUiIHN0b3AtY29sb3I9IiMwODkxYjIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMGQ5NDg4Ii8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogIDwvZGVmcz4KICA8cmVjdCB4PSIwIiB5PSI3LjUiIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCIgcng9IjEzIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHBhdGggZD0iTTEwLjUgMzEgQTkuNSA5LjUgMCAwIDEgMjkuNSAzMSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjMiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxyZWN0IHg9IjcuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHJlY3QgeD0iMjYuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHBhdGggZD0iTTI5LjcgMzUuOCBRMjcgNDEuNyAyMC41IDQxLjciIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSIyLjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxjaXJjbGUgY3g9IjIwLjUiIGN5PSI0MS43IiByPSIyIiBmaWxsPSIjZmZmZmZmIi8+CiAgPGNpcmNsZSBjeD0iMzMiIGN5PSIxNSIgcj0iNC40IiBmaWxsPSIjMjJjNTVlIiBzdHJva2U9IiNmZmZmZmYiIHN0cm9rZS13aWR0aD0iMiIvPgoKICA8dGV4dCB4PSI1MyIgeT0iMzYiIGZvbnQtZmFtaWx5PSJBcmlhbCBCbGFjaywgQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjYiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiMwZjE3MmEiIGxldHRlci1zcGFjaW5nPSItMC41Ij5JVFNNPC90ZXh0PgogIDxyZWN0IHg9IjUzIiB5PSI0MiIgd2lkdGg9IjQ2IiBoZWlnaHQ9IjMuMiIgcng9IjEuNiIgZmlsbD0idXJsKCNnKSIvPgo8L3N2Zz4K") !important;
    --glpi-logo-light-reduced: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0MCA0MCIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIj4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iZyIgeDE9IjAiIHkxPSIwIiB4Mj0iMSIgeTI9IjEiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAiIHN0b3AtY29sb3I9IiMyNTYzZWIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIwLjU1IiBzdG9wLWNvbG9yPSIjMDg5MWIyIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iIzBkOTQ4OCIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiByeD0iMTMiIGZpbGw9InVybCgjZykiLz4KICA8cGF0aCBkPSJNMTAuNSAyMy41IEE5LjUgOS41IDAgMCAxIDI5LjUgMjMuNSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjMiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxyZWN0IHg9IjcuMyIgeT0iMjEuMyIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHJlY3QgeD0iMjYuMyIgeT0iMjEuMyIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHBhdGggZD0iTTI5LjcgMjguMyBRMjcgMzQuMiAyMC41IDM0LjIiIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSIyLjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxjaXJjbGUgY3g9IjIwLjUiIGN5PSIzNC4yIiByPSIyIiBmaWxsPSIjZmZmZmZmIi8+CiAgPGNpcmNsZSBjeD0iMzMiIGN5PSI3LjUiIHI9IjQuNCIgZmlsbD0iIzIyYzU1ZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjIiLz4KPC9zdmc+Cg==") !important;
    --glpi-logo-dark-reduced: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA0MCA0MCIgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIj4KICA8ZGVmcz4KICAgIDxsaW5lYXJHcmFkaWVudCBpZD0iZyIgeDE9IjAiIHkxPSIwIiB4Mj0iMSIgeTI9IjEiPgogICAgICA8c3RvcCBvZmZzZXQ9IjAiIHN0b3AtY29sb3I9IiMyNTYzZWIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIwLjU1IiBzdG9wLWNvbG9yPSIjMDg5MWIyIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iIzBkOTQ4OCIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjQwIiBoZWlnaHQ9IjQwIiByeD0iMTMiIGZpbGw9InVybCgjZykiLz4KICA8cGF0aCBkPSJNMTAuNSAyMy41IEE5LjUgOS41IDAgMCAxIDI5LjUgMjMuNSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjMiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxyZWN0IHg9IjcuMyIgeT0iMjEuMyIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHJlY3QgeD0iMjYuMyIgeT0iMjEuMyIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHBhdGggZD0iTTI5LjcgMjguMyBRMjcgMzQuMiAyMC41IDM0LjIiIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSIyLjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxjaXJjbGUgY3g9IjIwLjUiIGN5PSIzNC4yIiByPSIyIiBmaWxsPSIjZmZmZmZmIi8+CiAgPGNpcmNsZSBjeD0iMzMiIGN5PSI3LjUiIHI9IjQuNCIgZmlsbD0iIzIyYzU1ZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjIiLz4KPC9zdmc+Cg==") !important;
    --glpi-logo-dark-login: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMTAgNTUiIHdpZHRoPSIyMTAiIGhlaWdodD0iNTUiPgogIDxkZWZzPgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzI1NjNlYiIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjAuNTUiIHN0b3AtY29sb3I9IiMwODkxYjIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMGQ5NDg4Ii8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogIDwvZGVmcz4KICA8cmVjdCB4PSIwIiB5PSI3LjUiIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCIgcng9IjEzIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHBhdGggZD0iTTEwLjUgMzEgQTkuNSA5LjUgMCAwIDEgMjkuNSAzMSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjMiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxyZWN0IHg9IjcuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHJlY3QgeD0iMjYuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHBhdGggZD0iTTI5LjcgMzUuOCBRMjcgNDEuNyAyMC41IDQxLjciIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSIyLjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxjaXJjbGUgY3g9IjIwLjUiIGN5PSI0MS43IiByPSIyIiBmaWxsPSIjZmZmZmZmIi8+CiAgPGNpcmNsZSBjeD0iMzMiIGN5PSIxNSIgcj0iNC40IiBmaWxsPSIjMjJjNTVlIiBzdHJva2U9IiNmZmZmZmYiIHN0cm9rZS13aWR0aD0iMiIvPgoKICA8dGV4dCB4PSI1MyIgeT0iMzYiIGZvbnQtZmFtaWx5PSJBcmlhbCBCbGFjaywgQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjYiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiMwZjE3MmEiIGxldHRlci1zcGFjaW5nPSItMC41Ij5JVFNNPC90ZXh0PgogIDxyZWN0IHg9IjUzIiB5PSI0MiIgd2lkdGg9IjQ2IiBoZWlnaHQ9IjMuMiIgcng9IjEuNiIgZmlsbD0idXJsKCNnKSIvPgo8L3N2Zz4K") !important;
    --glpi-logo-light-login: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMTAgNTUiIHdpZHRoPSIyMTAiIGhlaWdodD0iNTUiPgogIDxkZWZzPgogICAgPGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+CiAgICAgIDxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzI1NjNlYiIvPgogICAgICA8c3RvcCBvZmZzZXQ9IjAuNTUiIHN0b3AtY29sb3I9IiMwODkxYjIiLz4KICAgICAgPHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjMGQ5NDg4Ii8+CiAgICA8L2xpbmVhckdyYWRpZW50PgogIDwvZGVmcz4KICA8cmVjdCB4PSIwIiB5PSI3LjUiIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCIgcng9IjEzIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHBhdGggZD0iTTEwLjUgMzEgQTkuNSA5LjUgMCAwIDEgMjkuNSAzMSIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmZmZmIiBzdHJva2Utd2lkdGg9IjMiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxyZWN0IHg9IjcuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHJlY3QgeD0iMjYuMyIgeT0iMjguOCIgd2lkdGg9IjYuNCIgaGVpZ2h0PSIxMC44IiByeD0iMy4yIiBmaWxsPSIjZmZmZmZmIi8+CiAgPHBhdGggZD0iTTI5LjcgMzUuOCBRMjcgNDEuNyAyMC41IDQxLjciIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSIyLjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgogIDxjaXJjbGUgY3g9IjIwLjUiIGN5PSI0MS43IiByPSIyIiBmaWxsPSIjZmZmZmZmIi8+CiAgPGNpcmNsZSBjeD0iMzMiIGN5PSIxNSIgcj0iNC40IiBmaWxsPSIjMjJjNTVlIiBzdHJva2U9IiNmZmZmZmYiIHN0cm9rZS13aWR0aD0iMiIvPgoKICA8dGV4dCB4PSI1MyIgeT0iMzYiIGZvbnQtZmFtaWx5PSJBcmlhbCBCbGFjaywgQXJpYWwsIHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMjYiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiNmZmZmZmYiIGxldHRlci1zcGFjaW5nPSItMC41Ij5JVFNNPC90ZXh0PgogIDxyZWN0IHg9IjUzIiB5PSI0MiIgd2lkdGg9IjQ2IiBoZWlnaHQ9IjMuMiIgcng9IjEuNiIgZmlsbD0idXJsKCNnKSIvPgo8L3N2Zz4K") !important;
}

.glpi-logo {
    background-size: contain !important;
    background-position: center !important;
}

/* Login page hero: big, confident presence. */
body.welcome-anonymous .glpi-logo {
    width: 560px !important;
    height: 147px !important;
    max-width: 90vw;
    filter: drop-shadow(0 14px 32px rgba(37, 99, 235, 0.28));
}

/* Topbar: as big as the 79px bar (--glpi-topbar-height) allows while
   leaving a little breathing room — can\'t literally match the 147px
   login hero without overflowing/clipping the nav bar. */
.topbar .glpi-logo {
    width: 245px !important;
    height: 64px !important;
}
/* ITDEV-LOGO-END */

/* ITDEV-WATERMARK-START */
/*
 * "IT-DEV" watermark: one large, faint, centered mark fixed in the
 * viewport on every page (not tiled) — signals this is the dev
 * environment, not prod. Applied via the same Entity > Custom CSS
 * mechanism as logo/ and topbar-modern/ — no core files touched.
 */
body::before {
    content: "IT-DEV";
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-20deg);
    font-family: "Arial Black", Arial, sans-serif;
    font-weight: 900;
    font-size: 11vw;
    color: rgba(124, 135, 148, 0.09);
    white-space: nowrap;
    pointer-events: none;
    user-select: none;
    z-index: 2147483647;
}
/* ITDEV-WATERMARK-END */

/* ITDEV-FOOTER-START */
/*
 * Replaces the visible "GLPI Copyright (C) ..." footer link text with
 * "IT-DEV" branding, CSS-only (the <a class="copyright"> element holds
 * nothing but that text, so hiding the real text and generating a
 * replacement via ::after is safe here — see rebrand-it-dev/README.md
 * for the two spots that couldn\'t be done this way).
 */
a.copyright {
    font-size: 0 !important;
    line-height: 0 !important;
}
a.copyright::after {
    content: "IT-DEV Copyright (C) 2015-2026 Teclib\' and contributors";
    font-size: 0.875rem;
    line-height: normal;
}
/* ITDEV-FOOTER-END */

/* ITDEV-DASHBOARD-START */
/*
 * Modernizes GLPI\'s dashboard cards (Central, Assets, Assistance, Mini
 * tickets, IT Helpdesk KPI — every dashboard shares this styling,
 * there\'s no per-dashboard CSS scope in GLPI): rounder corners, a soft
 * shadow with hover lift, bolder big-number typography. Card colors
 * themselves are set per-card via card_options (see
 * recolor-cards.php), not here.
 *
 * Applied via the same Entity > Custom CSS mechanism as logo/ and
 * watermark/ — no core files touched.
 */

.dashboard .card {
    border-radius: 14px !important;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 6px 16px rgba(15, 23, 42, 0.05);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.dashboard .grid-stack-item-content:hover .card {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(15, 23, 42, 0.08), 0 14px 30px rgba(15, 23, 42, 0.09);
}

.dashboard .big-number .formatted-number .number,
.dashboard .big-number .formatted-number .suffix {
    font-weight: 800 !important;
    letter-spacing: -0.02em;
}

.dashboard .main-label {
    font-weight: 600 !important;
    opacity: 0.88;
}
/* ITDEV-DASHBOARD-END */

/* ITDEV-TOPBAR-START */
/*
 * Modern minimal-flat overlay for the top navbar and user/profile menu.
 * Applied via GLPI\'s built-in Entity > "Custom CSS" setting (root entity),
 * so it survives core updates untouched — no template/core files modified.
 *
 * Scope: horizontal top navbar (.topbar) + user menu dropdown (.user-menu).
 * Style: flat surfaces, no heavy shadows, larger icons, rounded pill/soft
 * corners, smooth hover transitions.
 *
 * Written 2026-09-14 but never actually applied until the system-wide
 * page_layout switched from "vertical" (left sidebar) to "horizontal"
 * (this top nav) — see the sibling page_layout config change. Now it\'s
 * the primary navigation for every user, so this styling matters.
 */

/* --- Top navbar: flatten + breathing room --- */
.topbar.navbar {
    box-shadow: none !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.topbar #navbar-menu .nav-item .nav-link {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.9rem 1.35rem !important;
    border-radius: 10px;
    font-weight: 600;
    font-size: 1.05rem;
    transition: background-color 0.15s ease, opacity 0.15s ease;
}

.topbar #navbar-menu .nav-item .nav-link:hover {
    background-color: rgba(255, 255, 255, 0.08);
}

.topbar #navbar-menu .nav-item.active .nav-link {
    background-color: rgba(255, 255, 255, 0.14);
    opacity: 1 !important;
}

/* Bigger icons across the top navbar */
.topbar .nav-item i,
.topbar .nav-link i {
    font-size: 1.75rem;
    margin-right: 0.45em;
}

/* --- User/profile menu trigger --- */
.user-menu .user-menu-dropdown-toggle {
    padding: 0.4rem 0.75rem !important;
    border-radius: 999px;
    transition: background-color 0.15s ease;
}

.user-menu .user-menu-dropdown-toggle:hover {
    background-color: rgba(255, 255, 255, 0.1);
}

.user-menu .user-menu-dropdown-toggle img,
.user-menu .user-menu-dropdown-toggle .avatar {
    width: 2.25rem !important;
    height: 2.25rem !important;
}

/* --- User/profile dropdown menu: flat card, bigger icons --- */
.user-menu .dropdown-menu {
    border: 1px solid rgba(0, 0, 0, 0.08);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    border-radius: 12px;
    padding: 0.5rem;
    min-width: 260px;
}

.user-menu .dropdown-menu .dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.6rem 0.75rem;
    border-radius: 8px;
    font-size: 0.95rem;
}

.user-menu .dropdown-menu .dropdown-item i {
    font-size: 1.25rem;
    width: 1.4em;
    text-align: center;
}

.user-menu .dropdown-menu .dropdown-item:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.user-menu .dropdown-header {
    font-weight: 600;
    padding: 0.5rem 0.75rem;
}

.user-menu .dropdown-divider {
    margin: 0.4rem 0;
}

/* --- Main sector dropdowns (Assets, Assistance, Management, ...) ---
   These are the primary navigation now that page_layout is horizontal
   instead of the vertical sidebar — worth the same flat-card treatment
   as the user menu got above. */
.topbar .navbar-nav .dropdown-menu {
    border: 1px solid rgba(0, 0, 0, 0.08);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.14);
    border-radius: 12px;
    padding: 0.6rem;
    margin-top: 0.4rem !important;
}

.topbar .navbar-nav .dropdown-menu .dropdown-header {
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.6;
    padding: 0.4rem 0.75rem;
}

.topbar .navbar-nav .dropdown-menu .dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.7rem 0.9rem;
    border-radius: 8px;
    font-size: 1.02rem;
    transition: background-color 0.12s ease;
}

.topbar .navbar-nav .dropdown-menu .dropdown-item i {
    font-size: 1.3rem;
    width: 1.3em;
    text-align: center;
    opacity: 0.75;
}

.topbar .navbar-nav .dropdown-menu .dropdown-item:hover,
.topbar .navbar-nav .dropdown-menu .dropdown-item.active {
    background-color: rgba(0, 0, 0, 0.05);
}

/* --- Per-sector accent colors ---
   Added 2026-09-16 ("ทำให้ user อยากใช้งาน" - make it inviting):
   each top-level sector gets its own identity color, matched via its
   actual icon class (verified against src/Html.php::getMenuInfos(),
   not guessed) rather than nth-child position, so it\'s unaffected by
   plugins adding/removing a "Plugins" tab between them.
   Needs :has() and color-mix() (Chrome/Edge 111+, Safari 16.4+,
   Firefox 113+) - falls back to the plain flat styling above on older
   browsers, no breakage. */
.topbar .nav-item:has(> .nav-link .ti-package)      { --sector-color: #2563eb; } /* Assets */
.topbar .nav-item:has(> .nav-link .ti-headset)      { --sector-color: #0d9488; } /* Assistance */
.topbar .nav-item:has(> .nav-link .ti-wallet)       { --sector-color: #7c3aed; } /* Management */
.topbar .nav-item:has(> .nav-link .ti-briefcase)    { --sector-color: #d97706; } /* Tools */
.topbar .nav-item:has(> .nav-link .ti-shield-check) { --sector-color: #dc2626; } /* Administration */
.topbar .nav-item:has(> .nav-link .ti-settings)     { --sector-color: #64748b; } /* Setup */

.topbar .nav-item .nav-link i {
    transition: color 0.15s ease, transform 0.15s ease;
}

.topbar .nav-item:hover .nav-link {
    transform: translateY(-1px);
}

.topbar .nav-item:hover .nav-link i,
.topbar .nav-item.active .nav-link i {
    color: var(--sector-color, currentColor);
}

.topbar .nav-item.active .nav-link {
    box-shadow: inset 0 -3px 0 0 var(--sector-color, transparent);
    background-color: color-mix(in srgb, var(--sector-color, white) 16%, transparent) !important;
}

.topbar .nav-item .dropdown-menu .dropdown-header {
    color: var(--sector-color, inherit);
    opacity: 1 !important;
}

.topbar .nav-item .dropdown-menu .dropdown-item:hover {
    background-color: color-mix(in srgb, var(--sector-color, black) 8%, transparent) !important;
}

.topbar .nav-item .dropdown-menu .dropdown-item i {
    color: var(--sector-color, inherit);
    opacity: 0.85;
}
/* ITDEV-TOPBAR-END */

/* ITDEV-ROWCOLOR-START */
/* Whole-row tinting for the Ticket search list, based on GLPI\'s own
 * badge colors already rendered in the Priority (search option id 3)
 * and Time to Resolve (id 18) columns - verified directly against
 * the rendered HTML rather than guessed:
 *   - Priority badge border-colors come from Setup > General >
 *     "Priority colors" (session glpipriority_1..6); defaults used
 *     here are #ff5555 (Major), #ffadad (Very High), #ffbfbf (High),
 *     #ffcece (Medium), #ffe0e0 (Low), #fff2f2 (Very Low).
 *   - The overdue badge (unsolved ticket past its Time to Resolve
 *     deadline) always renders with border-color #cf9b9b - this is
 *     hardcoded in GLPI\'s Glpi\Search\Provider\SQLProvider, not a
 *     configurable color, so no session variable to track here.
 * Closed tickets get a neutral gray instead of a priority/urgency
 * color - once closed, urgency no longer matters, and gray matches
 * GLPI\'s own status icon color scheme (css/includes/components/
 * itilobject/_status.scss maps both "closed" and "solved" to black/
 * neutral, unlike the colored "new"/"waiting"/etc states). Matched via
 * the status icon\'s own CSS class (.itilstatus.closed on search
 * option id 12), not a style-string guess.
 *
 * Order matters (equal specificity, later rule wins): priority tints
 * first, overdue next (wins over priority), closed last (wins over
 * both - a closed ticket doesn\'t need an urgency color anymore).
 *
 * Made deliberately bold ("โดดเด่น" per the user, 2026-09-16, after
 * the first subtle-tint pass wasn\'t eye-catching enough): a solid
 * 4px left border in the row\'s actual color, stronger background
 * opacity, and bolder text on the row\'s own cells.
 *
 * Needs :has() (Chrome/Edge 105+, Safari 15.4+, Firefox 121+) - if a
 * browser doesn\'t support it, rows just render untinted, falling back
 * to GLPI\'s existing per-cell badges (no breakage, graceful).
 */
.search-results tr:has(td[data-searchopt-content-id="3"] .badge_block[style*="#ff5555"]) {
    background-color: rgba(255, 85, 85, 0.28) !important;
    border-left: 4px solid #ff5555 !important;
    font-weight: 600;
}
.search-results tr:has(td[data-searchopt-content-id="3"] .badge_block[style*="#ffadad"]) {
    background-color: rgba(255, 173, 173, 0.28) !important;
    border-left: 4px solid #ffadad !important;
}
.search-results tr:has(td[data-searchopt-content-id="3"] .badge_block[style*="#ffbfbf"]) {
    background-color: rgba(255, 191, 191, 0.28) !important;
    border-left: 4px solid #ffbfbf !important;
}
.search-results tr:has(td[data-searchopt-content-id="3"] .badge_block[style*="#ffcece"]) {
    background-color: rgba(255, 206, 206, 0.32) !important;
    border-left: 4px solid #ffcece !important;
}
.search-results tr:has(td[data-searchopt-content-id="3"] .badge_block[style*="#ffe0e0"]) {
    background-color: rgba(255, 224, 224, 0.38) !important;
    border-left: 4px solid #ffe0e0 !important;
}
.search-results tr:has(td[data-searchopt-content-id="3"] .badge_block[style*="#fff2f2"]) {
    background-color: rgba(255, 242, 242, 0.45) !important;
    border-left: 4px solid #fff2f2 !important;
}
.search-results tr:has(td[data-searchopt-content-id="18"] .badge_block[style*="cf9b9b"]) {
    background-color: rgba(207, 155, 155, 0.45) !important;
    border-left: 4px solid #cf9b9b !important;
    font-weight: 700;
}
.search-results tr:has(td[data-searchopt-content-id="12"] i.itilstatus.closed) {
    background-color: rgba(128, 128, 128, 0.20) !important;
    border-left: 4px solid #888888 !important;
    font-weight: 400 !important;
    opacity: 0.75;
}
/* ITDEV-ROWCOLOR-END */
' WHERE id = 0;
