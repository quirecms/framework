// The admin's JavaScript. Pages work without it; Turbo makes moving between them fast, and Stimulus
// controllers add behaviour to elements (data-controller="…").
import './vendor/turbo.js';
import { Application, Controller } from './vendor/stimulus.js';

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
