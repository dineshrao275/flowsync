<?php

namespace App\Http\Controllers\Hrms\Payroll;

use App\Http\Controllers\Controller;
use App\Services\Hrms\Payroll\PayslipDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll/HRMS — one rendered payslip from its signed link.
 *
 * Intentionally outside the auth/tenant groups, like the document and photo
 * downloads: the signature is the credential, so a fresh browser tab works.
 * Every value it needs therefore arrives inside the signed query string, and
 * the record is resolved inside the tenant connection. `payslip` is an int
 * on purpose — binding it would query the central connection, where
 * `payslips` does not exist.
 */
class PayslipDownloadController extends Controller
{
    public function __construct(private readonly PayslipDownload $downloads) {}

    public function __invoke(Request $request, int $payslip): StreamedResponse
    {
        $actor = $request->query('actor');

        return $this->downloads->stream(
            $payslip,
            (int) $request->query('tenant'),
            $actor === null ? null : (int) $actor,
            $request->ip(),
        );
    }
}
