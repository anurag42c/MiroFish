# Changelog

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
