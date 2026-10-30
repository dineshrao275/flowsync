const COPIED = [
    'boxSizing', 'width', 'overflowX', 'overflowY', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'fontStyle', 'fontVariant', 'fontWeight', 'fontSize',
    'lineHeight', 'fontFamily', 'textAlign', 'textTransform', 'textIndent', 'letterSpacing', 'wordSpacing', 'tabSize',
];

/**
 * Viewport coordinates of the character at `index` in a textarea/input: {left, top, bottom}.
 * Mirrors the field's text into an off-screen div with the same typography and measures a marker.
 */
export function caretRect(el, index) {
    if (!el) return { left: 0, top: 0, bottom: 0 };
    const style = window.getComputedStyle(el);
    const mirror = document.createElement('div');
    mirror.style.position = 'absolute';
    mirror.style.top = '0px';
    mirror.style.left = '0px';
    mirror.style.visibility = 'hidden';
    mirror.style.pointerEvents = 'none';
    mirror.style.whiteSpace = 'pre-wrap';
    mirror.style.wordWrap = 'break-word';
    COPIED.forEach((prop) => { mirror.style[prop] = style[prop]; });
    const val = el.value || '';
    mirror.textContent = val.slice(0, index);
    const marker = document.createElement('span');
    marker.textContent = val.slice(index) || '.';
    mirror.appendChild(marker);
    document.body.appendChild(mirror);

    const box = el.getBoundingClientRect();
    const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.4;
    const left = box.left + marker.offsetLeft - el.scrollLeft;
    const top = box.top + marker.offsetTop - el.scrollTop;
    document.body.removeChild(mirror);

    return { left, top, bottom: top + lineHeight };
}
