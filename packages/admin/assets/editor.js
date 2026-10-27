// The Markdown editor of the admin, on CodeMirror 6: loaded only on pages that have one (see admin.js).
// The textarea stays the form's field: the editor writes every change into it, so the form works as it
// does without JavaScript.
//
// It's written as a manuscript: emphasis, code and links read as they will, their marks shown only
// where the cursor is in them (to see and change them there, as Typora and Obsidian do); headings and
// quotes keep their marks muted; images show as a box with their description; and changes to the live
// text are marked in it when there is one.
import { EditorView, keymap, drawSelection, placeholder, Decoration, ViewPlugin, WidgetType } from '@codemirror/view';
import { EditorSelection } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language';
import { markdown, markdownKeymap } from '@codemirror/lang-markdown';
import { autocompletion } from '@codemirror/autocomplete';
import { unifiedMergeView } from '@codemirror/merge';
import { tags } from '@lezer/highlight';

// Colours come from the admin's tokens, so they follow light and dark.
const highlighting = HighlightStyle.define([
    { tag: tags.heading1, fontWeight: '700', fontSize: '1.6em' },
    { tag: tags.heading2, fontWeight: '700', fontSize: '1.3em' },
    { tag: [tags.heading3, tags.heading4, tags.heading5, tags.heading6], fontWeight: '700' },
    { tag: tags.strong, fontWeight: '700' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: tags.strikethrough, textDecoration: 'line-through' },
    { tag: [tags.link, tags.url], color: 'var(--q-accent)' },
    { tag: [tags.quote], color: 'var(--q-muted)', fontStyle: 'italic' },
    { tag: [tags.processingInstruction, tags.contentSeparator], color: 'var(--q-muted)' },
    { tag: tags.monospace, fontFamily: 'var(--q-font-mono)', fontSize: '0.9em' },
]);

// The formatted span (e.g. "StrongEmphasis") that a selection is in, if any.
const enclosing = (state, from, to, name) => {
    for (const side of [1, -1]) {
        for (let node = syntaxTree(state).resolveInner(from, side); node; node = node.parent) {
            if (node.name === name && node.from <= from && node.to >= to) {
                return node;
            }
        }
    }

    return null;
};

// What each format is in the syntax tree, and its marks.
const formats = { bold: ['StrongEmphasis', 'EmphasisMark'], italic: ['Emphasis', 'EmphasisMark'], code: ['InlineCode', 'CodeMark'] };

// Wraps the selection in a marker, or inserts the markers around the cursor. A format toggles: in text
// that has it already, its marks are taken off.
const wrap = (before, after = before, format = null) => (view) => {
    const { state } = view;

    view.dispatch(state.changeByRange((range) => {
        const node = format ? enclosing(state, range.from, range.to, formats[format][0]) : null;
        const marks = node ? node.getChildren(formats[format][1]) : [];

        if (marks.length >= 2) {
            const [first, last] = [marks[0], marks[marks.length - 1]];
            const changes = state.changes([{ from: first.from, to: first.to }, { from: last.from, to: last.to }]);

            return { changes, range: range.map(changes) };
        }

        return {
            changes: [{ from: range.from, insert: before }, { from: range.to, insert: after }],
            range: range.empty
                ? EditorSelection.cursor(range.from + before.length)
                : EditorSelection.range(range.from + before.length, range.to + before.length),
        };
    }));
    view.focus();

    return true;
};

/**
 * The formats the selection is in (bold, italic, code), for the toolbar to show them pressed.
 *
 * @returns {Set<string>}
 */
export function activeFormats(state) {
    const { from, to } = state.selection.main;

    return new Set(Object.keys(formats).filter((format) => enclosing(state, from, to, formats[format][0]) !== null));
}

// Starts each selected line with a prefix, e.g. "## " or "- ".
const prefix = (marker) => (view) => {
    const changes = [];

    for (const range of view.state.selection.ranges) {
        for (let line = view.state.doc.lineAt(range.from); ; line = view.state.doc.line(line.number + 1)) {
            changes.push({ from: line.from, insert: marker });

            if (line.to >= range.to || line.number === view.state.doc.lines) {
                break;
            }
        }
    }

    view.dispatch({ changes });
    view.focus();

    return true;
};

// An image on a line of its own, after the cursor's line, with its description selected to write.
const image = (view, labels = {}) => {
    const alt = labels.image_alt ?? 'Description';
    const line = view.state.doc.lineAt(view.state.selection.main.head);
    const at = line.length === 0 ? line.from : line.to;
    const before = line.length === 0 ? '' : '\n\n';

    view.dispatch({
        changes: { from: at, insert: `${before}![${alt}](https://)` },
        selection: EditorSelection.range(at + before.length + 2, at + before.length + 2 + alt.length),
    });
    view.focus();

    return true;
};

export const commands = {
    bold: wrap('**', '**', 'bold'),
    italic: wrap('_', '_', 'italic'),
    code: wrap('`', '`', 'code'),
    link: wrap('[', '](https://)'),
    heading: prefix('## '),
    list: prefix('- '),
    quote: prefix('> '),
    image,
};

// An image, as a box with its description and file, while the cursor isn't on its line.
class ImageWidget extends WidgetType {
    constructor(alt, src, missing) {
        super();
        this.alt = alt;
        this.src = src;
        this.missing = missing;
    }

    eq(other) {
        return other.alt === this.alt && other.src === this.src;
    }

    toDOM() {
        const box = document.createElement('span');
        box.className = `cm-md-image${this.missing ? ' cm-md-image--missing' : ''}`;
        box.textContent = this.alt === '' ? this.src.split('/').pop() : `${this.alt} · ${this.src.split('/').pop()}`;

        return box;
    }

    ignoreEvent() {
        return false;
    }
}

const hidden = Decoration.replace({});
const muted = Decoration.mark({ class: 'cm-md-mark' });

function manuscript(view) {
    const ranges = [];
    const selection = view.hasFocus ? view.state.selection.ranges : [];
    // Whether the cursor or the selection is in (or right next to) a span: its marks show there.
    const touched = (from, to) => selection.some((range) => range.from <= to && range.to >= from);

    for (const { from, to } of view.visibleRanges) {
        syntaxTree(view.state).iterate({
            from,
            to,
            enter: (node) => {
                if (node.name === 'Image') {
                    const text = view.state.doc.sliceString(node.from, node.to);
                    const match = /^!\[([^\]]*)\]\(([^)\s]*)/.exec(text);
                    const oneLine = view.state.doc.lineAt(node.from).number === view.state.doc.lineAt(node.to).number;

                    if (match && oneLine && !touched(node.from, node.to)) {
                        ranges.push(Decoration.replace({ widget: new ImageWidget(match[1], match[2], match[1].trim() === '') }).range(node.from, node.to));
                    }

                    return false;
                }

                const parent = node.node.parent;

                if (node.name === 'HeaderMark' || node.name === 'QuoteMark' || node.name === 'ListMark') {
                    ranges.push(muted.range(node.from, node.to));
                } else if ((node.name === 'EmphasisMark' || node.name === 'StrikethroughMark' || (node.name === 'CodeMark' && parent?.name === 'InlineCode')) && parent && !touched(parent.from, parent.to)) {
                    ranges.push(hidden.range(node.from, node.to));
                } else if ((node.name === 'URL' || node.name === 'LinkMark') && parent?.name === 'Link' && !touched(parent.from, parent.to)) {
                    // A link reads as its text: its brackets and address show where the cursor is in it.
                    ranges.push(hidden.range(node.from, node.to));
                }
            },
        });
    }

    return Decoration.set(ranges, true);
}

const manuscriptPlugin = ViewPlugin.fromClass(class {
    constructor(view) {
        this.decorations = manuscript(view);
    }

    update(update) {
        if (update.docChanged || update.viewportChanged || update.selectionSet || update.focusChanged) {
            this.decorations = manuscript(update.view);
        }
    }
}, { decorations: (plugin) => plugin.decorations });

// "/" at the start of a line offers what to add.
const blocks = (labels) => (context) => {
    const word = context.matchBefore(/^\/\w*$/m);

    if (!word || word.from !== context.state.doc.lineAt(context.pos).from) {
        return null;
    }

    const option = (label, text, cursor) => ({
        label,
        apply: (view, completion, from, to) => {
            view.dispatch({ changes: { from, to, insert: text }, selection: { anchor: from + (cursor ?? text.length) } });
        },
    });
    const alt = labels.image_alt ?? 'Description';

    return {
        from: word.from,
        options: [
            option(labels.heading ?? 'Heading', '## '),
            option(labels.subheading ?? 'Subheading', '### '),
            option(labels.image ?? 'Image', `![${alt}](https://)`, 2 + alt.length + 2),
            option((labels.link ?? 'Link').replace(/\s*\(.*\)$/, ''), '[](https://)', 1),
            option(labels.list ?? 'List', '- '),
            option(labels.quote ?? 'Quote', '> '),
            option(labels.separator ?? 'Separator', '---\n'),
        ],
        filter: true,
    };
};

/**
 * Replaces the textarea with an editor, and returns it.
 *
 * @param {{placeholder?: string, original?: string|null, labels?: object, onUpdate?: function}} options
 */
export function createEditor(textarea, label, options = {}) {
    const view = new EditorView({
        doc: textarea.value,
        extensions: [
            history(),
            drawSelection(),
            EditorView.lineWrapping,
            markdown(),
            syntaxHighlighting(highlighting),
            manuscriptPlugin,
            autocompletion({ override: [blocks(options.labels ?? {})], icons: false }),
            ...(options.placeholder ? [placeholder(options.placeholder)] : []),
            // Changes to the live text, marked in it: added text, and what was taken out.
            ...(typeof options.original === 'string' ? [unifiedMergeView({ original: options.original, mergeControls: false, gutter: true, syntaxHighlightDeletions: false, allowInlineDiffs: true })] : []),
            // Tab isn't taken: it moves on to the next control, as everywhere else.
            keymap.of([
                { key: 'Mod-b', run: commands.bold },
                { key: 'Mod-i', run: commands.italic },
                { key: 'Mod-k', run: commands.link },
                ...markdownKeymap,
                ...defaultKeymap,
                ...historyKeymap,
            ]),
            EditorView.contentAttributes.of({ 'aria-label': label, 'aria-multiline': 'true', spellcheck: 'true' }),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    textarea.value = update.state.doc.toString();
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                }

                options.onUpdate?.(update);
            }),
        ],
    });

    textarea.hidden = true;
    textarea.after(view.dom);

    // The textarea's label now focuses the editor.
    for (const labelElement of textarea.labels ?? []) {
        labelElement.addEventListener('click', () => view.focus());
    }

    return view;
}
