import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Bell } from '@phosphor-icons/react';
import Icon from './Icon';

describe('Icon', () => {
    it('is decorative by default', () => {
        const { container } = render(<Icon as={Bell} />);

        expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
    });

    it('becomes an image when labelled', () => {
        render(<Icon as={Bell} label="Notifications" size="lg" />);

        expect(screen.getByRole('img', { name: 'Notifications' })).toBeInTheDocument();
    });
});
