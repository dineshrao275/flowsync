@extends('layouts.email')

@section('content')
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <!-- Category Badge -->
    <tr>
        <td style="padding-bottom: 12px;">
            <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #059669; background-color: #ecfdf5; border: 1px solid #d1fae5; padding: 4px 12px; border-radius: 9999px;">
                {{ __('Scheduled Report') }}
            </span>
        </td>
    </tr>

    <!-- Title -->
    <tr>
        <td style="padding-bottom: 18px;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.35; letter-spacing: -0.01em;">
                {{ __('Your report is ready for download') }}
            </h1>
        </td>
    </tr>

    <!-- Report Info Card -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px;">
                <tr>
                    <td style="padding-bottom: 12px;">
                        <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">
                            {{ __('Report Name') }}
                        </div>
                        <div style="font-size: 18px; font-weight: 700; color: #0f172a;">
                            {{ $reportName }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="border-top: 1px solid #e2e8f0; padding-top: 12px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="padding-right: 16px;">
                                    <span style="font-size: 13px; color: #64748b; font-weight: 500;">{{ __('Rows included:') }}</span>
                                    <strong style="font-size: 14px; color: #0f172a;">{{ number_format($rows) }}</strong>
                                </td>
                                <td>
                                    <span style="display: inline-block; font-size: 12px; font-weight: 600; background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 4px;">
                                        .CSV attached
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    @if ($truncated)
    <!-- Truncation Notice -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px;">
                <tr>
                    <td style="font-size: 13px; color: #92400e; line-height: 1.5;">
                        <strong>{{ __('Notice:') }}</strong> {{ __('It was cut at the first :max rows; narrow the filters to see the rest.', ['max' => \App\Services\Reports\ReportRunner::MAX_ROWS]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif

    <!-- Footer Note -->
    <tr>
        <td style="border-top: 1px solid #f1f5f9; padding-top: 16px; font-size: 13px; color: #64748b; line-height: 1.5;">
            {{ __('Your requested data has been generated and attached to this email as a CSV file. Open the attachment to view the full dataset.') }}
        </td>
    </tr>
</table>
@endsection
