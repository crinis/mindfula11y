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
 * @file scan-service.js
 * @description Service for accessibility scan operations.
 * @typedef {import('./types.js').CreateScanDemand} CreateScanDemand
 */
import AjaxRequest from "@typo3/core/ajax/ajax-request.js";

/**
 * Service for managing accessibility scan operations.
 *
 * @class ScanService
 */
export class ScanService {
  /**
   * Creates a new accessibility scan.
   * @param {CreateScanDemand} createScanDemand
   * @returns {Promise<{scanId: string, status: string}>}
   */
  async createScan(createScanDemand) {
    try {
      const response = await new AjaxRequest(
        TYPO3.settings.ajaxUrls.mindfula11y_createscan
      ).post(createScanDemand);
      const data = await response.resolve();

      return { scanId: data.scanId, status: data.status || "pending" };
    } catch (error) {
      if (error.response) {
        const errorData = await error.response.json();
        if (errorData?.error) {
          const customError = new Error(errorData.error.title);
          customError.description = errorData.error.description;
          throw customError;
        }
      }
      throw error;
    }
  }

  /**
   * Loads scan results from the server.
   * @param {string} scanId
   * @returns {Promise<{status: string, violations: Array}|null>}
   */
  async loadScan(scanId) {
    try {
      const response = await new AjaxRequest(
        TYPO3.settings.ajaxUrls.mindfula11y_getscan
      )
        .withQueryArguments({ scanId })
        .get();
      const data = await response.resolve();

      return {
        status: data.status || "completed",
        violations: data.violations || [],
      };
    } catch (error) {
      if (error.response?.status === 404) {
        return null; // Scan not found
      }
      if (error.response) {
        const errorData = await error.response.json();
        if (errorData?.error) {
          const customError = new Error(errorData.error.title);
          customError.description = errorData.error.description;
          throw customError;
        }
      }
      throw error;
    }
  }

  /**
   * Calculates total issues from violations array.
   * @param {Array} violations
   * @returns {number}
   */
  getTotalIssues(violations) {
    return violations.reduce((total, v) => total + (v.issues ? v.issues.length : 0), 0);
  }

  /**
   * Checks if scan is in progress.
   * @param {string} status
   * @returns {boolean}
   */
  isScanInProgress(status) {
    return ['pending', 'running'].includes(status);
  }
}

export default ScanService;