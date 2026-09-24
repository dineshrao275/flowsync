import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { fieldErrors } from '../../services/api';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Alert from '../../components/ui/Alert';
import AuthShell from '../../components/ui/AuthShell';

export default function ForgotPassword() {
    const [email, setEmail] = useState('');
    const [errors, setErrors] = useState({});
    const [sent, setSent] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    async function handleSubmit(e) {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});

        try {
            await api.post('/auth/forgot-password', { email });
            setSent(true);
        } catch (error) {
            setErrors(fieldErrors(error));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthShell
            title="Reset your password"
            subtitle="We'll email you a link to reset your password"
            footer={
                <Link to="/login" className="font-medium text-indigo-600 hover:text-indigo-500">
                    Back to sign in
                </Link>
            }
        >
            {sent ? (
                <div className="space-y-4">
                    <Alert type="success">
                        If an account exists for <strong>{email}</strong>, a password reset link has been
                        sent. Check your inbox (and spam folder).
                    </Alert>
                    <Link to="/login">
                        <Button variant="secondary" className="w-full">
                            Return to sign in
                        </Button>
                    </Link>
                </div>
            ) : (
                <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                    <Alert>{errors.form}</Alert>
                    <Input
                        label="Email address"
                        name="email"
                        type="email"
                        autoComplete="email"
                        required
                        placeholder="you@example.com"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        error={errors.email}
                    />
                    <Button type="submit" className="w-full" loading={submitting}>
                        Send reset link
                    </Button>
                </form>
            )}
        </AuthShell>
    );
}