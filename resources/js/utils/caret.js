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
    const style = window.getComputedStyle(el);
    const mirror = document.createElement('div');
    mirror.style.position = 'absolute';
    mirror.style.visibility = 'hidden';
    mirror.style.whiteSpace = 'pre-wrap';
    mirror.style.wordWrap = 'break-word';
    COPIED.forEach((prop) => { mirror.style[prop] = style[prop]; });
    mirror.textContent = el.value.slice(0, index);
    const marker = document.createElement('span');
    marker.textContent = el.value.slice(index) || '.';
    mirror.appendChild(marker);
    document.body.appendChild(mirror);

    const box = el.getBoundingClientRect();
    const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.4;
    const left = box.left + marker.offsetLeft - el.scrollLeft;
    const top = box.top + marker.offsetTop - el.scrollTop;
    document.body.removeChild(mirror);

    return { left, top, bottom: top + lineHeight };
}
