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

import { lll } from '@typo3/core/lit-helper.js';
import type { CSSResult, TemplateResult } from 'lit';
import { html, LitElement, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';
import { live } from 'lit/directives/live.js';
import '@typo3/backend/element/icon-element.js';
import '@typo3/backend/element/spinner-element.js';
import '../notice/notice.js';
import { LiveAnnouncer } from '../../lib/live-announcer.js';
import { renderNoticeBody } from '../../lib/status-render.js';
import type { GenerateAltTextDemand } from '../../lib/types.js';
import { AltTextService } from '../../service/alt-text-service.js';
import { RecordService } from '../../service/record-service.js';
import { type ErrorView, errorView } from '../../service/request-error.js';
import { baseStyles } from '../../styles/base-styles.js';
import buttonStyles from '../../styles/button.css.js';
import noticeStyles from '../../styles/notice.css.js';
import componentStyles from './altless-file-reference.css.js';

const DECORATIVE_FIELD = 'tx_mindfula11y_decorative';

/**
 * One image file reference lacking alternative text in the "Missing alt text"
 * module: preview, editable alt-text field with optional AI generation, a
 * per-reference decorative toggle, and a save action using the core DataHandler.
 *
 * Feedback renders inline (notices + pre-rendered live region) instead of
 * toasts: the card sits in a long list, and a toast cannot say which item it
 * belongs to. Permission gating arrives via conditional attributes — without
 * `record-edit-link` the card is read-only, without `generate-alt-text-demand`
 * there is no Generate action.
 */
@customElement('mindfula11y-altless-file-reference')
export class AltlessFileReference extends LitElement {
    static override styles: CSSResult[] = [...baseStyles, noticeStyles, buttonStyles, componentStyles];

    @property({ attribute: 'preview-url' }) previewUrl: string = '';
    @property({ attribute: 'original-url' }) originalUrl: string = '';
    @property({ type: Number }) uid: number = 0;
    @property({ attribute: 'record-edit-link' }) recordEditLink: string = '';
    @property({ attribute: 'record-edit-link-label' }) recordEditLinkLabel: string = '';
    @property({ attribute: 'decorative-editable', type: Boolean }) decorativeEditable: boolean = false;
    @property({ attribute: 'generate-alt-text-demand', type: Object })
    generateAltTextDemand: GenerateAltTextDemand | null = null;
    @property({ attribute: 'fallback-alternative' }) fallbackAlternative: string = '';

    @state() private value: string = '';
    @state() private lastSavedValue: string = '';
    @state() private decorative: boolean = false;
    @state() private lastSavedDecorative: boolean = false;
    @state() private busy: 'idle' | 'generating' | 'saving' = 'idle';
    @state() private actionError: ErrorView | null = null;
    @state() private saved: boolean = false;

    private readonly altTextService = new AltTextService();
    private readonly recordService = new RecordService();
    private readonly announcer: LiveAnnouncer = new LiveAnnouncer(this);

    override render(): TemplateResult {
        return html`<div class="card">
            <div class="body">
                ${this.renderPreview()}
                <div class="content">
                    ${this.renderEditor()} ${this.renderFallback()}
                    ${
                        this.recordEditLink !== ''
                            ? html`<p class="footer">
                                  <a class="edit" href=${this.recordEditLink}>${this.recordEditLinkLabel}</a>
                              </p>`
                            : nothing
                    }
                </div>
            </div>
        </div>`;
    }

    private renderPreview(): TemplateResult | typeof nothing {
        if (this.previewUrl === '') {
            return nothing;
        }
        const image = html`<img class="image" src=${this.previewUrl} alt=${this.imageAlternative()} />`;
        if (this.originalUrl === '') {
            return html`<div class="preview">${image}</div>`;
        }
        return html`<div class="preview">
            <a href=${this.originalUrl} target="_blank" rel="noreferrer">
                ${image}
                <span class="sr-only">${lll('mindfula11y.altText.opensNewTab')}</span>
            </a>
        </div>`;
    }

    private renderEditor(): TemplateResult | typeof nothing {
        if (this.recordEditLink === '') {
            return nothing;
        }
        return html`<div class="editor">
            ${
                this.decorativeEditable
                    ? html`<div class="decorative-option">
                          <label class="decorative-label">
                              <input
                                  type="checkbox"
                                  .checked=${live(this.decorative)}
                                  ?disabled=${this.busy !== 'idle'}
                                  aria-describedby="decorative-description"
                                  @change=${this.handleDecorativeChange}
                              />
                              <span>${lll('mindfula11y.altText.decorative.label')}</span>
                          </label>
                          <p id="decorative-description" class="hint">
                              ${lll('mindfula11y.altText.decorative.description')}
                          </p>
                      </div>`
                    : nothing
            }
            ${
                this.decorative
                    ? nothing
                    : html`<label class="label" for="alt">${lll('mindfula11y.altText.altLabel')}</label>
                          <textarea
                              id="alt"
                              class="input"
                              rows="3"
                              .value=${live(this.value)}
                              placeholder=${lll('mindfula11y.altText.altPlaceholder')}
                              ?readonly=${this.busy !== 'idle'}
                              @input=${this.handleInput}
                          ></textarea>`
            }
            <div class="actions">
                ${
                    this.generateAltTextDemand !== null && !this.decorative
                        ? html`<button
                              type="button"
                              class="button"
                              aria-disabled=${this.busy !== 'idle' ? 'true' : nothing}
                              @click=${this.handleGenerate}
                          >
                              ${
                                  this.busy === 'generating'
                                      ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                                      : html`<typo3-backend-icon
                                            identifier="actions-refresh"
                                            size="small"
                                        ></typo3-backend-icon>`
}
                              ${lll('mindfula11y.altText.generate.button')}
                          </button>`
                        : nothing
                }
                <button
                    type="button"
                    class="button"
                    aria-disabled=${
                        this.busy !== 'idle' ||
                        (this.value === this.lastSavedValue && this.decorative === this.lastSavedDecorative)
                            ? 'true'
                            : nothing
                    }
                    @click=${this.handleSave}
                >
                    ${
                        this.busy === 'saving'
                            ? html`<typo3-backend-spinner size="small"></typo3-backend-spinner>`
                            : html`<typo3-backend-icon identifier="actions-save" size="small"></typo3-backend-icon>`
                    }
                    ${lll('mindfula11y.altText.save')}
                </button>
            </div>
            ${this.announcer.render()}
            <div class="status-region" role="status">
                ${
                    this.actionError !== null
                        ? html`<mindfula11y-notice class="status" state="danger">
                              ${renderNoticeBody(this.actionError)}
                          </mindfula11y-notice>`
                        : this.saved
                          ? html`<mindfula11y-notice class="status" state="success">
                                <span>${lll('mindfula11y.altText.save.success')}</span>
                            </mindfula11y-notice>`
                          : nothing
                }
            </div>
        </div>`;
    }

    private renderFallback(): TemplateResult | typeof nothing {
        if (this.fallbackAlternative === '') {
            return nothing;
        }
        return html`<mindfula11y-notice class="status" state="info">
            ${renderNoticeBody({ title: lll('mindfula11y.altText.fallbackAltLabel'), description: this.fallbackAlternative })}
        </mindfula11y-notice>`;
    }

    /** Alt for the preview itself: the drafted text or a generic image label. */
    private imageAlternative(): string {
        return this.value !== '' ? this.value : lll('mindfula11y.altText.imagePreview');
    }

    private handleInput(event: Event): void {
        this.value = (event.target as HTMLTextAreaElement).value;
        this.saved = false;
    }

    private handleDecorativeChange(event: Event): void {
        this.decorative = (event.target as HTMLInputElement).checked;
        this.saved = false;
    }

    private async handleGenerate(): Promise<void> {
        if (this.generateAltTextDemand === null || this.busy !== 'idle') {
            return;
        }
        this.busy = 'generating';
        this.actionError = null;
        this.saved = false;
        await this.announcer.announce(lll('mindfula11y.altText.generate.loading'));
        try {
            this.value = await this.altTextService.generateAltText(this.generateAltTextDemand);
            await this.announcer.announce(lll('mindfula11y.altText.generate.success'));
        } catch (error) {
            this.actionError = errorView(error, 'mindfula11y.altText.generate.error.unknown');
        } finally {
            this.busy = 'idle';
        }
    }

    private async handleSave(): Promise<void> {
        // Mirrors the button's aria-disabled condition: the control stays
        // focusable (a real `disabled` would blur a keyboard user to <body>
        // for the whole async window), so the click handler is the guard.
        if (
            this.busy !== 'idle' ||
            (this.value === this.lastSavedValue && this.decorative === this.lastSavedDecorative)
        ) {
            return;
        }
        this.busy = 'saving';
        this.actionError = null;
        try {
            const fields: Record<string, string> = {
                alternative: this.decorative ? '' : this.value,
            };
            if (this.decorativeEditable) {
                fields[DECORATIVE_FIELD] = this.decorative ? '1' : '0';
            }
            await this.recordService.updateFields('sys_file_reference', this.uid, fields);
            if (this.decorative) {
                this.value = '';
            }
            this.lastSavedValue = this.value;
            this.lastSavedDecorative = this.decorative;
            this.saved = true;
        } catch (error) {
            // RecordService throws RecordUpdateError (not a RequestError), so
            // this resolves to the same fallback title/description pair as before.
            this.actionError = errorView(error, 'mindfula11y.altText.save.error');
        } finally {
            this.busy = 'idle';
        }
    }
}

declare global {
    interface HTMLElementTagNameMap {
        'mindfula11y-altless-file-reference': AltlessFileReference;
    }
}
