@extends('layouts.email')

@section('content')
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <!-- Category Badge -->
    <tr>
        <td style="padding-bottom: 12px;">
            <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #6366f1; background-color: #eef2ff; border: 1px solid #e0e7ff; padding: 4px 12px; border-radius: 9999px;">
                {{ __('Daily Summary') }}
            </span>
        </td>
    </tr>

    <!-- Title -->
    <tr>
        <td style="padding-bottom: 14px;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.35; letter-spacing: -0.01em;">
                {{ __('notifications.digest.intro') }}
            </h1>
        </td>
    </tr>

    <!-- Items List -->
    <tr>
        <td style="padding-bottom: 24px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                @foreach ($items as $item)
                <tr>
                    <td style="padding-bottom: 12px;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 14px 16px;">
                            <tr>
                                <td style="font-size: 14px; font-weight: 600; color: #0f172a; line-height: 1.45;">
                                    {{ $item['line'] }}
                                </td>
                            </tr>
                            @if (! empty($item['url']))
                            <tr>
                                <td style="padding-top: 8px;">
                                    <a href="{!! $item['url'] !!}" target="_blank" rel="noopener" style="font-size: 12px; font-weight: 600; color: #2563eb; text-decoration: none;">
                                        {{ __('View task') }} &rarr;
                                    </a>
                                </td>
                            </tr>
                            @endif
                        </table>
                    </td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>

    <!-- Footer Note -->
    <tr>
        <td style="border-top: 1px solid #f1f5f9; padding-top: 18px; font-size: 12px; color: #94a3b8; line-height: 1.5;">
            {{ __('You are receiving this summary because your notification preferences are set to digest mode.') }}
        </td>
    </tr>
</table>
@endsection
