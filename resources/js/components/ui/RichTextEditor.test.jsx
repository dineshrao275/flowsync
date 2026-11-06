import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RichTextEditor from './RichTextEditor';

describe('RichTextEditor', () => {
    it('renders the full toolbar', () => {
        render(<RichTextEditor value="" onChange={() => {}} />);

        for (const name of [
            'Bold',
            'Italic',
            'Strikethrough',
            'Underline',
            'Heading 1',
            'Heading 2',
            'Heading 3',
            'Bullet list',
            'Numbered list',
            'Checklist',
            'Quote',
            'Code block',
            'Undo',
            'Redo',
            'Clear formatting',
        ]) {
            expect(screen.getByTitle(name)).toBeInTheDocument();
        }
    });

    it('shows the given content and applies a heading toggle', () => {
        const onChange = vi.fn();
        const { container } = render(<RichTextEditor value="<p>hi</p>" onChange={onChange} />);

        expect(container.querySelector('.tiptap p')).toHaveTextContent('hi');

        fireEvent.click(screen.getByTitle('Heading 1'));

        expect(container.querySelector('.tiptap h1')).toHaveTextContent('hi');
    });

    it('syncs an external reset back into the document', () => {
        const onChange = vi.fn();
        const { container, rerender } = render(<RichTextEditor value="<p>hi</p>" onChange={onChange} />);

        expect(container.querySelector('.tiptap')).toHaveTextContent('hi');

        rerender(<RichTextEditor value="" onChange={onChange} />);

        expect(container.querySelector('.tiptap').textContent).toBe('');
    });

    describe('does not report its own load-time normalization as an edit', () => {
        // TipTap rewrites stored content into its own schema; forwarding that made the task drawer show
        // "unsaved changes" the moment it opened.
        it.each([
            ['plain text', 'koala insulation'],
            ['editor html with a stray empty paragraph', '<p>koala insulation</p> <p> </p>'],
            ['a list', '<ol><li>one</li><li>two</li></ol>'],
            ['a heading followed by bare text', '<h2>T</h2>text'],
            ['a blank value', ''],
            ['an empty paragraph', '<p></p>'],
        ])('stays silent when opened with %s', async (_name, html) => {
            const onChange = vi.fn();
            render(<RichTextEditor value={html} onChange={onChange} />);
            await new Promise((resolve) => setTimeout(resolve, 30));

            expect(onChange).not.toHaveBeenCalled();
        });

        it('still reports a real edit', async () => {
            const onChange = vi.fn();
            render(<RichTextEditor value="koala" onChange={onChange} />);
            await new Promise((resolve) => setTimeout(resolve, 30));
            expect(onChange).not.toHaveBeenCalled();

            fireEvent.click(screen.getByTitle('Heading 1'));

            expect(onChange).toHaveBeenCalledTimes(1);
            expect(onChange.mock.calls[0][0]).toContain('<h1>koala</h1>');
        });

        it('treats an external reset as the new baseline, not an edit', async () => {
            const onChange = vi.fn();
            const { rerender } = render(<RichTextEditor value="<p>one</p>" onChange={onChange} />);
            await new Promise((resolve) => setTimeout(resolve, 30));

            rerender(<RichTextEditor value="two" onChange={onChange} />);
            await new Promise((resolve) => setTimeout(resolve, 30));

            expect(onChange).not.toHaveBeenCalled();
        });
    });
});
