<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject ?? config('app.name') }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; padding: 16px !important; }
            .content-card { padding: 24px 20px !important; }
            .btn { width: 100% !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f1f5f9; table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 40px 16px 48px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px;" class="email-container">
                    <!-- Brand Header -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); padding: 8px 16px; border-radius: 10px; border: 1px solid #334155;">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="vertical-align: middle; padding-right: 8px;">
                                                    <div style="width: 18px; height: 18px; background: #3b82f6; border-radius: 4px; display: inline-block;"></div>
                                                </td>
                                                <td style="vertical-align: middle; color: #ffffff; font-size: 16px; font-weight: 700; letter-spacing: -0.02em;">
                                                    {{ config('app.name', 'FlowSync') }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Main Content Card -->
                    <tr>
                        <td>
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; overflow: hidden;" class="content-card">
                                <tr>
                                    <td style="padding: 36px 32px;">
                                        @yield('content')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding-top: 28px; text-align: center; color: #94a3b8; font-size: 12px; line-height: 1.6;">
                            <p style="margin: 0 0 6px;">
                                {{ __('You are receiving this automated email from :app.', ['app' => config('app.name', 'FlowSync')]) }}
                            </p>
                            <p style="margin: 0; color: #94a3b8;">
                                &copy; {{ date('Y') }} {{ config('app.name', 'FlowSync') }}. {{ __('All rights reserved.') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
