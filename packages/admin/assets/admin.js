// The admin's JavaScript. Pages work without it; Turbo makes moving between them fast, and Stimulus
// controllers add behaviour to elements (data-controller="…").
// Both are npm packages, which the site downloads (see "extra.quire.importmap" in composer.json).
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

// Content lists: a checkbox that selects every row, shown only with JavaScript, where it works.
application.register('content-list', class extends Controller {
    static targets = ['all', 'item'];

    connect() {
        if (this.hasAllTarget) {
            this.allTarget.hidden = false;
            this.update();
        }
    }

    toggleAll() {
        this.itemTargets.forEach((checkbox) => { checkbox.checked = this.allTarget.checked; });
        this.update();
    }

    update() {
        if (!this.hasAllTarget) {
            return;
        }

        const checked = this.itemTargets.filter((checkbox) => checkbox.checked).length;
        this.allTarget.checked = checked > 0 && checked === this.itemTargets.length;
        this.allTarget.indeterminate = checked > 0 && checked < this.itemTargets.length;
    }
});
