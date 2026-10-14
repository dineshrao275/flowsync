A new {{ $source }} request came in from the marketing site.

Name: {{ $leadName }}
Email: {{ $leadEmail }}
@if($company)Company: {{ $company }}
@endif
Message:
{{ $message }}

Reply to the sender directly, then mark the lead worked in /app → Platform → Leads.
