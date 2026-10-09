/**
 * Secret redaction. Everything the workflow persists or returns (agent output, test output,
 * diffs, reports) goes through here first. Conservative on purpose: a false positive costs a
 * little readability, a false negative leaks a credential into a file under .agents/workflow.
 */
const TOKEN_PATTERNS = [
  /sk-ant-[A-Za-z0-9_-]{16,}/g, // Anthropic
  /\bsk-[A-Za-z0-9]{20,}/g, // OpenAI-style
  /\b(?:sk|pk|rk)_(?:test|live)_[A-Za-z0-9]{12,}/g, // Stripe
  /\bwhsec_[A-Za-z0-9]{12,}/g, // webhook signing secrets
  /\bAIza[0-9A-Za-z_-]{30,}/g, // Google API keys
  /\bgh[pousr]_[A-Za-z0-9]{30,}/g, // GitHub
  /\bAKIA[0-9A-Z]{16}\b/g, // AWS access key id
  /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/g, // JWT
  /base64:[A-Za-z0-9+/]{40,}={0,2}/g, // Laravel APP_KEY
  /\bBearer\s+[A-Za-z0-9._~+/=-]{20,}/gi,
  /-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/g,
];

// NAME=value / NAME: value where NAME smells like a credential; the name stays, the value goes.
const ASSIGNMENT = /\b([A-Za-z0-9_.-]*(?:KEY|SECRET|TOKEN|PASSWORD|PASSWD|CREDENTIAL)[A-Za-z0-9_.-]*)(\s*[=:]\s*)(["']?)([^\s"']{4,})\3/gi;
const URL_CREDENTIALS = /(\b[a-z][a-z0-9+.-]*:\/\/)([^\s/:@]+):([^\s/@]+)@/gi;

export const REDACTED = '[REDACTED]';

export function redact(input) {
  if (typeof input !== 'string' || input === '') return input;
  let out = input;
  for (const re of TOKEN_PATTERNS) out = out.replace(re, REDACTED);
  out = out.replace(URL_CREDENTIALS, `$1$2:${REDACTED}@`);
  out = out.replace(ASSIGNMENT, (_m, name, sep, quote) => {
    return `${name}${sep}${quote}${REDACTED}${quote}`;
  });
  return out;
}

export function containsSecret(input) {
  if (typeof input !== 'string' || input === '') return false;
  return redact(input) !== input;
}

/** Redact every string inside a JSON-like structure (returns a copy). */
export function redactDeep(value) {
  if (typeof value === 'string') return redact(value);
  if (Array.isArray(value)) return value.map(redactDeep);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, redactDeep(v)]));
  }
  return value;
}
