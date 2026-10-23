<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ContactStoreRequest;
use App\Mail\LeadReceivedMail;
use App\Models\MarketingLead;
use App\Support\SiteConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

/**
 * Public contact/demo endpoint (no auth). Throttled + honeypot-guarded; a
 * filled honeypot answers success without storing anything so bots learn
 * nothing. Notifications go to the sales inbox when one is configured.
 */
class ContactController extends Controller
{
    public function store(ContactStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Honeypot: a filled `website` field means a bot — succeed silently.
        if (! empty($data['website'])) {
            return response()->json(['message' => __('Thanks — we will be in touch.')]);
        }

        $lead = MarketingLead::create([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'company' => $data['company'] ?? null,
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'],
            'source' => $data['source'] ?? MarketingLead::SOURCE_CONTACT,
            'plan_interest' => $data['plan_interest'] ?? null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        $site = SiteConfig::all();
        $inbox = $site['sales_email'] ?? $site['contact_email'] ?? null;

        if ($inbox) {
            Mail::to($inbox)->queue(new LeadReceivedMail(
                $lead->name, $lead->email, $lead->company, $lead->message, $lead->source,
            ));
        }

        return response()->json(['message' => __('Thanks — we will be in touch.')]);
    }
}
