/**
 * Minimal JSON Schema check for tool arguments (type, required, enum, bounds, additionalProperties).
 * The protocol does not validate for us, and a missing id would otherwise become `projects/undefined/...`.
 * Returns a list of problems; an empty list means the arguments are acceptable.
 */

function typeOf(value) {
    if (Array.isArray(value)) return 'array';
    if (value === null) return 'null';
    return typeof value;
}

function matchesType(value, type) {
    switch (type) {
        case 'integer': return Number.isInteger(value);
        case 'number': return typeof value === 'number' && Number.isFinite(value);
        case 'array': return Array.isArray(value);
        case 'object': return value !== null && typeof value === 'object' && !Array.isArray(value);
        default: return typeof value === type;
    }
}

function check(path, value, schema, problems) {
    if (schema.type && !matchesType(value, schema.type)) {
        problems.push(`${path} must be ${schema.type === 'integer' ? 'an integer' : `a ${schema.type}`} (got ${typeOf(value)}${typeof value === 'string' ? ` "${value.slice(0, 20)}"` : ''})`);
        return;
    }
    if (schema.enum && !schema.enum.includes(value)) {
        problems.push(`${path} must be one of: ${schema.enum.join(', ')}`);
    }
    if (typeof value === 'number') {
        if (schema.minimum !== undefined && value < schema.minimum) problems.push(`${path} must be >= ${schema.minimum}`);
        if (schema.maximum !== undefined && value > schema.maximum) problems.push(`${path} must be <= ${schema.maximum}`);
    }
    if (typeof value === 'string' && schema.maxLength !== undefined && value.length > schema.maxLength) {
        problems.push(`${path} must be at most ${schema.maxLength} characters (got ${value.length})`);
    }
    if (Array.isArray(value) && schema.items) {
        value.forEach((item, index) => check(`${path}[${index}]`, item, schema.items, problems));
    }
}

export function validateArgs(schema, args) {
    const problems = [];
    const properties = schema?.properties ?? {};

    for (const name of schema?.required ?? []) {
        if (args[name] === undefined || args[name] === null || args[name] === '') {
            problems.push(`${name} is required`);
        }
    }

    for (const [name, value] of Object.entries(args)) {
        if (value === undefined) continue;
        if (!(name in properties)) {
            if (schema?.additionalProperties === false) problems.push(`unknown argument "${name}"`);
            continue;
        }
        check(name, value, properties[name], problems);
    }

    return problems;
}
