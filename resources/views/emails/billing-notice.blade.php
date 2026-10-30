@extends('layouts.email')

@section('content')
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <!-- Category Badge -->
    <tr>
        <td style="padding-bottom: 14px;">
            <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #0284c7; background-color: #f0f9ff; border: 1px solid #e0f2fe; padding: 4px 12px; border-radius: 9999px;">
                {{ __('Billing & Subscription') }}
            </span>
        </td>
    </tr>

    <!-- Greeting -->
    <tr>
        <td style="padding-bottom: 16px;">
            <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #0f172a; line-height: 1.4;">
                {{ $greeting }}
            </h2>
        </td>
    </tr>

    <!-- Message Body -->
    <tr>
        <td style="padding-bottom: 24px; font-size: 15px; color: #334155; line-height: 1.65;">
            @foreach ($lines as $line)
                @if (filter_var(trim($line), FILTER_VALIDATE_URL) || str_starts_with(trim($line), 'http://') || str_starts_with(trim($line), 'https://'))
                    <!-- Rendered as button below -->
                    @php $actionUrl = $actionUrl ?: trim($line); @endphp
                @else
                    <p style="margin: 0 0 14px; color: #334155;">
                        {{ $line }}
                    </p>
                @endif
            @endforeach
        </td>
    </tr>

    @if ($actionUrl)
    <!-- Action Button -->
    <tr>
        <td style="padding-bottom: 28px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="btn">
                <tr>
                    <td align="center" style="border-radius: 8px; background-color: #0f172a;">
                        <a href="{!! $actionUrl !!}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px; letter-spacing: 0.01em;">
                            {{ __('View Account & Subscription') }} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <!-- Direct link fallback -->
    <tr>
        <td style="border-top: 1px solid #f1f5f9; padding-top: 16px; font-size: 12px; color: #94a3b8; line-height: 1.5;">
            {{ __('Or visit:') }} <a href="{!! $actionUrl !!}" style="color: #0284c7; text-decoration: underline; word-break: break-all;">{!! $actionUrl !!}</a>
        </td>
    </tr>
    @endif
</table>
@endsection
