import { useState } from 'react';
import { fieldErrors } from '../../services/api';
import Alert from '../../components/ui/Alert';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Modal from '../../components/ui/Modal';

/**
 * Create or edit a location.
 *
 * The geofence is expressed as an *intent* checkbox plus the three numbers that
 * make it real, because that is what the API does with them: the service
 * derives `is_geo_fenced` from whether the geometry is complete, so a client
 * that sent the flag on its own would get a location reporting "not geofenced"
 * back. Ticking the box without a centre and a radius is a 422 on `form` with a
 * message saying exactly that, which the form shows above the fields — it is a
 * complaint about the group, not about one of them.
 *
 * The initial checkbox state reads `is_geo_fenced`, which the presenter reports
 * as the *effective* fence (flag **and** geometry). An incomplete row therefore
 * opens unticked, which is the truth about it rather than what was once stored.
 */
export default function LocationFormModal({ location, saving, onClose, onSubmit }) {
    const editing = Boolean(location);

    const [form, setForm] = useState(() => ({
        name: location?.name ?? '',
        address_line1: location?.address_line1 ?? '',
        address_line2: location?.address_line2 ?? '',
        city: location?.city ?? '',
        state: location?.state ?? '',
        postal_code: location?.postal_code ?? '',
        country: location?.country ?? '',
        timezone: location?.timezone ?? '',
        geo_lat: location?.geo_lat ?? '',
        geo_lng: location?.geo_lng ?? '',
        geo_radius_m: location?.geo_radius_m ?? '',
        is_geo_fenced: location?.is_geo_fenced ?? false,
    }));
    const [errors, setErrors] = useState({});

    function set(key, value) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    function submit(e) {
        e.preventDefault();
        setErrors({});

        // Blank coordinates are `null`, not `''`. A number column sent an empty
        // string is a validation error about a number, which tells the user
        // nothing about the field they left alone on purpose.
        const number = (value) => (value === '' || value === null ? null : Number(value));
        const text = (value) => {
            const trimmed = value.trim();
            return trimmed === '' ? null : trimmed;
        };

        onSubmit({
            name: form.name,
            address_line1: text(form.address_line1),
            address_line2: text(form.address_line2),
            city: text(form.city),
            state: text(form.state),
            postal_code: text(form.postal_code),
            country: form.country.trim() ? form.country.trim().toUpperCase() : null,
            timezone: text(form.timezone),
            geo_lat: number(form.geo_lat),
            geo_lng: number(form.geo_lng),
            geo_radius_m: number(form.geo_radius_m),
            is_geo_fenced: form.is_geo_fenced,
        }).catch((err) => setErrors(fieldErrors(err)));
    }

    return (
        <Modal
            open
            title={editing ? 'Edit location' : 'New location'}
            subtitle={editing ? location.name : 'A site, an office, or a region'}
            onClose={onClose}
            size="lg"
        >
            <form onSubmit={submit} className="space-y-5 px-6 py-5">
                {errors.form && <Alert>{errors.form}</Alert>}

                <Input
                    label="Name"
                    value={form.name}
                    onChange={(e) => set('name', e.target.value)}
                    error={errors.name}
                    required
                    autoFocus
                />

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Address</legend>
                    <Input
                        label="Address line 1"
                        value={form.address_line1}
                        onChange={(e) => set('address_line1', e.target.value)}
                        error={errors.address_line1}
                    />
                    <Input
                        label="Address line 2"
                        value={form.address_line2}
                        onChange={(e) => set('address_line2', e.target.value)}
                        error={errors.address_line2}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Input label="City" value={form.city} onChange={(e) => set('city', e.target.value)} error={errors.city} />
                        <Input label="State" value={form.state} onChange={(e) => set('state', e.target.value)} error={errors.state} />
                        <Input
                            label="Postal code"
                            value={form.postal_code}
                            onChange={(e) => set('postal_code', e.target.value)}
                            error={errors.postal_code}
                        />
                        <Input
                            label="Country"
                            value={form.country}
                            onChange={(e) => set('country', e.target.value)}
                            error={errors.country}
                            placeholder="Two-letter code, e.g. IN"
                        />
                    </div>
                </fieldset>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-semibold text-gray-700">Geofence</legend>

                    <label className="flex items-center gap-2 text-sm text-gray-700">
                        <input
                            type="checkbox"
                            checked={form.is_geo_fenced}
                            onChange={(e) => set('is_geo_fenced', e.target.checked)}
                            className="h-4 w-4 rounded border-gray-300"
                        />
                        Clock in only within range of this site
                    </label>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Input
                            label="Latitude"
                            value={form.geo_lat}
                            onChange={(e) => set('geo_lat', e.target.value)}
                            error={errors.geo_lat}
                            placeholder="19.0760"
                        />
                        <Input
                            label="Longitude"
                            value={form.geo_lng}
                            onChange={(e) => set('geo_lng', e.target.value)}
                            error={errors.geo_lng}
                            placeholder="72.8777"
                        />
                        <Input
                            label="Radius (m)"
                            type="number"
                            min="1"
                            value={form.geo_radius_m}
                            onChange={(e) => set('geo_radius_m', e.target.value)}
                            error={errors.geo_radius_m}
                            placeholder="150"
                        />
                    </div>

                    <p className="text-xs text-gray-500">
                        Coordinates on their own are fine — a site is useful on a map before anyone
                        configures a fence. Ticking the box needs all three.
                    </p>
                </fieldset>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <Button variant="secondary" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={saving}>
                        {editing ? 'Save changes' : 'Create location'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
