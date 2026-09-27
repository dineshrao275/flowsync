import { useState } from 'react';
import api, { fieldErrors } from '../../services/api';
import Alert from '../ui/Alert';
import Button from '../ui/Button';
import Input from '../ui/Input';
import Select from '../ui/Select';

/**
 * One document upload: title, type, file, and the sensitivity flag.
 *
 * The employee arrives as a prop, not a lookup, because every caller already
 * knows whose file this is — the store passes its picker value, “My files”
 * passes the caller’s own record, and the profile tab passes the profile.
 * An `employees` list turns the fixed id into a picker for the callers whose
 * readers may file into anyone’s record; without it there is no select, so a
 * self-service caller can never file into someone else’s record by editing a
 * dropdown that should not have been there.
 */
export default function DocumentUploader({ employeeId, employees, types, onUploaded }) {
    const [title, setTitle] = useState('');
    const [typeId, setTypeId] = useState('');
    const [pickedEmployee, setPickedEmployee] = useState(employeeId ? String(employeeId) : '');
    const [file, setFile] = useState(null);
    const [confidential, setConfidential] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [errors, setErrors] = useState({});

    async function upload(e) {
        e.preventDefault();
        if (!file) return;

        setUploading(true);
        setErrors({});

        const formData = new FormData();
        formData.append('employee_id', employees ? pickedEmployee : employeeId);
        formData.append('document_type_id', typeId);
        formData.append('title', title);
        formData.append('file', file);
        if (confidential) formData.append('confidential', 'true');

        try {
            const { data } = await api.post('/hrms/documents', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            setTitle('');
            setTypeId('');
            setFile(null);
            setConfidential(false);
            onUploaded?.(data.document);
        } catch (err) {
            setErrors(fieldErrors(err));
        } finally {
            setUploading(false);
        }
    }

    const ready = file && title.trim() !== '' && typeId !== '' && (employees ? pickedEmployee !== '' : employeeId);

    return (
        <form onSubmit={upload} className="space-y-3">
            {employees && (
                <Select label="Employee" value={pickedEmployee} onChange={(e) => setPickedEmployee(e.target.value)}>
                    <option value="">Select an employee</option>
                    {employees.map((employee) => (
                        <option key={employee.id} value={employee.id}>
                            {employee.name}
                        </option>
                    ))}
                </Select>
            )}
            <Input label="Title" placeholder="Passport" value={title} onChange={(e) => setTitle(e.target.value)} />
            {errors.title && <Alert>{errors.title}</Alert>}
            <Select label="Document type" value={typeId} onChange={(e) => setTypeId(e.target.value)}>
                <option value="">Select a type</option>
                {(types ?? []).map((type) => (
                    <option key={type.id} value={type.id}>
                        {type.name}
                    </option>
                ))}
            </Select>
            {errors.document_type_id && <Alert>{errors.document_type_id}</Alert>}
            <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">File</label>
                <input
                    type="file"
                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200"
                    onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                />
            </div>
            {errors.file && <Alert>{errors.file}</Alert>}
            {errors.employee_id && <Alert>{errors.employee_id}</Alert>}
            <label className="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" checked={confidential} onChange={(e) => setConfidential(e.target.checked)} />
                Confidential — needs the sensitive-documents permission to open
            </label>
            {errors.form && <Alert>{errors.form}</Alert>}
            <Button type="submit" loading={uploading} disabled={!ready}>
                Upload document
            </Button>
        </form>
    );
}
