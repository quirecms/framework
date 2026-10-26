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

// The Markdown editor: CodeMirror, loaded when a page has one, with a toolbar. Without JavaScript, or
// until it loads, the textarea is the editor.
application.register('markdown-editor', class extends Controller {
    static targets = ['source'];

    // The toolbar's labels come translated from the page: { toolbar, bold, italic, … }.
    static values = { label: String, labels: Object };

    static buttons = [['bold', 'B'], ['italic', 'I'], ['heading', 'H'], ['link', '🔗'], ['list', '•'], ['quote', '❝'], ['code', '<>']];

    async connect() {
        const { createEditor, commands } = await import('./editor.js');

        if (!this.element.isConnected) {
            return;
        }

        this.view = createEditor(this.sourceTarget, this.labelValue || this.sourceTarget.labels?.[0]?.textContent?.trim() || '');
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

// The editor's live preview: the text as the site shows it, rendered by Quire as you type.
application.register('content-editor', class extends Controller {
    static targets = ['body', 'preview', 'previewBody', 'previewButton', 'id', 'pendingVersion', 'status', 'savedAt', 'checks'];

    // autosave: changes to a published item are saved as the editor types (as a pending revision).
    static values = { previewUrl: String, checkUrl: String, autosave: Boolean, saved: String, unsaved: String, conflict: String };

    connect() {
        this.update = this.debounce(() => this.render(), 500);
        this.bodyTarget.addEventListener('input', this.update);

        // The content checks, run on what's in the form.
        if (this.hasChecksTarget && this.checkUrlValue) {
            this.recheck = this.debounce(() => this.check(), 1000);
            this.element.addEventListener('input', this.recheck);
            this.element.addEventListener('change', this.recheck);
        }

        if (this.autosaveValue) {
            this.scheduleSave = this.debounce(() => this.save(), 2000);
            this.element.addEventListener('input', this.scheduleSave);
            this.element.addEventListener('change', this.scheduleSave);
            // A button saves anyway: no autosave races it.
            this.element.addEventListener('submit', this.stopSaving);
        }

        if (this.hasPreviewButtonTarget) {
            this.previewButtonTarget.addEventListener('click', this.toggle);
            this.previewButtonTarget.setAttribute('aria-expanded', String(!this.previewTarget.hidden));
            this.previewButtonTarget.setAttribute('aria-controls', this.previewTarget.id || (this.previewTarget.id = 'editor-preview'));
        }
    }

    disconnect() {
        this.bodyTarget.removeEventListener('input', this.update);

        if (this.recheck) {
            this.element.removeEventListener('input', this.recheck);
            this.element.removeEventListener('change', this.recheck);
        }

        this.previewButtonTarget?.removeEventListener('click', this.toggle);

        if (this.scheduleSave) {
            this.element.removeEventListener('input', this.scheduleSave);
            this.element.removeEventListener('change', this.scheduleSave);
            this.element.removeEventListener('submit', this.stopSaving);
        }
    }

    async check() {
        const form = new FormData(this.element);
        form.delete('intent');
        const response = await fetch(this.checkUrlValue, { method: 'POST', body: form, headers: { Accept: 'text/html' } });

        if (response.ok) {
            this.checksTarget.innerHTML = await response.text();
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
        const form = new FormData(this.element);
        form.set('intent', 'autosave');

        try {
            const response = await fetch(this.element.action, { method: 'POST', body: form, headers: { Accept: 'application/json' } });

            if (response.ok && response.headers.get('Content-Type')?.includes('json')) {
                const saved = await response.json();
                this.pendingVersionTarget.value = saved.pending_version;
                this.statusTarget.textContent = this.savedValue.replace('{time}', saved.saved_at);
            } else {
                this.statusTarget.textContent = response.status === 409 ? this.conflictValue : this.unsavedValue.replace('{problem}', `HTTP ${response.status}`);
            }
        } catch (error) {
            this.statusTarget.textContent = this.unsavedValue.replace('{problem}', error.message);
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
        }
    };

    async render() {
        if (this.previewTarget.hidden) {
            return;
        }

        const form = new FormData();
        form.append('_token', this.element.querySelector('input[name="_token"]').value);
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
