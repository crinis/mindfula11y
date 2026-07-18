/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2026  Mindful Markup, Felix Spittel
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

import Notification from '@typo3/backend/notification.js';
import DocumentService from '@typo3/core/document-service.js';
import RegularEvent from '@typo3/core/event/regular-event.js';
import { lll } from '@typo3/core/lit-helper.js';
import '@typo3/backend/element/spinner-element.js';
import type { GenerateAltTextDemand } from '../lib/types.js';
import { AltTextApi } from './alt-text-api.js';
import { errorView } from './request-error.js';

/*
 * Light DOM by design: this is not a component — it augments the core-rendered
 * FormEngine fieldControl button and input of the alt-text field
 * (Classes/Form/FieldControl/GenerateAltTextControl.php) and must stay in the
 * document tree. Sanctioned exception 1 in AGENTS.md §3.B.
 */

/**
 * FormEngine fieldControl behavior: generates alternative text via the
 * mindfula11y AJAX endpoint and writes it into the field's visible input.
 * Instantiated by the control's JavaScriptModuleInstruction with the control
 * anchor's id selector and the signed generation demand.
 */
export class GenerateAltTextControl {
    private busy: boolean = false;

    constructor(selector: string, demand: GenerateAltTextDemand) {
        DocumentService.ready().then(() => {
            const control = document.querySelector<HTMLAnchorElement>(selector);
            const itemName = control?.dataset.itemName ?? '';
            const input = document.querySelector<HTMLInputElement>(
                `[data-formengine-input-name="${CSS.escape(itemName)}"]`,
            );
            if (control === null || input === null) {
                console.error(`Generate-alt-text control or its input not found for selector "${selector}".`);
                return;
            }
            new RegularEvent('click', (event: Event): void => {
                event.preventDefault();
                void this.handleGenerate(control, input, demand);
            }).bindTo(control);
        });
    }

    private async handleGenerate(
        control: HTMLAnchorElement,
        input: HTMLInputElement,
        demand: GenerateAltTextDemand,
    ): Promise<void> {
        if (this.busy) {
            return;
        }
        this.busy = true;
        const icon = control.firstElementChild;
        const spinner = document.createElement('typo3-backend-spinner');
        spinner.setAttribute('size', 'small');
        control.setAttribute('aria-busy', 'true');
        control.replaceChildren(spinner);
        try {
            const altText = await new AltTextApi().generateAltText(demand);
            input.value = altText;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            Notification.success(
                lll('mindfula11y.altText.generate.success'),
                lll('mindfula11y.altText.generate.success.description'),
            );
        } catch (error) {
            const view = errorView(error, 'mindfula11y.altText.generate.error.unknown');
            Notification.error(view.title, view.description);
        } finally {
            if (icon !== null) {
                control.replaceChildren(icon);
            }
            control.removeAttribute('aria-busy');
            this.busy = false;
        }
    }
}

export default GenerateAltTextControl;
