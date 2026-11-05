/**
 * Comment @mentions. The textbox only ever shows `@Acme Corp Owner`; the user id of whoever was picked from the
 * autocomplete is kept beside the text (never typed, never shown) and joined back in when the comment is sent as
 * `@[Acme Corp Owner](user:1)`, which is what the server resolves. Reading a stored comment back into the textbox
 * goes the other way, so editing shows clean names too.
 */

const TOKEN = /@\[([^\]\n]{1,100})\]\(user:(\d+)\)/g;

/** Stored text -> `{ text, mentions }`: tokens collapse to `@Name`, the picked ids come back in order. */
export function revealMentions(stored) {
    const mentions = [];
    const text = String(stored ?? '').replace(TOKEN, (_, name, id) => {
        mentions.push({ id: Number(id), name });
        return `@${name}`;
    });
    return { text, mentions };
}

const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Visible text + picked mentions -> the text to send. Each `@Name` in the box takes the next id picked for that
 * name, so it does not matter in which order people were picked or where they sit in the text, and two people who
 * share a name stay distinct. A `@Name` nobody picked (typed by hand) is left as it is for the server's name rules.
 */
export function embedMentions(text, mentions) {
    if (!mentions?.length) return text;

    const queues = new Map();
    for (const { id, name } of mentions) {
        const key = name.toLowerCase();
        queues.set(key, [...(queues.get(key) ?? []), id]);
    }

    // Longest names first so "Ada Lovelace" is not eaten by "Ada".
    const names = [...new Set(mentions.map((m) => m.name))].sort((a, b) => b.length - a.length);
    const pattern = new RegExp(`@(${names.map(escapeRegex).join('|')})(?![\\p{L}\\p{N}_])`, 'giu');

    return text.replace(pattern, (whole, name) => {
        const queue = queues.get(name.toLowerCase());
        const id = queue?.shift();
        return id === undefined ? whole : `@[${name}](user:${id})`;
    });
}
