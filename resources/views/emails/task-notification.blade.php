@extends('layouts.email')

@section('content')
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
    <!-- Notification Badge -->
    <tr>
        <td style="padding-bottom: 12px;">
            <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #2563eb; background-color: #eff6ff; border: 1px solid #dbeafe; padding: 4px 12px; border-radius: 9999px;">
                {{ __('Task Notification') }}
            </span>
        </td>
    </tr>

    <!-- Main Headline -->
    <tr>
        <td style="padding-bottom: 24px;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.35; letter-spacing: -0.01em;">
                {{ $line }}
            </h1>
        </td>
    </tr>

    <!-- Task Details Box -->
    <tr>
        <td style="padding-bottom: 28px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px;">
                <!-- Project -->
                <tr>
                    <td colspan="2" style="padding: 6px 0; font-size: 14px; color: #334155;">
                        {{ __('notifications.labels.project') }}: {{ $task['project_name'] ?? __('notifications.fallback.project') }}
                    </td>
                </tr>

                <!-- Task Key & Title -->
                <tr>
                    <td colspan="2" style="padding: 6px 0; font-size: 14px; color: #0f172a; font-weight: 500;">
                        {{ __('notifications.labels.task') }}: {{ $task['key'] ?? __('notifications.fallback.key') }} &mdash; {{ $task['title'] ?? __('notifications.fallback.task') }}
                    </td>
                </tr>

                @if (! empty($task['from_status']) || ! empty($task['to_status']))
                <!-- Status Progression -->
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #64748b; font-weight: 600;">
                        {{ __('notifications.labels.status') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #0f172a;">
                        @if (! empty($task['from_status']))
                            <span style="display: inline-block; background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">{{ $task['from_status'] }}</span>
                            <span style="color: #94a3b8; margin: 0 4px;">&rarr;</span>
                        @endif
                        <span style="display: inline-block; background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">{{ $task['to_status'] ?? '' }}</span>
                    </td>
                </tr>
                @endif

                @if (! empty($task['blocked_by']))
                <!-- Unblocked By -->
                <tr>
                    <td style="padding: 6px 0; font-size: 13px; color: #64748b; font-weight: 600;">
                        {{ __('notifications.labels.unblocked_by') }}:
                    </td>
                    <td style="padding: 6px 0; font-size: 14px; color: #0f172a;">
                        {{ $task['blocked_by']['title'] ?? '' }}
                    </td>
                </tr>
                @endif

                @if (! empty($task['snippet']))
                <!-- Comment Snippet -->
                <tr>
                    <td colspan="2" style="padding-top: 14px;">
                        <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px;">
                            {{ __('notifications.labels.comment') }}:
                        </div>
                        <div style="background: #ffffff; border-left: 3px solid #3b82f6; border-radius: 0 6px 6px 0; padding: 12px 14px; font-size: 14px; color: #334155; line-height: 1.5; font-style: italic;">
                            &ldquo;{{ $task['snippet'] }}&rdquo;
                        </div>
                    </td>
                </tr>
                @endif
            </table>
        </td>
    </tr>

    <!-- Action CTA Button -->
    <tr>
        <td style="padding-bottom: 28px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="btn">
                <tr>
                    <td align="center" style="border-radius: 8px; background-color: #0f172a;">
                        <a href="{!! $url !!}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 28px; font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px; letter-spacing: 0.01em;">
                            {{ __('notifications.labels.open_task') }} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <!-- Fallback Direct Link -->
    <tr>
        <td style="border-top: 1px solid #f1f5f9; padding-top: 18px; font-size: 12px; color: #94a3b8; line-height: 1.5;">
            {{ __('If the button above does not work, copy and paste this link into your browser:') }}<br>
            <a href="{!! $url !!}" style="color: #2563eb; text-decoration: underline; word-break: break-all;">{!! $url !!}</a>
        </td>
    </tr>
</table>
@endsection
