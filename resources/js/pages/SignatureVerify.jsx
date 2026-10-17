import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import api from '../services/api';
import usePageTitle from '../hooks/usePageTitle';
import Spinner from '../components/ui/Spinner';
import Badge from '../components/ui/Badge';
import { formatDateTime } from '../utils/format';

/** Public: confirm an e-signature receipt code — no sign-in, and nothing beyond what a receipt proves. */
export default function SignatureVerify() {
    const { code } = useParams();
    const { t } = useTranslation();
    usePageTitle(t('hrms.signatures.verifyTitle'));
    const [result, setResult] = useState(null);

    useEffect(() => {
        api.get(`/signatures/verify/${encodeURIComponent(code)}`).then(({ data }) => setResult(data)).catch(() => setResult({ found: false }));
    }, [code]);

    if (!result) return <div className="flex justify-center py-16"><Spinner /></div>;

    return (
        <main className="mx-auto max-w-lg px-4 py-16">
            <h1 className="text-2xl font-bold text-gray-900">{t('hrms.signatures.verifyTitle')}</h1>
            {!result.found ? <p className="mt-4 text-gray-600">{t('hrms.signatures.notFound')}</p> : (
                <dl className="mt-6 space-y-3 text-sm">
                    <div><dt className="text-gray-500">{t('hrms.signatures.document')}</dt><dd className="font-medium text-gray-900">{result.document}</dd></div>
                    <div><dt className="text-gray-500">{t('hrms.signatures.signedBy')}</dt><dd className="font-medium text-gray-900">{result.signer}</dd></div>
                    <div><dt className="text-gray-500">{t('hrms.signatures.signedAt')}</dt><dd className="font-medium text-gray-900">{formatDateTime(result.signed_at)}</dd></div>
                    <div className="flex gap-2">
                        <Badge tone={result.valid ? 'success' : 'danger'}>{result.valid ? t('hrms.signatures.receiptValid') : t('hrms.signatures.receiptInvalid')}</Badge>
                        <Badge tone={result.intact ? 'success' : 'danger'}>{result.intact ? t('hrms.signatures.intact') : t('hrms.signatures.changed')}</Badge>
                    </div>
                    <div><dt className="text-gray-500">SHA-256</dt><dd className="break-all font-mono text-xs text-gray-700">{result.document_sha256}</dd></div>
                </dl>
            )}
        </main>
    );
}
