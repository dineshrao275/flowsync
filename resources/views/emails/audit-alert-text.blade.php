[CRITICAL SECURITY ALERT] Audit Log Hash Chain Broken

A cryptographic integrity check on the FlowSync unified audit trail detected a broken hash link:
- Break Location: Row #{{ $breakRowId }}
- Failure Reason: {{ $breakReason }}
- Rows Checked: {{ $checkedCount }}

Next Steps:
Run `php artisan audit:verify` on the server for details.
