import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

export const root = new URL('../../../../', import.meta.url);

const STIMULUS_IMPORT = "import { Controller } from '@hotwired/stimulus';";

// Node cannot resolve the import map's bare specifier, and the controllers use nothing of Stimulus' base class: each test sets the element and values Stimulus would.
export async function loadController(name) {
    const source = readFileSync(new URL(`assets/controllers/${name}_controller.js`, root), 'utf8');
    assert.ok(source.includes(STIMULUS_IMPORT));
    const { default: controller } = await import(
        'data:text/javascript,' + encodeURIComponent(source.replace(STIMULUS_IMPORT, 'class Controller {}'))
    );

    return controller;
}

export class FakeElement {
    attributes = new Map();
    classes = new Set();
    hidden = false;

    constructor(textContent = '') {
        this.textContent = textContent;
        this.classList = {
            contains: (name) => this.classes.has(name),
            toggle: (name, force = !this.classes.has(name)) => {
                if (force) {
                    this.classes.add(name);
                } else {
                    this.classes.delete(name);
                }

                return force;
            },
        };
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    toggleAttribute(name, force) {
        if (force) {
            this.setAttribute(name, '');
        } else {
            this.removeAttribute(name);
        }

        return force;
    }
}

export class FakeClick {
    defaultPrevented = false;
    propagationStopped = false;

    constructor(currentTarget = null) {
        this.currentTarget = currentTarget;
    }

    preventDefault() {
        this.defaultPrevented = true;
    }

    stopImmediatePropagation() {
        this.propagationStopped = true;
    }
}
