@extends('layouts.email')

@section('content')
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <!-- Category Badge -->
    <tr>
        <td style="padding-bottom: 12px;">
            <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #7c3aed; background-color: #f5f3ff; border: 1px solid #ede9fe; padding: 4px 12px; border-radius: 9999px;">
                {{ __('New :source Lead', ['source' => ucfirst($source)]) }}
            </span>
        </td>
    </tr>

    <!-- Title -->
    <tr>
        <td style="padding-bottom: 18px;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.35; letter-spacing: -0.01em;">
                {{ __('New contact inquiry from :name', ['name' => $leadName]) }}
            </h1>
        </td>
    </tr>

    <!-- Lead Info Card -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px;">
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #64748b; font-weight: 600; width: 90px;">
                        {{ __('Name') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #0f172a; font-weight: 600;">
                        {{ $leadName }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #64748b; font-weight: 600;">
                        {{ __('Email') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #0f172a;">
                        <a href="mailto:{{ $leadEmail }}" style="color: #2563eb; text-decoration: underline;">{{ $leadEmail }}</a>
                    </td>
                </tr>
                @if ($company)
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #64748b; font-weight: 600;">
                        {{ __('Company') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #0f172a; font-weight: 500;">
                        {{ $company }}
                    </td>
                </tr>
                @endif
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #64748b; font-weight: 600;">
                        {{ __('Source') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #0f172a;">
                        <span style="display: inline-block; font-size: 12px; font-weight: 600; background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 4px;">
                            {{ $source }}
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Message Quote -->
    <tr>
        <td style="padding-bottom: 24px;">
            <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                {{ __('Message Content') }}
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #7c3aed; border-radius: 4px 8px 8px 4px; padding: 16px 18px; font-size: 14px; color: #334155; line-height: 1.6; white-space: pre-wrap;">{{ $leadMessage }}</div>
        </td>
    </tr>

    <!-- Action Button -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="btn">
                <tr>
                    <td align="center" style="border-radius: 8px; background-color: #0f172a;">
                        <a href="mailto:{{ $leadEmail }}?subject=Re:%20FlowSync%20Inquiry" style="display: inline-block; padding: 12px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px; letter-spacing: 0.01em;">
                            {{ __('Reply Directly to :name', ['name' => $leadName]) }} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Management Note -->
    <tr>
        <td style="border-top: 1px solid #f1f5f9; padding-top: 16px; font-size: 12px; color: #94a3b8; line-height: 1.5;">
            {{ __('Reply to the sender directly, then mark the lead worked in /app → Platform → Leads.') }}
        </td>
    </tr>
</table>
@endsection
