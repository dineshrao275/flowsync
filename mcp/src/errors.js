import { McpError, ErrorCode } from '@modelcontextprotocol/sdk/types.js';

/**
 * Maps FlowSync HTTP error responses to protocol-compliant McpErrors
 * with clear, actionable messages for LLM agents.
 */
export function mapHttpError(status, data = {}, headers = new Headers()) {
    const requestId = headers.get?.('x-request-id') || headers['x-request-id'] || null;
    const reqSuffix = requestId ? ` (Request ID: ${requestId})` : '';

    let message;
    let code = ErrorCode.InvalidRequest;

    switch (status) {
        case 401:
            message = data.code === 'employee_exited'
                ? 'The token owner is no longer an active employee, so this token no longer works. Ask a tenant administrator; creating a new token will not help.' + reqSuffix
                : 'Token invalid or tenant inactive — create a new token.' + reqSuffix;
            break;

        case 403: {
            const moduleDenied = headers.get?.('x-module-denied') || headers['x-module-denied'];
            const moduleReason = headers.get?.('x-module-reason') || headers['x-module-reason'];
            const productDenied = headers.get?.('x-product-denied') || headers['x-product-denied'];
            const onboardingRedirect = headers.get?.('x-onboarding-redirect') || headers['x-onboarding-redirect'];

            if (data.code === 'token_read_only') {
                message = 'This token cannot do that; use a write token with tms.write.' + reqSuffix;
            } else if (data.code === 'token_ability_missing') {
                message = `This token lacks required ability (${data.ability || 'tms.read/tms.write'}).` + reqSuffix;
            } else if (moduleDenied || moduleReason || productDenied) {
                const missing = moduleDenied || moduleReason || productDenied;
                // `X-Module-Reason` says why (plan, expired trial, switched off); show it when it names the module separately.
                const why = moduleDenied && moduleReason && moduleReason !== moduleDenied ? ` (${moduleReason})` : '';
                message = `This tenant plan does not include access to the '${missing}' capability${why}.` + reqSuffix;
            } else if (onboardingRedirect) {
                message = 'Finish tenant onboarding before using the API.' + reqSuffix;
            } else {
                message = (data.message || 'Access forbidden: insufficient permissions.') + reqSuffix;
            }
            break;
        }

        case 404:
            message = 'Not found.' + reqSuffix;
            break;

        case 409:
            message = 'Conflict or duplicate request still in-flight. Retry in a moment.' + reqSuffix;
            break;

        case 422: {
            if (data.errors && typeof data.errors === 'object') {
                const details = Object.entries(data.errors)
                    .map(([field, errs]) => `${field}: ${Array.isArray(errs) ? errs.join(', ') : errs}`)
                    .join('; ');
                message = `Validation failed: ${details}` + reqSuffix;
            } else {
                message = (data.message || 'Validation or workflow rule refusal.') + reqSuffix;
            }
            break;
        }

        case 429: {
            if (data.code === 'usage_limit') {
                message = 'Monthly API call quota exhausted — do not retry; contact a tenant administrator.' + reqSuffix;
            } else {
                const retryAfter = headers.get?.('retry-after') || headers['retry-after'] || '60';
                message = `Rate limit exceeded — retry after ${retryAfter} seconds.` + reqSuffix;
            }
            break;
        }

        case 503:
            if (data.code === 'maintenance') {
                message = 'Platform maintenance in progress — back off and retry later.' + reqSuffix;
            } else {
                message = 'Service temporarily unavailable.' + reqSuffix;
            }
            code = ErrorCode.InternalError;
            break;

        default:
            if (status >= 500) {
                message = `Temporary server error (${status}).` + reqSuffix;
                code = ErrorCode.InternalError;
            } else {
                message = (data.message || `Request failed with status ${status}.`) + reqSuffix;
            }
            break;
    }

    const err = new McpError(code, message);
    err.status = status;
    err.data = data;
    err.requestId = requestId;
    return err;
}
