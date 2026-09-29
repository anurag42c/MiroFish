# Changelog

## 1.1.0
- **Invoice / material-return documents**: upload a picture or PDF, tag it PO invoice or material return, read it with Claude vision (structured JSON output) or offline Tesseract (rule-based reader), review beside the picture, verify.
- **Matching with the weighbridge**: automatic ticket linking (vehicle, challan no., supplier, material, weight, date), split loads, manual link/unlink, goods-vs-service line handling, quantity check with tolerance, unit suggestion.
- **Exceptions** with severities, resolve/waive with notes (kept across re-matching), a dashboard, and the reverse check *weighed but no invoice*.
- **PO checks** (CSV import or on-demand SAP lookup): PO present/known, vendor, items, rate, cumulative over-quantity; **return checks** against the original invoice.
- **Sending** verified documents to **SAP** (HTTP: basic/bearer, CSRF handshake, reference extraction, OData/JSON PO lookup) and/or **Oracle** (`WB_DOCUMENTS`, `WB_DOCUMENT_LINES`); hold policy, retry, and re-send when the data changes.
- Preflight check now reports PHP upload limits, GD/EXIF and Tesseract; health endpoint reports document counters.
- Manual: new chapter 13, Appendix B2 (Oracle tables), G (SAP data contract), H (exception catalogue); acceptance checklist items 21-26.

## 1.0.0
First release.
- Weighing: two-pass and single-pass (stored tare) tickets, stability rules, minimum weight, slips, reports, CSV.
- Indicators: RS-232, RS-485 (USB adapter), TCP gateways, Modbus RTU; ASCII continuous / poll-command parsing; simulator.
- Several scales per PC with a supervisor service; tickets may use different bridges for in and out.
- Setup page with live data and test buttons for scales, gates, camera/ANPR and Oracle.
- Oracle transfer: store-and-forward, idempotent MERGE, cancellations propagated.
- Plate recognition (Plate Recognizer, CodeProject.AI, local command) with vehicle/plate identity checks.
- Gates: boom-barrier control (HTTP / TCP / serial / Modbus), gate-pass register, blocked vehicles, exit rules, weighbridge boom automation.
- Security and operations: roles, audit log, login lockout, CSRF, encrypted secrets, backups, health endpoint, read-only REST API.
