import { render } from '@testing-library/react';
import { axe } from 'vitest-axe';
import * as matchers from 'vitest-axe/matchers';
import { describe, expect, it } from 'vitest';
import Alert from './Alert';
import Button from './Button';
import Checkbox from './Checkbox';
import DataTable from './DataTable';
import EmptyState from './EmptyState';
import ErrorState from './ErrorState';
import FormField from './FormField';
import Input from './Input';
import Menu from './Menu';
import Modal from './Modal';
import PageHeader from './PageHeader';
import Select from './Select';
import Switch from './Switch';
import Tabs from './Tabs';
import Textarea from './Textarea';

expect.extend(matchers);

// jsdom has no layout engine, so colour-contrast is checked by the token tests
// (theme.test.js) rather than here.
const audit = (container) => axe(container, { rules: { 'color-contrast': { enabled: false } } });

describe('primitives have no detectable axe violations', () => {
    it('buttons, alerts and empty/error states', async () => {
        const { container } = render(
            <main>
                <Button>Save</Button>
                <Button size="icon" aria-label="Close">×</Button>
                <Alert type="error">Something failed</Alert>
                <Alert type="success">Saved</Alert>
                <EmptyState title="Nothing yet" description="Create one" />
                <ErrorState onRetry={() => {}} />
            </main>,
        );

        expect(await audit(container)).toHaveNoViolations();
    });

    it('form controls wired through FormField', async () => {
        const { container } = render(
            <form>
                <FormField label="Name" hint="Your full name" required><Input /></FormField>
                <FormField label="Bio" error="Too long"><Textarea /></FormField>
                <FormField label="Role"><Select><option value="a">A</option></Select></FormField>
                <Checkbox label="Remember me" />
                <Switch checked onChange={() => {}} label="Notifications" />
            </form>,
        );

        expect(await audit(container)).toHaveNoViolations();
    });

    it('tabs, menu, page header and data table', async () => {
        const { container } = render(
            <main>
                <PageHeader title="People" description="Everyone" actions={<Button>Add</Button>} />
                <Tabs label="Sections" active="a" onChange={() => {}} tabs={[{ key: 'a', label: 'One' }, { key: 'b', label: 'Two' }]} />
                <Menu label="Actions" trigger={(props) => <button {...props}>More</button>} items={[{ key: 'x', label: 'Edit' }]} />
                <DataTable
                    caption="People"
                    columns={[{ key: 'name', header: 'Name', sortable: true }, { key: 'role', header: 'Role' }]}
                    rows={[{ id: 1, name: 'Ada', role: 'admin' }]}
                    sort={{ key: 'name', dir: 'asc' }}
                    selectable
                    selected={new Set()}
                    onSelectedChange={() => {}}
                />
            </main>,
        );

        expect(await audit(container)).toHaveNoViolations();
    });

    it('an open modal dialog', async () => {
        render(<Modal open onClose={() => {}} title="Confirm"><p>Body text</p></Modal>);

        expect(await audit(document.body)).toHaveNoViolations();
    });

    it('the harness really detects violations (control)', async () => {
        const { container } = render(<main><img src="x.png" /><button type="button" /></main>);
        const results = await audit(container);

        expect(results.violations.map((violation) => violation.id)).toEqual(expect.arrayContaining(['image-alt', 'button-name']));
    });
});
