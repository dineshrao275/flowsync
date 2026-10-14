import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RichText from './RichText';

describe('RichText', () => {
    it('renders nothing for an empty value', () => {
        const { container } = render(<RichText value="" />);
        expect(container.innerHTML).toBe('');
    });

    it('keeps formatting but strips scripts and event handlers', () => {
        render(
            <RichText
                value={'<p>hi</p><script>alert(1)</script><ul><li><img src="x" onerror="alert(2)">item</li></ul>'}
            />,
        );

        expect(screen.getByText('hi')).toBeInTheDocument();
        expect(screen.getByText('item')).toBeInTheDocument();
        expect(document.querySelector('script')).toBeNull();
        expect(document.querySelector('[onerror]')).toBeNull();
    });

    it('renders plain-text descriptions untouched', () => {
        render(<RichText value="Just some words, no tags." />);
        expect(screen.getByText('Just some words, no tags.')).toBeInTheDocument();
    });

    it('shows checklist state without live controls', () => {
        const { container } = render(
            <RichText
                value={
                    '<ul data-type="taskList"><li data-type="taskItem" data-checked="true"><label><input type="checkbox" checked="checked"><span></span></label><div><p>done</p></div></li></ul>'
                }
            />,
        );

        expect(screen.getByText('done')).toBeInTheDocument();
        const list = container.querySelector('[data-type="taskList"]');
        expect(list).not.toBeNull();
        // The read-only stylesheet deadens task lists under `.rich-text`
        // (jsdom loads no stylesheets, so pin the hook, not the rule).
        expect(list.closest('.rich-text')).not.toBeNull();
    });
});
