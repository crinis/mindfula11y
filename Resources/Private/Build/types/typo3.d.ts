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

/**
 * Hand-written ambient declarations for the TYPO3 core/backend ES modules this
 * extension imports at runtime through the core importmap. TYPO3 publishes no
 * type packages — keep these minimal and verify against the shipped module in
 * vendor/typo3/… when adding a member.
 */

declare module '@typo3/core/ajax/ajax-request.js' {
    import type AjaxResponse from '@typo3/core/ajax/ajax-response.js';

    export default class AjaxRequest {
        constructor(url: string);
        withQueryArguments(queryArguments: string | Record<string, unknown> | URLSearchParams): AjaxRequest;
        get(init?: RequestInit): Promise<AjaxResponse>;
        post(data: BodyInit | Record<string, unknown> | null, init?: RequestInit): Promise<AjaxResponse>;
        put(data: BodyInit | Record<string, unknown>, init?: RequestInit): Promise<AjaxResponse>;
        delete(data?: BodyInit | Record<string, unknown>, init?: RequestInit): Promise<AjaxResponse>;
    }
}

declare module '@typo3/core/ajax/ajax-response.js' {
    export default class AjaxResponse {
        readonly response: Response;
        resolve<T = unknown>(expectedType?: string): Promise<T>;
        raw(): Response;
        dereference(): Promise<AjaxResponse>;
    }
}

declare module '@typo3/core/lit-helper.js' {
    import type { TemplateResult } from 'lit';

    export const lll: (key: string, ...args: Array<string | number>) => string;
    export const styleTag: (styles: unknown, host?: Window) => TemplateResult;
    export const renderHTML: (result: TemplateResult) => string;
    export const renderNodes: (result: TemplateResult) => NodeListOf<ChildNode>;
    export const classesArrayToClassInfo: (classes: string[]) => Record<string, boolean>;
}

declare module '@typo3/core/document-service.js' {
    const documentService: {
        ready(): Promise<Document>;
    };
    export default documentService;
}

declare module '@typo3/core/event/regular-event.js' {
    export default class RegularEvent {
        constructor(eventName: string, callback: (event: Event, target?: Element) => void);
        bindTo(element: Element | Document): void;
        delegateTo(element: Element | Document, selector: string): void;
        release(): void;
    }
}

declare module '@typo3/backend/notification.js' {
    interface NotificationAction {
        label: string;
        action?: unknown;
    }

    const notification: {
        notice(title: string, message?: string, duration?: number, actions?: NotificationAction[]): void;
        info(title: string, message?: string, duration?: number, actions?: NotificationAction[]): void;
        success(title: string, message?: string, duration?: number, actions?: NotificationAction[]): void;
        warning(title: string, message?: string, duration?: number, actions?: NotificationAction[]): void;
        error(title: string, message?: string, duration?: number, actions?: NotificationAction[]): void;
    };
    export default notification;
}

declare module '@typo3/backend/ajax-data-handler.js' {
    const ajaxDataHandler: {
        process(parameters: string | Record<string, unknown>): Promise<{ hasErrors: boolean; messages: unknown[] }>;
    };
    export default ajaxDataHandler;
}

/** Side-effect import registering the <typo3-backend-icon> custom element. */
declare module '@typo3/backend/element/icon-element.js';

/** Side-effect import registering the <typo3-backend-spinner> custom element. */
declare module '@typo3/backend/element/spinner-element.js';

/**
 * Backend globals provided by the TYPO3 page renderer.
 *
 * This file must stay a global script (no top-level import/export): in a
 * module file, `declare module 'x'` turns into a module augmentation that has
 * to resolve `x` — as a script it declares the ambient modules above.
 */
interface Window {
    TYPO3: {
        settings: {
            ajaxUrls: Record<string, string>;
        };
        lang: Record<string, string>;
    };
    litNonce?: string;
}

declare const TYPO3: Window['TYPO3'];
