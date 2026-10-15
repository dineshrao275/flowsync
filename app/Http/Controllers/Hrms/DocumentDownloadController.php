<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Services\Hrms\DocumentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document/HRMS — one file from its signed link.
 *
 * Intentionally outside the auth/tenant groups, like the task attachment and
 * photo downloads: the signature is the credential, so a fresh browser tab
 * works. Every value it needs therefore arrives inside the signed query
 * string, and the record is resolved inside the tenant connection. `document`
 * is an int on purpose — binding it would query the central connection, where
 * `employee_documents` does not exist.
 */
class DocumentDownloadController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function __invoke(Request $request, int $document): StreamedResponse
    {
        $actor = $request->query('actor');

        return $this->documents->download(
            $document,
            (int) $request->query('tenant'),
            $actor === null ? null : (int) $actor,
            $request->ip(),
        );
    }
}
