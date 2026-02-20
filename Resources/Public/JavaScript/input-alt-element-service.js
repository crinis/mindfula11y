/*
 * Mindful A11y extension for TYPO3 integrating accessibility tools into the backend.
 * Copyright (C) 2025  Mindful Markup, Felix Spittel
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
 * @file input-alt-element-service.js
 * @description Provides a service for handling alternative text input fields in TYPO3, including alt text generation via AJAX.
 */
import RegularEvent from "@typo3/core/event/regular-event.js";
import DocumentService from "@typo3/core/document-service.js";
import Notification from "@typo3/backend/notification.js";
import AltTextGeneratorService from "./alt-text-generator-service.js";

/** @typedef {import('./types.js').GenerateAltTextDemand} GenerateAltTextDemand */

/**
 * Service for handling input alt text generation for the TCA input alt element.
 *
 * Binds to a form field and provides functionality to generate and populate
 * alternative text (alt text) for images via an AJAX request, including UI feedback.
 *
 * @class InputAltElementService
 */
class InputAltElementService {
  /**
   * Create a new InputAltElementService instance.
   *
   * @param {string} selector - CSS selector for the element to bind the service to.
   * @param {GenerateAltTextDemand} altTextDemand - Configuration options for the service and AJAX request.
   * @param {AltTextGeneratorService} [altTextGeneratorService=null] - Optional alt text generator service for dependency injection (mainly for testing).
   */
  constructor(selector, altTextDemand, altTextGeneratorService = null) {
    this.ajaxUrl = TYPO3.settings.ajaxUrls.mindfula11y_generatealttext;
    this.altTextGeneratorService = new AltTextGeneratorService(this.ajaxUrl);

    DocumentService.ready().then((document) => {
      const fullElement = document.querySelector(selector);
      if (!fullElement) {
        console.error(
          "InputAltElementService: Could not find element with selector: " +
            selector
        );
        return;
      }
      const generateButton = fullElement.querySelector(
        ".t3js-form-field-alt-generate"
      );
      const inputField = fullElement.querySelector(".form-control");
      if (!generateButton || !inputField) {
        console.error(
          "InputAltElementService: Could not find generate button or input field in element with selector: " +
            selector
        );
        return;
      }
      this.registerEvents(generateButton, inputField, altTextDemand);
    });
  }

  /**
   * Register event listeners for the generate button, handle click events,
   * trigger AJAX requests, and update the UI with the generated alt text.
   *
   * @param {HTMLElement} generateButton - Button element to trigger alt text generation.
   * @param {HTMLInputElement} inputField - Input field to populate with the generated alt text.
   * @param {GenerateAltTextDemand} altTextDemand - Configuration options for the service and AJAX request.
   * @returns {void}
   */
  registerEvents(generateButton, inputField, altTextDemand) {
    new RegularEvent("click", async (e) => {
      e.preventDefault();
      generateButton.setAttribute("disabled", "disabled");
      generateButton.parentElement.appendChild(this.createSpinner());
      const altText = await this.altTextGeneratorService.generateAltText(
        altTextDemand
      );
      generateButton.removeAttribute("disabled");
      const spinner =
        generateButton.parentElement.querySelector(".spinner-wrap");
      if (spinner) {
        spinner.remove();
      }
      if (!altText) {
        return;
      }
      inputField.value = altText;
      inputField.dispatchEvent(new Event("change"));
      Notification.success(
        TYPO3.lang["mindfula11y.altText.generate.success"],
        TYPO3.lang[
          "mindfula11y.altText.generate.success.description"
        ]
      );
    }).bindTo(generateButton);
  }

  /**
   * Creates a spinner element to indicate a loading state next to the button.
   *
   * @returns {HTMLDivElement} Spinner element to be shown during AJAX requests.
   */
  createSpinner() {
    const spinnerWrap = document.createElement("div");
    spinnerWrap.classList.add("spinner-wrap", "ms-2");
    const spinner = document.createElement("div");
    spinner.classList.add("spinner-border");
    spinner.setAttribute("role", "status");
    const span = document.createElement("span");
    span.classList.add("visually-hidden");
    span.textContent = TYPO3.lang["mindfula11y.altText.generate.loading"];
    spinner.appendChild(span);
    spinnerWrap.appendChild(spinner);
    return spinnerWrap;
  }
}

export default InputAltElementService;
