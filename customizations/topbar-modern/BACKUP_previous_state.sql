-- Root entity custom CSS state before topbar-modern/apply-topbar.php ran.
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
' WHERE id = 0;
