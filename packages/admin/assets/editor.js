// The Markdown editor of the admin, on CodeMirror 6: loaded only on pages that have one (see admin.js).
// The textarea stays the form's field: the editor writes every change into it, so the form works as it
// does without JavaScript.
import { EditorView, keymap, drawSelection } from '@codemirror/view';
import { EditorSelection } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { markdown, markdownKeymap } from '@codemirror/lang-markdown';
import { tags } from '@lezer/highlight';

// Colours come from the admin's tokens, so they follow light and dark.
const highlighting = HighlightStyle.define([
    { tag: tags.heading, fontWeight: '700' },
    { tag: tags.strong, fontWeight: '700' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: tags.strikethrough, textDecoration: 'line-through' },
    { tag: [tags.link, tags.url], color: 'var(--admin-accent)' },
    { tag: [tags.quote, tags.processingInstruction, tags.contentSeparator], color: 'var(--admin-muted)' },
    { tag: tags.monospace, fontFamily: 'var(--admin-font-mono)' },
]);

// Wraps the selection in a marker, or inserts the markers around the cursor.
const wrap = (before, after = before) => (view) => {
    view.dispatch(view.state.changeByRange((range) => ({
        changes: [{ from: range.from, insert: before }, { from: range.to, insert: after }],
        range: range.empty
            ? EditorSelection.cursor(range.from + before.length)
            : EditorSelection.range(range.from + before.length, range.to + before.length),
    })));
    view.focus();

    return true;
};

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

export const commands = {
    bold: wrap('**'),
    italic: wrap('_'),
    code: wrap('`'),
    link: wrap('[', '](https://)'),
    heading: prefix('## '),
    list: prefix('- '),
    quote: prefix('> '),
};

/**
 * Replaces the textarea with an editor, and returns it.
 */
export function createEditor(textarea, label) {
    const view = new EditorView({
        doc: textarea.value,
        extensions: [
            history(),
            drawSelection(),
            EditorView.lineWrapping,
            markdown(),
            syntaxHighlighting(highlighting),
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
