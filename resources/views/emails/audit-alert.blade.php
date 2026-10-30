@extends('layouts.email')

@section('content')
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <!-- Category Badge -->
    <tr>
        <td style="padding-bottom: 12px;">
            <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #dc2626; background-color: #fef2f2; border: 1px solid #fee2e2; padding: 4px 12px; border-radius: 9999px;">
                {{ __('Critical Security Alert') }}
            </span>
        </td>
    </tr>

    <!-- Title -->
    <tr>
        <td style="padding-bottom: 18px;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #991b1b; line-height: 1.35; letter-spacing: -0.01em;">
                {{ __('Audit Log Hash Chain Broken') }}
            </h1>
        </td>
    </tr>

    <!-- Description -->
    <tr>
        <td style="padding-bottom: 20px; font-size: 14px; color: #334155; line-height: 1.6;">
            {{ __('A cryptographic integrity check on the FlowSync unified audit trail detected a broken hash link. This indicates that an audit log record was modified, dropped, or corrupted in the database.') }}
        </td>
    </tr>

    <!-- Error Details Card -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 18px 20px;">
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #991b1b; font-weight: 600; width: 120px;">
                        {{ __('Break Location') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #7f1d1d; font-weight: 700;">
                        Row #{{ $breakRowId }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #991b1b; font-weight: 600;">
                        {{ __('Failure Reason') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #7f1d1d;">
                        {{ $breakReason }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #991b1b; font-weight: 600;">
                        {{ __('Rows Checked') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #7f1d1d;">
                        {{ number_format($checkedCount) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Remediation Guidance -->
    <tr>
        <td style="padding-bottom: 24px;">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                {{ __('Next Steps') }}
            </div>
            <div style="background: #0f172a; color: #f8fafc; border-radius: 8px; padding: 12px 16px; font-family: monospace; font-size: 13px;">
                php artisan audit:verify
            </div>
        </td>
    </tr>

    <!-- Footer Note -->
    <tr>
        <td style="border-top: 1px solid #f1f5f9; padding-top: 16px; font-size: 12px; color: #94a3b8; line-height: 1.5;">
            {{ __('This security alert was automatically dispatched by the server integrity monitor.') }}
        </td>
    </tr>
</table>
@endsection
