// The admin's JavaScript. Pages work without it; Turbo makes moving between them fast, and Stimulus
// controllers add behaviour to elements (data-controller="…").
// Both are npm packages, which the site downloads (see "extra.quire.importmap" in composer.json).
// Scales of Open Props (sizes, radii, easings) that Quire UI's tokens use; rendered as <link> tags,
// so they apply without JavaScript too.
import 'open-props/sizes.min.css';
import 'open-props/borders.min.css';
import 'open-props/easings.min.css';
import '@hotwired/turbo';
import { Application, Controller } from '@hotwired/stimulus';

const application = Application.start();

// Extensions register controllers on window.QuireAdmin, whether their script runs before or after this
// one. A controller is given as a function that receives Stimulus's Controller class and returns the
// controller's class:
//
//     (window.QuireAdmin ??= { controllers: [] }).controllers.push(
//         ['acme-chart', (Controller) => class extends Controller { connect() { … } }],
//     );
const pending = window.QuireAdmin?.controllers ?? [];
const register = ([name, define]) => application.register(name, define.prototype instanceof Controller ? define : define(Controller));

window.QuireAdmin = {
    application,
    Controller,
    controllers: {
        push: (...entries) => entries.forEach(register),
    },
};

window.QuireAdmin.controllers.push(...pending);

// Content lists. Without JavaScript, the selection bar is a menu of actions and an "Apply" button.
// With it, the bar shows when something is selected, says how many items each action applies to, and
// offers every item that matches; keys select (x, ⇧x for a range), move (j, k) and edit (e).
application.register('content-list', class extends Controller {
    static targets = ['all', 'item', 'row', 'edit', 'bar', 'summary', 'extra', 'buttons', 'action', 'menu', 'matching', 'allMatching'];

    // labels: { selected: "{count} selected on this page.", matching: "All {count} that match are selected." }
    static values = { total: Number, labels: Object };

    connect() {
        if (this.hasAllTarget) {
            this.allTarget.hidden = false;
        }

        if (this.hasMenuTarget) {
            this.menuTarget.hidden = true;
            this.barTarget.classList.add('is-enhanced');
        }

        this.cursor = -1;
        this.last = null;
        document.addEventListener('keydown', this.keys);
        this.update();
    }

    disconnect() {
        document.removeEventListener('keydown', this.keys);
    }

    toggleAll() {
        this.itemTargets.forEach((checkbox) => { checkbox.checked = this.allTarget.checked; });
        this.matching = false;
        this.update();
    }

    selectMatching() {
        this.itemTargets.forEach((checkbox) => { checkbox.checked = true; });
        this.matching = true;
        this.update();
    }

    clear() {
        this.itemTargets.forEach((checkbox) => { checkbox.checked = false; });
        this.matching = false;
        this.update();
    }

    update(event) {
        // ⇧ with a click selects the range from the last one chosen.
        if (event?.shiftKey && this.last && event.target !== this.last) {
            const boxes = this.itemTargets;
            const [from, to] = [boxes.indexOf(this.last), boxes.indexOf(event.target)].sort((a, b) => a - b);
            boxes.slice(from, to + 1).forEach((checkbox) => { checkbox.checked = event.target.checked; });
        }

        if (event?.target && this.itemTargets.includes(event.target)) {
            this.last = event.target;
            this.matching = this.matching && event.target.checked;
        }

        const selected = this.itemTargets.filter((checkbox) => checkbox.checked);

        if (this.hasAllTarget) {
            this.allTarget.checked = selected.length > 0 && selected.length === this.itemTargets.length;
            this.allTarget.indeterminate = selected.length > 0 && selected.length < this.itemTargets.length;
        }

        this.rowTargets.forEach((row) => row.classList.toggle('is-selected', row.querySelector('input[type=checkbox]')?.checked ?? false));

        if (!this.hasBarTarget) {
            return;
        }

        const labels = this.labelsValue;
        this.allMatchingTarget.value = this.matching ? '1' : '0';
        this.barTarget.classList.toggle('is-active', selected.length > 0);
        this.extraTarget.hidden = selected.length === 0;
        this.buttonsTarget.hidden = selected.length === 0;

        if (this.hasMatchingTarget) {
            this.matchingTarget.hidden = this.matching || selected.length === 0;
        }

        this.summaryTarget.textContent = selected.length === 0
            ? (labels.none ?? '')
            : (this.matching ? labels.matching : labels.selected).replace('{count}', String(this.matching ? this.totalValue : selected.length));

        // Each action, with how many of the selected items it applies to; none, and it's hidden.
        const rows = selected.map((checkbox) => checkbox.closest('tr'));

        for (const button of this.actionTargets) {
            const count = rows.filter((row) => (row?.dataset.actions ?? '').split(' ').includes(button.value)).length;
            button.hidden = !this.matching && count === 0;
            button.textContent = this.matching || count === selected.length ? button.dataset.label : `${button.dataset.label} (${count})`;
        }
    }

    keys = (event) => {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || event.target.closest('input, textarea, select, [contenteditable]')) {
            return;
        }

        const rows = this.rowTargets;
        const key = event.key.toLowerCase();

        if (rows.length === 0 || !['j', 'k', 'x', 'e'].includes(key)) {
            return;
        }

        event.preventDefault();

        if (key === 'j' || key === 'k') {
            this.cursor = Math.max(0, Math.min(rows.length - 1, this.cursor + (key === 'j' ? 1 : -1)));
            rows.forEach((row, index) => row.classList.toggle('is-current', index === this.cursor));
            rows[this.cursor].querySelector('.content-list__title')?.focus();

            return;
        }

        const row = rows[Math.max(0, this.cursor)];

        if (key === 'e') {
            row.querySelector('.content-list__title')?.click();

            return;
        }

        const checkbox = row.querySelector('input[type=checkbox]');
        checkbox.checked = !checkbox.checked;
        this.update({ target: checkbox, shiftKey: event.shiftKey });
    };
});

// A disclosure that starts closed on phones (where the list should come first) and open elsewhere.
application.register('phone-closed', class extends Controller {
    connect() {
        if (window.matchMedia('(max-width: 40rem)').matches) {
            this.element.open = false;
        }
    }
});

// The next page of a content list, as you come near its end: its rows are appended (a Turbo Stream).
// Without JavaScript, or until then, the link goes to the next page.
application.register('infinite-scroll', class extends Controller {
    static values = { url: String };

    connect() {
        this.element.closest('.content-list__footer')?.classList.add('is-infinite');
        this.observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                this.load();
            }
        }, { rootMargin: '400px' });
        this.observer.observe(this.element);
        this.element.addEventListener('click', this.click);
    }

    disconnect() {
        this.observer?.disconnect();
        this.element.removeEventListener('click', this.click);
    }

    click = (event) => {
        event.preventDefault();
        this.load();
    };

    async load() {
        if (this.loading) {
            return;
        }

        this.loading = true;
        this.element.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'text/vnd.turbo-stream.html' } });

            if (response.ok) {
                window.Turbo.renderStreamMessage(await response.text());
            }
        } finally {
            this.loading = false;
            this.element.removeAttribute('aria-busy');
        }
    }
});

// The Markdown editor: CodeMirror, loaded when a page has one, written as a manuscript: the text set
// as it reads, with changes to what's live shown in it, and "/" for headings, images and links. A
// toolbar shows while it's being written. Without JavaScript, or until it loads, the textarea is the
// editor.
application.register('markdown-editor', class extends Controller {
    static targets = ['source'];

    // The toolbar's labels come translated from the page: { toolbar, bold, italic, … }. original: the
    // live text, which changes are shown against; placeholder: what an empty manuscript says.
    static values = { label: String, labels: Object, placeholder: String, original: String };

    static buttons = [['bold', 'B'], ['italic', 'I'], ['heading', 'H'], ['link', '🔗'], ['list', '•'], ['quote', '❝'], ['code', '<>']];

    async connect() {
        const { createEditor, commands } = await import('./editor.js');

        if (!this.element.isConnected) {
            return;
        }

        this.view = createEditor(this.sourceTarget, this.labelValue || this.sourceTarget.labels?.[0]?.textContent?.trim() || '', {
            placeholder: this.placeholderValue,
            original: this.hasOriginalValue ? this.originalValue : null,
            labels: this.labelsValue,
            onUpdate: (update) => this.dispatch('update', { detail: { view: this.view, update } }),
        });
        this.toolbar = document.createElement('div');
        this.toolbar.className = 'markdown-toolbar';
        this.toolbar.setAttribute('role', 'toolbar');
        this.toolbar.setAttribute('aria-label', this.labelsValue.toolbar ?? 'Formatting');

        for (const [command, text] of this.constructor.buttons) {
            const label = this.labelsValue[command] ?? command;
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = text;
            button.title = label;
            button.setAttribute('aria-label', label);
            button.addEventListener('click', () => commands[command](this.view));
            this.toolbar.append(button);
        }

        this.element.prepend(this.toolbar);
        this.dispatch('ready', { detail: { view: this.view } });
    }

    disconnect() {
        // Turbo keeps a copy of the page: leave it as it was without the editor.
        this.toolbar?.remove();
        this.view?.destroy();
        this.view?.dom.remove();

        if (this.hasSourceTarget) {
            this.sourceTarget.hidden = false;
        }
    }
});

// The manuscript's margin: notes (what the checks found, comments) beside their lines, and commenting
// on selected text. Without JavaScript, the notes are a list under the text.
application.register('manuscript', class extends Controller {
    static targets = ['notes', 'commenter', 'commentText', 'quote', 'line'];

    connect() {
        this.element.addEventListener('markdown-editor:ready', this.ready);
        this.element.addEventListener('markdown-editor:update', this.updated);
        window.addEventListener('resize', this.place);
    }

    disconnect() {
        this.element.removeEventListener('markdown-editor:ready', this.ready);
        this.element.removeEventListener('markdown-editor:update', this.updated);
        window.removeEventListener('resize', this.place);
    }

    ready = (event) => {
        this.view = event.detail.view;
        this.element.classList.toggle('has-margin', this.hasNotesTarget);
        this.element.querySelectorAll('[data-action="manuscript#go"]').forEach((button) => { button.hidden = false; });
        this.place();
    };

    updated = (event) => {
        const { update } = event.detail;

        if (update.geometryChanged || update.docChanged || update.viewportChanged) {
            this.place();
        }

        if (update.selectionSet || update.focusChanged) {
            this.offerComment();
        }
    };

    // Each note at the top of its line, below the one before it when they'd overlap.
    place = () => {
        if (!this.view || !this.hasNotesTarget) {
            return;
        }

        // On narrow screens the notes are a list under the text: nothing to place.
        if (getComputedStyle(this.notesTarget.firstElementChild ?? this.notesTarget).position !== 'absolute') {
            this.notesTarget.style.minHeight = '';
            [...this.notesTarget.children].forEach((note) => { note.style.top = ''; });

            return;
        }

        const offset = this.view.dom.getBoundingClientRect().top - this.element.getBoundingClientRect().top;
        let next = 0;

        for (const note of this.notesTarget.children) {
            const line = Number(note.dataset.line || 0);
            const top = line > 0 && line <= this.view.state.doc.lines ? offset + this.view.lineBlockAt(this.view.state.doc.line(line).from).top : next;
            const at = Math.max(top, next);
            note.style.top = `${at}px`;
            next = at + note.offsetHeight + 8;
        }

        this.notesTarget.style.minHeight = `${next}px`;
    };

    go(event) {
        const line = Number(event.currentTarget.dataset.line);

        if (this.view && line > 0 && line <= this.view.state.doc.lines) {
            const { from } = this.view.state.doc.line(line);
            this.view.dispatch({ selection: { anchor: from }, scrollIntoView: true });
            this.view.focus();
        }
    }

    // Text selected on one line can be commented on, at the selection.
    offerComment() {
        if (!this.hasCommenterTarget || !this.view) {
            return;
        }

        const range = this.view.state.selection.main;
        const doc = this.view.state.doc;
        const quote = doc.sliceString(range.from, range.to).trim();
        const line = doc.lineAt(range.from);

        if (quote === '' || range.to > line.to || quote.length > 500) {
            if (!this.commenterTarget.contains(document.activeElement)) {
                this.commenterTarget.hidden = true;
            }

            return;
        }

        this.quoteTarget.value = quote;
        this.lineTarget.value = String(line.number);
        this.commentTextTarget.placeholder = this.commentTextTarget.placeholder.replace(/“.*”/, `“${quote.length > 40 ? `${quote.slice(0, 40)}…` : quote}”`);
        const coords = this.view.coordsAtPos(range.to);
        const box = this.element.getBoundingClientRect();
        this.commenterTarget.hidden = false;
        this.commenterTarget.style.top = coords ? `${coords.bottom - box.top + 6}px` : '0px';
    }
});

// The editor: checks, where the item will appear and the preview, as it's written; autosave of changes
// to published items; focus mode.
application.register('content-editor', class extends Controller {
    static targets = ['form', 'body', 'preview', 'previewBody', 'previewButton', 'id', 'pendingVersion', 'status', 'checks', 'badge', 'placement', 'focusButton'];

    // autosave: changes to a published item are saved as the editor types (as a pending revision).
    static values = { previewUrl: String, checkUrl: String, placementUrl: String, autosave: Boolean, saved: String, unsaved: String, conflict: String, toCheck: String };

    get form() {
        return this.hasFormTarget ? this.formTarget : this.element;
    }

    connect() {
        this.update = this.debounce(() => this.render(), 500);
        this.bodyTarget.addEventListener('input', this.update);

        // The content checks and where it will appear, for what's in the form.
        this.refresh = this.debounce(() => this.check(), 1000);
        this.form.addEventListener('input', this.refresh);
        this.form.addEventListener('change', this.refresh);

        if (this.autosaveValue) {
            this.scheduleSave = this.debounce(() => this.save(), 2000);
            this.form.addEventListener('input', this.scheduleSave);
            this.form.addEventListener('change', this.scheduleSave);
            // A button saves anyway: no autosave races it.
            this.form.addEventListener('submit', this.stopSaving);
        }

        if (this.hasPreviewButtonTarget) {
            this.previewButtonTarget.addEventListener('click', this.toggle);
            this.previewButtonTarget.setAttribute('aria-expanded', String(!this.previewTarget.hidden));
            this.previewButtonTarget.setAttribute('aria-controls', this.previewTarget.id || (this.previewTarget.id = 'editor-preview'));
        }

        if (this.hasFocusButtonTarget) {
            this.focusButtonTarget.hidden = false;
            this.setFocus(this.stored('quire-admin-focus') === '1');
        }
    }

    disconnect() {
        this.bodyTarget.removeEventListener('input', this.update);
        this.form.removeEventListener('input', this.refresh);
        this.form.removeEventListener('change', this.refresh);
        this.previewButtonTarget?.removeEventListener('click', this.toggle);

        if (this.scheduleSave) {
            this.form.removeEventListener('input', this.scheduleSave);
            this.form.removeEventListener('change', this.scheduleSave);
            this.form.removeEventListener('submit', this.stopSaving);
        }
    }

    // Focus mode: the manuscript alone, without versions, notes on the side and details.
    focus() {
        const on = this.focusButtonTarget.getAttribute('aria-pressed') !== 'true';
        this.setFocus(on);

        try {
            localStorage.setItem('quire-admin-focus', on ? '1' : '0');
        } catch {
            // A preference of this browser only; without storage, it's forgotten.
        }
    }

    setFocus(on) {
        this.element.classList.toggle('is-focused', on);
        this.focusButtonTarget.setAttribute('aria-pressed', String(on));
    }

    stored(key) {
        try {
            return localStorage.getItem(key);
        } catch {
            return null;
        }
    }

    async check() {
        const form = new FormData(this.form);
        form.delete('intent');
        const [checks, placement] = await Promise.all([
            this.hasChecksTarget ? fetch(this.checkUrlValue, { method: 'POST', body: form, headers: { Accept: 'text/html' } }) : null,
            this.hasPlacementTarget && this.placementUrlValue ? fetch(this.placementUrlValue, { method: 'POST', body: form, headers: { Accept: 'text/html' } }) : null,
        ]);

        if (checks?.ok) {
            this.checksTarget.innerHTML = await checks.text();
            const summary = this.checksTarget.querySelector('[data-findings]');
            const count = Number(summary?.dataset.findings ?? 0);

            if (this.hasBadgeTarget) {
                this.badgeTarget.hidden = count === 0;
                this.badgeTarget.className = `q-tag q-tag--${summary?.dataset.level ?? 'warn'} editor-bar__badge`;
                this.badgeTarget.textContent = (this.toCheckValue || '{count} to check').replace('{count}', String(count));
            }
        }

        if (placement?.ok) {
            this.placementTarget.innerHTML = await placement.text();
        }
    }

    stopSaving = () => {
        this.stopped = true;
    };

    async save() {
        if (this.stopped || this.saving) {
            return;
        }

        this.saving = true;
        const form = new FormData(this.form);
        form.set('intent', 'autosave');

        try {
            const response = await fetch(this.form.action, { method: 'POST', body: form, headers: { Accept: 'application/json' } });

            if (response.ok && response.headers.get('Content-Type')?.includes('json')) {
                const saved = await response.json();
                this.pendingVersionTarget.value = saved.pending_version;

                if (this.hasStatusTarget) {
                    this.statusTarget.textContent = this.savedValue.replace('{time}', saved.saved_at);
                }
            } else if (this.hasStatusTarget) {
                this.statusTarget.textContent = response.status === 409 ? this.conflictValue : this.unsavedValue.replace('{problem}', `HTTP ${response.status}`);
            }
        } catch (error) {
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = this.unsavedValue.replace('{problem}', error.message);
            }
        } finally {
            this.saving = false;
        }
    }

    toggle = (event) => {
        event.preventDefault();
        this.previewTarget.hidden = !this.previewTarget.hidden;
        event.currentTarget.setAttribute('aria-expanded', String(!this.previewTarget.hidden));

        if (!this.previewTarget.hidden) {
            this.render();
            this.previewTarget.scrollIntoView({ block: 'nearest' });
        }
    };

    async render() {
        if (this.previewTarget.hidden) {
            return;
        }

        const form = new FormData();
        form.append('_token', this.form.querySelector('input[name="_token"]').value);
        form.append('body', this.bodyTarget.value);

        if (this.hasIdTarget) {
            form.append('id', this.idTarget.value);
        }

        const response = await fetch(this.previewUrlValue, { method: 'POST', body: form, headers: { Accept: 'text/html' } });

        if (response.ok) {
            this.previewBodyTarget.innerHTML = await response.text();
        }
    }

    debounce(callback, wait) {
        let timer;

        return () => {
            clearTimeout(timer);
            timer = setTimeout(callback, wait);
        };
    }
});

// A relation's list gets a search box: matching items are added to the list to choose from.
application.register('relation-picker', class extends Controller {
    static targets = ['select'];

    // Labels come translated from the page: { search, found } ("found" has "{count}" in it).
    static values = { url: String, labels: Object };

    connect() {
        this.search = document.createElement('input');
        this.search.type = 'search';
        this.search.className = 'relation-field__search';
        this.search.setAttribute('aria-label', `${this.labelsValue.search ?? 'Search'}: ${this.selectTarget.labels?.[0]?.textContent?.trim() ?? ''}`);
        this.search.setAttribute('aria-controls', this.selectTarget.id);
        this.status = document.createElement('p');
        this.status.className = 'visually-hidden';
        this.status.setAttribute('aria-live', 'polite');
        this.selectTarget.before(this.search);
        this.selectTarget.after(this.status);

        let timer;
        this.search.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => this.find(), 250);
        });
    }

    disconnect() {
        this.search?.remove();
        this.status?.remove();
    }

    async find() {
        const terms = this.search.value.trim();

        if (terms === '') {
            return;
        }

        const response = await fetch(`${this.urlValue}&q=${encodeURIComponent(terms)}`, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            return;
        }

        const results = await response.json();
        const present = new Set([...this.selectTarget.options].map((option) => option.value));

        for (const result of results.reverse()) {
            if (!present.has(result.id)) {
                // After the empty choice of a single relation, before the others.
                const position = this.selectTarget.multiple ? 0 : 1;
                this.selectTarget.add(new Option(result.label, result.id), this.selectTarget.options[position] ?? null);
            }
        }

        this.status.textContent = (this.labelsValue.found ?? '{count} found').replace('{count}', String(results.length));
    }
});

// Moves the focus to an element when it appears, e.g. the list of a form's errors.
application.register('focus', class extends Controller {
    connect() {
        this.element.focus();
    }
});

// Narrows a role's abilities to those matching what's typed, by label or name. Without JavaScript, all show.
application.register('ability-filter', class extends Controller {
    static targets = ['tools', 'input', 'item', 'section'];

    connect() {
        this.toolsTarget.hidden = false;
    }

    filter() {
        const words = this.inputTarget.value.toLowerCase().split(/\s+/).filter(Boolean);

        for (const item of this.itemTargets) {
            item.hidden = !words.every((word) => (item.dataset.text ?? '').includes(word));
        }

        for (const section of this.sectionTargets) {
            const items = section.querySelectorAll('[data-ability-filter-target~="item"]');
            section.hidden = items.length > 0 && [...items].every((item) => item.hidden);
        }
    }
});

// Copies a command to the clipboard. Without JavaScript, or a clipboard, the button stays hidden.
application.register('copy', class extends Controller {
    static targets = ['source', 'button', 'status'];
    static values = { copied: String };

    connect() {
        this.buttonTarget.hidden = !navigator.clipboard;
    }

    async copy() {
        await navigator.clipboard.writeText(this.sourceTarget.textContent);
        this.statusTarget.textContent = this.copiedValue;
        setTimeout(() => { this.statusTarget.textContent = ''; }, 3000);
    }
});

// Inviting people: how many invitations the button sends, and what the role chosen may do.
application.register('invite-form', class extends Controller {
    static targets = ['emails', 'role', 'sentence', 'send'];
    static values = { labels: Object };

    connect() {
        this.count();
    }

    count() {
        const emails = this.emailsTarget.value.split(/[\s,;]+/).filter((email) => email.includes('@'));
        const labels = this.labelsValue;
        this.sendTarget.textContent = emails.length === 0 ? labels.none : emails.length === 1 ? labels.one : labels.other.replace('%count%', emails.length);
    }

    describe() {
        this.sentenceTarget.textContent = this.roleTarget.selectedOptions[0]?.dataset.sentence ?? '';
    }
});

// The preferences sheet: each change is saved as it's made and shows at once; the language and
// "Under the hood" change what the server writes, so the page loads again for those.
application.register('preferences', class extends Controller {
    static targets = ['form'];

    async save(event) {
        const form = this.formTarget;
        const root = document.documentElement;
        const value = (name) => form.querySelector(`[name="${name}"]:checked`)?.value;
        const attribute = (name, on) => (on ? root.setAttribute(name, on) : root.removeAttribute(name));

        attribute('data-theme', value('theme') === 'system' ? null : value('theme'));
        attribute('data-contrast', value('contrast') === 'high' ? 'high' : null);
        attribute('data-text', value('text') === 'md' ? null : value('text'));
        attribute('data-density', value('density') === 'compact' ? 'compact' : null);
        attribute('data-motion', value('motion') === 'reduce' ? 'reduce' : null);

        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Quire-Request': 'fetch' } });

        if (!response.ok) {
            form.requestSubmit();
        } else if (event.target.closest('[data-reload]')) {
            window.Turbo ? window.Turbo.visit(window.location.href, { action: 'replace' }) : window.location.reload();
        }
    }
});

// Keyboard shortcuts, like those of mail and code hosts: "g" then a letter goes somewhere, "j"/"k" move
// through a list, "x" selects, Ctrl/⌘+S saves and Ctrl/⌘+Shift+P publishes, "?" shows them all.
application.register('shortcuts', class extends Controller {
    static targets = ['preferences'];
    static values = { urls: Object };

    preferences(event) {
        if (!this.hasPreferencesTarget) {
            return;
        }

        event?.preventDefault();
        this.element.querySelectorAll('details.q-menu[open]').forEach((menu) => menu.removeAttribute('open'));
        this.preferencesTarget.showModal();
    }

    key(event) {
        const command = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (command && !event.altKey && (key === 's' || (key === 'p' && event.shiftKey))) {
            const button = key === 's'
                ? document.querySelector('button[name=intent][value=save], button[name=intent][value=save_changes]')
                : document.querySelector('.editor-bar__publish');

            if (button) {
                event.preventDefault();
                button.click();
            }

            return;
        }

        const typing = event.target.closest?.('input, textarea, select, [contenteditable], .cm-editor');

        if (command || event.altKey || typing || event.defaultPrevented) {
            return;
        }

        if (this.pending === 'g') {
            this.pending = null;
            const url = { h: this.urlsValue.home, c: this.urlsValue.content, p: this.urlsValue.people }[key];

            if (url) {
                event.preventDefault();
                window.Turbo ? window.Turbo.visit(url) : (window.location.href = url);
            }

            return;
        }

        switch (event.key) {
            case 'g':
                this.pending = 'g';
                clearTimeout(this.timer);
                this.timer = setTimeout(() => (this.pending = null), 1500);
                break;
            case '?':
                event.preventDefault();
                this.preferences();
                this.preferencesTarget?.querySelector('.prefs-keys')?.scrollIntoView({ block: 'nearest' });
                break;
            case 'n':
                if (this.urlsValue.new) {
                    event.preventDefault();
                    window.Turbo ? window.Turbo.visit(this.urlsValue.new) : (window.location.href = this.urlsValue.new);
                }
                break;
            case 'j':
            case 'k':
                this.move(event, event.key === 'j' ? 1 : -1);
                break;
            case 'x':
                this.select(event);
                break;
        }
    }

    // The rows of the list on the page: each one's first link.
    rows() {
        return [...document.querySelectorAll('main tbody tr')].filter((row) => row.querySelector('a'));
    }

    move(event, step) {
        const rows = this.rows();

        if (rows.length === 0) {
            return;
        }

        event.preventDefault();
        const current = rows.findIndex((row) => row.contains(document.activeElement));
        const next = rows[Math.min(rows.length - 1, Math.max(0, current === -1 ? 0 : current + step))];
        next.querySelector('a').focus();
        next.scrollIntoView({ block: 'nearest' });
    }

    select(event) {
        const row = this.rows().find((candidate) => candidate.contains(document.activeElement));
        const box = row?.querySelector('input[type=checkbox]');

        if (box) {
            event.preventDefault();
            box.click();
        }
    }
});

// The intent bar: Ctrl/⌘+K, or the search box at the top, opens it; what's typed is understood as it's
// typed, as what to do (a plan, done with Enter) and what it names (opened with the arrows and Enter).
application.register('intent', class extends Controller {
    static targets = ['dialog', 'input', 'body'];
    static values = { url: String };

    shortcut(event) {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            this.open();
        }
    }

    open(event) {
        if (this.dialogTarget.open) {
            return;
        }

        const trigger = event?.target instanceof HTMLInputElement ? event.target : null;
        this.dialogTarget.showModal();
        this.inputTarget.value = trigger?.value ?? this.inputTarget.value;
        this.inputTarget.focus();
        this.inputTarget.select();
        this.search();
    }

    closed() {
        this.element.querySelector('.q-intent-trigger input')?.blur();
    }

    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetch(), 140);
    }

    async fetch() {
        this.asked = (this.asked ?? 0) + 1;
        const asked = this.asked;
        const url = `${this.urlValue}?q=${encodeURIComponent(this.inputTarget.value.trim())}`;
        const response = await fetch(url, { headers: { 'X-Quire-Request': 'fetch' } });

        // Only the answer to what's typed now.
        if (response.ok && asked === this.asked) {
            this.bodyTarget.innerHTML = await response.text();
        }
    }

    // The places to go: the plan's buttons, then what was found.
    choices() {
        return [...this.bodyTarget.querySelectorAll('.intent-actions button, .intent-actions a, .intent-found__item')];
    }

    key(event) {
        const choices = this.choices();


        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const index = choices.indexOf(document.activeElement);
            const next = event.key === 'ArrowDown' ? Math.min(choices.length - 1, index + 1) : index - 1;
            (next < 0 ? this.inputTarget : choices[next])?.focus();
        } else if (event.key === 'Enter' && event.target === this.inputTarget) {
            event.preventDefault();
            clearTimeout(this.timer);
            // What's on screen may be for an earlier text: understand the latest first.
            this.fetch().then(() => this.choices()[0]?.click());
        }
    }
});
